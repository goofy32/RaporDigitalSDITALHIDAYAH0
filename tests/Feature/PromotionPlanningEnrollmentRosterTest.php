<?php

namespace Tests\Feature;

use App\Http\Controllers\KenaikanKelasController;
use App\Models\Guru;
use App\Models\User;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionPlanningEnrollmentRosterTest extends TestCase
{
    private User $admin;

    private Guru $wali;

    private int $sourceYearId;

    private int $targetYearId;

    private int $invalidTargetSemesterYearId;

    private int $sourceClass5AId;

    private int $sourceClass5BId;

    private int $targetClass6AId;

    private int $targetClass5AId;

    private int $ahmadId;

    private int $dimasId;

    private int $sitiSemesterOneOnlyId;

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

    public function test_promotion_roster_lists_source_class_students_from_semester_two_enrollment(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Ahmad Fauzan');
        $response->assertDontSee('Dimas Pratama');
        $response->assertDontSee('Siti Semester Satu');
        $this->assertSame(1, substr_count($response->getContent(), 'Ahmad Fauzan'));
    }

    public function test_index_counts_use_semester_two_enrollment_not_legacy_kelas_id(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.index'));

        $response->assertOk();
        $response->assertSee('Kelas 5 A');
        $response->assertSee('1 Siswa');
        $response->assertSee('Kelas 5 B');
    }

    public function test_matching_legacy_fallback_only_works_when_student_has_no_enrollments(): void
    {
        $legacyStudentId = $this->insertStudent('2605005', '9000000005', 'Rina Legacy', $this->sourceClass5AId);
        $wrongLegacyStudentId = $this->insertStudent('2605006', '9000000006', 'Wrong Legacy', $this->sourceClass5BId);
        $hasOtherEnrollmentId = $this->insertStudent('2605007', '9000000007', 'Has Other Enrollment', $this->sourceClass5AId);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $hasOtherEnrollmentId,
            'kelas_id' => $this->sourceClass5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Rina Legacy');
        $response->assertDontSee('Wrong Legacy');
        $response->assertDontSee('Has Other Enrollment');

        $this->assertSame($this->sourceClass5AId, (int) DB::table('siswas')->where('id', $legacyStudentId)->value('kelas_id'));
        $this->assertSame($this->sourceClass5BId, (int) DB::table('siswas')->where('id', $wrongLegacyStudentId)->value('kelas_id'));
    }

    public function test_target_class_candidates_come_from_next_year_semester_one_structure(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Kandidat Naik Kelas');
        $response->assertSee('Kelas 6 A');
        $response->assertSee('Kandidat Tinggal Kelas');
        $response->assertSee('Kelas 5 A');
        $response->assertDontSee('Kelas 6 B');
    }

    public function test_semester_ganjil_promotion_index_redirects_with_friendly_message(): void
    {
        DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->update([
            'semester' => 1,
        ]);

        $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.index'))
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('error', 'Kenaikan kelas hanya dapat dilakukan dari Semester Genap. Silakan aktifkan tahun ajaran Semester Genap terlebih dahulu.');
    }

    public function test_target_class_creation_link_uses_next_year_context(): void
    {
        DB::table('kelas')->where('id', $this->targetClass6AId)->delete();

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Buat Kelas Baru');
        $response->assertSee('target_tahun_ajaran_id='.$this->targetYearId, false);
        $response->assertSee('redirect_to=', false);
    }

    public function test_class_store_respects_requested_target_year_and_redirects_back_to_planning(): void
    {
        $redirectTo = route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId);

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->sourceYearId])
            ->post(route('kelas.store'), [
                'nomor_kelas' => 6,
                'nama_kelas' => 'B',
                'target_tahun_ajaran_id' => $this->targetYearId,
                'redirect_to' => $redirectTo,
            ])
            ->assertRedirect($redirectTo)
            ->assertSessionHas('success');

        $this->assertDatabaseHas('kelas', [
            'nomor_kelas' => 6,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->targetYearId,
        ]);
        $this->assertDatabaseMissing('kelas', [
            'nomor_kelas' => 6,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->sourceYearId,
        ]);
    }

    public function test_already_promoted_students_are_marked_processed_and_not_actionable(): void
    {
        $this->insertEnrollment($this->ahmadId, $this->targetClass6AId, $this->targetYearId, 1);

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Sudah diproses');
        $response->assertSee('Naik Kelas - Kelas 6 A');
        $response->assertSee('1/1 sudah diproses');
        $response->assertSee('Selesai');
        $response->assertSee('disabled', false);
        $response->assertDontSee('id="actionForms"', false);
    }

    public function test_unprocessed_students_remain_actionable_and_class_summary_warns(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee('Belum diproses');
        $response->assertSee('0/1 sudah diproses');
        $response->assertSee('Belum selesai');
        $response->assertSee('value="'.$this->ahmadId.'"', false);
        $response->assertSee('id="actionForms"', false);
    }

    public function test_class_summary_marks_complete_when_all_source_students_have_target_enrollment(): void
    {
        $this->insertEnrollment($this->ahmadId, $this->targetClass6AId, $this->targetYearId, 1);

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.index'));

        $response->assertOk();
        $response->assertSee('1/1 sudah diproses');
        $response->assertSee('Selesai');
    }

    public function test_opening_promotion_planning_does_not_mutate_students_or_enrollments(): void
    {
        $studentCount = DB::table('siswas')->count();
        $enrollmentCount = DB::table('siswa_kelas_semester')->count();
        $ahmadLegacyClassId = DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id');

        $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.index'))
            ->assertOk();

        $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId))
            ->assertOk();

        $this->assertSame($studentCount, DB::table('siswas')->count());
        $this->assertSame($enrollmentCount, DB::table('siswa_kelas_semester')->count());
        $this->assertSame($ahmadLegacyClassId, DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id'));
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $this->targetYearId)->count());
    }

    public function test_unsafe_legacy_promotion_actions_are_blocked_without_mutation(): void
    {
        $beforeClassId = DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id');
        $beforeEnrollmentCount = DB::table('siswa_kelas_semester')->count();

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-mass'))
            ->assertRedirect(route('admin.kenaikan-kelas.index'))
            ->assertSessionHas('error', 'Kenaikan kelas massal dan kelulusan berbasis enrollment belum diaktifkan. Gunakan proses siswa terpilih untuk kenaikan atau tinggal kelas.');

        $this->actingAs($this->admin, 'web')
            ->post(route('admin.kenaikan-kelas.process-kelulusan'), [
                'source_kelas_id' => $this->sourceClass5AId,
                'source_tahun_ajaran_id' => $this->sourceYearId,
                'siswa_ids' => [$this->ahmadId],
                'status' => 'lulus',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Kelulusan hanya dapat diproses untuk kelas akhir.');

        $this->assertSame($beforeClassId, DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id'));
        $this->assertSame($beforeEnrollmentCount, DB::table('siswa_kelas_semester')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $this->targetYearId)->count());
    }

    public function test_explicit_source_year_must_be_semester_two(): void
    {
        $semesterOneYearId = (int) DB::table('tahun_ajarans')
            ->where('tahun_ajaran', '2027/2028')
            ->where('semester', 1)
            ->value('id');

        $this->assertGreaterThan(0, $semesterOneYearId);
        $this->assertSame(1, (int) DB::table('tahun_ajarans')->where('id', $semesterOneYearId)->value('semester'));
        $this->assertSame(1, (int) \App\Models\TahunAjaran::findOrFail($semesterOneYearId)->semester);

        $request = Request::create('/admin/kenaikan-kelas', 'GET', [
            'tahun_ajaran_id' => $semesterOneYearId,
        ]);

        try {
            (new KenaikanKelasController)->index($request, app(SiswaKelasSemesterResolver::class));
            $this->fail('Expected semester-1 source context to be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_non_final_planning_ui_renders_enrollment_promotion_forms(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.kenaikan-kelas.show-siswa', $this->sourceClass5AId));

        $response->assertOk();
        $response->assertSee(route('admin.kenaikan-kelas.process-kenaikan'), false);
        $response->assertSee(route('admin.kenaikan-kelas.process-tinggal'), false);
        $response->assertSee('name="source_kelas_id"', false);
        $response->assertSee('name="source_tahun_ajaran_id"', false);
        $response->assertSee('name="target_tahun_ajaran_id"', false);
        $response->assertDontSee(route('admin.kenaikan-kelas.process-kelulusan'), false);
    }

    private function createSchema(): void
    {
        foreach ([
            'report_generations',
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
            $table->string('username')->nullable();
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

        $this->wali = Guru::create([
            'nama' => 'Budi Santoso',
            'email' => 'budi@example.test',
            'username' => 'budi',
            'password' => Hash::make('password'),
        ]);

        $this->sourceYearId = $this->insertYear('2026/2027', 2, true);
        $this->targetYearId = $this->insertYear('2027/2028', 1, false);
        $this->invalidTargetSemesterYearId = $this->insertYear('2027/2028', 2, false);
        $otherYearId = $this->insertYear('2025/2026', 2, false);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sourceClass5AId = $this->insertClass('5', 'A', $this->sourceYearId);
        $this->sourceClass5BId = $this->insertClass('5', 'B', $this->sourceYearId);
        $otherYearClass5AId = $this->insertClass('5', 'A', $otherYearId);
        $this->targetClass6AId = $this->insertClass('6', 'A', $this->targetYearId);
        $this->targetClass5AId = $this->insertClass('5', 'A', $this->targetYearId);
        $this->insertClass('6', 'B', $this->invalidTargetSemesterYearId);

        foreach ([$this->sourceClass5AId, $this->sourceClass5BId] as $classId) {
            DB::table('guru_kelas')->insert([
                'guru_id' => $this->wali->id,
                'kelas_id' => $classId,
                'is_wali_kelas' => $classId === $this->sourceClass5AId,
                'role' => $classId === $this->sourceClass5AId ? 'wali_kelas' : 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->ahmadId = $this->insertStudent('2605001', '9000000001', 'Ahmad Fauzan', $this->sourceClass5BId);
        $this->dimasId = $this->insertStudent('2605002', '9000000002', 'Dimas Pratama', $this->sourceClass5BId);
        $this->sitiSemesterOneOnlyId = $this->insertStudent('2605003', '9000000003', 'Siti Semester Satu', $this->sourceClass5AId);
        $otherYearStudentId = $this->insertStudent('2605004', '9000000004', 'Other Year Student', $otherYearClass5AId);

        $this->insertEnrollment($this->ahmadId, $this->sourceClass5AId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->dimasId, $this->sourceClass5BId, $this->sourceYearId, 2);
        $this->insertEnrollment($this->sitiSemesterOneOnlyId, $this->sourceClass5AId, $this->sourceYearId, 1);
        $this->insertEnrollment($otherYearStudentId, $otherYearClass5AId, $otherYearId, 2);

        DB::table('report_generations')->insert([
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->sourceClass5AId,
            'report_template_id' => null,
            'generated_file' => 'reports/ahmad.pdf',
            'type' => 'UAS',
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
            'generated_at' => now(),
            'generated_by' => $this->wali->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function insertStudent(string $nis, string $nisn, string $nama, int $kelasId, string $status = 'aktif'): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $nama,
            'jenis_kelamin' => 'L',
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'status' => $status,
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
