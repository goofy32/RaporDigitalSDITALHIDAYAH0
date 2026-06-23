<?php

namespace Tests\Feature;

use App\Models\Guru;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
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
