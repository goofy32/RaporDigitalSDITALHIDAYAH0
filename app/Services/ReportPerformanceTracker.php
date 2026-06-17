<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportPerformanceTracker
{
    private const CONFIG_KEY = 'logging.report_performance.enabled';

    private const SEGMENT_KEYS = [
        'authorization' => 'authorization_ms',
        'context' => 'context_ms',
        'preload' => 'preload_ms',
        'template_open' => 'template_open_ms',
        'template_replace' => 'template_replace_ms',
        'images' => 'images_ms',
        'docx_save' => 'docx_save_ms',
        'libreoffice' => 'libreoffice_ms',
        'libreoffice_lookup' => 'libreoffice_lookup_ms',
        'libreoffice_profile_setup' => 'libreoffice_profile_setup_ms',
        'libreoffice_process' => 'libreoffice_process_ms',
        'libreoffice_output_validation' => 'libreoffice_output_validation_ms',
        'cache_lookup' => 'cache_lookup_ms',
        'cache_write' => 'cache_write_ms',
        'response' => 'response_ms',
    ];

    private bool $enabled;

    private ?string $requestId = null;

    private ?string $routeName = null;

    private ?string $flowType = null;

    private ?string $reportType = null;

    private ?bool $cacheHit = null;

    private ?float $startedAt = null;

    /**
     * @var array<string, float>
     */
    private array $segments = [];

    /**
     * @var array<string, array{segment: string, started_at: float}>
     */
    private array $runningSegments = [];

    private int $queryCount = 0;

    private float $databaseMs = 0.0;

    private bool $emitted = false;

    public function __construct()
    {
        $this->enabled = self::isEnabled();
        $this->resetSegments();
    }

    public static function isEnabled(): bool
    {
        return (bool) config(self::CONFIG_KEY, false);
    }

    public static function registerDatabaseListener(): void
    {
        if (! self::isEnabled() || app()->bound('report.performance.db_listener_registered')) {
            return;
        }

        app()->instance('report.performance.db_listener_registered', true);

        DB::listen(function (QueryExecuted $query): void {
            app(self::class)->recordQuery((float) $query->time);
        });
    }

    public static function startFlowIfEnabled(
        string $flowType,
        ?string $reportType = null,
        ?string $routeName = null
    ): ?self {
        if (! self::isEnabled()) {
            return null;
        }

        $tracker = app(self::class);
        $tracker->startFlow($flowType, $reportType, $routeName);

        return $tracker;
    }

    public static function finishIfEnabled(?self $tracker = null): void
    {
        if (! self::isEnabled()) {
            return;
        }

        ($tracker ?: app(self::class))->finish();
    }

    public static function measureSegment(string $segment, callable $callback): mixed
    {
        if (! self::isEnabled()) {
            return $callback();
        }

        return app(self::class)->measure($segment, $callback);
    }

    public static function startSegmentIfEnabled(string $segment): ?string
    {
        if (! self::isEnabled()) {
            return null;
        }

        return app(self::class)->startSegment($segment);
    }

    public static function endSegmentIfEnabled(?string $token): void
    {
        if ($token === null || ! self::isEnabled()) {
            return;
        }

        app(self::class)->endSegment($token);
    }

    public static function setCacheHitIfEnabled(?bool $cacheHit): void
    {
        if (self::isEnabled()) {
            app(self::class)->setCacheHit($cacheHit);
        }
    }

    public static function setFlowTypeIfEnabled(string $flowType): void
    {
        if (self::isEnabled()) {
            app(self::class)->setFlowType($flowType);
        }
    }

    public function startFlow(string $flowType, ?string $reportType = null, ?string $routeName = null): void
    {
        if (! $this->enabled || $this->requestId !== null) {
            return;
        }

        $this->requestId = (string) Str::uuid();
        $this->flowType = $flowType;
        $this->reportType = $reportType;
        $this->routeName = $routeName ?: request()->route()?->getName();
        $this->startedAt = microtime(true);
    }

    public function setFlowType(string $flowType): void
    {
        if ($this->enabled && $this->requestId !== null) {
            $this->flowType = $flowType;
        }
    }

    public function setReportType(?string $reportType): void
    {
        if ($this->enabled && $this->requestId !== null) {
            $this->reportType = $reportType;
        }
    }

    public function setCacheHit(?bool $cacheHit): void
    {
        if ($this->enabled && $this->requestId !== null) {
            $this->cacheHit = $cacheHit;
        }
    }

    public function recordQuery(float $durationMs): void
    {
        if (! $this->enabled || $this->requestId === null) {
            return;
        }

        $this->queryCount++;
        $this->databaseMs += max(0, $durationMs);
    }

    public function startSegment(string $segment): ?string
    {
        if (! $this->enabled || $this->requestId === null || ! isset(self::SEGMENT_KEYS[$segment])) {
            return null;
        }

        $token = $segment.':'.Str::uuid();
        $this->runningSegments[$token] = [
            'segment' => $segment,
            'started_at' => microtime(true),
        ];

        return $token;
    }

    public function endSegment(?string $token): void
    {
        if (! $this->enabled || $token === null || ! isset($this->runningSegments[$token])) {
            return;
        }

        $running = $this->runningSegments[$token];
        unset($this->runningSegments[$token]);

        $key = self::SEGMENT_KEYS[$running['segment']];
        $this->segments[$key] += $this->elapsedMs($running['started_at']);
    }

    public function measure(string $segment, callable $callback): mixed
    {
        $token = $this->startSegment($segment);

        try {
            return $callback();
        } finally {
            $this->endSegment($token);
        }
    }

    public function finish(): void
    {
        if (! $this->enabled || $this->requestId === null || $this->emitted) {
            return;
        }

        foreach (array_keys($this->runningSegments) as $token) {
            $this->endSegment($token);
        }

        $this->emitted = true;

        Log::info('report.performance', $this->metrics());
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $metrics = [
            'request_id' => $this->requestId,
            'route_name' => $this->routeName,
            'flow_type' => $this->flowType,
            'report_type' => $this->reportType,
            'cache_hit' => $this->cacheHit,
            'query_count' => $this->queryCount,
            'database_ms' => round($this->databaseMs, 2),
        ];

        foreach ($this->segments as $key => $value) {
            $metrics[$key] = round($value, 2);
        }

        $metrics['total_ms'] = $this->startedAt ? round($this->elapsedMs($this->startedAt), 2) : 0.0;
        $metrics['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        return $metrics;
    }

    private function resetSegments(): void
    {
        $this->segments = array_fill_keys(array_values(self::SEGMENT_KEYS), 0.0);
    }

    private function elapsedMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }
}
