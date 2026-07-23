<?php

namespace Tests\Feature;

use App\Http\Middleware\SlowRequestMonitor;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SlowRequestMonitorTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.debug', false);
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->logPath = storage_path('logs/performance-monitor-test.log');
        @unlink($this->logPath);

        config()->set('logging.channels.performance_test', [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'info',
            'replace_placeholders' => true,
        ]);
        Log::forgetChannel('performance_test');

        config()->set('performance.slow_requests.enabled', false);
        config()->set('performance.slow_requests.log_channel', 'performance_test');
        config()->set('performance.slow_requests.thresholds', $this->thresholds());
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        Log::forgetChannel('performance_test');

        parent::tearDown();
    }

    public function test_disabled_monitor_does_not_initialize_listener_metrics_or_emit_log(): void
    {
        $request = Request::create('/_performance/disabled', 'GET');

        $this->handleMonitoredRequest(function () {
            DB::select('select 1 as value');

            return response('ok');
        }, $request);

        $this->assertFalse($this->app->bound('performance.slow_request.db_listener_registered'));
        $this->assertFalse($request->attributes->has('performance.slow_request.metrics'));
        $this->assertSame([], $this->loggedContexts());
    }

    public function test_fast_request_below_threshold_does_not_emit_log(): void
    {
        $this->enableMonitor();

        $this->handleMonitoredRequest(fn () => response('ok'));

        $this->assertSame([], $this->loggedContexts());
    }

    public function test_duration_threshold_emits_one_sanitized_event(): void
    {
        $this->enableMonitor(['duration_ms' => 0.1]);

        $this->handleMonitoredRequest(
            function () {
                usleep(1000);

                return response('ok');
            },
            Request::create('/_performance/sensitive?email=secret@example.test&nisn=999999', 'POST', [
                'password' => 'super-secret-password',
                'nama_siswa' => 'Nama Rahasia',
                'csrf_token' => 'csrf-secret',
            ]),
            $this->makeRoute('performance.sensitive', '_performance/sensitive', ['guest'])
        );

        $contexts = $this->loggedContexts();

        $this->assertCount(1, $contexts);
        $this->assertContains('duration', $contexts[0]['triggers']);
        $this->assertSame('POST', $contexts[0]['method']);
        $this->assertSame('performance.sensitive', $contexts[0]['route_name']);
        $this->assertSame('_performance/sensitive', $contexts[0]['route_uri']);
        $this->assertSame('guest', $contexts[0]['guard']);

        $encoded = json_encode($contexts[0]);

        $this->assertStringNotContainsString('secret@example.test', $encoded);
        $this->assertStringNotContainsString('999999', $encoded);
        $this->assertStringNotContainsString('super-secret-password', $encoded);
        $this->assertStringNotContainsString('Nama Rahasia', $encoded);
        $this->assertStringNotContainsString('csrf-secret', $encoded);
        $this->assertStringNotContainsString('select 1', $encoded);
        $this->assertStringNotContainsString('bindings', $encoded);
    }

    public function test_query_count_threshold_emits_log_without_sql_or_bindings(): void
    {
        $this->enableMonitor(['query_count' => 1]);

        $this->handleMonitoredRequest(function () {
            DB::select('select ? as value', ['secret-binding']);
            DB::select('select ? as value', ['another-secret-binding']);

            return response('ok');
        });

        $contexts = $this->loggedContexts();

        $this->assertCount(1, $contexts);
        $this->assertContains('query_count', $contexts[0]['triggers']);
        $this->assertSame(2, $contexts[0]['query_count']);
        $this->assertGreaterThanOrEqual(0, $contexts[0]['database_ms']);
        $this->assertGreaterThanOrEqual(0, $contexts[0]['max_query_ms']);

        $encoded = json_encode($contexts[0]);

        $this->assertStringNotContainsString('select ? as value', $encoded);
        $this->assertStringNotContainsString('secret-binding', $encoded);
        $this->assertStringNotContainsString('another-secret-binding', $encoded);
        $this->assertStringNotContainsString('bindings', $encoded);
    }

    public function test_non_positive_thresholds_disable_threshold_triggers(): void
    {
        $this->enableMonitor([
            'duration_ms' => 0,
            'query_count' => 0,
            'database_ms' => -1,
            'max_query_ms' => -1,
        ]);

        $this->handleMonitoredRequest(function () {
            usleep(1000);
            DB::select('select 1 as value');
            DB::select('select 1 as value');

            return response('ok');
        });

        $this->assertSame([], $this->loggedContexts());
    }

    public function test_server_error_is_logged_and_exception_is_rethrown(): void
    {
        $this->enableMonitor();

        try {
            $this->handleMonitoredRequest(function () {
                throw new RuntimeException('Sensitive failure text should not be logged.');
            });

            $this->fail('Expected the route exception to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sensitive failure text should not be logged.', $exception->getMessage());
        }

        $contexts = $this->loggedContexts();

        $this->assertCount(1, $contexts);
        $this->assertContains('server_error', $contexts[0]['triggers']);
        $this->assertSame(500, $contexts[0]['status_code']);
        $this->assertStringNotContainsString('Sensitive failure text', json_encode($contexts[0]));
    }

    public function test_redirect_is_logged_without_query_string(): void
    {
        $this->enableMonitor(['duration_ms' => 0.1]);

        $this->handleMonitoredRequest(
            function () {
                usleep(1000);

                return redirect('/admin/student/9876543210/edit/siswa-rahasia?token=redirect-secret');
            },
            Request::create('/_performance/redirect', 'POST'),
            $this->makeRoute('performance.redirect', '_performance/redirect')
        );

        $contexts = $this->loggedContexts();

        $this->assertCount(1, $contexts);
        $this->assertTrue($contexts[0]['is_redirect']);
        $this->assertArrayNotHasKey('redirect_path', $contexts[0]);

        $encoded = json_encode($contexts[0]);

        $this->assertStringNotContainsString('/admin/student/9876543210/edit', $encoded);
        $this->assertStringNotContainsString('9876543210', $encoded);
        $this->assertStringNotContainsString('siswa-rahasia', $encoded);
        $this->assertStringNotContainsString('redirect-secret', $encoded);
    }

    public function test_unnamed_guest_request_is_safe(): void
    {
        $this->enableMonitor(['duration_ms' => 0.1]);

        $this->handleMonitoredRequest(
            function () {
                usleep(1000);

                return response('ok');
            },
            Request::create('/_performance/unnamed', 'GET'),
            $this->makeRoute(null, '_performance/unnamed', ['guest'])
        );

        $contexts = $this->loggedContexts();

        $this->assertCount(1, $contexts);
        $this->assertNull($contexts[0]['route_name']);
        $this->assertSame('_performance/unnamed', $contexts[0]['route_uri']);
        $this->assertSame('guest', $contexts[0]['guard']);
    }

    public function test_database_listener_is_not_registered_twice_in_one_process(): void
    {
        $this->enableMonitor(['query_count' => 1]);

        $this->handleMonitoredRequest(function () {
            DB::select('select 1 as value');
            DB::select('select 1 as value');

            return response('ok');
        });

        $this->handleMonitoredRequest(function () {
            DB::select('select 1 as value');
            DB::select('select 1 as value');

            return response('ok');
        });

        $contexts = $this->loggedContexts();

        $this->assertCount(2, $contexts);
        $this->assertSame(2, $contexts[0]['query_count']);
        $this->assertSame(2, $contexts[1]['query_count']);
    }

    public function test_logging_failure_does_not_change_normal_response(): void
    {
        $this->enableMonitor(['duration_ms' => 0.1]);
        config()->set('performance.slow_requests.log_channel', 'missing_performance_channel');

        $response = $this->handleMonitoredRequest(function () {
            usleep(1000);

            return response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $this->loggedContexts());
    }

    public function test_logging_failure_does_not_replace_application_exception(): void
    {
        $this->enableMonitor();
        config()->set('performance.slow_requests.log_channel', 'missing_performance_channel');

        try {
            $this->handleMonitoredRequest(function () {
                throw new RuntimeException('Original application exception.');
            });

            $this->fail('Expected the route exception to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Original application exception.', $exception->getMessage());
        }
    }

    public function test_guard_context_uses_route_middleware_without_authentication_queries(): void
    {
        $this->enableMonitor(['duration_ms' => 0.1]);

        $request = Request::create('/_performance/guru-route', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('selected_role', 'wali_kelas');
        $request->session()->put('tahun_ajaran_id', 10);
        $request->session()->put('selected_semester', 2);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->handleMonitoredRequest(
            function () {
                usleep(1000);

                return response('ok');
            },
            $request,
            $this->makeRoute('performance.guru', '_performance/guru-route', ['auth:guru', 'role:guru'])
        );

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $contexts = $this->loggedContexts();

        $this->assertSame([], $queryLog);
        $this->assertCount(1, $contexts);
        $this->assertSame('guru', $contexts[0]['guard']);
        $this->assertSame('wali_kelas', $contexts[0]['selected_role']);
        $this->assertSame(10, $contexts[0]['tahun_ajaran_id']);
        $this->assertSame(2, $contexts[0]['semester']);
    }

    /**
     * @param array<string, int|float> $overrides
     */
    private function enableMonitor(array $overrides = []): void
    {
        config()->set('performance.slow_requests.enabled', true);
        config()->set('performance.slow_requests.thresholds', $this->thresholds($overrides));
    }

    /**
     * @param array<string, int|float> $overrides
     * @return array<string, int|float>
     */
    private function thresholds(array $overrides = []): array
    {
        return array_merge([
            'duration_ms' => 999999.0,
            'query_count' => 999999,
            'database_ms' => 999999.0,
            'max_query_ms' => 999999.0,
        ], $overrides);
    }

    private function handleMonitoredRequest(
        callable $callback,
        ?Request $request = null,
        ?Route $route = null
    ): mixed {
        $request ??= Request::create('/_performance/test', 'GET');
        $route ??= $this->makeRoute('performance.test', '_performance/test');

        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        return app(SlowRequestMonitor::class)->handle(
            $request,
            fn (Request $request) => $callback($request)
        );
    }

    /**
     * @param array<int, string> $middleware
     */
    private function makeRoute(?string $name, string $uri, array $middleware = []): Route
    {
        $route = new Route(['GET', 'POST'], $uri, ['uses' => fn () => null]);

        if ($name !== null) {
            $route->name($name);
        }

        if ($middleware !== []) {
            $route->middleware($middleware);
        }

        return $route;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loggedContexts(): array
    {
        if (! file_exists($this->logPath)) {
            return [];
        }

        preg_match_all('/performance\.slow_request\s+({[^\n]*})/', file_get_contents($this->logPath), $matches);

        return array_map(
            fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $matches[1]
        );
    }
}
