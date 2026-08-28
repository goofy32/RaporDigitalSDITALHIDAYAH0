<?php

namespace Tests\Feature;

use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\ScoreController;
use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
use App\Models\LingkupMateri;
use App\Models\Nilai;
use App\Models\TujuanPembelajaran;
use App\Models\User;
use App\Services\PdfCacheService;
use App\Services\PengajarScoreExcelTemplateService;
use App\Services\ReportScoreEligibilityService;
use App\Services\ScoreAggregateRecalculationService;
use App\Services\SpreadsheetImportGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PengajarScoreAuthorizationTest extends TestCase
{
    private Guru $budi;

    private Guru $ani;

    private User $admin;

    private int $activeYearId;

    private int $oldYearId;

    private int $classId;

    private int $studentId;

    private int $subjectId;

    private int $wrongSemesterSubjectId;

    private int $lingkupMateriId;

    private int $tujuanPembelajaranId;

    /**
     * @var array<int, string>
     */
    private array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $workbook) {
            if (is_file($workbook)) {
                @unlink($workbook);
            }
        }

        parent::tearDown();
    }

    public function test_authorized_pengajar_can_save_grades_for_assigned_subject(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->assertSame(3, DB::table('nilais')->count());
        $this->assertSame(1, DB::table('nilais')->whereNotNull('tujuan_pembelajaran_id')->whereNotNull('nilai_tp')->count());
        $this->assertSame(1, DB::table('nilais')->whereNotNull('lingkup_materi_id')->whereNull('tujuan_pembelajaran_id')->whereNotNull('nilai_lm')->count());
        $this->assertSame(1, DB::table('nilais')->whereNull('lingkup_materi_id')->whereNull('tujuan_pembelajaran_id')->whereNotNull('nilai_akhir_rapor')->count());
    }

    public function test_admin_tp_page_renders_valid_destroy_and_dependency_urls(): void
    {
        $baseUrl = url('/admin/tujuan-pembelajaran');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->sessionForActiveYear('admin'))
            ->get(route('tujuan_pembelajaran.create', $this->subjectId))
            ->assertOk()
            ->assertSee('data-destroy-base-url="'.$baseUrl.'"', false)
            ->assertSee('data-dependency-check-base-url="'.$baseUrl.'"', false);
    }

    public function test_admin_tp_dependency_check_and_delete_routes_still_work(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->sessionForActiveYear('admin'))
            ->getJson(route('tujuan_pembelajaran.check_dependencies', $this->tujuanPembelajaranId))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'hasDependents' => false,
            ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->sessionForActiveYear('admin'))
            ->deleteJson(route('tujuan_pembelajaran.destroy', $this->tujuanPembelajaranId))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Tujuan pembelajaran berhasil dihapus!',
            ]);

        $this->assertTrue(TujuanPembelajaran::onlyTrashed()->whereKey($this->tujuanPembelajaranId)->exists());
    }

    public function test_pengajar_and_wali_tp_pages_render_without_missing_route_parameters(): void
    {
        $destroyBaseUrl = url('/pengajar/tujuan-pembelajaran');

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.tujuan_pembelajaran.create', $this->subjectId))
            ->assertOk()
            ->assertSee('data-destroy-base-url="'.$destroyBaseUrl.'"', false);

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->sessionForActiveYear('wali_kelas'))
            ->get(route('wali_kelas.tujuan_pembelajaran.view', $this->subjectId))
            ->assertOk()
            ->assertSee('data-destroy-base-url="'.$destroyBaseUrl.'"', false);
    }

    public function test_tp_route_authorization_remains_unchanged(): void
    {
        $this->get(route('tujuan_pembelajaran.create', $this->subjectId))
            ->assertRedirect(route('login'));

        $this->actingAsPengajar($this->ani)
            ->deleteJson(route('pengajar.tujuan_pembelajaran.destroy', $this->tujuanPembelajaranId))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_input_score_uses_default_bobot_without_creating_row(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->activeYearId)->delete();

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.input_score', $this->subjectId))
            ->assertOk()
            ->assertSee('Bobot Nilai', false);

        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->activeYearId)->count());
    }

    public function test_score_save_uses_default_bobot_without_creating_row(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->activeYearId)->delete();

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->activeYearId)->count());
        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_blank_nilai_akhir_semester_is_skipped_from_final_report_score(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(85, 90, '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertNotNull($aggregate);
        $this->assertEquals(85.0, (float) $aggregate->na_tp);
        $this->assertEquals(90.0, (float) $aggregate->na_lm);
        $this->assertNull($aggregate->nilai_akhir_semester);
        $this->assertEquals(88.0, (float) $aggregate->nilai_akhir_rapor);
    }

    public function test_zero_nilai_akhir_semester_is_included_as_real_value(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 100, 0, 0),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertNotNull($aggregate);
        $this->assertEquals(0.0, (float) $aggregate->nilai_akhir_semester);
        $this->assertEquals(45.0, (float) $aggregate->nilai_akhir_rapor);
    }

    public function test_mid_semester_score_uses_tp_and_lm_without_final_semester_components(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertNotNull($aggregate);
        $this->assertEquals(80.0, (float) $aggregate->na_tp);
        $this->assertEquals(90.0, (float) $aggregate->na_lm);
        $this->assertNull($aggregate->nilai_akhir_semester);
        $this->assertEquals(85.0, (float) $aggregate->nilai_akhir_rapor);
        $this->assertFalse((bool) $aggregate->is_submitted);
    }

    public function test_mid_semester_score_keeps_zero_lm_in_dynamic_weighting(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 0, '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertNotNull($aggregate);
        $this->assertEquals(0.0, (float) $aggregate->na_lm);
        $this->assertEquals(40.0, (float) $aggregate->nilai_akhir_rapor);
        $this->assertFalse((bool) $aggregate->is_submitted);
    }

    public function test_clearing_lm_recalculates_aggregate_and_invalidates_mid_semester_report(): void
    {
        Storage::fake('public');
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        $student = \App\Models\Siswa::findOrFail($this->studentId);
        Storage::disk('public')->put('pdf_reports/old-mid-semester.pdf', 'PDF');
        PdfCacheService::cachePdf(
            $student,
            'UTS',
            $this->activeYearId,
            'pdf_reports/old-mid-semester.pdf',
            'old-mid-semester.pdf',
            3
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, '', '', ''),
            ])
            ->assertOk();

        $aggregate = $this->aggregateNilaiModel();
        $this->assertNotNull($aggregate);
        $this->assertEquals(80.0, (float) $aggregate->na_tp);
        $this->assertNull($aggregate->na_lm);
        $this->assertEquals(80.0, (float) $aggregate->nilai_akhir_rapor);
        $this->assertFalse(app(ReportScoreEligibilityService::class)->isEligible($aggregate, 'UTS', $this->classId));
        $this->assertNull(PdfCacheService::getCachedPdf($student, 'UTS', $this->activeYearId));
        $this->assertFalse(Storage::disk('public')->exists('pdf_reports/old-mid-semester.pdf'));
    }

    public function test_soft_deleted_tp_clears_tp_aggregate_and_uts_eligibility(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        TujuanPembelajaran::findOrFail($this->tujuanPembelajaranId)->delete();

        $aggregate = $this->aggregateNilaiModel();
        $this->assertNotNull($aggregate);
        $this->assertNull($aggregate->na_tp);
        $this->assertEquals(90.0, (float) $aggregate->na_lm);
        $this->assertEquals(90.0, (float) $aggregate->nilai_akhir_rapor);
        $this->assertFalse(app(ReportScoreEligibilityService::class)->isEligible($aggregate, 'UTS', $this->classId));
    }

    public function test_soft_deleted_lm_clears_all_related_aggregates(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        LingkupMateri::findOrFail($this->lingkupMateriId)->delete();

        $aggregate = $this->aggregateNilaiModel();
        $this->assertNotNull($aggregate);
        $this->assertNull($aggregate->na_tp);
        $this->assertNull($aggregate->na_lm);
        $this->assertNull($aggregate->nilai_akhir_rapor);
        $this->assertFalse(app(ReportScoreEligibilityService::class)->isEligible($aggregate, 'UTS', $this->classId));
    }

    public function test_inactive_lm_is_removed_from_aggregate_and_eligibility(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        LingkupMateri::findOrFail($this->lingkupMateriId)->update(['is_active' => false]);

        $aggregate = $this->aggregateNilaiModel();
        $this->assertNotNull($aggregate);
        $this->assertNull($aggregate->na_tp);
        $this->assertNull($aggregate->na_lm);
        $this->assertNull($aggregate->nilai_akhir_rapor);
        $this->assertFalse(app(ReportScoreEligibilityService::class)->isEligible($aggregate, 'UTS', $this->classId));
    }

    public function test_restore_rolls_back_source_rows_when_aggregate_recalculation_fails(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        TujuanPembelajaran::findOrFail($this->tujuanPembelajaranId)->delete();
        $deletedScoreIds = Nilai::onlyTrashed()
            ->where('tujuan_pembelajaran_id', $this->tujuanPembelajaranId)
            ->pluck('id');
        $this->assertNotEmpty($deletedScoreIds);

        $this->mock(ScoreAggregateRecalculationService::class, function ($mock) {
            $mock->shouldReceive('recalculateMany')
                ->once()
                ->andThrow(new \Exception('Simulated aggregate recalculation failure.'));
        });

        $request = Request::create('/admin/recycle-bin/restore', 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = app(RecycleBinController::class)->restore(
            $request,
            'tujuan-pembelajaran',
            $this->tujuanPembelajaranId
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            'Terjadi kesalahan saat memulihkan data. Silakan coba lagi.',
            $response->getData(true)['message'] ?? null
        );
        $this->assertTrue(TujuanPembelajaran::onlyTrashed()->whereKey($this->tujuanPembelajaranId)->exists());
        $this->assertSame(
            $deletedScoreIds->count(),
            Nilai::onlyTrashed()->whereIn('id', $deletedScoreIds)->count()
        );
        $this->assertNull($this->aggregateNilaiModel()?->na_tp);
    }

    public function test_rebuilt_uts_uses_latest_final_score_after_as_changes(): void
    {
        Storage::fake('public');
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();
        $this->assertEquals(85.0, (float) $this->aggregateNilaiModel()->nilai_akhir_rapor);

        $student = \App\Models\Siswa::findOrFail($this->studentId);
        Storage::disk('public')->put('pdf_reports/uts-before-as.pdf', 'PDF');
        PdfCacheService::cachePdf(
            $student,
            'UTS',
            $this->activeYearId,
            'pdf_reports/uts-before-as.pdf',
            'uts-before-as.pdf',
            3
        );
        Storage::disk('public')->put('pdf_reports/uas-before-as.pdf', 'PDF');
        PdfCacheService::cachePdf(
            $student,
            'UAS',
            $this->activeYearId,
            'pdf_reports/uas-before-as.pdf',
            'uas-before-as.pdf',
            3
        );
        Storage::disk('public')->put('docx_reports/uts-before-as.docx', 'PK DOCX');
        Cache::put(PdfCacheService::getDocxCacheKey($student, 'UTS', $this->activeYearId), [
            'path' => 'docx_reports/uts-before-as.docx',
            'filename' => 'uts-before-as.docx',
            'file_size' => 7,
            'generated_at' => now()->toISOString(),
            'freshness_version' => PdfCacheService::currentFreshnessVersion(
                $student,
                'UTS',
                $this->activeYearId
            ),
            'semester' => 1,
        ], now()->addHour());

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, 70, 90),
            ])
            ->assertOk();

        $aggregate = $this->aggregateNilaiModel();
        $this->assertEquals(80.0, (float) $aggregate->nilai_akhir_semester);
        $this->assertEquals(83.0, (float) $aggregate->nilai_akhir_rapor);
        $this->assertTrue(app(ReportScoreEligibilityService::class)->isEligible($aggregate, 'UTS', $this->classId));
        $this->assertNull(PdfCacheService::getCachedPdf($student, 'UTS', $this->activeYearId));
        $this->assertNull(PdfCacheService::getCachedPdf($student, 'UAS', $this->activeYearId));
        $this->assertNull(PdfCacheService::getCachedDocx($student, 'UTS', $this->activeYearId));
        $this->assertFalse(Storage::disk('public')->exists('pdf_reports/uts-before-as.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('pdf_reports/uas-before-as.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('docx_reports/uts-before-as.docx'));
    }

    public function test_score_save_profiling_uses_normal_freshness_invalidation_for_both_report_types(): void
    {
        config()->set('report.score_save_profiling.enabled', true);
        Storage::fake('public');

        $student = \App\Models\Siswa::findOrFail($this->studentId);
        $utsVersion = PdfCacheService::currentFreshnessVersion($student, 'UTS', $this->activeYearId, 1);
        $uasVersion = PdfCacheService::currentFreshnessVersion($student, 'UAS', $this->activeYearId, 1);

        Storage::disk('public')->put('pdf_reports/profiled-uts.pdf', 'PDF');
        PdfCacheService::cachePdf(
            $student,
            'UTS',
            $this->activeYearId,
            'pdf_reports/profiled-uts.pdf',
            'profiled-uts.pdf',
            3,
            $utsVersion,
            1
        );
        Storage::disk('public')->put('pdf_reports/profiled-uas.pdf', 'PDF');
        PdfCacheService::cachePdf(
            $student,
            'UAS',
            $this->activeYearId,
            'pdf_reports/profiled-uas.pdf',
            'profiled-uas.pdf',
            3,
            $uasVersion,
            1
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, '', ''),
            ])
            ->assertOk();

        $this->assertGreaterThan($utsVersion, PdfCacheService::currentFreshnessVersion($student, 'UTS', $this->activeYearId, 1));
        $this->assertGreaterThan($uasVersion, PdfCacheService::currentFreshnessVersion($student, 'UAS', $this->activeYearId, 1));
        $this->assertNull(PdfCacheService::getCachedPdf($student, 'UTS', $this->activeYearId, 1));
        $this->assertNull(PdfCacheService::getCachedPdf($student, 'UAS', $this->activeYearId, 1));
    }

    public function test_all_blank_final_score_components_remain_blank_not_zero(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents('', '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertTrue($aggregate === null || $aggregate->nilai_akhir_rapor === null);
        $this->assertDatabaseMissing('nilais', [
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'nilai_akhir_rapor' => 0,
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_complete_score_calculation_still_uses_all_components(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, 100, 80),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $aggregate = $this->aggregateNilai();

        $this->assertNotNull($aggregate);
        $this->assertEquals(90.0, (float) $aggregate->nilai_akhir_semester);
        $this->assertEquals(88.0, (float) $aggregate->nilai_akhir_rapor);
    }

    public function test_score_save_schedules_pdf_auto_prepare_when_enabled(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.delay_seconds', 60);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        Queue::fake();

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertPushedOn('pdf-warm', AutoPreparePdfReportJob::class);
        Queue::assertPushed(AutoPreparePdfReportJob::class, 1);
        Queue::assertPushed(AutoPreparePdfReportJob::class, function (AutoPreparePdfReportJob $job) {
            return $job->siswaId === $this->studentId
                && $job->tahunAjaranId === $this->activeYearId
                && $job->type === 'UTS'
                && $job->delay
                && abs($job->delay->getTimestamp() - now()->addSeconds(60)->getTimestamp()) <= 2;
        });
        Queue::assertNotPushed(AutoPreparePdfReportJob::class, fn (AutoPreparePdfReportJob $job) => $job->type === 'UAS');
    }

    public function test_score_save_does_not_schedule_pdf_auto_prepare_when_disabled(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', false);
        Queue::fake();

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_score_save_cleans_deferred_pdf_cache_observer_flag_after_success(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse(app()->bound('score_save.defer_nilai_pdf_cache_invalidation'));
    }

    public function test_score_save_profiling_allows_production_like_staging_when_test_tools_are_enabled(): void
    {
        $originalEnvironment = app()->environment();
        $method = new \ReflectionMethod(ScoreController::class, 'scoreSaveProfilingEnabled');
        $method->setAccessible(true);

        app()->detectEnvironment(fn () => 'production');

        try {
            config([
                'report.score_save_profiling.enabled' => true,
                'staging_test_tools.enabled' => true,
            ]);

            $this->assertTrue($method->invoke(new ScoreController()));

            config(['staging_test_tools.enabled' => false]);

            $this->assertFalse($method->invoke(new ScoreController()));
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_another_pengajar_receives_forbidden_and_existing_grades_are_not_modified(): void
    {
        $existingNilaiId = $this->insertAggregateNilai(70);

        $this->actingAsPengajar($this->ani)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(99),
            ])
            ->assertForbidden();

        $this->assertSame(1, DB::table('nilais')->count());
        $this->assertDatabaseHas('nilais', [
            'id' => $existingNilaiId,
            'nilai_akhir_rapor' => 70,
        ]);
    }

    public function test_wali_selected_role_receives_forbidden_on_pengajar_save(): void
    {
        $this->actingAs($this->budi, 'guru')
            ->withSession($this->sessionForActiveYear('wali_kelas'))
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_wrong_academic_year_receives_forbidden_and_does_not_create_grades(): void
    {
        $this->actingAs($this->budi, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->oldYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_wrong_semester_receives_forbidden_when_subject_is_not_for_active_semester(): void
    {
        DB::table('tahun_ajarans')
            ->where('id', $this->activeYearId)
            ->update(['semester' => 1]);

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->wrongSemesterSubjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_stale_score_form_after_active_semester_change_is_rejected_without_saving(): void
    {
        DB::table('tahun_ajarans')
            ->where('id', $this->activeYearId)
            ->update(['semester' => 2]);

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->sessionForActiveYear('pengajar'))
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(),
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_student_outside_subject_class_receives_forbidden_and_does_not_create_grades(): void
    {
        $otherClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherStudentId = DB::table('siswas')->insertGetId([
            'nis' => '2001',
            'nisn' => '2001000',
            'nama' => 'Siswa Kelas Lain',
            'kelas_id' => $otherClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayloadForStudent($otherStudentId),
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_authorized_pengajar_can_download_score_import_template(): void
    {
        $response = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_template', $this->subjectId));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'Template_Nilai_5_A_Matematika.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_authorized_pengajar_can_download_multi_sheet_score_import_templates(): void
    {
        $this->insertLearningSetup($this->wrongSemesterSubjectId, 'Materi Genap', '1');
        $secondClassId = $this->insertClass(6, 'B');
        $this->insertStudentForClass($secondClassId, '2001', '2001000', 'Siti Aminah');
        $secondSubjectId = $this->insertSubject('Bahasa Indonesia', $this->budi->id, 1, $secondClassId);
        $secondSetup = $this->insertLearningSetup($secondSubjectId, 'Teks Narasi', '1');

        $response = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'Template_Nilai_Guru_Budi_2026_2027_Ganjil.xlsx',
            (string) $response->headers->get('content-disposition')
        );

        $workbook = $this->workbookFromResponse($response);
        $scoreSheetNames = $this->scoreSheetNames($workbook);

        $this->assertCount(2, $scoreSheetNames);
        $this->assertContains('5 A - Matematika', $scoreSheetNames);
        $this->assertContains('6 B - Bahasa Indonesia', $scoreSheetNames);

        $firstSheet = $workbook->getSheetByName('5 A - Matematika');
        $secondSheet = $workbook->getSheetByName('6 B - Bahasa Indonesia');

        $this->assertContains('Ahmad Fauzan', collect($firstSheet->rangeToArray('E6:E20'))->flatten()->filter()->values()->all());
        $this->assertContains('Siti Aminah', collect($secondSheet->rangeToArray('E6:E20'))->flatten()->filter()->values()->all());
        $this->assertStringContainsString('Kelas: Kelas 6 B', (string) $secondSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Mata Pelajaran: Bahasa Indonesia', (string) $secondSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Tahun Ajaran: 2026/2027', (string) $secondSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Semester: 1', (string) $secondSheet->getCell('A1')->getValue());
        $this->assertTrue((bool) $secondSheet->getProtection()->getSheet());
        $this->assertFalse($secondSheet->getRowDimension(2)->getVisible());
        $this->assertFalse($secondSheet->getRowDimension(3)->getVisible());
        $this->assertFalse($secondSheet->getRowDimension(5)->getVisible());
        $this->assertFalse($secondSheet->getColumnDimension('A')->getVisible());
        $this->assertFalse($secondSheet->getColumnDimension('F')->getVisible());
        $this->assertFalse($secondSheet->getColumnDimension('G')->getVisible());
        $this->assertSame(['No', 'NIS', 'NISN', 'Nama Siswa'], $secondSheet->rangeToArray('B4:E4')[0]);
        $this->assertSame(Protection::PROTECTION_PROTECTED, $secondSheet->getStyle('E6')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $secondSheet->getStyle('H6')->getProtection()->getLocked());
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $secondSheet->getStyle('H6')->getAlignment()->getHorizontal());
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $secondSheet->getStyle($this->cellAddressByKey($secondSheet, "lm_{$secondSetup['lingkup_materi_id']}", 6))->getAlignment()->getHorizontal()
        );
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $secondSheet->getStyle($this->cellAddressByKey($secondSheet, 'nilai_tes', 6))->getAlignment()->getHorizontal()
        );
    }

    public function test_multi_sheet_template_order_matches_data_pembelajaran_order_and_preview_order(): void
    {
        DB::table('mata_pelajarans')->where('id', $this->wrongSemesterSubjectId)->delete();

        $earlyClassId = $this->insertClass(4, 'C');
        $this->insertStudentForClass($earlyClassId, '2001', '2001000', 'Siti Aminah');
        $earlySubjectId = $this->insertSubject('IPA', $this->budi->id, 1, $earlyClassId);
        $this->insertLearningSetup($earlySubjectId, 'Makhluk Hidup', '1');

        $laterClassId = $this->insertClass(6, 'B');
        $this->insertStudentForClass($laterClassId, '3001', '3001000', 'Rafi Maulana');
        $laterSubjectId = $this->insertSubject('Bahasa Indonesia', $this->budi->id, 1, $laterClassId);
        $this->insertLearningSetup($laterSubjectId, 'Teks Narasi', '1');

        $expectedOptionOrder = [
            'Kelas 4 C - IPA',
            'Kelas 5 A - Matematika',
            'Kelas 6 B - Bahasa Indonesia',
        ];
        $expectedSheetOrder = [
            '4 C - IPA',
            '5 A - Matematika',
            '6 B - Bahasa Indonesia',
        ];

        $page = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk()
            ->getContent();

        $this->assertTextAppearsInOrder($expectedOptionOrder, $page);

        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );

        $this->assertSame($expectedSheetOrder, $this->scoreSheetNames($workbook));

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);
        $state = $this->multiSheetImportState($token);

        $this->assertSame($expectedSheetOrder, collect($state['sheets'])->pluck('sheet_name')->all());
    }

    public function test_multi_sheet_score_import_template_rejects_partial_readiness(): void
    {
        $incompleteSubjectId = $this->insertSubject('IPA Belum Lengkap', $this->budi->id, 1);
        DB::table('lingkup_materis')->insert([
            'mata_pelajaran_id' => $incompleteSubjectId,
            'judul_lingkup_materi' => 'Makhluk Hidup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates'))
            ->assertStatus(422)
            ->assertSee('Download Semua Template Siap belum bisa digunakan karena masih ada pembelajaran yang belum lengkap.');

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_template', $this->subjectId))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_multi_sheet_score_import_template_returns_clear_error_when_no_subjects_are_ready(): void
    {
        DB::table('tujuan_pembelajarans')
            ->where('lingkup_materi_id', $this->lingkupMateriId)
            ->delete();

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates'))
            ->assertStatus(422)
            ->assertSee('Belum ada pembelajaran lengkap yang siap diunduh template nilainya.');
    }

    public function test_multi_sheet_score_import_template_is_scoped_to_current_pengajar(): void
    {
        $aniSubjectId = $this->insertSubject('Bahasa Arab', $this->ani->id, 1);
        $this->insertLearningSetup($aniSubjectId, 'Mufradat', '1');

        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->ani)
                ->get(route('pengajar.score.import_templates'))
        );

        $scoreSheetNames = $this->scoreSheetNames($workbook);

        $this->assertSame(['5 A - Bahasa Arab'], $scoreSheetNames);
        $this->assertNotContains('5 A - Matematika', $scoreSheetNames);
    }

    public function test_multi_sheet_score_import_template_sanitizes_and_uniquifies_sheet_names(): void
    {
        $this->insertLearningSetup($this->wrongSemesterSubjectId, 'Materi Genap', '1');
        $firstSubjectId = $this->insertSubject('Mapel Panjang / Sama: Dengan Nama Ekstra Pertama', $this->budi->id, 1);
        $secondSubjectId = $this->insertSubject('Mapel Panjang / Sama: Dengan Nama Ekstra Kedua', $this->budi->id, 1);
        $this->insertLearningSetup($firstSubjectId, 'Topik Pertama', '1');
        $this->insertLearningSetup($secondSubjectId, 'Topik Kedua', '1');

        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );

        $longSubjectSheets = collect($this->scoreSheetNames($workbook))
            ->filter(fn (string $sheetName) => str_contains($sheetName, 'Mapel Panjang'))
            ->values();

        $this->assertCount(2, $longSubjectSheets);
        $this->assertCount(2, $longSubjectSheets->unique());
        $this->assertTrue($longSubjectSheets->contains(fn (string $sheetName) => str_ends_with($sheetName, '(2)')));

        foreach ($longSubjectSheets as $sheetName) {
            $this->assertLessThanOrEqual(31, mb_strlen($sheetName, 'UTF-8'));
            $this->assertDoesNotMatchRegularExpression('/[\\\\\\/\\?\\*\\[\\]\\:]/', $sheetName);
        }
    }

    public function test_score_import_template_download_is_available_from_pengajar_subject_list(): void
    {
        $this->insertLearningSetup($this->wrongSemesterSubjectId, 'Materi Genap', '1');

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk()
            ->assertSee('Download Template Nilai')
            ->assertSee('Download Semua Template Siap')
            ->assertSee('Upload Semua Nilai Excel')
            ->assertSee('bg-gray-100 text-gray-700 hover:bg-gray-200', false)
            ->assertSee('Kelas 5 A - Matematika')
            ->assertSee(route('pengajar.score.import_template', $this->subjectId), false)
            ->assertSee(route('pengajar.score.import_templates'), false)
            ->assertSee(route('pengajar.score.import_templates.preview'), false)
            ->assertSee('data-row-action="input"', false)
            ->assertSee('data-row-action="delete"', false)
            ->assertSee('table-action-group', false)
            ->assertSee('aria-label="Masukkan nilai Matematika"', false);
    }

    public function test_score_import_template_action_is_not_rendered_in_each_table_row(): void
    {
        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk()
            ->assertDontSee('data-row-action="template"', false);
    }

    public function test_score_import_template_disabled_state_explains_incomplete_lm_tp_setup(): void
    {
        DB::table('tujuan_pembelajarans')
            ->where('lingkup_materi_id', $this->lingkupMateriId)
            ->delete();

        $response = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'));

        $response->assertOk()
            ->assertSeeText('Download Template Nilai')
            ->assertSeeText('Download Semua Template Siap')
            ->assertSeeText('Upload Semua Nilai Excel')
            ->assertSeeText('Template nilai belum bisa diunduh karena belum ada pembelajaran yang lengkap. Pastikan setiap Lingkup Materi memiliki Tujuan Pembelajaran.')
            ->assertDontSee(route('pengajar.score.import_template', $this->subjectId), false)
            ->assertDontSee(route('pengajar.score.import_templates'), false)
            ->assertDontSee(route('pengajar.score.import_templates.preview'), false);

        $html = $response->getContent();
        $buttonPosition = strpos($html, 'Download Template Nilai');
        $buttonEndPosition = strpos($html, '</button>', $buttonPosition);
        $messagePosition = strpos($html, 'Template nilai belum bisa diunduh karena belum ada pembelajaran yang lengkap.');

        $this->assertNotFalse($buttonPosition);
        $this->assertNotFalse($buttonEndPosition);
        $this->assertNotFalse($messagePosition);
        $this->assertGreaterThan($buttonEndPosition, $messagePosition);
    }

    public function test_score_import_template_single_stays_enabled_but_bulk_is_disabled_for_partial_readiness(): void
    {
        $response = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'));

        $response->assertOk()
            ->assertSeeText('Download Template Nilai')
            ->assertSeeText('Download Semua Template Siap')
            ->assertSeeText('Upload Semua Nilai Excel')
            ->assertSeeText('1 pembelajaran siap diunduh melalui Download Template Nilai. 1 pembelajaran belum lengkap, sehingga Download Semua Template Siap belum bisa digunakan. Lengkapi Tujuan Pembelajaran terlebih dahulu.')
            ->assertSee(route('pengajar.score.import_template', $this->subjectId), false)
            ->assertDontSee(route('pengajar.score.import_templates'), false)
            ->assertDontSee(route('pengajar.score.import_templates.preview'), false);

        $html = $response->getContent();
        $actionStart = strpos($html, '<!-- Action Buttons -->');
        $actionEnd = strpos($html, '<!-- Debug information -->');

        $this->assertNotFalse($actionStart);
        $this->assertNotFalse($actionEnd);
        $this->assertStringContainsString('cursor-not-allowed', substr($html, $actionStart, $actionEnd - $actionStart));
    }

    public function test_score_readiness_warning_names_missing_lingkup_materi_and_guides_teacher(): void
    {
        DB::table('lingkup_materis')->insert([
            'mata_pelajaran_id' => $this->subjectId,
            'judul_lingkup_materi' => 'Bilangan Cacah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk()
            ->assertSee('Belum lengkap: Lingkup Materi "Bilangan Cacah" belum memiliki Tujuan Pembelajaran.')
            ->assertSee('Buka menu Data Mata Pelajaran, lalu klik ikon TP pada mata pelajaran ini untuk menambahkan Tujuan Pembelajaran.')
            ->assertDontSee('Lengkapi TP')
            ->assertSee('data-readiness-warning="true"', false)
            ->assertSee('data-row-action="warning"', false)
            ->assertSee('table-action-control', false)
            ->assertSee('@click.prevent="showLmTpWarning(mapelName, readinessMessages)"', false)
            ->assertDontSee(route('pengajar.tujuan_pembelajaran.create', $this->subjectId), false)
            ->assertDontSee(route('pengajar.score.import_template', $this->subjectId), false);
    }

    public function test_backend_rejects_score_import_template_for_incomplete_subject(): void
    {
        DB::table('tujuan_pembelajarans')
            ->where('lingkup_materi_id', $this->lingkupMateriId)
            ->delete();

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_template', $this->subjectId))
            ->assertStatus(422)
            ->assertSee('Belum lengkap: Lingkup Materi "Bilangan" belum memiliki Tujuan Pembelajaran.');
    }

    public function test_score_import_upload_uses_modal_action_on_input_score_page(): void
    {
        $response = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.input_score', $this->subjectId));

        $response->assertOk()
            ->assertSeeText('Import Nilai Excel')
            ->assertSeeText('Kelas: 5 A')
            ->assertSeeText('Mata Pelajaran: Matematika')
            ->assertSee('x-show="openExcelImportModal"', false)
            ->assertSee('id="excelImportForm"', false)
            ->assertSee('id="excel_import_file"', false)
            ->assertSeeText('Unggah template nilai Excel untuk memuat nilai ke form. Nilai belum disimpan sampai tombol Simpan diklik.')
            ->assertSeeText('Batal')
            ->assertSeeText('Muat Excel')
            ->assertDontSeeText('Unggah template nilai untuk memuat nilai ke form. Nilai belum disimpan sampai tombol Simpan diklik.')
            ->assertDontSeeText('Preview Excel');

        $html = $response->getContent();
        $pageStart = strpos($html, 'data-page="pengajar-input-score"');
        $contextPosition = strpos($html, 'Kelas:', $pageStart);
        $importPosition = strpos($html, 'Import Nilai Excel', $contextPosition);
        $completionPosition = strpos($html, 'id="completion-counter"', $pageStart);
        $kembaliPosition = strpos($html, 'Kembali', $completionPosition);

        $this->assertNotFalse($pageStart);
        $this->assertNotFalse($contextPosition);
        $this->assertNotFalse($importPosition);
        $this->assertNotFalse($completionPosition);
        $this->assertNotFalse($kembaliPosition);
        $this->assertLessThan($completionPosition, $importPosition);
        $this->assertLessThan($kembaliPosition, $completionPosition);
    }

    public function test_score_import_template_contains_expected_students_for_class(): void
    {
        $otherClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswas')->insert([
            'nis' => '2001',
            'nisn' => '2001000',
            'nama' => 'Siswa Kelas Lain',
            'kelas_id' => $otherClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_template', $this->subjectId))
        );

        $sheet = $this->scoreSheet($workbook);
        $names = collect($sheet->rangeToArray('E6:E20'))->flatten()->filter()->values()->all();

        $this->assertContains('Ahmad Fauzan', $names);
        $this->assertNotContains('Siswa Kelas Lain', $names);
    }

    public function test_score_import_template_protects_identifier_columns_and_unlocks_score_cells(): void
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_template', $this->subjectId))
        );

        $sheet = $this->scoreSheet($workbook);
        $header = (string) $sheet->getCell('A1')->getValue();

        $this->assertSame('5 A - Matematika', $sheet->getTitle());
        $this->assertStringContainsString('Kelas: Kelas 5 A', $header);
        $this->assertStringContainsString('Mata Pelajaran: Matematika', $header);
        $this->assertStringContainsString('Tahun Ajaran: 2026/2027', $header);
        $this->assertStringContainsString('Semester: 1', $header);
        $this->assertTrue((bool) $sheet->getProtection()->getSheet());
        $this->assertFalse($sheet->getRowDimension(2)->getVisible());
        $this->assertFalse($sheet->getRowDimension(3)->getVisible());
        $this->assertFalse($sheet->getRowDimension(5)->getVisible());
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('B')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('C')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('D')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('E')->getVisible());
        $this->assertFalse($sheet->getColumnDimension('F')->getVisible());
        $this->assertFalse($sheet->getColumnDimension('G')->getVisible());
        $this->assertSame(['No', 'NIS', 'NISN', 'Nama Siswa'], $sheet->rangeToArray('B4:E4')[0]);
        $this->assertSame('H6', $sheet->getFreezePane());
        $this->assertSame(Protection::PROTECTION_PROTECTED, $sheet->getStyle('A6')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_PROTECTED, $sheet->getStyle('E6')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('H6')->getProtection()->getLocked());
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle('H6')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle($this->cellAddressByKey($sheet, "lm_{$this->lingkupMateriId}", 6))->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle($this->cellAddressByKey($sheet, 'nilai_non_tes', 6))->getAlignment()->getHorizontal());
        $this->assertSame('FFF3F4F6', $sheet->getStyle('A6')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFFF2CC', $sheet->getStyle('H6')->getFill()->getStartColor()->getARGB());
        $this->assertStringContainsString(
            'Isi hanya kolom nilai. Kolom identitas siswa, kelas, dan mata pelajaran tidak perlu diubah.',
            collect($workbook->getSheetByName('Petunjuk')->rangeToArray('A1:A20'))->flatten()->filter()->implode("\n")
        );
    }

    public function test_unauthorized_guru_cannot_download_score_import_template(): void
    {
        $this->actingAsPengajar($this->ani)
            ->get(route('pengajar.score.import_template', $this->subjectId))
            ->assertForbidden();
    }

    public function test_score_import_preview_accepts_valid_template_and_writes_no_scores(): void
    {
        $uploadedFile = $this->validScoreImportUpload([
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => 80,
            "lm_{$this->lingkupMateriId}" => 82,
            'nilai_tes' => 84,
            'nilai_non_tes' => 86,
        ]);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), ['file' => $uploadedFile])
            ->assertOk()
            ->assertSeeText('Data Excel berhasil dimuat. Nilai belum disimpan. Periksa kembali lalu klik Simpan.')
            ->assertSeeText('Ahmad Fauzan')
            ->assertSee('value="80"', false)
            ->assertSee('value="82"', false)
            ->assertSee('value="84"', false)
            ->assertSee('value="86"', false)
            ->assertSee('data-import-blocking-errors="false"', false)
            ->assertSee('data-excel-import-loaded="true"', false);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_single_score_import_blank_cell_clears_existing_score(): void
    {
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(80, 90, 70, 90),
            ])
            ->assertOk();
        $existingId = (int) DB::table('nilais')
            ->where('siswa_id', $this->studentId)
            ->where('mata_pelajaran_id', $this->subjectId)
            ->where('tujuan_pembelajaran_id', $this->tujuanPembelajaranId)
            ->whereNull('deleted_at')
            ->value('id');

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => null,
                    "lm_{$this->lingkupMateriId}" => null,
                    'nilai_tes' => null,
                    'nilai_non_tes' => null,
                ]),
            ])
            ->assertOk()
            ->assertSeeText('Data Excel berhasil dimuat. Nilai belum disimpan. Periksa kembali lalu klik Simpan.');

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            ''
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents('', '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertClearedScoreRow($existingId, 'nilai_tp');
        $this->assertNull($this->aggregateNilaiModel());
    }

    public function test_single_score_import_zero_cell_overwrites_existing_score(): void
    {
        $this->insertTpScore(80);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => 0,
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            '0'
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(0, '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertActiveTpScore(0);
    }

    public function test_single_score_import_zero_cell_creates_score_from_blank_database(): void
    {
        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => '0',
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            '0'
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents('0', '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertActiveTpScore(0);
    }

    public function test_single_score_import_normal_number_updates_existing_score(): void
    {
        $this->insertTpScore(80);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => 90,
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            '90'
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(90, '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertActiveTpScore(90);
    }

    public function test_single_score_import_formula_empty_string_clears_existing_score(): void
    {
        $existingId = $this->insertTpScore(80);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => '=""',
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            ''
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents('', '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertClearedScoreRow($existingId, 'nilai_tp');
    }

    public function test_single_score_import_formula_zero_saves_zero(): void
    {
        $this->insertTpScore(80);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => '=0',
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            '0'
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents(0, '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertActiveTpScore(0);
    }

    public function test_single_score_import_whitespace_clears_existing_score(): void
    {
        $existingId = $this->insertTpScore(80);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => '   ',
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            ''
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->scoresPayloadWithComponents('', '', '', ''),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertClearedScoreRow($existingId, 'nilai_tp');
    }

    public function test_single_score_import_can_clear_one_tp_and_update_another_tp(): void
    {
        $secondTpId = DB::table('tujuan_pembelajarans')->insertGetId([
            'lingkup_materi_id' => $this->lingkupMateriId,
            'kode_tp' => '2',
            'deskripsi_tp' => 'Menyelesaikan soal bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstScoreId = $this->insertTpScore(80);
        $this->insertTpScore(70, $this->subjectId, $this->studentId, $this->lingkupMateriId, $secondTpId);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->validScoreImportUpload([
                    "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => null,
                    "tp_{$this->lingkupMateriId}_{$secondTpId}" => 90,
                ]),
            ])
            ->assertOk();

        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$this->tujuanPembelajaranId}]",
            ''
        );
        $this->assertScoreInputValue(
            $response->getContent(),
            "scores[{$this->studentId}][tp][{$this->lingkupMateriId}][{$secondTpId}]",
            '90'
        );

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => [
                    $this->studentId => [
                        'tp' => [
                            $this->lingkupMateriId => [
                                $this->tujuanPembelajaranId => '',
                                $secondTpId => 90,
                            ],
                        ],
                        'lm' => [
                            $this->lingkupMateriId => '',
                        ],
                        'nilai_tes' => '',
                        'nilai_non_tes' => '',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertClearedScoreRow($firstScoreId, 'nilai_tp');
        $this->assertActiveTpScore(90, $this->subjectId, $this->studentId, $this->lingkupMateriId, $secondTpId);
    }

    public function test_score_import_missing_score_key_does_not_modify_existing_score(): void
    {
        $this->insertTpScore(80);
        $workbook = $this->templateWorkbook();
        $this->clearTemplateColumnKey(
            $this->scoreSheet($workbook),
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}"
        );

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertOk()
            ->assertSeeText('Format template Excel tidak sesuai atau sudah berubah.')
            ->assertSee('data-import-blocking-errors="true"', false);

        $this->assertActiveTpScore(80);
    }

    public function test_score_import_preview_rejects_student_from_another_class(): void
    {
        $otherClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherStudentId = DB::table('siswas')->insertGetId([
            'nis' => '2001',
            'nisn' => '2001000',
            'nama' => 'Siswa Kelas Lain',
            'kelas_id' => $otherClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uploadedFile = $this->validScoreImportUpload([
            'siswa_id' => $otherStudentId,
            'nis' => '2001',
            'nisn' => '2001000',
            'nama_siswa' => 'Siswa Kelas Lain',
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => 80,
        ]);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), ['file' => $uploadedFile])
            ->assertOk()
            ->assertSeeText('Baris 6, siswa Siswa Kelas Lain: Siswa tidak ditemukan pada kelas ini.')
            ->assertSee('data-import-blocking-errors="true"', false);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_wrong_subject_template_shows_friendly_context_message(): void
    {
        $otherSubjectId = $this->insertSubject('IPA', $this->budi->id, 1);
        $this->insertLearningSetup($otherSubjectId, 'Makhluk Hidup', '1');

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->downloadedTemplateUpload($otherSubjectId),
            ]);

        $response->assertOk()
            ->assertSeeText('Template Excel tidak sesuai dengan kelas atau mata pelajaran yang sedang dibuka. Silakan download ulang template dari Data Pembelajaran untuk kelas dan mata pelajaran ini.')
            ->assertSeeText('Anda sedang membuka Kelas: Kelas 5A, Mata Pelajaran: Matematika. Silakan upload template yang sesuai dengan halaman ini.')
            ->assertSeeText('File yang diupload tampaknya berasal dari Kelas: Kelas 5A, Mata Pelajaran: IPA. Silakan gunakan template yang sesuai.')
            ->assertSee('data-import-blocking-errors="true"', false);
        $this->assertStringNotContainsString('mata_pelajaran_id', $this->excelImportFeedbackHtml($response));

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_wrong_class_template_shows_friendly_context_message(): void
    {
        $otherClassId = $this->insertClass(6, 'B');
        $this->insertStudentForClass($otherClassId, '2001', '2001000', 'Siti Aminah');
        $otherSubjectId = $this->insertSubject('Matematika', $this->budi->id, 1, $otherClassId);
        $this->insertLearningSetup($otherSubjectId, 'Bilangan Kelas 6', '1');

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->downloadedTemplateUpload($otherSubjectId),
            ]);

        $response->assertOk()
            ->assertSeeText('Template Excel tidak sesuai dengan kelas atau mata pelajaran yang sedang dibuka.')
            ->assertSeeText('File yang diupload tampaknya berasal dari Kelas: Kelas 6B, Mata Pelajaran: Matematika. Silakan gunakan template yang sesuai.')
            ->assertSee('data-import-blocking-errors="true"', false);
        $this->assertStringNotContainsString('kelas_id', $this->excelImportFeedbackHtml($response));

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_missing_tp_column_shows_friendly_template_message(): void
    {
        $workbook = $this->templateWorkbook();
        $sheet = $this->scoreSheet($workbook);
        $technicalKey = "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}";
        $this->clearTemplateColumnKey($sheet, $technicalKey);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);

        $response->assertOk()
            ->assertSeeText('Format template Excel tidak sesuai atau sudah berubah. Silakan download ulang template terbaru dari Data Pembelajaran, isi nilainya kembali, lalu upload ulang.')
            ->assertSeeText('Jangan mengubah judul kolom, sheet, atau bagian yang dikunci pada template.')
            ->assertSee('data-import-blocking-errors="true"', false);
        $this->assertStringNotContainsString($technicalKey, $this->excelImportFeedbackHtml($response));

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_missing_lm_column_shows_friendly_template_message(): void
    {
        $workbook = $this->templateWorkbook();
        $sheet = $this->scoreSheet($workbook);
        $technicalKey = "lm_{$this->lingkupMateriId}";
        $this->clearTemplateColumnKey($sheet, $technicalKey);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);

        $response->assertOk()
            ->assertSeeText('Format template Excel tidak sesuai atau sudah berubah.')
            ->assertSee('data-import-blocking-errors="true"', false);
        $this->assertStringNotContainsString($technicalKey, $this->excelImportFeedbackHtml($response));

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_tampered_metadata_shows_friendly_message(): void
    {
        $workbook = $this->templateWorkbook();
        $this->scoreSheet($workbook)->setCellValue('D3', null);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);

        $response->assertOk()
            ->assertSeeText('Template Excel tidak dapat dibaca dengan benar. Pastikan file berasal dari tombol Download Template Nilai pada aplikasi ini dan belum diubah strukturnya.')
            ->assertSee('data-import-blocking-errors="true"', false);
        $this->assertStringNotContainsString('mata_pelajaran_id', $this->excelImportFeedbackHtml($response));

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_multi_sheet_workbook_with_friendly_message(): void
    {
        $this->insertLearningSetup($this->wrongSemesterSubjectId, 'Materi Genap', '1');
        $secondClassId = $this->insertClass(6, 'B');
        $this->insertStudentForClass($secondClassId, '2001', '2001000', 'Siti Aminah');
        $secondSubjectId = $this->insertSubject('Bahasa Indonesia', $this->budi->id, 1, $secondClassId);
        $this->insertLearningSetup($secondSubjectId, 'Teks Narasi', '1');
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertOk()
            ->assertSeeText('Untuk saat ini, import nilai hanya menerima template satu kelas dan satu mata pelajaran. Silakan gunakan file dari tombol Download Template Nilai, bukan Download Semua Template Siap.')
            ->assertSee('data-import-blocking-errors="true"', false);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_duplicated_siswa_id(): void
    {
        $workbook = $this->templateWorkbook();
        $sheet = $this->scoreSheet($workbook);
        $highestColumn = $sheet->getHighestDataColumn();
        $sheet->fromArray($sheet->rangeToArray("A6:{$highestColumn}6")[0], null, 'A7');
        $this->setValueByKey($sheet, "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 6, 80);
        $this->setValueByKey($sheet, "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 7, 81);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertOk()
            ->assertSeeText('Siswa ini muncul lebih dari satu kali di file Excel.')
            ->assertSee('data-import-blocking-errors="true"', false);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_invalid_score_values(): void
    {
        $uploadedFile = $this->validScoreImportUpload([
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => -1,
            "lm_{$this->lingkupMateriId}" => 101,
            'nilai_tes' => 'abc',
        ]);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), ['file' => $uploadedFile])
            ->assertOk()
            ->assertSeeText('Baris 6, siswa Ahmad Fauzan: Nilai TP 1 tidak boleh kurang dari 0 atau lebih dari 100.')
            ->assertSeeText('Nilai LM Bilangan tidak boleh kurang dari 0 atau lebih dari 100.')
            ->assertSeeText('Nilai Tes harus berupa angka 0 sampai 100.')
            ->assertSee('data-import-invalid="true"', false)
            ->assertSee('data-import-blocking-errors="true"', false);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_sparse_workbook_with_row_index_above_limit(): void
    {
        $workbook = $this->templateWorkbook();
        $row = PengajarScoreExcelTemplateService::DATA_START_ROW + SpreadsheetImportGuard::MAX_SCORE_IMPORT_ROWS;
        $this->setValueByKey($this->scoreSheet($workbook), 'siswa_id', $row, $this->studentId);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertRedirect(route('pengajar.score.input_score', $this->subjectId))
            ->assertSessionHas('error', 'File memiliki terlalu banyak baris untuk diproses.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_oversized_upload_before_parsing(): void
    {
        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => UploadedFile::fake()->create(
                    'score-import.xlsx',
                    2049,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('file');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_malformed_workbook_without_server_error(): void
    {
        $path = $this->rawUploadPath('malformed-score-import.xlsx', 'not an xlsx workbook');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => new UploadedFile(
                    $path,
                    'score-import.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
            ])
            ->assertRedirect();

        $this->assertTrue(
            session()->has('errors') || session()->has('error'),
            'Malformed score workbook should be rejected through validation or safe import error.'
        );
        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_unauthorized_guru_cannot_upload_score_import_template(): void
    {
        $uploadedFile = $this->validScoreImportUpload([
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}" => 80,
        ]);

        $this->actingAsPengajar($this->ani)
            ->post(route('pengajar.score.import_preview', $this->subjectId), ['file' => $uploadedFile])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_authorized_pengajar_can_upload_multi_sheet_workbook_and_preview_without_saving(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);

        $response->assertRedirect();
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Preview Upload Semua Nilai Excel')
            ->assertSeeText('Nilai disimpan per sheet. Setelah klik Simpan & Lanjut, sheet ini langsung tersimpan, lalu Anda berpindah ke sheet berikutnya.')
            ->assertSeeText('Sheet lain belum tersimpan sampai Anda membuka preview sheet tersebut dan klik Simpan & Lanjut.')
            ->assertSeeText('Matematika')
            ->assertSeeText('Bahasa Indonesia')
            ->assertSeeText('Ahmad Fauzan')
            ->assertSeeText('80')
            ->assertSeeText('83')
            ->assertSeeText('Simpan & Lanjut')
            ->assertSee('name="turbo-cache-control"', false)
            ->assertSee('data-turbo="false"', false);

        $html = $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->getContent();
        $sheetHeaderPosition = strpos($html, 'Preview Sheet Saat Ini');
        $savePosition = strpos($html, 'Simpan &amp; Lanjut', $sheetHeaderPosition);
        $studentTablePosition = strpos($html, 'Siswa', $savePosition);

        $this->assertNotFalse($sheetHeaderPosition);
        $this->assertNotFalse($savePosition);
        $this->assertNotFalse($studentTablePosition);
        $this->assertLessThan($studentTablePosition, $savePosition);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_upload_rejects_single_template_file_with_friendly_message(): void
    {
        $this->insertReadySecondSubjectForBudi();

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->downloadedTemplateUpload($this->subjectId),
            ])
            ->assertRedirect(route('pengajar.score.index'))
            ->assertSessionHas('error', 'File Excel ini bukan template Upload Semua Nilai dari aplikasi. Silakan gunakan file dari tombol Download Semua Template Siap.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_upload_tampered_metadata_shows_friendly_sheet_error(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);
        $workbook->getSheetByName('5 A - Matematika')->setCellValue('D3', null);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);

        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Template Excel tidak dapat dibaca dengan benar. Pastikan file berasal dari tombol Download Semua Template Siap dan belum diubah strukturnya.')
            ->assertSeeText('Template Upload Semua Nilai tidak lengkap. Silakan download ulang template terbaru dari Download Semua Template Siap.')
            ->assertSeeText('Perlu diperbaiki');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_upload_rejects_workbook_containing_another_gurus_sheet(): void
    {
        $this->insertReadySecondSubjectForBudi();
        $aniSubjectId = $this->insertSubject('Bahasa Arab', $this->ani->id, 1);
        $this->insertLearningSetup($aniSubjectId, 'Mufradat', '1');
        $aniWorkbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->ani)
                ->get(route('pengajar.score.import_templates'))
        );

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($aniWorkbook),
            ]);

        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('tidak sesuai dengan pembelajaran yang tersedia')
            ->assertSeeText('Template Upload Semua Nilai tidak lengkap. Silakan download ulang template terbaru dari Download Semua Template Siap.')
            ->assertSeeText('Perlu diperbaiki');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_upload_invalid_sheet_cannot_be_saved(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);
        $this->setValueByKey($workbook->getSheetByName('5 A - Matematika'), "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 6, -1);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Baris 6, siswa Ahmad Fauzan: Nilai TP 1 tidak boleh kurang dari 0 atau lebih dari 100.');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertSessionHas('error', 'Sheet ini belum bisa disimpan karena masih ada nilai yang perlu diperbaiki.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_import_rejects_workbook_with_too_many_worksheets(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);
        $workbook->createSheet()->setTitle('Extra Sheet');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertRedirect(route('pengajar.score.index'))
            ->assertSessionHas('error', 'Workbook memiliki terlalu banyak worksheet untuk diproses.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_import_rejects_sparse_row_above_limit(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);
        $row = PengajarScoreExcelTemplateService::DATA_START_ROW + SpreadsheetImportGuard::MAX_SCORE_IMPORT_ROWS;
        $this->setValueByKey($workbook->getSheetByName('5 A - Matematika'), 'siswa_id', $row, $this->studentId);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertRedirect(route('pengajar.score.index'))
            ->assertSessionHas('error', 'File memiliki terlalu banyak baris untuk diproses.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_multi_sheet_import_blank_cell_clears_existing_score_and_keeps_null_payload(): void
    {
        $existingId = $this->insertTpScore(80);
        $this->insertReadySecondSubjectForBudi();
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);
        $state = $this->multiSheetImportState($token);

        $payload = $state['sheets'][0]['scores_payload'][$this->studentId]['tp'][$this->lingkupMateriId] ?? [];
        $this->assertArrayHasKey($this->tujuanPembelajaranId, $payload);
        $this->assertNull($payload[$this->tujuanPembelajaranId]);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Akan dikosongkan');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertSessionHas('success');

        $this->assertClearedScoreRow($existingId, 'nilai_tp');
    }

    public function test_multi_sheet_import_zero_cell_persists_zero(): void
    {
        $this->insertTpScore(80);
        $this->insertReadySecondSubjectForBudi();
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );
        $this->setValueByKey(
            $workbook->getSheetByName('5 A - Matematika'),
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}",
            6,
            0
        );

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);
        $state = $this->multiSheetImportState($token);

        $payload = $state['sheets'][0]['scores_payload'][$this->studentId]['tp'][$this->lingkupMateriId] ?? [];
        $this->assertArrayHasKey($this->tujuanPembelajaranId, $payload);
        $this->assertSame(0.0, $payload[$this->tujuanPembelajaranId]);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('0');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertSessionHas('success');

        $this->assertActiveTpScore(0);
    }

    public function test_multi_sheet_import_processes_clear_and_numeric_update_on_separate_sheets(): void
    {
        $existingId = $this->insertTpScore(80);
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );
        $this->setValueByKey(
            $workbook->getSheetByName('6 B - Bahasa Indonesia'),
            "tp_{$second['lingkup_materi_id']}_{$second['tujuan_pembelajaran_id']}",
            6,
            92
        );

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertSessionHas('success');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertSessionHas('success');

        $this->assertClearedScoreRow($existingId, 'nilai_tp');
        $this->assertActiveTpScore(
            92,
            $second['subject_id'],
            $second['student_id'],
            $second['lingkup_materi_id'],
            $second['tujuan_pembelajaran_id']
        );
    }

    public function test_multi_sheet_import_invalid_row_keeps_existing_score_unchanged(): void
    {
        $this->insertTpScore(80);
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);
        $this->setValueByKey($workbook->getSheetByName('5 A - Matematika'), "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 6, -1);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertSessionHas('error', 'Sheet ini belum bisa disimpan karena masih ada nilai yang perlu diperbaiki.');

        $this->assertActiveTpScore(80);
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $second['subject_id'])->count());
    }

    public function test_multi_sheet_import_missing_score_key_keeps_existing_score_unchanged(): void
    {
        $this->insertTpScore(80);
        $this->insertReadySecondSubjectForBudi();
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );
        $this->clearTemplateColumnKey(
            $workbook->getSheetByName('5 A - Matematika'),
            "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}"
        );

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Format template Excel tidak sesuai atau sudah berubah. Silakan download ulang template terbaru dari Download Semua Template Siap');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertSessionHas('error', 'Sheet ini belum bisa disimpan karena masih ada nilai yang perlu diperbaiki.');

        $this->assertActiveTpScore(80);
    }

    public function test_multi_sheet_save_does_not_advance_or_mark_saved_when_database_persistence_fails(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);
        $state = $this->multiSheetImportState($token);
        $state['sheets'][0]['scores_payload'] = [];
        Cache::put($this->multiSheetImportCacheKey($token), $state, now()->addMinutes(60));

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertSessionHas('error', 'Nilai pada sheet ini belum berhasil tersimpan dengan benar. Silakan coba klik Simpan & Lanjut lagi.');

        $stateAfterSave = $this->multiSheetImportState($token);

        $this->assertEmpty($stateAfterSave['sheets'][0]['saved']);
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $this->subjectId)->count());
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $second['subject_id'])->count());
    }

    public function test_valid_multi_sheet_upload_saves_current_sheet_then_shows_next_and_final_summary(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();
        $workbook = $this->validMultiSheetWorkbook($second);

        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertSessionHas('success');

        $this->assertSame(3, DB::table('nilais')->where('mata_pelajaran_id', $this->subjectId)->count());
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $second['subject_id'])->count());
        $this->assertPersistedImportedValues($this->subjectId, $this->studentId, $this->lingkupMateriId, $this->tujuanPembelajaranId, 80, 81, 82, 83);

        $stateAfterFirstSave = $this->multiSheetImportState($token);
        $this->assertTrue((bool) $stateAfterFirstSave['sheets'][0]['saved']);
        $this->assertEmpty($stateAfterFirstSave['sheets'][1]['saved']);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.input_score', $this->subjectId))
            ->assertOk()
            ->assertSee('value="80', false)
            ->assertSee('value="81', false)
            ->assertSee('value="82', false)
            ->assertSee('value="83', false);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertOk()
            ->assertSeeText('Bahasa Indonesia')
            ->assertSeeText('Siti Aminah');

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertSessionHas('success');

        $this->assertSame(3, DB::table('nilais')->where('mata_pelajaran_id', $second['subject_id'])->count());
        $this->assertPersistedImportedValues($second['subject_id'], $second['student_id'], $second['lingkup_materi_id'], $second['tujuan_pembelajaran_id'], 84, 85, 86, 87);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Semua sheet berhasil disimpan')
            ->assertSeeText('2 sheet nilai sudah diproses.');
    }

    public function test_multi_sheet_save_persists_only_current_sheet_when_exiting_before_next_sheet(): void
    {
        $second = $this->insertReadySecondSubjectForBudi();

        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $this->subjectId), [
                'scores' => $this->validScoresPayload(70),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->actingAsPengajar($this->budi)
            ->postJson(route('pengajar.score.save_scores', $second['subject_id']), [
                'scores' => $this->validScoresPayloadForSpecificSetup(
                    $second['student_id'],
                    $second['lingkup_materi_id'],
                    $second['tujuan_pembelajaran_id'],
                    71
                ),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $workbook = $this->validMultiSheetWorkbook($second);
        $response = $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.preview'), [
                'file' => $this->uploadedWorkbook($workbook),
            ]);
        $token = $this->tokenFromPreviewRedirect($response);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => 1]))
            ->assertRedirect(route('pengajar.score.import_templates.preview_sheet', ['token' => $token, 'sheet' => 2]))
            ->assertSessionHas('success');

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk();

        $this->assertPersistedImportedValues($this->subjectId, $this->studentId, $this->lingkupMateriId, $this->tujuanPembelajaranId, 80, 81, 82, 83);
        $this->assertPersistedImportedValues($second['subject_id'], $second['student_id'], $second['lingkup_materi_id'], $second['tujuan_pembelajaran_id'], 71, 71, 71, 71);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.input_score', $this->subjectId))
            ->assertOk()
            ->assertSee('value="80', false)
            ->assertSee('value="81', false)
            ->assertSee('value="82', false)
            ->assertSee('value="83', false);

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.input_score', $second['subject_id']))
            ->assertOk()
            ->assertSee('value="71', false)
            ->assertDontSee('value="84', false)
            ->assertDontSee('value="85', false)
            ->assertDontSee('value="86', false)
            ->assertDontSee('value="87', false);

        $stateAfterExit = $this->multiSheetImportState($token);
        $this->assertTrue((bool) $stateAfterExit['sheets'][0]['saved']);
        $this->assertEmpty($stateAfterExit['sheets'][1]['saved']);
    }

    private function validScoreImportUpload(array $values): UploadedFile
    {
        $workbook = $this->templateWorkbook();
        $sheet = $this->scoreSheet($workbook);

        foreach ($values as $key => $value) {
            $this->setValueByKey($sheet, $key, 6, $value);
        }

        return $this->uploadedWorkbook($workbook);
    }

    private function templateWorkbook()
    {
        return $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_template', $this->subjectId))
        );
    }

    private function downloadedTemplateUpload(int $subjectId): UploadedFile
    {
        return $this->uploadedWorkbook(
            $this->workbookFromResponse(
                $this->actingAsPengajar($this->budi)
                    ->get(route('pengajar.score.import_template', $subjectId))
            )
        );
    }

    private function validMultiSheetWorkbook(array $second)
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAsPengajar($this->budi)
                ->get(route('pengajar.score.import_templates'))
        );

        $firstSheet = $workbook->getSheetByName('5 A - Matematika');
        $secondSheet = $workbook->getSheetByName('6 B - Bahasa Indonesia');

        $this->setValueByKey($firstSheet, "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 6, 80);
        $this->setValueByKey($firstSheet, "lm_{$this->lingkupMateriId}", 6, 81);
        $this->setValueByKey($firstSheet, 'nilai_tes', 6, 82);
        $this->setValueByKey($firstSheet, 'nilai_non_tes', 6, 83);

        $this->setValueByKey($secondSheet, "tp_{$second['lingkup_materi_id']}_{$second['tujuan_pembelajaran_id']}", 6, 84);
        $this->setValueByKey($secondSheet, "lm_{$second['lingkup_materi_id']}", 6, 85);
        $this->setValueByKey($secondSheet, 'nilai_tes', 6, 86);
        $this->setValueByKey($secondSheet, 'nilai_non_tes', 6, 87);

        return $workbook;
    }

    private function tokenFromPreviewRedirect($response): string
    {
        $location = (string) $response->headers->get('Location');

        if (! preg_match('#/score/import/templates/preview/([^/?]+)#', $location, $matches)) {
            $this->fail("Redirect tidak mengarah ke preview Upload Semua Nilai: {$location}");
        }

        return $matches[1];
    }

    private function multiSheetImportCacheKey(string $token): string
    {
        return sprintf('pengajar_score_multi_import:%s:%s', $this->budi->id, $token);
    }

    private function multiSheetImportState(string $token): array
    {
        $state = Cache::get($this->multiSheetImportCacheKey($token));

        if (! is_array($state)) {
            $this->fail("State Upload Semua Nilai tidak ditemukan untuk token {$token}.");
        }

        return $state;
    }

    private function assertPersistedImportedValues(
        int $subjectId,
        int $studentId,
        int $lingkupMateriId,
        int $tujuanPembelajaranId,
        int $tp,
        int $lm,
        int $nilaiTes,
        int $nilaiNonTes
    ): void {
        $tpRow = DB::table('nilais')
            ->where('mata_pelajaran_id', $subjectId)
            ->where('siswa_id', $studentId)
            ->where('lingkup_materi_id', $lingkupMateriId)
            ->where('tujuan_pembelajaran_id', $tujuanPembelajaranId)
            ->first();
        $lmRow = DB::table('nilais')
            ->where('mata_pelajaran_id', $subjectId)
            ->where('siswa_id', $studentId)
            ->where('lingkup_materi_id', $lingkupMateriId)
            ->whereNull('tujuan_pembelajaran_id')
            ->first();
        $semesterRow = DB::table('nilais')
            ->where('mata_pelajaran_id', $subjectId)
            ->where('siswa_id', $studentId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->first();

        $this->assertNotNull($tpRow);
        $this->assertNotNull($lmRow);
        $this->assertNotNull($semesterRow);
        $this->assertSame((float) $tp, (float) $tpRow->nilai_tp);
        $this->assertSame((float) $lm, (float) $lmRow->nilai_lm);
        $this->assertSame((float) $nilaiTes, (float) $semesterRow->nilai_tes);
        $this->assertSame((float) $nilaiNonTes, (float) $semesterRow->nilai_non_tes);
    }

    private function insertTpScore(
        mixed $score,
        ?int $subjectId = null,
        ?int $studentId = null,
        ?int $lingkupMateriId = null,
        ?int $tujuanPembelajaranId = null
    ): int {
        return DB::table('nilais')->insertGetId([
            'siswa_id' => $studentId ?? $this->studentId,
            'mata_pelajaran_id' => $subjectId ?? $this->subjectId,
            'lingkup_materi_id' => $lingkupMateriId ?? $this->lingkupMateriId,
            'tujuan_pembelajaran_id' => $tujuanPembelajaranId ?? $this->tujuanPembelajaranId,
            'nilai_tp' => $score,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertActiveTpScore(
        mixed $expected,
        ?int $subjectId = null,
        ?int $studentId = null,
        ?int $lingkupMateriId = null,
        ?int $tujuanPembelajaranId = null
    ): void {
        $row = DB::table('nilais')
            ->where('siswa_id', $studentId ?? $this->studentId)
            ->where('mata_pelajaran_id', $subjectId ?? $this->subjectId)
            ->where('lingkup_materi_id', $lingkupMateriId ?? $this->lingkupMateriId)
            ->where('tujuan_pembelajaran_id', $tujuanPembelajaranId ?? $this->tujuanPembelajaranId)
            ->where('tahun_ajaran_id', $this->activeYearId)
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame((float) $expected, (float) $row->nilai_tp);
    }

    private function assertClearedScoreRow(int $rowId, string $column): void
    {
        $row = DB::table('nilais')->where('id', $rowId)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->{$column});
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(0, DB::table('nilais')->where('id', $rowId)->whereNull('deleted_at')->count());
    }

    private function assertScoreInputValue(string $html, string $name, string $expected): void
    {
        $pattern = '/<input\b(?=[^>]*\bname="'.preg_quote($name, '/').'")(?=[^>]*\bvalue="'.preg_quote($expected, '/').'")[^>]*>/s';

        $this->assertMatchesRegularExpression($pattern, $html);
    }

    private function workbookFromResponse($response)
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/score-template-'.uniqid('', true).'.xlsx';

        file_put_contents($path, $response->streamedContent());
        $this->workbooks[] = $path;

        return IOFactory::load($path);
    }

    private function rawUploadPath(string $filename, string $contents): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.uniqid('', true).'-'.$filename;
        File::put($path, $contents);
        $this->workbooks[] = $path;

        return $path;
    }

    private function scoreSheet($workbook)
    {
        return $workbook->getActiveSheet();
    }

    private function scoreSheetNames($workbook): array
    {
        $names = [];

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            if ($sheet->getTitle() === 'Petunjuk') {
                continue;
            }

            $names[] = $sheet->getTitle();
        }

        return $names;
    }

    private function uploadedWorkbook($workbook): UploadedFile
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/score-import-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $this->workbooks[] = $path;

        return new UploadedFile(
            $path,
            'score-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function excelImportFeedbackHtml($response): string
    {
        $html = $response->getContent();
        $start = strpos($html, 'File Excel berhasil dibaca, tetapi masih ada error validasi.');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, 'id="excelImportForm"', $start);

        if ($end === false) {
            return substr($html, $start);
        }

        return substr($html, $start, $end - $start);
    }

    private function setValueByKey($sheet, string $key, int $row, mixed $value): void
    {
        $columnMap = $this->scoreTemplateColumnMap($sheet);

        if (! isset($columnMap[$key])) {
            $this->fail("Kolom {$key} tidak ditemukan di template.");
        }

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap[$key]).$row, $value);
    }

    private function cellAddressByKey($sheet, string $key, int $row): string
    {
        $columnMap = $this->scoreTemplateColumnMap($sheet);

        if (! isset($columnMap[$key])) {
            $this->fail("Kolom {$key} tidak ditemukan di template.");
        }

        return Coordinate::stringFromColumnIndex($columnMap[$key]).$row;
    }

    /**
     * @param array<int, string> $needles
     */
    private function assertTextAppearsInOrder(array $needles, string $haystack): void
    {
        $previousPosition = -1;

        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);

            $this->assertNotFalse($position, "Expected to find [{$needle}] in response.");
            $this->assertGreaterThan($previousPosition, $position, "Expected [{$needle}] to appear after the previous item.");

            $previousPosition = $position;
        }
    }

    private function clearTemplateColumnKey($sheet, string $key): void
    {
        $columnMap = $this->scoreTemplateColumnMap($sheet);

        if (! isset($columnMap[$key])) {
            $this->fail("Kolom {$key} tidak ditemukan di template.");
        }

        $sheet->setCellValue(
            Coordinate::stringFromColumnIndex($columnMap[$key]).PengajarScoreExcelTemplateService::KEY_ROW,
            ''
        );
    }

    private function scoreTemplateColumnMap($sheet): array
    {
        $columnMap = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $key = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex).'5')->getValue());

            if ($key !== '') {
                $columnMap[$key] = $columnIndex;
            }
        }

        return $columnMap;
    }

    private function actingAsPengajar(Guru $guru): self
    {
        return $this->actingAs($guru, 'guru')
            ->withSession($this->sessionForActiveYear('pengajar'));
    }

    private function sessionForActiveYear(string $role): array
    {
        return [
            'selected_role' => $role,
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ];
    }

    private function validScoresPayload(int $score = 80): array
    {
        return $this->validScoresPayloadForStudent($this->studentId, $score);
    }

    private function validScoresPayloadForStudent(int $studentId, int $score = 80): array
    {
        return [
            $studentId => [
                'tp' => [
                    $this->lingkupMateriId => [
                        $this->tujuanPembelajaranId => $score,
                    ],
                ],
                'lm' => [
                    $this->lingkupMateriId => $score,
                ],
                'nilai_tes' => $score,
                'nilai_non_tes' => $score,
            ],
        ];
    }

    private function scoresPayloadWithComponents(mixed $tpScore, mixed $lmScore, mixed $nilaiTes, mixed $nilaiNonTes): array
    {
        return [
            $this->studentId => [
                'tp' => [
                    $this->lingkupMateriId => [
                        $this->tujuanPembelajaranId => $tpScore,
                    ],
                ],
                'lm' => [
                    $this->lingkupMateriId => $lmScore,
                ],
                'nilai_tes' => $nilaiTes,
                'nilai_non_tes' => $nilaiNonTes,
            ],
        ];
    }

    private function aggregateNilai(): ?object
    {
        return DB::table('nilais')
            ->where('siswa_id', $this->studentId)
            ->where('mata_pelajaran_id', $this->subjectId)
            ->where('tahun_ajaran_id', $this->activeYearId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->whereNull('deleted_at')
            ->first();
    }

    private function aggregateNilaiModel(): ?Nilai
    {
        return Nilai::query()
            ->where('siswa_id', $this->studentId)
            ->where('mata_pelajaran_id', $this->subjectId)
            ->where('tahun_ajaran_id', $this->activeYearId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->first();
    }

    private function validScoresPayloadForSpecificSetup(int $studentId, int $lingkupMateriId, int $tujuanPembelajaranId, int $score): array
    {
        return [
            $studentId => [
                'tp' => [
                    $lingkupMateriId => [
                        $tujuanPembelajaranId => $score,
                    ],
                ],
                'lm' => [
                    $lingkupMateriId => $score,
                ],
                'nilai_tes' => $score,
                'nilai_non_tes' => $score,
            ],
        ];
    }

    private function insertAggregateNilai(int $nilai): int
    {
        return DB::table('nilais')->insertGetId([
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'nilai_akhir_rapor' => $nilai,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $nomorKelas, string $namaKelas): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $nomorKelas,
            'nama_kelas' => $namaKelas,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudentForClass(int $classId, string $nis, string $nisn, string $name): int
    {
        $studentId = DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'kelas_id' => $classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $studentId;
    }

    private function insertLearningSetup(int $subjectId, string $lingkupMateriTitle, string $kodeTp): array
    {
        $lingkupMateriId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => $lingkupMateriTitle,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tujuanPembelajaranId = DB::table('tujuan_pembelajarans')->insertGetId([
            'lingkup_materi_id' => $lingkupMateriId,
            'kode_tp' => $kodeTp,
            'deskripsi_tp' => "Tujuan {$lingkupMateriTitle}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'lingkup_materi_id' => $lingkupMateriId,
            'tujuan_pembelajaran_id' => $tujuanPembelajaranId,
        ];
    }

    private function insertReadySecondSubjectForBudi(): array
    {
        $this->insertLearningSetup($this->wrongSemesterSubjectId, 'Materi Genap', '1');

        $classId = $this->insertClass(6, 'B');
        $studentId = $this->insertStudentForClass($classId, '2001', '2001000', 'Siti Aminah');
        $subjectId = $this->insertSubject('Bahasa Indonesia', $this->budi->id, 1, $classId);
        $setup = $this->insertLearningSetup($subjectId, 'Teks Narasi', '1');

        return [
            'class_id' => $classId,
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'lingkup_materi_id' => $setup['lingkup_materi_id'],
            'tujuan_pembelajaran_id' => $setup['tujuan_pembelajaran_id'],
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'notifications',
            'report_templates',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'kkms',
            'bobot_nilais',
            'mata_pelajarans',
            'siswa_kelas_semester',
            'siswas',
            'guru_kelas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'gurus',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('guru_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id');
            $table->foreignId('kelas_id');
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role')->default('pengajar');
            $table->timestamps();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bobot_nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('bobot_tp')->default(1);
            $table->integer('bobot_lm')->default(1);
            $table->integer('bobot_as')->default(2);
            $table->timestamps();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('kode_tp');
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
            $table->decimal('na_tp', 5, 2)->nullable();
            $table->decimal('na_lm', 5, 2)->nullable();
            $table->integer('tp_number')->nullable();
            $table->decimal('nilai_tes', 5, 2)->nullable();
            $table->decimal('nilai_non_tes', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('target');
            $table->json('specific_users')->nullable();
            $table->boolean('is_read')->default(false);
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
    }

    private function seedFixture(): void
    {
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = User::findOrFail($adminId);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al-Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => false,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $budiId = $this->insertGuru('Guru Budi', 'budi');
        $aniId = $this->insertGuru('Guru Ani', 'ani');

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $budiId,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $budiId,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $aniId,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->studentId = DB::table('siswas')->insertGetId([
            'nis' => '1001',
            'nisn' => '1001000',
            'nama' => 'Ahmad Fauzan',
            'kelas_id' => $this->classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $this->studentId,
            'kelas_id' => $this->classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subjectId = $this->insertSubject('Matematika', $budiId, 1);
        $this->wrongSemesterSubjectId = $this->insertSubject('Matematika Genap', $budiId, 2);

        DB::table('report_templates')->insert([
            'filename' => 'template-uts.docx',
            'path' => 'templates/template-uts.docx',
            'type' => 'UTS',
            'is_active' => true,
            'kelas_id' => $this->classId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->lingkupMateriId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $this->subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tujuanPembelajaranId = DB::table('tujuan_pembelajarans')->insertGetId([
            'lingkup_materi_id' => $this->lingkupMateriId,
            'kode_tp' => '1',
            'deskripsi_tp' => 'Memahami bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $this->activeYearId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->budi = Guru::findOrFail($budiId);
        $this->ani = Guru::findOrFail($aniId);
    }

    private function insertGuru(string $nama, string $username): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $nama,
            'email' => "{$username}@example.test",
            'username' => $username,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $guruId, int $semester, ?int $classId = null): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId ?? $this->classId,
            'guru_id' => $guruId,
            'semester' => $semester,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
