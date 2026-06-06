<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionEnrollmentWriteTest extends TestCase
{
    private User $admin;

    private int $sourceYearId;

    private int $targetYearId;

    private int $otherTargetYearId;

    private int $otherSourceYearId;

    private int $sourceClass5AId;

    private int $sourceClass5BId;

    private int $sourceClass6AId;

    private int $targetClass6AId;

    private int $targetClass5AId;

    private int $otherYearTargetClass6AId;

    private int $otherYearSourceClass5AId;

    private int $ahmadId;

    private int $sitiId;

    private int $dimasId;

    private int $semesterOneOnlyId;

    private int $otherYearStudentId;

    private int $finalGradeStudentId;

    private int $finalTransferredStudentId;

    private int $finalInactiveStudentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Event::fake();

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

    public function test_individual_promotion_creates_new_year_enrollment_without_mutating_student_identity(): void
    {
        $beforeStudentCount = DB::table('siswas')->count();
        $beforeWorkCounts = $this->studentSpecificCounts($this->sourceYearId);
        $beforeAhmad = DB::table('siswas')->where('id', $this->ahmadId)->first();

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->targetClass6AId,
            'tahun_ajaran_id' => $this->targetYearId,
            'semester' => 1,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->sourceClass5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
        ]);

        $afterAhmad = DB::table('siswas')->where('id', $this->ahmadId)->first();
        $this->assertSame($beforeStudentCount, DB::table('siswas')->count());
        $this->assertSame((int) $beforeAhmad->kelas_id, (int) $afterAhmad->kelas_id);
        $this->assertSame($beforeAhmad->nis, $afterAhmad->nis);
        $this->assertSame($beforeAhmad->nisn, $afterAhmad->nisn);
        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
        $this->assertSame($beforeWorkCounts, $this->studentSpecificCounts($this->sourceYearId));
        $this->assertTargetYearWorkDataIsEmpty();
    }

    public function test_selected_student_bulk_promotion_creates_target_enrollments_for_all_valid_source_students(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId, $this->sitiId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, DB::table('siswa_kelas_semester')
            ->where('kelas_id', $this->targetClass6AId)
            ->where('tahun_ajaran_id', $this->targetYearId)
            ->where('semester', 1)
            ->count());
        $this->assertSame($this->sourceClass5AId, (int) DB::table('siswas')->where('id', $this->sitiId)->value('kelas_id'));
    }

    public function test_mixed_valid_and_invalid_student_payload_is_rejected_atomically(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId, $this->dimasId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('siswa_kelas_semester')
            ->whereIn('siswa_id', [$this->ahmadId, $this->dimasId])
            ->where('tahun_ajaran_id', $this->targetYearId)
            ->where('semester', 1)
            ->count());
    }

    public function test_student_with_only_semester_one_source_enrollment_is_rejected(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->semesterOneOnlyId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertMissingTargetEnrollment($this->semesterOneOnlyId);
    }

    public function test_student_from_another_academic_year_is_rejected(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->otherYearStudentId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertMissingTargetEnrollment($this->otherYearStudentId);
    }

    public function test_target_class_from_another_year_is_rejected(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId],
                'kelas_tujuan_id' => $this->otherYearTargetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertMissingTargetEnrollment($this->ahmadId);
    }

    public function test_duplicate_target_enrollment_is_rejected_without_duplicate_rows(): void
    {
        $this->insertEnrollment($this->ahmadId, $this->targetClass6AId, $this->targetYearId, 1);

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kenaikan'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId],
                'kelas_tujuan_id' => $this->targetClass6AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('siswa_kelas_semester')
            ->where('siswa_id', $this->ahmadId)
            ->where('tahun_ajaran_id', $this->targetYearId)
            ->where('semester', 1)
            ->count());
    }

    public function test_repeater_creates_same_grade_new_year_enrollment_without_mutating_history(): void
    {
        $beforeClassId = DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-tinggal'), $this->promotionPayload([
                'siswa_ids' => [$this->ahmadId],
                'kelas_tujuan_id' => $this->targetClass5AId,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->targetClass5AId,
            'tahun_ajaran_id' => $this->targetYearId,
            'semester' => 1,
        ]);
        $this->assertSame($beforeClassId, DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id'));
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->sourceClass5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
        ]);
    }

    public function test_final_grade_student_can_be_marked_graduated_without_target_enrollment_or_identity_mutation(): void
    {
        $beforeStudentCount = DB::table('siswas')->count();
        $beforeWorkCounts = $this->studentSpecificCounts($this->sourceYearId);
        $beforeStudent = DB::table('siswas')->where('id', $this->finalGradeStudentId)->first();

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $this->graduationPayload([
                'siswa_ids' => [$this->finalGradeStudentId],
                'status' => 'lulus',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $afterStudent = DB::table('siswas')->where('id', $this->finalGradeStudentId)->first();

        $this->assertSame('lulus', $afterStudent->status);
        $this->assertSame($beforeStudentCount, DB::table('siswas')->count());
        $this->assertSame((int) $beforeStudent->kelas_id, (int) $afterStudent->kelas_id);
        $this->assertSame($beforeStudent->nis, $afterStudent->nis);
        $this->assertSame($beforeStudent->nisn, $afterStudent->nisn);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->finalGradeStudentId,
            'kelas_id' => $this->sourceClass6AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
        ]);
        $this->assertMissingTargetEnrollment($this->finalGradeStudentId);
        $this->assertSame($beforeWorkCounts, $this->studentSpecificCounts($this->sourceYearId));
        $this->assertTargetYearWorkDataIsEmpty();
        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
        $this->assertSame(0, DB::table('kelas')->where('nomor_kelas', '7')->count());
    }

    public function test_repeated_graduation_request_is_idempotent(): void
    {
        $payload = $this->graduationPayload([
            'siswa_ids' => [$this->finalGradeStudentId],
            'status' => 'lulus',
        ]);

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('lulus', DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('status'));
        $this->assertMissingTargetEnrollment($this->finalGradeStudentId);
    }

    public function test_forged_graduation_request_for_another_class_student_is_rejected(): void
    {
        $beforeStatus = DB::table('siswas')->where('id', $this->dimasId)->value('status');
        $beforeClassId = DB::table('siswas')->where('id', $this->dimasId)->value('kelas_id');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $this->graduationPayload([
                'siswa_ids' => [$this->dimasId],
                'status' => 'lulus',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($beforeStatus, DB::table('siswas')->where('id', $this->dimasId)->value('status'));
        $this->assertSame($beforeClassId, DB::table('siswas')->where('id', $this->dimasId)->value('kelas_id'));
        $this->assertMissingTargetEnrollment($this->dimasId);
    }

    public function test_mixed_graduation_payload_is_rejected_atomically(): void
    {
        $beforeFinalStatus = DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('status');
        $beforeDimasStatus = DB::table('siswas')->where('id', $this->dimasId)->value('status');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $this->graduationPayload([
                'siswa_ids' => [$this->finalGradeStudentId, $this->dimasId],
                'status' => 'lulus',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($beforeFinalStatus, DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('status'));
        $this->assertSame($beforeDimasStatus, DB::table('siswas')->where('id', $this->dimasId)->value('status'));
        $this->assertMissingTargetEnrollment($this->finalGradeStudentId);
        $this->assertMissingTargetEnrollment($this->dimasId);
    }

    public function test_non_final_student_cannot_be_graduated(): void
    {
        $beforeStatus = DB::table('siswas')->where('id', $this->ahmadId)->value('status');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), [
                'source_kelas_id' => $this->sourceClass5AId,
                'source_tahun_ajaran_id' => $this->sourceYearId,
                'siswa_ids' => [$this->ahmadId],
                'status' => 'lulus',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Kelulusan hanya dapat diproses untuk kelas akhir.');

        $this->assertSame($beforeStatus, DB::table('siswas')->where('id', $this->ahmadId)->value('status'));
        $this->assertMissingTargetEnrollment($this->ahmadId);
    }

    public function test_transferred_and_inactive_statuses_are_status_only(): void
    {
        $beforeTransferredClassId = DB::table('siswas')->where('id', $this->finalTransferredStudentId)->value('kelas_id');
        $beforeInactiveClassId = DB::table('siswas')->where('id', $this->finalInactiveStudentId)->value('kelas_id');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $this->graduationPayload([
                'siswa_ids' => [$this->finalTransferredStudentId],
                'status' => 'pindah',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), $this->graduationPayload([
                'siswa_ids' => [$this->finalInactiveStudentId],
                'status' => 'dropout',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('pindah', DB::table('siswas')->where('id', $this->finalTransferredStudentId)->value('status'));
        $this->assertSame('dropout', DB::table('siswas')->where('id', $this->finalInactiveStudentId)->value('status'));
        $this->assertSame($beforeTransferredClassId, DB::table('siswas')->where('id', $this->finalTransferredStudentId)->value('kelas_id'));
        $this->assertSame($beforeInactiveClassId, DB::table('siswas')->where('id', $this->finalInactiveStudentId)->value('kelas_id'));
        $this->assertMissingTargetEnrollment($this->finalTransferredStudentId);
        $this->assertMissingTargetEnrollment($this->finalInactiveStudentId);
    }

    public function test_mass_promotion_remains_blocked_without_student_mutation(): void
    {
        $beforeStatus = DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('status');
        $beforeClassId = DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('kelas_id');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-mass'))
            ->assertRedirect(route('admin.kenaikan-kelas.index'))
            ->assertSessionHas('error');

        $this->assertSame($beforeStatus, DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('status'));
        $this->assertSame($beforeClassId, DB::table('siswas')->where('id', $this->finalGradeStudentId)->value('kelas_id'));
        $this->assertMissingTargetEnrollment($this->finalGradeStudentId);
    }

    private function createSchema(): void
    {
        foreach ([
            'report_generations',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'capaian_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'siswa_kelas_semester',
            'siswas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->integer('semester')->default(1);
            $table->text('deskripsi')->nullable();
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

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('status')->default('aktif');
            $table->boolean('is_naik_kelas')->nullable();
            $table->foreignId('kelas_tujuan_id')->nullable();
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_unique_context');
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_class_context_index');
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->integer('nilai_akhir_rapor')->nullable();
            $table->timestamps();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
            $table->timestamps();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('catatan_mata_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->text('capaian_tertinggi')->nullable();
            $table->text('capaian_terendah')->nullable();
            $table->timestamps();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('ekstrakurikuler_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->string('nilai')->nullable();
            $table->string('predikat')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type');
            $table->string('tahun_ajaran');
            $table->integer('semester');
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'demo_admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->sourceYearId = $this->insertYear('2026/2027', 2, true);
        $this->targetYearId = $this->insertYear('2027/2028', 1, false);
        $this->otherTargetYearId = $this->insertYear('2028/2029', 1, false);
        $this->otherSourceYearId = $this->insertYear('2025/2026', 2, false);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sourceClass5AId = $this->insertClass('5', 'A', $this->sourceYearId);
        $this->sourceClass5BId = $this->insertClass('5', 'B', $this->sourceYearId);
        $this->sourceClass6AId = $this->insertClass('6', 'A', $this->sourceYearId);
        $this->targetClass6AId = $this->insertClass('6', 'A', $this->targetYearId);
        $this->targetClass5AId = $this->insertClass('5', 'A', $this->targetYearId);
        $this->otherYearTargetClass6AId = $this->insertClass('6', 'A', $this->otherTargetYearId);
        $this->otherYearSourceClass5AId = $this->insertClass('5', 'A', $this->otherSourceYearId);

        $this->ahmadId = $this->insertStudent('2605001', '9000000001', 'Ahmad Fauzan', $this->sourceClass5BId);
        $this->sitiId = $this->insertStudent('2605002', '9000000002', 'Siti Aisyah', $this->sourceClass5AId);
        $this->dimasId = $this->insertStudent('2605003', '9000000003', 'Dimas Pratama', $this->sourceClass5BId);
        $this->semesterOneOnlyId = $this->insertStudent('2605004', '9000000004', 'Semester One Only', $this->sourceClass5AId);
        $this->otherYearStudentId = $this->insertStudent('2605005', '9000000005', 'Other Year Student', $this->otherYearSourceClass5AId);
        $this->finalGradeStudentId = $this->insertStudent('2606001', '9000000006', 'Final Grade Student', $this->sourceClass6AId);
        $this->finalTransferredStudentId = $this->insertStudent('2606002', '9000000007', 'Final Transfer Student', $this->sourceClass6AId);
        $this->finalInactiveStudentId = $this->insertStudent('2606003', '9000000008', 'Final Inactive Student', $this->sourceClass6AId);

        $this->insertEnrollment($this->ahmadId, $this->sourceClass5AId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->sitiId, $this->sourceClass5AId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->dimasId, $this->sourceClass5BId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->semesterOneOnlyId, $this->sourceClass5AId, $this->sourceYearId, 1);
        $this->insertEnrollment($this->otherYearStudentId, $this->otherYearSourceClass5AId, $this->otherSourceYearId, 2);
        $this->insertEnrollment($this->finalGradeStudentId, $this->sourceClass6AId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->finalTransferredStudentId, $this->sourceClass6AId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->finalInactiveStudentId, $this->sourceClass6AId, $this->sourceYearId, 2);

        $this->insertStudentSpecificSourceData($this->ahmadId);
        $this->insertStudentSpecificSourceData($this->finalGradeStudentId);
    }

    private function promotionPayload(array $overrides = []): array
    {
        return array_merge([
            'source_kelas_id' => $this->sourceClass5AId,
            'source_tahun_ajaran_id' => $this->sourceYearId,
            'target_tahun_ajaran_id' => $this->targetYearId,
        ], $overrides);
    }

    private function graduationPayload(array $overrides = []): array
    {
        return array_merge([
            'source_kelas_id' => $this->sourceClass6AId,
            'source_tahun_ajaran_id' => $this->sourceYearId,
        ], $overrides);
    }

    private function insertStudentSpecificSourceData(int $siswaId): void
    {
        DB::table('nilais')->insert([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => 1,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'nilai_akhir_rapor' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('absensis')->insert([
            'siswa_id' => $siswaId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'sakit' => 1,
            'izin' => 0,
            'tanpa_keterangan' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_siswa')->insert([
            'siswa_id' => $siswaId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'catatan' => 'Catatan semester genap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_mata_pelajaran')->insert([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => 1,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'catatan' => 'Catatan mapel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('capaian_custom')->insert([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => 1,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'capaian_tertinggi' => 'Baik',
            'capaian_terendah' => 'Perlu latihan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ekstraId = DB::table('ekstrakurikulers')->insertGetId([
            'nama' => 'Pramuka',
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $siswaId,
            'ekstrakurikuler_id' => $ekstraId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'nilai' => 'A',
            'predikat' => 'A',
            'keterangan' => 'Aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_generations')->insert([
            'siswa_id' => $siswaId,
            'kelas_id' => $this->sourceClass5AId,
            'report_template_id' => null,
            'generated_file' => 'reports/ahmad.pdf',
            'type' => 'UAS',
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
            'generated_at' => now(),
            'generated_by' => $this->admin->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function studentSpecificCounts(int $tahunAjaranId): array
    {
        return [
            'nilais' => DB::table('nilais')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'absensis' => DB::table('absensis')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'catatan_siswa' => DB::table('catatan_siswa')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'catatan_mata_pelajaran' => DB::table('catatan_mata_pelajaran')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'capaian_custom' => DB::table('capaian_custom')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'nilai_ekstrakurikuler' => DB::table('nilai_ekstrakurikuler')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
            'report_generations' => DB::table('report_generations')->where('tahun_ajaran_id', $tahunAjaranId)->count(),
        ];
    }

    private function assertTargetYearWorkDataIsEmpty(): void
    {
        foreach ($this->studentSpecificCounts($this->targetYearId) as $table => $count) {
            $this->assertSame(0, $count, "Expected {$table} to have no target-year work data.");
        }
    }

    private function assertMissingTargetEnrollment(int $siswaId): void
    {
        $this->assertSame(0, DB::table('siswa_kelas_semester')
            ->where('siswa_id', $siswaId)
            ->where('tahun_ajaran_id', $this->targetYearId)
            ->where('semester', 1)
            ->count());
    }

    private function insertYear(string $label, int $semester, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $label,
            'is_active' => $active,
            'tanggal_mulai' => $semester === 1 ? substr($label, 0, 4).'-07-01' : substr($label, 5, 4).'-01-01',
            'tanggal_selesai' => substr($label, 5, 4).'-06-30',
            'semester' => $semester,
            'deskripsi' => 'Fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(string $nomorKelas, string $namaKelas, int $tahunAjaranId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $nomorKelas,
            'nama_kelas' => $namaKelas,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $nisn, string $nama, int $kelasId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $nama,
            'jenis_kelamin' => 'L',
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'status' => 'aktif',
            'is_naik_kelas' => null,
            'kelas_tujuan_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $siswaId, int $kelasId, int $tahunAjaranId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $siswaId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
