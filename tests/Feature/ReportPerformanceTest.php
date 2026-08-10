<?php

namespace Tests\Feature;

use App\Jobs\AutoPreparePdfReportJob;
use App\Jobs\GeneratePdfReportJob;
use App\Models\Siswa;
use App\Services\DocumentConversionService;
use App\Services\PdfCacheService;
use App\Services\ReportPdfAutoPrepareService;
use App\Services\ReportPerformanceTracker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Throwable;

class ReportPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();
        Storage::fake('public');

        $this->createSchema();
        $this->resetReportPerformance(false);
    }

    public function test_report_performance_instrumentation_is_disabled_by_default(): void
    {
        Log::shouldReceive('info')->never();

        ReportPerformanceTracker::registerDatabaseListener();
        $tracker = ReportPerformanceTracker::startFlowIfEnabled('html_preview', 'UTS', 'wali_kelas.rapor.preview');

        DB::select('select 1 as value');

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $this->assertNull($tracker);
    }

    public function test_enabled_tracker_emits_one_aggregate_event_and_counts_queries_without_sql_or_bindings(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        ReportPerformanceTracker::registerDatabaseListener();
        ReportPerformanceTracker::registerDatabaseListener();

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('html_preview', 'UTS', 'wali_kelas.rapor.preview');

        DB::select('select ? as value', ['secret-binding']);

        ReportPerformanceTracker::finishIfEnabled($tracker);
        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertNotNull($metrics);
        $this->assertNotEmpty($metrics['request_id']);
        $this->assertSame('wali_kelas.rapor.preview', $metrics['route_name']);
        $this->assertSame('html_preview', $metrics['flow_type']);
        $this->assertSame('UTS', $metrics['report_type']);
        $this->assertSame(1, $metrics['query_count']);
        $this->assertGreaterThanOrEqual(0, $metrics['database_ms']);

        $encoded = json_encode($metrics);

        $this->assertStringNotContainsString('select', $encoded);
        $this->assertStringNotContainsString('secret-binding', $encoded);
        $this->assertStringNotContainsString('bindings', $encoded);
    }

    public function test_html_preview_metrics_do_not_record_docx_or_libreoffice_segments(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('html_preview', 'UAS', 'wali_kelas.rapor.preview');

        ReportPerformanceTracker::measureSegment('authorization', fn () => true);
        ReportPerformanceTracker::measureSegment('context', fn () => true);
        ReportPerformanceTracker::measureSegment('preload', fn () => true);
        ReportPerformanceTracker::measureSegment('response', fn () => true);

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertSame('html_preview', $metrics['flow_type']);
        $this->assertSame(0.0, $metrics['template_open_ms']);
        $this->assertSame(0.0, $metrics['template_replace_ms']);
        $this->assertSame(0.0, $metrics['images_ms']);
        $this->assertSame(0.0, $metrics['docx_save_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_lookup_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_profile_setup_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_process_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_output_validation_ms']);
    }

    public function test_pdf_cache_miss_metrics_distinguish_generation_segments(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_preview_cache_miss', 'UTS', 'wali_kelas.rapor.pdf.preview');
        ReportPerformanceTracker::setCacheHitIfEnabled(false);

        foreach (['template_open', 'template_replace', 'images', 'docx_save', 'libreoffice'] as $segment) {
            ReportPerformanceTracker::measureSegment($segment, fn () => usleep(1000));
        }

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertSame('pdf_preview_cache_miss', $metrics['flow_type']);
        $this->assertFalse($metrics['cache_hit']);
        $this->assertGreaterThan(0, $metrics['template_open_ms']);
        $this->assertGreaterThan(0, $metrics['template_replace_ms']);
        $this->assertGreaterThan(0, $metrics['images_ms']);
        $this->assertGreaterThan(0, $metrics['docx_save_ms']);
        $this->assertGreaterThan(0, $metrics['libreoffice_ms']);
    }

    public function test_libreoffice_subsegments_are_reported_separately_from_aggregate_timing(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_preview_cache_miss', 'UTS', 'wali_kelas.rapor.pdf.preview');

        ReportPerformanceTracker::measureSegment('libreoffice', function () {
            foreach (['libreoffice_lookup', 'libreoffice_profile_setup', 'libreoffice_process', 'libreoffice_output_validation'] as $segment) {
                ReportPerformanceTracker::measureSegment($segment, fn () => usleep(1000));
            }
        });

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertGreaterThan(0, $metrics['libreoffice_ms']);
        $this->assertGreaterThan(0, $metrics['libreoffice_lookup_ms']);
        $this->assertGreaterThan(0, $metrics['libreoffice_profile_setup_ms']);
        $this->assertGreaterThan(0, $metrics['libreoffice_process_ms']);
        $this->assertGreaterThan(0, $metrics['libreoffice_output_validation_ms']);
    }

    public function test_pdf_cache_hit_metrics_do_not_record_generation_segments_or_student_identity(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $siswa = $this->createStudent(
            nama: 'Siswa Rahasia',
            nis: 'NIS-RAHASIA-001',
            nisn: 'NISN-RAHASIA-001'
        );

        Storage::disk('public')->put('pdf_reports/cached.pdf', 'PDF');
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1, 1), [
            'path' => 'pdf_reports/cached.pdf',
            'filename' => 'Rapor_Siswa_Rahasia.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
            'freshness_version' => PdfCacheService::currentFreshnessVersion($siswa, 'UTS', 1, 1),
            'semester' => 1,
        ], now()->addHour());

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_preview_pending', 'UTS', 'wali_kelas.rapor.pdf.preview');

        $cached = PdfCacheService::getCachedPdf($siswa, 'UTS', 1, 1);
        ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_preview_cache_hit');

        $this->assertNotNull($cached);

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertSame('pdf_preview_cache_hit', $metrics['flow_type']);
        $this->assertTrue($metrics['cache_hit']);
        $this->assertGreaterThanOrEqual(0, $metrics['cache_lookup_ms']);
        $this->assertSame(0.0, $metrics['template_open_ms']);
        $this->assertSame(0.0, $metrics['template_replace_ms']);
        $this->assertSame(0.0, $metrics['images_ms']);
        $this->assertSame(0.0, $metrics['docx_save_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_lookup_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_profile_setup_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_process_ms']);
        $this->assertSame(0.0, $metrics['libreoffice_output_validation_ms']);

        $encoded = json_encode($metrics);

        $this->assertStringNotContainsString('Siswa Rahasia', $encoded);
        $this->assertStringNotContainsString('NIS-RAHASIA-001', $encoded);
        $this->assertStringNotContainsString('NISN-RAHASIA-001', $encoded);
        $this->assertStringNotContainsString('Rapor_Siswa_Rahasia.pdf', $encoded);
        $this->assertStringNotContainsString(storage_path(), $encoded);
    }

    public function test_legacy_cache_without_freshness_version_is_rejected(): void
    {
        $siswa = $this->createStudent('Legacy Cache Student', 'LEGACY-001', 'LEGACY-NISN-001');
        Storage::disk('public')->put('pdf_reports/legacy-without-version.pdf', 'PDF');
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1, 1), [
            'path' => 'pdf_reports/legacy-without-version.pdf',
            'filename' => 'legacy-without-version.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $this->assertNull(PdfCacheService::getCachedPdf($siswa, 'UTS', 1, 1));
        $this->assertFalse(Storage::disk('public')->exists('pdf_reports/legacy-without-version.pdf'));
    }

    public function test_pdf_cache_miss_records_cache_miss_without_sql_or_absolute_paths(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $siswa = $this->createStudent(
            nama: 'Nama Tidak Masuk Log',
            nis: 'NIS-TIDAK-MASUK',
            nisn: 'NISN-TIDAK-MASUK'
        );

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_download_pending', 'UAS', 'wali_kelas.rapor.pdf.download');

        $cached = PdfCacheService::getCachedPdf($siswa, 'UAS', 1, 1);
        ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_download_cache_miss');

        $this->assertNull($cached);

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertSame('pdf_download_cache_miss', $metrics['flow_type']);
        $this->assertFalse($metrics['cache_hit']);
        $this->assertGreaterThanOrEqual(0, $metrics['cache_lookup_ms']);

        $encoded = json_encode($metrics);

        $this->assertStringNotContainsString('Nama Tidak Masuk Log', $encoded);
        $this->assertStringNotContainsString('NIS-TIDAK-MASUK', $encoded);
        $this->assertStringNotContainsString('NISN-TIDAK-MASUK', $encoded);
        $this->assertStringNotContainsString('select', $encoded);
        $this->assertStringNotContainsString(storage_path(), $encoded);
    }

    public function test_pdf_cache_metadata_write_is_measured_without_sensitive_data(): void
    {
        $this->resetReportPerformance(true);
        Log::spy();

        $siswa = $this->createStudent(
            nama: 'Siswa Cache Rahasia',
            nis: 'NIS-CACHE-RAHASIA',
            nisn: 'NISN-CACHE-RAHASIA'
        );

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_preview_cache_miss', 'UTS', 'wali_kelas.rapor.pdf.preview');

        PdfCacheService::cachePdf(
            $siswa,
            'UTS',
            1,
            'pdf_reports/cache-write.pdf',
            'Rapor_Siswa_Cache_Rahasia.pdf',
            1024,
            null,
            1
        );

        ReportPerformanceTracker::finishIfEnabled($tracker);

        $metrics = null;
        Log::shouldHaveReceived('info')
            ->with('report.performance', Mockery::on(function (array $context) use (&$metrics) {
                $metrics = $context;

                return true;
            }))
            ->once();

        $this->assertSame('pdf_preview_cache_miss', $metrics['flow_type']);
        $this->assertGreaterThanOrEqual(0, $metrics['cache_write_ms']);

        $encoded = json_encode($metrics);

        $this->assertStringNotContainsString('Siswa Cache Rahasia', $encoded);
        $this->assertStringNotContainsString('NIS-CACHE-RAHASIA', $encoded);
        $this->assertStringNotContainsString('NISN-CACHE-RAHASIA', $encoded);
        $this->assertStringNotContainsString('Rapor_Siswa_Cache_Rahasia.pdf', $encoded);
        $this->assertStringNotContainsString('pdf_reports/cache-write.pdf', $encoded);
    }

    public function test_pdf_job_does_not_convert_when_generation_lock_is_already_held(): void
    {
        $siswa = $this->createStudent('Locked Student', 'LOCK-001', 'LOCK-NISN-001');
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);
        $lock = Cache::lock(PdfCacheService::getGenerationLockKey($siswa, 'UTS', 1, 1), 180);
        $this->assertTrue($lock->get());

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        try {
            (new GeneratePdfReportJob($siswa, 'UTS', 1, 'locked-request', 99))->handle();
        } finally {
            $lock->release();
        }

        $progress = Cache::get('pdf_progress_locked-request');

        $this->assertSame('processing', $progress['status']);
        $this->assertSame('waiting', $progress['stage']);
        $this->assertTrue($progress['processing']);
        $this->assertFalse($progress['cached']);
    }

    public function test_pdf_job_rechecks_cache_before_conversion(): void
    {
        $siswa = $this->createStudent('Cached Job Student', 'JOB-001', 'JOB-NISN-001');
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);
        Storage::disk('public')->put('pdf_reports/job-cached.pdf', 'PDF');
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1, 1), [
            'path' => 'pdf_reports/job-cached.pdf',
            'filename' => 'job-cached.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
            'freshness_version' => PdfCacheService::currentFreshnessVersion($siswa, 'UTS', 1, 1),
            'semester' => 1,
        ], now()->addHour());

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        (new GeneratePdfReportJob($siswa, 'UTS', 1, 'cache-hit-request'))->handle();

        $progress = Cache::get('pdf_progress_cache-hit-request');

        $this->assertSame('ready', $progress['status']);
        $this->assertTrue($progress['cached']);
        $this->assertArrayNotHasKey('download_url', $progress);
    }

    public function test_pdf_generation_lock_is_released_after_job_failure(): void
    {
        $siswa = $this->createStudent('Failure Student', 'FAIL-001', 'FAIL-NISN-001');
        $key = PdfCacheService::getGenerationLockKey($siswa, 'UTS', 1, 1);

        try {
            (new GeneratePdfReportJob($siswa, 'UTS', 1, 'failed-request', 99))->handle();
            $this->fail('Expected the job to fail before report generation.');
        } catch (Throwable) {
            // The minimal test schema intentionally has no report template table.
        }

        $progress = Cache::get('pdf_progress_failed-request');
        $this->assertSame('failed', $progress['status']);
        $this->assertSame('PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.', $progress['message']);
        $this->assertArrayNotHasKey('download_url', $progress);
        $this->assertStringNotContainsString('report_templates', json_encode($progress));

        $lock = Cache::lock($key, 1);

        try {
            $this->assertTrue($lock->get());
        } finally {
            $lock->release();
        }
    }

    public function test_pdf_generation_lock_is_scoped_per_student_type_and_year(): void
    {
        $first = $this->createStudent('First Student', 'LOCK-101', 'LOCK-NISN-101');
        $second = $this->createStudent('Second Student', 'LOCK-102', 'LOCK-NISN-102');

        $this->assertNotSame(
            PdfCacheService::getGenerationLockKey($first, 'UTS', 1, 1),
            PdfCacheService::getGenerationLockKey($second, 'UTS', 1, 1)
        );

        $this->assertNotSame(
            PdfCacheService::getGenerationLockKey($first, 'UTS', 1, 1),
            PdfCacheService::getGenerationLockKey($first, 'UAS', 1, 1)
        );

        $this->assertNotSame(
            PdfCacheService::getGenerationLockKey($first, 'UTS', 1, 1),
            PdfCacheService::getGenerationLockKey($first, 'UTS', 2, 1)
        );
    }

    public function test_report_cache_identity_distinguishes_semester_for_every_context_key(): void
    {
        $siswa = $this->createStudent('Semester Cache', 'SEM-001', 'SEM-NISN-001');

        foreach ([
            fn (int $semester) => PdfCacheService::getCacheKey($siswa, 'UTS', 1, $semester),
            fn (int $semester) => PdfCacheService::getDocxCacheKey($siswa, 'UTS', 1, $semester),
            fn (int $semester) => PdfCacheService::getGenerationLockKey($siswa, 'UTS', 1, $semester),
            fn (int $semester) => PdfCacheService::getGenerationRequestKey($siswa, 'UTS', 1, $semester),
            fn (int $semester) => PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1, $semester),
            fn (int $semester) => PdfCacheService::getFreshnessKey($siswa, 'UTS', 1, $semester),
        ] as $keyFactory) {
            $this->assertNotSame($keyFactory(1), $keyFactory(2));
        }

        $this->assertNotSame(
            PdfCacheService::getCacheKey($siswa, 'UTS', 1, 1),
            PdfCacheService::getCacheKey($siswa, 'UAS', 1, 1)
        );
    }

    public function test_same_year_cache_from_semester_one_is_not_used_after_semester_changes(): void
    {
        $this->insertAcademicYear(1, 1);
        $siswa = $this->createStudent('Semester Transition', 'SEM-002', 'SEM-NISN-002');
        Storage::disk('public')->put('pdf_reports/semester-one.pdf', 'PDF');
        Storage::disk('public')->put('pdf_reports/semester-one-uas.pdf', 'PDF');

        PdfCacheService::cachePdf(
            $siswa,
            'UTS',
            1,
            'pdf_reports/semester-one.pdf',
            'semester-one.pdf',
            3,
            null,
            1
        );
        PdfCacheService::cachePdf(
            $siswa,
            'UAS',
            1,
            'pdf_reports/semester-one-uas.pdf',
            'semester-one-uas.pdf',
            3,
            null,
            1
        );

        $this->assertNotNull(PdfCacheService::getCachedPdf($siswa, 'UTS', 1, 1));
        $this->assertNotNull(PdfCacheService::getCachedPdf($siswa, 'UAS', 1, 1));
        DB::table('tahun_ajarans')->where('id', 1)->update(['semester' => 2]);

        $this->assertNull(PdfCacheService::getCachedPdf($siswa, 'UTS', 1));
        $this->assertNull(PdfCacheService::getCachedPdf($siswa, 'UAS', 1));
        $this->assertNotNull(PdfCacheService::getCachedPdf($siswa, 'UTS', 1, 1));
        $this->assertNotNull(PdfCacheService::getCachedPdf($siswa, 'UAS', 1, 1));
    }

    public function test_pdf_auto_prepare_is_disabled_by_default_even_when_cache_is_cleared(): void
    {
        Queue::fake();
        config()->set('report.pdf_auto_prepare.enabled', false);

        $siswa = $this->createStudent('Disabled Auto Prepare', 'AUTO-DIS-001', 'AUTO-DIS-NISN-001');

        PdfCacheService::clearStudentCache($siswa, 1, true);

        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_pdf_auto_prepare_schedules_delayed_warm_jobs_when_enabled(): void
    {
        Queue::fake();
        $this->fakeLibreOfficeAvailability();
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.delay_seconds', 60);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);

        $siswa = $this->createStudent('Enabled Auto Prepare', 'AUTO-EN-001', 'AUTO-EN-NISN-001');
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);
        Storage::disk('public')->put('pdf_reports/old.pdf', 'PDF');
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1), [
            'path' => 'pdf_reports/old.pdf',
            'filename' => 'old.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
            'freshness_version' => PdfCacheService::currentFreshnessVersion($siswa, 'UTS', 1),
            'semester' => 1,
        ], now()->addHour());

        PdfCacheService::clearStudentCache($siswa, 1, true);

        $this->assertFalse(Cache::has(PdfCacheService::getCacheKey($siswa, 'UTS', 1)));
        $this->assertFalse(Storage::disk('public')->exists('pdf_reports/old.pdf'));
        $this->assertNotEmpty(Cache::get(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1)));
        $this->assertNull(Cache::get(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UAS', 1)));

        Queue::assertPushedOn('pdf-warm', AutoPreparePdfReportJob::class);
        Queue::assertPushed(AutoPreparePdfReportJob::class, 1);
        Queue::assertNotPushed(AutoPreparePdfReportJob::class, fn (AutoPreparePdfReportJob $job) => $job->type === 'UAS');
    }

    public function test_repeated_pdf_auto_prepare_tokens_make_older_jobs_skip(): void
    {
        Queue::fake();
        $this->fakeLibreOfficeAvailability();
        config()->set('report.pdf_auto_prepare.enabled', true);
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);

        $siswa = $this->createStudent('Stale Auto Prepare', 'AUTO-ST-001', 'AUTO-ST-NISN-001');
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);

        (new ReportPdfAutoPrepareService())->scheduleForStudent($siswa, 1, ['UTS'], 'first_change');
        $oldToken = Cache::get(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1));

        (new ReportPdfAutoPrepareService())->scheduleForStudent($siswa, 1, ['UTS'], 'second_change');
        $newToken = Cache::get(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1));

        $this->assertNotSame($oldToken, $newToken);

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        app()->call([
            new AutoPreparePdfReportJob($siswa->id, 'UTS', 1, $oldToken, 'first_change'),
            'handle',
        ]);

        $this->assertFalse(Cache::has(PdfCacheService::getCacheKey($siswa, 'UTS', 1)));
        Queue::assertPushed(AutoPreparePdfReportJob::class, 2);
    }

    public function test_pdf_auto_prepare_does_not_create_state_or_dispatch_when_libreoffice_is_unavailable(): void
    {
        Queue::fake();
        $this->fakeLibreOfficeAvailability(false);
        config()->set('report.pdf_auto_prepare.enabled', true);
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);

        $siswa = $this->createStudent('Unavailable LibreOffice', 'AUTO-NO-LO-001', 'AUTO-NO-LO-NISN-001');
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);

        $scheduled = app(ReportPdfAutoPrepareService::class)
            ->scheduleForStudent($siswa, 1, ['UTS'], 'libreoffice_unavailable_test');

        $this->assertSame(0, $scheduled);
        $this->assertFalse(Cache::has(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1, 1)));
        $this->assertFalse(Cache::has(PdfCacheService::getGenerationRequestKey($siswa, 'UTS', 1, 1)));
        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_pdf_auto_prepare_job_skips_when_cache_already_exists(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);

        $siswa = $this->createStudent('Cache Hit Auto Prepare', 'AUTO-HIT-001', 'AUTO-HIT-NISN-001');
        $this->insertEligibleMidSemesterScore($siswa, 1, 1);
        Storage::disk('public')->put('pdf_reports/auto-hit.pdf', 'PDF');
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1), [
            'path' => 'pdf_reports/auto-hit.pdf',
            'filename' => 'auto-hit.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
            'freshness_version' => PdfCacheService::currentFreshnessVersion($siswa, 'UTS', 1),
            'semester' => 1,
        ], now()->addHour());

        Cache::put(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', 1), 'current-token', now()->addHour());

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        app()->call([
            new AutoPreparePdfReportJob($siswa->id, 'UTS', 1, 'current-token', 'cache_hit_test'),
            'handle',
        ]);

        $this->assertTrue(Cache::has(PdfCacheService::getCacheKey($siswa, 'UTS', 1)));
    }

    public function test_pdf_auto_prepare_job_skips_unavailable_report_type_without_failure_log(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        $this->insertAcademicYear(1, 1);
        $this->insertReportTemplate('UTS', 1, 1);

        $siswa = $this->createStudent('Unavailable Auto Prepare', 'AUTO-NA-001', 'AUTO-NA-NISN-001');
        Cache::put(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UAS', 1), 'uas-token', now()->addHour());

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        Log::shouldReceive('info')
            ->with('report.pdf.auto_prepare_failed', Mockery::any())
            ->never();
        Log::shouldReceive('info')
            ->with('report.pdf.auto_prepare_skipped_unavailable', Mockery::on(function (array $context) use ($siswa) {
                return $context['siswa_id'] === $siswa->id
                    && $context['report_type'] === 'UAS'
                    && $context['unavailable_reason'] === 'report_period_unopened';
            }))
            ->once();

        app()->call([
            new AutoPreparePdfReportJob($siswa->id, 'UAS', 1, 'uas-token', 'queued_before_fix'),
            'handle',
        ]);
        $this->assertFalse(Cache::has(PdfCacheService::getCacheKey($siswa, 'UAS', 1)));
    }

    public function test_frontend_pdf_polling_is_turbo_safe_and_stops_on_terminal_states(): void
    {
        $source = file_get_contents(resource_path('js/features/rapor-manager/pdf.js'));

        $this->assertStringContainsString('activePdfPolls', $source);
        $this->assertStringContainsString("document.addEventListener('turbo:before-cache'", $source);
        $this->assertStringContainsString("document.addEventListener('turbo:before-render'", $source);
        $this->assertStringContainsString("data.status === 'processing'", $source);
        $this->assertStringContainsString("data.status === 'ready'", $source);
        $this->assertStringContainsString('setTimeout(tick, 1000)', $source);
        $this->assertStringContainsString('maxChecks = 180', $source);
    }

    private function resetReportPerformance(bool $enabled): void
    {
        config()->set('logging.report_performance.enabled', $enabled);
        $this->app->forgetInstance(ReportPerformanceTracker::class);
    }

    private function createStudent(string $nama, string $nis, string $nisn): Siswa
    {
        $id = DB::table('siswas')->insertGetId([
            'nama' => $nama,
            'nis' => $nis,
            'nisn' => $nisn,
            'kelas_id' => null,
            'photo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Siswa::findOrFail($id);
    }

    private function insertAcademicYear(int $id, int $semester): void
    {
        DB::table('tahun_ajarans')->insert([
            'id' => $id,
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportTemplate(string $type, int $tahunAjaranId, int $semester): void
    {
        DB::table('report_templates')->insert([
            'filename' => "template-{$type}.docx",
            'path' => "templates/template-{$type}.docx",
            'type' => $type,
            'is_active' => true,
            'kelas_id' => null,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEligibleMidSemesterScore(Siswa $siswa, int $tahunAjaranId, int $semester): void
    {
        $kelasId = DB::table('kelas')->insertGetId([
            'nama_kelas' => 'Kelas '.$siswa->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswas')->where('id', $siswa->id)->update([
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
        ]);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $siswa->id,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siswa->refresh();

        $subjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'Matematika '.$siswa->id,
            'kelas_id' => $kelasId,
            'semester' => $semester,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $siswa->id,
            'mata_pelajaran_id' => $subjectId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'na_tp' => 80,
            'na_lm' => 90,
            'nilai_akhir_rapor' => 85,
            'is_submitted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeLibreOfficeAvailability(bool $available = true): void
    {
        $this->mock(DocumentConversionService::class, function ($mock) use ($available) {
            $mock->shouldReceive('isLibreOfficeAvailable')->andReturn($available);
        });
    }

    private function createSchema(): void
    {
        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('photo')->nullable();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('na_tp', 5, 2)->nullable();
            $table->decimal('na_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });
    }
}
