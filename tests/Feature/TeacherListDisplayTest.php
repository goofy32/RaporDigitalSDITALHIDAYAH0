<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherListDisplayTest extends TestCase
{
    private User $admin;

    private int $yearId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

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

    public function test_teacher_list_separates_wali_and_pengajar_without_duplicate_class_dump(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Wali Kelas:')
            ->assertSee('Mengajar:')
            ->assertSee('Kelas 5A')
            ->assertSee('1 mapel di Kelas 5A')
            ->assertDontSee('Kelas 5A, Kelas 5A')
            ->assertDontSee('Kelas 5A, Kelas 5A (Wali Kelas)')
            ->assertDontSee('Matematika - Kelas 5A')
            ->assertDontSee('Matematika - 5a')
            ->assertDontSee('Kelas 5 a');
    }

    public function test_teacher_list_shows_pengajar_class_assignment_before_subject_exists(): void
    {
        $kelas5BId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'b',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Yusuf Hidayat',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1988-04-12',
            'no_handphone' => '081200000002',
            'email' => 'yusuf@example.test',
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru',
            'username' => 'yusuf',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelas5BId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Yusuf Hidayat')
            ->assertSee('Mengajar:')
            ->assertSee('Kelas 5B')
            ->assertDontSee('Kelas 5 b');
    }

    public function test_teacher_detail_uses_consistent_class_labels(): void
    {
        $guruId = (int) DB::table('gurus')->where('username', 'budi')->value('id');

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher.show', $guruId))
            ->assertOk()
            ->assertSee('Kelas 5A')
            ->assertSee('Matematika')
            ->assertDontSee('Matematika - Kelas 5A')
            ->assertDontSee('Matematika - 5a')
            ->assertDontSee('Kelas 5 a');
    }

    public function test_teacher_responsibility_display_does_not_use_class_suffix_as_subject_label(): void
    {
        $kelas5BId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'b',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Yusuf Hidayat',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1988-04-12',
            'no_handphone' => '081200000002',
            'email' => 'yusuf.suffix@example.test',
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru',
            'username' => 'yusuf_suffix',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelas5BId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'b',
            'kelas_id' => $kelas5BId,
            'guru_id' => $guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Yusuf Hidayat')
            ->assertSee('Mengajar:')
            ->assertSee('Kelas 5B')
            ->assertDontSee('b - Kelas 5B');

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher.show', $guruId))
            ->assertOk()
            ->assertSee('Kelas 5B')
            ->assertDontSee('b - Kelas 5B');
    }

    public function test_class_label_formats_single_letters_and_named_classes(): void
    {
        $kelas1AId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 1,
            'nama_kelas' => 'a',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kelas2NameId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 2,
            'nama_kelas' => 'ABU UBAIDAH',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kelas5NameId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'ali bin abi thalib',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('Kelas 1A', \App\Models\Kelas::findOrFail($kelas1AId)->label_kelas);
        $this->assertSame('Kelas 2 Abu Ubaidah', \App\Models\Kelas::findOrFail($kelas2NameId)->label_kelas);
        $this->assertSame('Kelas 5 Ali bin Abi Thalib', \App\Models\Kelas::findOrFail($kelas5NameId)->label_kelas);
    }

    public function test_teacher_index_groups_responsibilities_compactly_by_class(): void
    {
        $classId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 2,
            'nama_kelas' => 'ABU UBAIDAH',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 1,
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $guruId = $this->insertGuru('Guru Ringkas', 'guru_ringkas');

        $this->attachGuruClass($guruId, $classId, true, 'wali_kelas');
        $this->attachGuruClass($guruId, $classId, false, 'pengajar');
        $this->attachGuruClass($guruId, $secondClassId, false, 'pengajar');

        foreach (['B.Indonesia', 'Bahasa sunda', 'Mtk', 'IPAS', 'PLH', 'Seni Budaya'] as $subject) {
            $this->insertSubject($subject, $guruId, $classId);
        }
        foreach (['PAI', 'PJOK'] as $subject) {
            $this->insertSubject($subject, $guruId, $secondClassId);
        }

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher'))
            ->assertOk()
            ->assertSee('Wali Kelas:')
            ->assertSee('Kelas 2 Abu Ubaidah')
            ->assertSee('Kelas 1 Ubay: 2 mapel')
            ->assertSee('Kelas 2 Abu Ubaidah: 6 mapel')
            ->assertDontSee('B.Indonesia - Kelas 2 Abu Ubaidah, Bahasa sunda - Kelas 2 Abu Ubaidah');
    }

    public function test_teacher_detail_shows_grouped_subject_list(): void
    {
        $classId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 2,
            'nama_kelas' => 'ABU UBAIDAH',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $guruId = $this->insertGuru('Guru Detail', 'guru_detail');
        $this->attachGuruClass($guruId, $classId, false, 'pengajar');
        $this->insertSubject('B.Indonesia', $guruId, $classId);
        $this->insertSubject('Bahasa sunda', $guruId, $classId);
        $this->insertSubject('Mtk', $guruId, $classId);

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->yearId, 'selected_semester' => 1])
            ->get(route('teacher.show', $guruId))
            ->assertOk()
            ->assertSeeInOrder(['Kelas 2 Abu Ubaidah', 'B.Indonesia', 'Bahasa sunda', 'Mtk'])
            ->assertDontSee('B.Indonesia - Kelas 2 Abu Ubaidah');
    }

    private function insertGuru(string $name, string $username): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1988-04-12',
            'no_handphone' => '081200000003',
            'email' => "{$username}@example.test",
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru_wali',
            'username' => $username,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachGuruClass(int $guruId, int $classId, bool $isWaliKelas, string $role): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $classId,
            'is_wali_kelas' => $isWaliKelas,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $guruId, int $classId): void
    {
        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'mata_pelajarans',
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
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->string('password_plain')->nullable();
            $table->string('photo')->nullable();
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
    }

    private function seedFixture(): void
    {
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Demo Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = User::findOrFail($adminId);

        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kelasId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'a',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guruId = DB::table('gurus')->insertGetId([
            'nuptk' => '900000001',
            'nama' => 'Budi Santoso',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1988-04-12',
            'no_handphone' => '081200000001',
            'email' => 'budi@example.test',
            'alamat' => 'Jl. Demo',
            'jabatan' => 'guru_wali',
            'username' => 'budi',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $guruId,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $guruId,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
