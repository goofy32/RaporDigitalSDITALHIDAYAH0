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

class EnrollmentAwareAuthorizationRosterTest extends TestCase
{
    private Guru $wali;

    private Guru $pengajar;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $waliGanjilClassId;

    private int $waliGenapClassId;

    private int $otherClassId;

    private int $ganjilSubjectId;

    private int $genapSubjectId;

    private int $ahmadId;

    private int $sitiId;

    private int $legacyId;

    private int $genapOnlyId;

    private int $otherClassStudentId;

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

    public function test_wali_student_roster_uses_ganjil_enrollment_context(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.student.index'));

        $response->assertOk()
            ->assertViewHas('students', function ($students) {
                $names = $students->getCollection()->pluck('nama');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student')
                    && $names->filter(fn ($name) => $name === 'Ahmad Fauzan')->count() === 1;
            });
    }

    public function test_wali_student_roster_uses_genap_enrollment_context(): void
    {
        $response = $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.student.index'));

        $response->assertOk()
            ->assertViewHas('students', function ($students) {
                $names = $students->getCollection()->pluck('nama');

                return $names->contains('Genap Only Student')
                    && ! $names->contains('Ahmad Fauzan')
                    && ! $names->contains('Siti Aisyah')
                    && ! $names->contains('Legacy Matching Student')
                    && ! $names->contains('Other Class Student');
            });
    }

