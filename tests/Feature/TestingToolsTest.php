<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Nilai;
use App\Models\ProfilSekolah;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TestingToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
    }

    public function test_route_is_hidden_when_staging_testing_flag_is_disabled(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertNotFound();
    }

    public function test_route_is_accessible_to_admin_when_enabled(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertOk()
            ->assertSee('Simulasi Multi-User Guru')
            ->assertSee('Gunakan hanya di staging. Jangan jalankan saat guru sedang testing nyata.');
    }

    public function test_guest_and_teacher_cannot_access_the_admin_testing_tool(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => true]);

        $this->get(route('admin.testing.multi-user.index'))
            ->assertRedirect(route('login'));

        $guru = Guru::find($this->createGuru());

        $this->actingAs($guru, 'guru')
            ->get(route('admin.testing.multi-user.index'))
            ->assertRedirect(route('login'));
    }

    public function test_pdf_simulation_validates_the_max_request_count(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.pdf'), [
                'action' => 'preview',
                'report_type' => 'UTS',
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 21,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_count');
    }

    public function test_score_simulation_requires_explicit_confirmation(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.score'), [
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'mata_pelajaran_id' => $context['mata_pelajaran_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 1,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_score_simulation_refuses_non_dummy_data(): void
    {
        $context = $this->realLookingContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.score'), [
                'confirmation' => config('staging_test_tools.score_confirmation'),
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'mata_pelajaran_id' => $context['mata_pelajaran_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 1,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Simulasi hanya boleh memakai kelas dan siswa dummy/test/simulasi.');
    }

    public function test_repeated_dummy_score_simulation_updates_without_duplicate_rows(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $admin = User::factory()->create();
        $payload = [
            'confirmation' => config('staging_test_tools.score_confirmation'),
            'tahun_ajaran_id' => $context['tahun_ajaran_id'],
            'kelas_id' => $context['kelas_id'],
            'mata_pelajaran_id' => $context['mata_pelajaran_id'],
            'student_id' => $context['siswa_id'],
            'request_count' => 2,
        ];

        $this->actingAs($admin)
            ->postJson(route('admin.testing.multi-user.score'), $payload + ['request_index' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        $this->actingAs($admin)
            ->postJson(route('admin.testing.multi-user.score'), $payload + ['request_index' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        $this->assertSame(1, Nilai::query()
            ->where('siswa_id', $context['siswa_id'])
            ->where('mata_pelajaran_id', $context['mata_pelajaran_id'])
            ->where('tahun_ajaran_id', $context['tahun_ajaran_id'])
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->count());
    }

    public function test_testing_tool_is_not_exposed_when_production_default_is_disabled(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'production',
            'staging_test_tools.enabled' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertNotFound();
    }

    private function basicSetup(): int
    {
        ProfilSekolah::create([
            'nama_instansi' => 'Yayasan Test',
            'nama_sekolah' => 'SD Test',
            'tahun_pelajaran' => '2025/2026',
            'semester' => 1,
            'npsn' => '12345678',
            'kepala_sekolah' => 'Kepala Test',
            'alamat' => 'Jl. Test',
            'guru_kelas' => 1,
            'kode_pos' => '12345',
            'kelas' => 1,
            'telepon' => '021',
            'jumlah_siswa' => 1,
        ]);

        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => true,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dummyContext(): array
    {
        return $this->createContext(
            className: 'Simulasi Test',
            subjectName: 'Mapel Simulasi Test',
            studentName: 'Siswa Dummy Test',
            nis: 'TEST-001',
            nisn: 'TESTN-001'
        );
    }

    private function realLookingContext(): array
    {
        return $this->createContext(
            className: 'A',
            subjectName: 'Matematika',
            studentName: 'Ahmad Fulan',
            nis: '1001',
            nisn: '2001'
        );
    }

    private function createContext(string $className, string $subjectName, string $studentName, string $nis, string $nisn): array
    {
        $tahunAjaranId = $this->basicSetup();
        $kelasId = $this->createClass($tahunAjaranId, $className);
        $guruId = $this->createGuru($kelasId);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mataPelajaranId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $subjectName,
            'kelas_id' => $kelasId,
            'semester' => 1,
            'guru_id' => $guruId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siswaId = Siswa::query()->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $studentName,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat Test',
            'kelas_id' => $kelasId,
            'nama_ayah' => 'Ayah Test',
            'nama_ibu' => 'Ibu Test',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Alamat Orang Tua',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $siswaId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'tahun_ajaran_id' => $tahunAjaranId,
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'siswa_id' => $siswaId,
        ];
    }

    private function createClass(int $tahunAjaranId, string $namaKelas): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => $namaKelas,
            'wali_kelas' => 'Wali Test',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createGuru(?int $kelasId = null): int
    {
        $kelasId ??= $this->createClass($this->basicSetup(), 'Simulasi Test');

        return DB::table('gurus')->insertGetId([
            'nuptk' => 'NUPTK'.fake()->unique()->numerify('######'),
            'nama' => 'Guru Simulasi Test',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => '081234567890',
            'email' => fake()->unique()->safeEmail(),
            'alamat' => 'Alamat Guru',
            'jabatan' => 'Guru',
            'kelas_pengajar_id' => $kelasId,
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('logo')->nullable();
            $table->string('nama_instansi');
            $table->string('nama_sekolah');
            $table->string('tahun_pelajaran');
            $table->integer('semester');
            $table->string('npsn');
            $table->string('kepala_sekolah');
            $table->text('alamat');
            $table->integer('guru_kelas');
            $table->string('kode_pos');
            $table->integer('kelas');
            $table->string('telepon');
            $table->integer('jumlah_siswa');
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->integer('semester')->default(1);
            $table->string('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas')->nullable();
            $table->string('nama_kelas');
            $table->string('wali_kelas')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->unique();
            $table->string('nama');
            $table->string('jenis_kelamin');
            $table->date('tanggal_lahir');
            $table->string('no_handphone');
            $table->string('email')->unique();
            $table->text('alamat');
            $table->string('jabatan');
            $table->unsignedBigInteger('kelas_pengajar_id')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
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
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('guru_id')->nullable();
            $table->json('lingkup_materi')->nullable();
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
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
            $table->text('alamat_orangtua');
            $table->string('photo')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->integer('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('na_tp', 5, 2)->nullable();
            $table->decimal('na_lm', 5, 2)->nullable();
            $table->decimal('nilai_tes', 5, 2)->nullable();
            $table->decimal('nilai_non_tes', 5, 2)->nullable();
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
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

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}
