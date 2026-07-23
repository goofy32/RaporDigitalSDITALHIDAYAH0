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

class GenerateInitialTestScoresTest extends TestCase
{
    private int $activeYearId;

    private int $inactiveYearId;

    private int $activeClassId;

    private int $otherClassId;

    private int $guruId;

    private int $activeSubjectId;

    private int $inactiveSubjectId;

    private int $missingLearningSubjectId;

    private int $studentId;

    private int $otherStudentId;

    private int $unenrolledStudentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

    public function test_command_creates_completed_scores_for_enrolled_students_only(): void
    {
        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        $this->assertSame(9, $this->scoreCountFor($this->studentId, $this->activeSubjectId));
        $this->assertSame(9, $this->scoreCountFor($this->otherStudentId, $this->activeSubjectId));
        $this->assertSame(0, $this->scoreCountFor($this->unenrolledStudentId, $this->activeSubjectId));

        $aggregate = DB::table('nilais')
            ->where('siswa_id', $this->studentId)
            ->where('mata_pelajaran_id', $this->activeSubjectId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->first();

        $this->assertNotNull($aggregate);
        $this->assertNotNull($aggregate->nilai_akhir_rapor);
        $this->assertSame(1, (int) $aggregate->is_submitted);
        $this->assertSame($this->activeYearId, (int) $aggregate->tahun_ajaran_id);
    }

    public function test_command_creates_tp_level_rows_with_nilai_tp(): void
    {
        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        $this->assertSame(6, DB::table('nilais')
            ->where('siswa_id', $this->studentId)
            ->where('mata_pelajaran_id', $this->activeSubjectId)
            ->whereNotNull('tujuan_pembelajaran_id')
            ->whereNotNull('nilai_tp')
            ->count());
    }

    public function test_command_uses_active_year_and_semester_only(): void
    {
        $genapSubjectId = $this->insertSubject('Mtk Genap', $this->guruId, $this->activeClassId, $this->activeYearId, 2);
        $this->insertLearningData($genapSubjectId);

        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $this->inactiveSubjectId)->count());
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $genapSubjectId)->count());
    }

    public function test_command_is_rerunnable_without_duplicate_scores(): void
    {
        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);
        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        $this->assertSame(18, DB::table('nilais')->where('mata_pelajaran_id', $this->activeSubjectId)->count());

        $duplicates = DB::table('nilais')
            ->select(
                'siswa_id',
                'mata_pelajaran_id',
                'lingkup_materi_id',
                'tujuan_pembelajaran_id',
                'tahun_ajaran_id',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('siswa_id', 'mata_pelajaran_id', 'lingkup_materi_id', 'tujuan_pembelajaran_id', 'tahun_ajaran_id')
            ->having('total', '>', 1)
            ->count();

        $this->assertSame(0, $duplicates);
    }

    public function test_subjects_missing_lm_tp_are_skipped_and_reported(): void
    {
        $this->artisan('initial-data:generate-test-scores', ['--subject-id' => $this->missingLearningSubjectId])
            ->expectsOutput("Subject #{$this->missingLearningSubjectId} skipped: LM/TP belum lengkap.")
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $this->missingLearningSubjectId)->count());
    }

    public function test_command_creates_no_supporting_data_or_s2_students(): void
    {
        $s2StudentId = $this->insertStudent('S2-2605001', 'S2-9000000001', 'Legacy S2 Student', $this->activeClassId);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $s2StudentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        foreach ([
            'absensis',
            'catatan_siswa',
            'catatan_mata_pelajaran',
            'capaian_kompetensi_custom',
            'nilai_ekstrakurikuler',
            'report_generations',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should stay empty.");
        }

        $this->assertSame(1, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
        $this->assertSame(0, DB::table('nilais')->where('siswa_id', $s2StudentId)->count());
    }

    public function test_pengajar_dashboard_progress_becomes_complete_after_generation(): void
    {
        DB::table('siswas')
            ->where('id', $this->unenrolledStudentId)
            ->update(['kelas_id' => $this->otherClassId]);

        $this->actingAs(Guru::findOrFail($this->guruId), 'guru')
            ->withSession($this->sessionForActivePengajar())
            ->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 0.0);

        $this->artisan('initial-data:generate-test-scores')
            ->assertExitCode(0);

        $this->actingAs(Guru::findOrFail($this->guruId), 'guru')
            ->withSession($this->sessionForActivePengajar())
            ->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 100.0);
    }

    public function test_command_fails_safely_without_active_year(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $this->artisan('initial-data:generate-test-scores')
            ->expectsOutput('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    private function createSchema(): void
    {
        foreach ([
            'report_generations',
            'nilai_ekstrakurikuler',
            'capaian_kompetensi_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'kkms',
            'siswa_kelas_semester',
            'siswas',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
            'guru_kelas',
            'kelas',
            'gurus',
            'profil_sekolah',
            'tahun_ajarans',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
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
            $table->text('deskripsi_tp');
            $table->timestamps();
            $table->softDeletes();
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
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
            $table->float('na_tp')->nullable();
            $table->float('na_lm')->nullable();
            $table->integer('tp_number')->nullable();
            $table->decimal('nilai_tes', 5, 2)->nullable();
            $table->decimal('nilai_non_tes', 5, 2)->nullable();
            $table->decimal('na_sumatif_semester', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        foreach ([
            'absensis',
            'catatan_siswa',
            'catatan_mata_pelajaran',
            'capaian_kompetensi_custom',
            'nilai_ekstrakurikuler',
            'report_generations',
        ] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }
    }

    private function seedFixture(): void
    {
        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->inactiveYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Test',
            'username' => 'guru-test',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherGuruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Lain',
            'username' => 'guru-lain',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->activeClassId = $this->insertClass(1, 'Ubay', $this->activeYearId);
        $this->otherClassId = $this->insertClass(1, 'Zaid', $this->activeYearId);
        $inactiveClassId = $this->insertClass(1, 'Inactive', $this->inactiveYearId);

        DB::table('guru_kelas')->insert([
            'guru_id' => $this->guruId,
            'kelas_id' => $this->activeClassId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activeSubjectId = $this->insertSubject('Mtk', $this->guruId, $this->activeClassId, $this->activeYearId, 1);
        $this->inactiveSubjectId = $this->insertSubject('Mtk Inactive', $this->guruId, $inactiveClassId, $this->inactiveYearId, 1);
        $this->missingLearningSubjectId = $this->insertSubject('No LM', $otherGuruId, $this->activeClassId, $this->activeYearId, 1);
        $this->insertLearningData($this->activeSubjectId);
        $this->insertLearningData($this->inactiveSubjectId);

        $this->studentId = $this->insertStudent('2601001', '9000000001', 'Enrolled One', $this->activeClassId);
        $this->otherStudentId = $this->insertStudent('2601002', '9000000002', 'Enrolled Two', $this->activeClassId);
        $this->unenrolledStudentId = $this->insertStudent('2601999', '9000000999', 'Legacy Only', $this->activeClassId);

        foreach ([$this->studentId, $this->otherStudentId] as $studentId) {
            DB::table('siswa_kelas_semester')->insert([
                'siswa_id' => $studentId,
                'kelas_id' => $this->activeClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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

    private function insertSubject(string $name, int $guruId, int $classId, int $yearId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'guru_id' => $guruId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLearningData(int $subjectId): void
    {
        for ($lm = 1; $lm <= 2; $lm++) {
            $lmId = DB::table('lingkup_materis')->insertGetId([
                'mata_pelajaran_id' => $subjectId,
                'judul_lingkup_materi' => "LM {$lm}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($tp = 1; $tp <= 3; $tp++) {
                DB::table('tujuan_pembelajarans')->insert([
                    'lingkup_materi_id' => $lmId,
                    'kode_tp' => "TP{$lm}.{$tp}",
                    'deskripsi_tp' => "Tujuan {$lm}.{$tp}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function insertStudent(string $nis, string $nisn, string $name, int $classId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'kelas_id' => $classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scoreCountFor(int $studentId, int $subjectId): int
    {
        return DB::table('nilais')
            ->where('siswa_id', $studentId)
            ->where('mata_pelajaran_id', $subjectId)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionForActivePengajar(): array
    {
        return [
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'selected_role' => 'pengajar',
            'user_type' => 'guru',
        ];
    }
}
