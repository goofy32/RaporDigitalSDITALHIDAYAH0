<?php

namespace Tests\Feature;

use App\Models\Siswa;
use App\Services\PdfCacheService;
use App\Services\ReportPerformanceTracker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

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
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', 1), [
            'path' => 'pdf_reports/cached.pdf',
            'filename' => 'Rapor_Siswa_Rahasia.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $tracker = ReportPerformanceTracker::startFlowIfEnabled('pdf_preview_pending', 'UTS', 'wali_kelas.rapor.pdf.preview');

        $cached = PdfCacheService::getCachedPdf($siswa, 'UTS', 1);
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

        $encoded = json_encode($metrics);

        $this->assertStringNotContainsString('Siswa Rahasia', $encoded);
        $this->assertStringNotContainsString('NIS-RAHASIA-001', $encoded);
        $this->assertStringNotContainsString('NISN-RAHASIA-001', $encoded);
        $this->assertStringNotContainsString('Rapor_Siswa_Rahasia.pdf', $encoded);
        $this->assertStringNotContainsString(storage_path(), $encoded);
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

        $cached = PdfCacheService::getCachedPdf($siswa, 'UAS', 1);
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
            1024
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

    private function resetReportPerformance(bool $enabled): void
    {
        config()->set('logging.report_performance.enabled', $enabled);
        $this->app->forgetInstance(ReportPerformanceTracker::class);
        $this->app->forgetInstance('report.performance.db_listener_registered');
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

    private function createSchema(): void
    {
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
    }
}
