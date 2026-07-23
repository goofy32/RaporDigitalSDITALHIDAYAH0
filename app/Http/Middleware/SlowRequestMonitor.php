<?php

namespace App\Http\Middleware;

use App\Services\SlowRequestMetrics;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SlowRequestMonitor
{
    private const METRICS_ATTRIBUTE = 'performance.slow_request.metrics';

    private const LISTENER_REGISTERED_KEY = 'performance.slow_request.db_listener_registered';

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $metrics = new SlowRequestMetrics();
        $request->attributes->set(self::METRICS_ATTRIBUTE, $metrics);
        $this->registerDatabaseListener();

        $response = null;
        $statusCode = 500;

        try {
            $response = $next($request);
            $statusCode = $this->statusCode($response);

            return $response;
        } catch (Throwable $exception) {
            $statusCode = 500;

            throw $exception;
        } finally {
            $this->logIfThresholdExceeded(
                $request,
                $response,
                $statusCode,
                $metrics,
                $this->elapsedMs($startedAt)
            );

            $request->attributes->remove(self::METRICS_ATTRIBUTE);
        }
    }

    private function enabled(): bool
    {
        return (bool) config('performance.slow_requests.enabled', false);
    }

    private function registerDatabaseListener(): void
    {
        if (app()->bound(self::LISTENER_REGISTERED_KEY)) {
            return;
        }

        app()->instance(self::LISTENER_REGISTERED_KEY, true);

        DB::listen(function (QueryExecuted $query): void {
            try {
                $metrics = request()->attributes->get(self::METRICS_ATTRIBUTE);

                if ($metrics instanceof SlowRequestMetrics) {
                    $metrics->recordQuery((float) $query->time);
                }
            } catch (Throwable) {
                // Instrumentation must never affect the application response.
            }
        });
    }

    private function logIfThresholdExceeded(
        Request $request,
        mixed $response,
        int $statusCode,
        SlowRequestMetrics $metrics,
        float $durationMs
    ): void {
        try {
            $triggers = $this->triggers($statusCode, $metrics, $durationMs);

            if ($triggers === []) {
                return;
            }

            Log::channel((string) config('performance.slow_requests.log_channel', 'performance'))
                ->info('performance.slow_request', $this->context(
                    $request,
                    $response,
                    $statusCode,
                    $metrics,
                    $durationMs,
                    $triggers
                ));
        } catch (Throwable) {
            // Do not turn monitoring failure into an HTTP failure.
        }
    }

    /**
     * @return array<int, string>
     */
    private function triggers(int $statusCode, SlowRequestMetrics $metrics, float $durationMs): array
    {
        $thresholds = config('performance.slow_requests.thresholds', []);
        $triggers = [];

        if ($this->exceedsFloatThreshold($durationMs, $thresholds['duration_ms'] ?? null)) {
            $triggers[] = 'duration';
        }

        if ($this->exceedsIntThreshold($metrics->queryCount(), $thresholds['query_count'] ?? null)) {
            $triggers[] = 'query_count';
        }

        if ($this->exceedsFloatThreshold($metrics->databaseMs(), $thresholds['database_ms'] ?? null)) {
            $triggers[] = 'database_time';
        }

        if ($this->exceedsFloatThreshold($metrics->maxQueryMs(), $thresholds['max_query_ms'] ?? null)) {
            $triggers[] = 'max_query';
        }

        if ($statusCode >= 500) {
            $triggers[] = 'server_error';
        }

        return $triggers;
    }

    /**
     * @param array<int, string> $triggers
     * @return array<string, mixed>
     */
    private function context(
        Request $request,
        mixed $response,
        int $statusCode,
        SlowRequestMetrics $metrics,
        float $durationMs,
        array $triggers
    ): array {
        $route = $request->route();
        $isRedirect = $response instanceof RedirectResponse;
        $routeMiddleware = $this->routeMiddleware($request);
        $guard = $this->guard($routeMiddleware);

        return [
            'event' => 'performance.slow_request',
            'request_id' => (string) Str::uuid(),
            'triggers' => $triggers,
            'method' => $request->method(),
            'route_name' => $route?->getName(),
            'route_uri' => $route?->uri(),
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 2),
            'query_count' => $metrics->queryCount(),
            'database_ms' => round($metrics->databaseMs(), 2),
            'max_query_ms' => round($metrics->maxQueryMs(), 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'is_redirect' => $isRedirect,
            'guard' => $guard,
            'selected_role' => $this->selectedRole($request, $guard),
            'tahun_ajaran_id' => $this->integerSessionValue($request, 'tahun_ajaran_id'),
            'semester' => $this->integerSessionValue($request, 'selected_semester'),
        ];
    }

    private function statusCode(mixed $response): int
    {
        return $response instanceof Response ? $response->getStatusCode() : 200;
    }

    private function guard(array $routeMiddleware): string
    {
        foreach ($routeMiddleware as $middleware) {
            if ($middleware === 'auth:guru' || str_starts_with($middleware, 'auth:guru,')) {
                return 'guru';
            }
        }

        foreach ($routeMiddleware as $middleware) {
            if ($middleware === 'auth' || $middleware === 'auth:web' || str_starts_with($middleware, 'auth:web,')) {
                return 'admin';
            }
        }

        foreach ($routeMiddleware as $middleware) {
            if ($middleware === 'guest' || str_starts_with($middleware, 'guest:')) {
                return 'guest';
            }
        }

        return 'unknown';
    }

    private function selectedRole(Request $request, string $guard): ?string
    {
        if ($guard === 'admin') {
            return 'admin';
        }

        if (! $request->hasSession()) {
            return null;
        }

        $role = $request->session()->get('selected_role');

        return in_array($role, ['pengajar', 'wali_kelas'], true) ? $role : null;
    }

    private function integerSessionValue(Request $request, string $key): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private function exceedsFloatThreshold(float $value, mixed $threshold): bool
    {
        if (! is_numeric($threshold)) {
            return false;
        }

        $threshold = (float) $threshold;

        return $threshold > 0 && $value > $threshold;
    }

    private function exceedsIntThreshold(int $value, mixed $threshold): bool
    {
        if (! is_numeric($threshold)) {
            return false;
        }

        $threshold = (int) $threshold;

        return $threshold > 0 && $value > $threshold;
    }

    /**
     * @return array<int, string>
     */
    private function routeMiddleware(Request $request): array
    {
        try {
            return array_values(array_filter(array_map(
                fn (mixed $middleware) => $this->middlewareToString($middleware),
                $request->route()?->gatherMiddleware() ?? []
            )));
        } catch (Throwable) {
            return [];
        }
    }

    private function middlewareToString(mixed $middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        }

        if (is_object($middleware)) {
            return $middleware::class;
        }

        return is_scalar($middleware) ? (string) $middleware : '';
    }
}
