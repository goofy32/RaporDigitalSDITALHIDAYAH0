<?php

namespace Tests\Feature;

use App\Http\Controllers\ScoreController;
use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
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

        $sheet = $workbook->getSheetByName('Nilai');
        $names = collect($sheet->rangeToArray('D6:D20'))->flatten()->filter()->values()->all();

        $this->assertContains('Ahmad Fauzan', $names);
        $this->assertNotContains('Siswa Kelas Lain', $names);
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
            ->assertSeeText('Ini baru preview. Nilai belum disimpan.')
            ->assertSeeText('Ahmad Fauzan')
            ->assertSeeText('Valid');

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
            ->assertSeeText('Siswa tidak termasuk kelas/konteks mata pelajaran ini.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_score_import_preview_rejects_duplicated_siswa_id(): void
    {
        $workbook = $this->templateWorkbook();
        $sheet = $workbook->getSheetByName('Nilai');
        $highestColumn = $sheet->getHighestDataColumn();
        $sheet->fromArray($sheet->rangeToArray("A6:{$highestColumn}6")[0], null, 'A7');
        $this->setValueByKey($sheet, "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 6, 80);
        $this->setValueByKey($sheet, "tp_{$this->lingkupMateriId}_{$this->tujuanPembelajaranId}", 7, 81);

        $this->actingAsPengajar($this->budi)
            ->post(route('pengajar.score.import_preview', $this->subjectId), [
                'file' => $this->uploadedWorkbook($workbook),
            ])
            ->assertOk()
            ->assertSeeText('siswa_id duplikat di file.');

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
            ->assertSeeText('TP 1 harus antara 0 sampai 100.')
            ->assertSeeText('LM Bilangan harus antara 0 sampai 100.')
            ->assertSeeText('Nilai Tes harus berupa angka.');

        $this->assertSame(0, DB::table('nilais')->count());
    }

    private function validScoreImportUpload(array $values): UploadedFile
    {
        $workbook = $this->templateWorkbook();
        $sheet = $workbook->getSheetByName('Nilai');

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

    private function workbookFromResponse($response)
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/score-template-'.uniqid('', true).'.xlsx';

        file_put_contents($path, $response->streamedContent());
        $this->workbooks[] = $path;

        return IOFactory::load($path);
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

    private function setValueByKey($sheet, string $key, int $row, mixed $value): void
    {
        $columnMap = $this->scoreTemplateColumnMap($sheet);

        if (! isset($columnMap[$key])) {
            $this->fail("Kolom {$key} tidak ditemukan di template.");
        }

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap[$key]).$row, $value);
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

    private function insertSubject(string $name, int $guruId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $this->classId,
            'guru_id' => $guruId,
            'semester' => $semester,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
