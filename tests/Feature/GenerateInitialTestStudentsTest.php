<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GenerateInitialTestStudentsTest extends TestCase
{
    private int $yearId;

    private int $kelasUbayId;

    private int $kelasZaidId;

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
        $this->seedActiveYearAndClasses();
    }

    public function test_command_creates_requested_students_and_enrollments_per_active_class(): void
    {
        $this->artisan('initial-data:generate-test-students', ['--per-class' => 20])
            ->assertExitCode(0);

        $this->assertSame(40, DB::table('siswas')->count());
        $this->assertSame(40, DB::table('siswa_kelas_semester')->count());

        foreach ([$this->kelasUbayId, $this->kelasZaidId] as $kelasId) {
            $this->assertSame(
                20,
                DB::table('siswa_kelas_semester')
                    ->where('kelas_id', $kelasId)
                    ->where('tahun_ajaran_id', $this->yearId)
                    ->where('semester', 1)
                    ->count()
            );
        }

        $this->assertDatabaseHas('siswas', [
            'nama' => 'Siswa 1 Ubay 01',
            'kelas_id' => $this->kelasUbayId,
            'status' => 'aktif',
            'photo' => null,
        ]);
        $this->assertDatabaseHas('siswas', [
            'nama' => 'Siswa 6 Zaid 20',
            'kelas_id' => $this->kelasZaidId,
            'jenis_kelamin' => 'Perempuan',
        ]);
    }

    public function test_command_is_rerunnable_without_duplicate_students_or_enrollments(): void
    {
        $this->artisan('initial-data:generate-test-students', ['--per-class' => 3])
            ->assertExitCode(0);
        $this->artisan('initial-data:generate-test-students', ['--per-class' => 3])
            ->assertExitCode(0);

        $this->assertSame(6, DB::table('siswas')->count());
        $this->assertSame(6, DB::table('siswa_kelas_semester')->count());

        $duplicates = DB::table('siswa_kelas_semester')
            ->select('siswa_id', 'tahun_ajaran_id', 'semester', DB::raw('COUNT(*) as total'))
            ->groupBy('siswa_id', 'tahun_ajaran_id', 'semester')
            ->having('total', '>', 1)
            ->count();

        $this->assertSame(0, $duplicates);
    }

    public function test_students_are_enrolled_in_active_year_semester_context_only(): void
    {
        $otherYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 2,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'Other',
            'tahun_ajaran_id' => $otherYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('initial-data:generate-test-students', ['--per-class' => 2])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $otherYearId)->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('kelas_id', $otherClassId)->count());
        $this->assertSame(4, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $this->yearId)->where('semester', 1)->count());
    }

    public function test_command_creates_no_student_specific_work_data_and_no_s2_students(): void
    {
        $this->artisan('initial-data:generate-test-students', ['--per-class' => 2])
            ->assertExitCode(0);

        foreach ([
            'nilais',
            'absensis',
            'catatan_siswa',
            'catatan_mata_pelajaran',
            'capaian_kompetensi_custom',
            'nilai_ekstrakurikuler',
            'report_generations',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should stay empty.");
        }

        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
    }

    public function test_command_does_not_mutate_existing_real_student_on_generated_identifier_collision(): void
    {
        DB::table('siswas')->insert([
            'nis' => '7026270001001',
            'nisn' => '8270001001',
            'nama' => 'Real Student Collision',
            'tanggal_lahir' => '2016-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat asli',
            'kelas_id' => $this->kelasUbayId,
            'nama_ayah' => 'Ayah',
            'nama_ibu' => 'Ibu',
            'pekerjaan_ayah' => 'Kerja',
            'pekerjaan_ibu' => 'Kerja',
            'alamat_orangtua' => 'Alamat asli',
            'tahun_ajaran_id' => $this->yearId,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('initial-data:generate-test-students', ['--per-class' => 1])
            ->assertExitCode(1);

        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertDatabaseHas('siswas', [
            'nis' => '7026270001001',
            'nisn' => '8270001001',
            'nama' => 'Real Student Collision',
            'alamat' => 'Alamat asli',
        ]);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_command_fails_safely_without_active_academic_year(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $this->artisan('initial-data:generate-test-students', ['--per-class' => 2])
            ->expectsOutput('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
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
            'siswa_kelas_semester',
            'siswas',
            'kelas',
            'tahun_ajarans',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
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
            $table->date('tanggal_lahir');
            $table->string('jenis_kelamin');
            $table->string('agama');
            $table->text('alamat');
            $table->foreignId('kelas_id');
            $table->string('nama_ayah');
            $table->string('nama_ibu');
            $table->string('pekerjaan_ayah');
            $table->string('pekerjaan_ibu');
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
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

        foreach ([
            'nilais',
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

    private function seedActiveYearAndClasses(): void
    {
        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->kelasUbayId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 1,
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->kelasZaidId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 6,
            'nama_kelas' => 'Zaid',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