    public function test_report_index_uses_enrollment_roster(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.rapor.index'));

        $response->assertOk()
            ->assertViewHas('siswa', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student')
                    && $names->filter(fn ($name) => $name === 'Ahmad Fauzan')->count() === 1;
            });
    }

    public function test_pengajar_input_score_uses_subject_enrollment_roster_and_loads_existing_scores(): void
    {
        $response = $this->actingAsPengajar($this->ganjilYearId, 1)
            ->get(route('pengajar.score.input_score', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('students', function ($students) {
                $names = collect($students)->pluck('name');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student');
            })
            ->assertViewHas('existingScores', function ($existingScores) {
                return ($existingScores[$this->ahmadId]['nilai_akhir_rapor'] ?? null) == 88;
            });
    }

    public function test_pengajar_genap_subject_does_not_use_ganjil_only_enrollment(): void
    {
        $response = $this->actingAsPengajar($this->genapYearId, 2)
            ->get(route('pengajar.score.input_score', $this->genapSubjectId));

        $response->assertOk()
            ->assertViewHas('students', function ($students) {
                $names = collect($students)->pluck('name');

                return $names->contains('Genap Only Student')
                    && ! $names->contains('Ahmad Fauzan')
                    && ! $names->contains('Siti Aisyah')
                    && ! $names->contains('Legacy Matching Student')
                    && ! $names->contains('Other Class Student');
            });
    }

    public function test_pengajar_preview_score_uses_enrollment_roster(): void
    {
        $response = $this->actingAsPengajar($this->ganjilYearId, 1)
            ->get(route('pengajar.score.preview_score', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('students', function ($students) {
                $names = collect($students)->pluck('name');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student');
            });
    }

    public function test_pengajar_can_save_grade_for_enrolled_student(): void
    {
        DB::table('nilais')->delete();

        $this->actingAsPengajar($this->ganjilYearId, 1)
            ->postJson(route('pengajar.score.save_scores', $this->ganjilSubjectId), [
                'scores' => $this->scorePayloadForStudent($this->ahmadId, $this->ganjilSubjectId, 80),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'nilai_akhir_rapor' => 80,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->ganjilYearId,
        ]);
    }

    public function test_genap_enrolled_student_can_save_when_legacy_class_points_to_ganjil(): void
    {
        DB::table('nilais')->delete();

        $this->actingAsPengajar($this->genapYearId, 2)
            ->postJson(route('pengajar.score.save_scores', $this->genapSubjectId), [
                'scores' => $this->scorePayloadForStudent($this->genapOnlyId, $this->genapSubjectId, 82),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $this->genapOnlyId,
            'mata_pelajaran_id' => $this->genapSubjectId,
            'nilai_akhir_rapor' => 82,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->genapYearId,
        ]);
    }

    public function test_ganjil_only_student_is_rejected_for_genap_subject(): void
    {
        $this->assertUnauthorizedSaveDoesNotMutateGrades(
            $this->genapYearId,
            2,
            $this->genapSubjectId,
            $this->scorePayloadForStudent($this->ahmadId, $this->genapSubjectId, 85)
        );
    }

    public function test_student_enrolled_in_another_class_is_rejected_on_save(): void
    {
        $this->assertUnauthorizedSaveDoesNotMutateGrades(
            $this->ganjilYearId,
            1,
            $this->ganjilSubjectId,
            $this->scorePayloadForStudent($this->otherClassStudentId, $this->ganjilSubjectId, 85)
        );
    }

    public function test_student_enrolled_in_another_academic_year_is_rejected_on_save(): void
    {
        $oldYearId = $this->insertYear('2025/2026', 1, false);
        $oldClassId = $this->insertClass(5, 'A', $oldYearId);
        $oldYearStudentId = $this->insertStudent('2001', 'Old Year Enrolled Student', $this->waliGanjilClassId);
        $this->insertEnrollment($oldYearStudentId, $oldClassId, $oldYearId, 1);

        $this->assertUnauthorizedSaveDoesNotMutateGrades(
            $this->ganjilYearId,
            1,
            $this->ganjilSubjectId,
            $this->scorePayloadForStudent($oldYearStudentId, $this->ganjilSubjectId, 85)
        );
    }

    public function test_unrelated_legacy_class_does_not_grant_save_access_when_enrollment_differs(): void
    {
        $studentId = $this->insertStudent('2002', 'Legacy Class Mismatch Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->assertUnauthorizedSaveDoesNotMutateGrades(
            $this->ganjilYearId,
            1,
            $this->ganjilSubjectId,
            $this->scorePayloadForStudent($studentId, $this->ganjilSubjectId, 85)
        );
    }

    public function test_matching_legacy_fallback_still_allows_save_when_student_has_no_enrollment(): void
    {
        DB::table('nilais')->delete();

        $this->actingAsPengajar($this->ganjilYearId, 1)
            ->postJson(route('pengajar.score.save_scores', $this->ganjilSubjectId), [
                'scores' => $this->scorePayloadForStudent($this->legacyId, $this->ganjilSubjectId, 83),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $this->legacyId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'nilai_akhir_rapor' => 83,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->ganjilYearId,
        ]);
    }

    public function test_mixed_valid_and_unauthorized_payload_is_entirely_rejected(): void
    {
        $payload = $this->scorePayloadForStudent($this->sitiId, $this->ganjilSubjectId, 88)
            + $this->scorePayloadForStudent($this->otherClassStudentId, $this->ganjilSubjectId, 88);

        $this->assertUnauthorizedSaveDoesNotMutateGrades(
            $this->ganjilYearId,
            1,
            $this->ganjilSubjectId,
            $payload
        );

        $this->assertDatabaseMissing('nilais', [
            'siswa_id' => $this->sitiId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
        ]);
    }

    private function actingAsWali(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession($this->sessionFor($tahunAjaranId, $semester, 'wali_kelas'));
    }

    private function actingAsPengajar(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->sessionFor($tahunAjaranId, $semester, 'pengajar'));
    }

    private function sessionFor(int $tahunAjaranId, int $semester, string $role): array
    {
        return [
            'selected_role' => $role,
            'tahun_ajaran_id' => $tahunAjaranId,
            'selected_semester' => $semester,
            'no_tahun_ajaran' => false,
        ];
    }

    private function assertUnauthorizedSaveDoesNotMutateGrades(
        int $tahunAjaranId,
        int $semester,
        int $subjectId,
        array $payload
    ): void {
        $existingCount = DB::table('nilais')->count();
        $existingAhmadValue = DB::table('nilais')
            ->where('siswa_id', $this->ahmadId)
            ->where('mata_pelajaran_id', $this->ganjilSubjectId)
            ->where('tahun_ajaran_id', $this->ganjilYearId)
            ->value('nilai_akhir_rapor');

        $this->actingAsPengajar($tahunAjaranId, $semester)
            ->postJson(route('pengajar.score.save_scores', $subjectId), [
                'scores' => $payload,
            ])
            ->assertForbidden();

        $this->assertSame($existingCount, DB::table('nilais')->count());
        $this->assertEquals(
            $existingAhmadValue,
            DB::table('nilais')
                ->where('siswa_id', $this->ahmadId)
                ->where('mata_pelajaran_id', $this->ganjilSubjectId)
                ->where('tahun_ajaran_id', $this->ganjilYearId)
                ->value('nilai_akhir_rapor')
        );
    }

    private function scorePayloadForStudent(int $studentId, int $subjectId, int $score): array
    {
        $lingkupMateriId = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subjectId)
            ->value('id');
        $tujuanPembelajaranId = DB::table('tujuan_pembelajarans')
            ->where('lingkup_materi_id', $lingkupMateriId)
            ->value('id');

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

    private function createSchema(): void
    {
        foreach ([
            'notifications',
            'absensis',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'bobot_nilais',
            'kkms',
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
            $table->integer('nomor_kelas');
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
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
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
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('kelas_id');
            $table->integer('nilai')->default(70);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
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

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
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
        $this->ganjilYearId = $this->insertYear('2026/2027', 1, true);
        $this->genapYearId = $this->insertYear('2026/2027', 2, false);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->waliGanjilClassId = $this->insertClass(5, 'A', $this->ganjilYearId);
        $this->waliGenapClassId = $this->insertClass(5, 'A', $this->genapYearId);
        $this->otherClassId = $this->insertClass(5, 'B', $this->ganjilYearId);

        $waliId = $this->insertGuru('Guru Wali', 'wali');
        $pengajarId = $this->insertGuru('Guru Pengajar', 'pengajar');

        $this->attachWali($waliId, $this->waliGanjilClassId);
        $this->attachWali($waliId, $this->waliGenapClassId);
        $this->attachPengajar($pengajarId, $this->waliGanjilClassId);
        $this->attachPengajar($pengajarId, $this->waliGenapClassId);

        $this->ahmadId = $this->insertStudent('1001', 'Ahmad Fauzan', $this->waliGanjilClassId);
        $this->sitiId = $this->insertStudent('1002', 'Siti Aisyah', $this->waliGanjilClassId);
        $this->legacyId = $this->insertStudent('1003', 'Legacy Matching Student', $this->waliGanjilClassId);
        $this->genapOnlyId = $this->insertStudent('1004', 'Genap Only Student', $this->waliGanjilClassId);
        $this->otherClassStudentId = $this->insertStudent('1005', 'Other Class Student', $this->otherClassId);

        $this->insertEnrollment($this->ahmadId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->sitiId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->genapOnlyId, $this->waliGenapClassId, $this->genapYearId, 2);
        $this->insertEnrollment($this->otherClassStudentId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->ganjilSubjectId = $this->insertSubject('Matematika Ganjil', $pengajarId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->genapSubjectId = $this->insertSubject('Matematika Genap', $pengajarId, $this->waliGenapClassId, $this->genapYearId, 2);

        $ganjilLmId = $this->insertLearningData($this->ganjilSubjectId);
        $this->insertLearningData($this->genapSubjectId);

        DB::table('nilais')->insert([
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'lingkup_materi_id' => $ganjilLmId,
            'nilai_lm' => 88,
            'nilai_akhir_rapor' => 88,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('absensis')->insert([
            'siswa_id' => $this->ahmadId,
            'semester' => 1,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'sakit' => 0,
            'izin' => 0,
            'tanpa_keterangan' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bobot_nilais')->insert([
            ['tahun_ajaran_id' => $this->ganjilYearId, 'bobot_tp' => 1, 'bobot_lm' => 1, 'bobot_as' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['tahun_ajaran_id' => $this->genapYearId, 'bobot_tp' => 1, 'bobot_lm' => 1, 'bobot_as' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('kkms')->insert([
            ['mata_pelajaran_id' => $this->ganjilSubjectId, 'kelas_id' => $this->waliGanjilClassId, 'nilai' => 70, 'tahun_ajaran_id' => $this->ganjilYearId, 'created_at' => now(), 'updated_at' => now()],
            ['mata_pelajaran_id' => $this->genapSubjectId, 'kelas_id' => $this->waliGenapClassId, 'nilai' => 70, 'tahun_ajaran_id' => $this->genapYearId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->wali = Guru::findOrFail($waliId);
        $this->pengajar = Guru::findOrFail($pengajarId);
    }

    private function insertYear(string $year, int $semester, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $year,
            'is_active' => $active,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name, int $yearId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGuru(string $name, string $username): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'email' => "{$username}@example.test",
            'username' => $username,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachWali(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachPengajar(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $name, int $kelasId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => "{$nis}000",
            'nama' => $name,
            'jenis_kelamin' => 'L',
            'kelas_id' => $kelasId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $kelasId, int $yearId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $guruId, int $kelasId, int $yearId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'semester' => $semester,
            'tahun_ajaran_id' => $yearId,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLearningData(int $subjectId): int
    {
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tujuan_pembelajarans')->insert([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => '1',
            'deskripsi_tp' => 'Memahami bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lmId;
    }
}
