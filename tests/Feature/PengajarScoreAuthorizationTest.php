<?php

namespace Tests\Feature;

use App\Http\Controllers\ScoreController;
use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
use App\Services\PengajarScoreExcelTemplateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PengajarScoreAuthorizationTest extends TestCase
{
    private Guru $budi;

    private Guru $ani;

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

        $this->withoutMiddleware(ValidateCsrfToken::class);

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
        $this->insertLearningSetup($secondSubjectId, 'Teks Narasi', '1');

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
            ->assertSee('flex items-center justify-center gap-1', false)
            ->assertSee('aria-label="Masukkan nilai Matematika"', false);
    }

    public function test_score_import_template_action_is_not_rendered_in_each_table_row(): void
    {
        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.index'))
            ->assertOk()
            ->assertDontSee('Template Excel');
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
            ->assertSee('inline-flex h-8 w-8 items-center justify-center', false)
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
            ->assertSeeText('Matematika')
            ->assertSeeText('Bahasa Indonesia')
            ->assertSeeText('Ahmad Fauzan')
            ->assertSeeText('80')
            ->assertSeeText('83')
            ->assertSeeText('Simpan & Lanjut');

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

        $this->actingAsPengajar($this->budi)
            ->get(route('pengajar.score.import_templates.preview_sheet', ['token' => $token]))
            ->assertOk()
            ->assertSeeText('Semua sheet berhasil disimpan')
            ->assertSeeText('2 sheet nilai sudah diproses.');
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

    private function workbookFromResponse($response)
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/score-template-'.uniqid('', true).'.xlsx';

        file_put_contents($path, $response->streamedContent());
        $this->workbooks[] = $path;

        return IOFactory::load($path);
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
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
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
