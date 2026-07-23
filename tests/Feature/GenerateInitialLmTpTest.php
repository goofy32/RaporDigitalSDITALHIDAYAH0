<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GenerateInitialLmTpTest extends TestCase
{
    private int $activeYearId;

    private int $inactiveYearId;

    private int $activeClassId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        $this->seedBaseData();
    }

    public function test_command_creates_two_lm_and_three_tp_per_lm_for_active_subjects(): void
    {
        $mtkId = $this->insertSubject('Mtk');
        $indonesiaId = $this->insertSubject('B.Indonesia');

        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);

        foreach ([$mtkId, $indonesiaId] as $subjectId) {
            $lmIds = DB::table('lingkup_materis')
                ->where('mata_pelajaran_id', $subjectId)
                ->pluck('id');

            $this->assertCount(2, $lmIds);

            foreach ($lmIds as $lmId) {
                $this->assertSame(3, DB::table('tujuan_pembelajarans')->where('lingkup_materi_id', $lmId)->count());
            }
        }

        $this->assertDatabaseHas('lingkup_materis', [
            'mata_pelajaran_id' => $mtkId,
            'judul_lingkup_materi' => 'Bilangan dan Operasi Hitung',
        ]);
        $this->assertDatabaseHas('tujuan_pembelajarans', [
            'kode_tp' => 'TP1.1',
            'deskripsi_tp' => 'Memahami nilai tempat dan urutan bilangan.',
        ]);
    }

    public function test_command_uses_active_academic_year_and_semester_only(): void
    {
        $activeSubjectId = $this->insertSubject('IPAS');
        $inactiveYearSubjectId = $this->insertSubject('IPAS', $this->inactiveYearId, 1);
        $otherSemesterSubjectId = $this->insertSubject('IPAS', $this->activeYearId, 2);

        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('lingkup_materis')->where('mata_pelajaran_id', $activeSubjectId)->count());
        $this->assertSame(0, DB::table('lingkup_materis')->where('mata_pelajaran_id', $inactiveYearSubjectId)->count());
        $this->assertSame(0, DB::table('lingkup_materis')->where('mata_pelajaran_id', $otherSemesterSubjectId)->count());
    }

    public function test_command_is_rerunnable_without_duplicate_lm_or_tp(): void
    {
        $subjectId = $this->insertSubject('PAI');

        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);
        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);

        $lmIds = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subjectId)
            ->pluck('id');

        $this->assertCount(2, $lmIds);
        $this->assertSame(6, DB::table('tujuan_pembelajarans')->whereIn('lingkup_materi_id', $lmIds)->count());
    }

    public function test_command_does_not_create_scores(): void
    {
        $this->insertSubject('PJOK');

        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('nilais')->count());
    }

    public function test_unknown_subject_uses_safe_generic_template(): void
    {
        $subjectId = $this->insertSubject('Robotika');

        $this->artisan('initial-data:generate-lm-tp')
            ->assertExitCode(0);

        $this->assertDatabaseHas('lingkup_materis', [
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Pemahaman Konsep Dasar',
        ]);
        $this->assertDatabaseHas('lingkup_materis', [
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Keterampilan dan Penerapan',
        ]);
        $this->assertSame(6, DB::table('tujuan_pembelajarans')
            ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->where('lingkup_materis.mata_pelajaran_id', $subjectId)
            ->count());
    }

    public function test_command_fails_safely_without_active_academic_year(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);
        $this->insertSubject('Mtk');

        $this->artisan('initial-data:generate-lm-tp')
            ->expectsOutput('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('lingkup_materis')->count());
        $this->assertSame(0, DB::table('tujuan_pembelajarans')->count());
    }

    private function createSchema(): void
    {
        foreach ([
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
            'kelas',
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

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
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

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedBaseData(): void
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

        $this->activeClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 1,
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, ?int $yearId = null, int $semester = 1): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $this->activeClassId,
            'guru_id' => null,
            'semester' => $semester,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $yearId ?: $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
