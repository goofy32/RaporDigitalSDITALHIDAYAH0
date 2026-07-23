<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminStudentListEnrollmentTest extends TestCase
{
    private User $admin;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $otherYearId;

    private int $ganjilClassId;

    private int $genapClassId;

    private int $otherClassId;

    private int $ahmadId;

    private int $dimasId;

    private int $otherYearStudentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();
        Event::fake();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_admin_student_list_shows_active_semester_enrollment_students(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->genapYearId])
            ->get(route('student'));

        $response->assertOk();
        $response->assertSee('Ahmad Fauzan');
        $response->assertSeeText('Kelas 5 Genap');
        $response->assertDontSee('Dimas Ganjil');
        $response->assertDontSee('Other Year Student');
    }

    public function test_admin_student_list_does_not_require_legacy_kelas_id_to_match_active_class(): void
    {
        $this->assertSame($this->ganjilClassId, (int) DB::table('siswas')->where('id', $this->ahmadId)->value('kelas_id'));

        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->genapYearId])
            ->get(route('student', ['search' => 'Ahmad']));

        $response->assertOk();
        $response->assertSee('Ahmad Fauzan');
        $response->assertSeeText('Kelas 5 Genap');
    }

    public function test_admin_student_list_context_filter_excludes_other_semesters_and_years(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->genapYearId])
            ->get(route('student', ['search' => 'kelas 5']));

        $response->assertOk();
        $response->assertSee('Ahmad Fauzan');
        $response->assertDontSee('Dimas Ganjil');
        $response->assertDontSee('Other Year Student');
    }

    private function createSchema(): void
    {
        foreach (['siswa_kelas_semester', 'siswas', 'kelas', 'profil_sekolah', 'tahun_ajarans', 'users'] as $table) {
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester']);
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

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ganjilYearId = $this->insertYear('2026/2027', 1, false);
        $this->genapYearId = $this->insertYear('2026/2027', 2, true);
        $this->otherYearId = $this->insertYear('2025/2026', 2, false);

        $this->ganjilClassId = $this->insertClass('5', 'Ganjil', $this->ganjilYearId);
        $this->genapClassId = $this->insertClass('5', 'Genap', $this->genapYearId);
        $this->otherClassId = $this->insertClass('5', 'Other', $this->otherYearId);

        $this->ahmadId = $this->insertStudent('2605001', '9000000001', 'Ahmad Fauzan', $this->ganjilClassId);
        $this->dimasId = $this->insertStudent('2605002', '9000000002', 'Dimas Ganjil', $this->ganjilClassId);
        $this->otherYearStudentId = $this->insertStudent('2605003', '9000000003', 'Other Year Student', $this->otherClassId);

        $this->insertEnrollment($this->ahmadId, $this->genapClassId, $this->genapYearId, 2);
        $this->insertEnrollment($this->dimasId, $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->otherYearStudentId, $this->otherClassId, $this->otherYearId, 2);
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
            'tahun_ajaran_id' => $this->ganjilYearId,
            'status' => 'aktif',
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
