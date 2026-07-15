<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LiveListSearchFilterTest extends TestCase
{
    private User $admin;

    private Guru $wali;

    private int $yearId;

    private int $ubayClassId;

    private int $zaidClassId;

    private int $ahmadId;

    private int $sitiId;

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

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_server_side_search_still_works_without_javascript(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('student', ['search' => 'Ahmad']))
            ->assertOk()
            ->assertSee('Ahmad Fauzan')
            ->assertDontSee('Siti Aisyah');
    }

    public function test_filter_button_is_visible_on_all_live_list_pages(): void
    {
        foreach ([
            route('kelas.index'),
            route('teacher'),
            route('student'),
            route('subject.index'),
            route('ekstra.index'),
            route('achievement.index'),
        ] as $url) {
            $this->actingAs($this->admin, 'web')
                ->withSession($this->adminSession())
                ->get($url)
                ->assertOk()
                ->assertSee('data-live-filter-button', false)
                ->assertSee('Filter');
        }

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->get(route('wali_kelas.student.index'))
            ->assertOk()
            ->assertSee('data-live-filter-button', false)
            ->assertSee('Filter');
    }

    public function test_ajax_search_returns_filtered_fragments_for_each_list_page(): void
    {
        $this->assertLiveHtmlContains(route('kelas.index', ['search' => 'Ubay']), 'Kelas 1 Ubay', 'Kelas 1 Zaid');
        $this->assertLiveHtmlContains(route('teacher', ['search' => 'Budi']), 'Budi Pengajar', 'Sari Pengajar');
        $this->assertLiveHtmlContains(route('student', ['search' => 'Ahmad']), 'Ahmad Fauzan', 'Siti Aisyah');
        $this->assertLiveHtmlContains(route('subject.index', ['search' => 'Matematika']), 'Matematika', 'Bahasa Indonesia');
        $this->assertLiveHtmlContains(route('ekstra.index', ['search' => 'Pramuka']), 'Pramuka', 'Memanah');
        $this->assertLiveHtmlContains(route('achievement.index', ['search' => 'Tahfidz']), 'Tahfidz', 'Sains');

        $response = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson(route('wali_kelas.student.index', ['search' => 'Ahmad']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk()->assertJsonStructure(['html']);
        $this->assertStringContainsString('Ahmad Fauzan', $response->json('html'));
        $this->assertStringNotContainsString('Siti Aisyah', $response->json('html'));
    }

    public function test_filters_apply_for_major_live_list_pages(): void
    {
        $this->assertLiveHtmlContains(route('student', [
            'kelas_id' => $this->ubayClassId,
            'jenis_kelamin' => 'Laki-laki',
        ]), 'Ahmad Fauzan', 'Siti Aisyah');

        $this->assertLiveHtmlContains(route('teacher', [
            'wali_status' => 'wali',
        ]), 'Budi Pengajar', 'Sari Pengajar');

        $this->assertLiveHtmlContains(route('subject.index', [
            'tp_status' => 'lengkap',
        ]), 'Matematika', 'Bahasa Indonesia');

        $response = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson(route('wali_kelas.student.index', ['catatan' => 'ada']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('Ahmad Fauzan', $response->json('html'));
        $this->assertStringNotContainsString('Siti Aisyah', $response->json('html'));
    }

    public function test_ajax_pagination_preserves_search_and_filter_query(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->insertStudent("27010{$i}", "91000010{$i}", "Siswa Tambahan {$i}", 'Laki-laki', $this->ubayClassId);
        }

        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson(route('student', [
                'search' => 'Siswa',
                'jenis_kelamin' => 'Laki-laki',
            ]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();

        $html = $response->json('html');

        $this->assertStringContainsString('search=Siswa', $html);
        $this->assertStringContainsString('jenis_kelamin=Laki-laki', $html);
    }

    public function test_reset_filter_url_restores_unfiltered_list(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('student'));

        $response->assertOk()
            ->assertSee('Reset Filter')
            ->assertSee(route('student'), false);
    }

    public function test_unauthorized_users_cannot_access_live_list_fragments(): void
    {
        $this->getJson(route('student', ['search' => 'Ahmad']), [
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertUnauthorized();

        $this->actingAs($this->admin, 'web')
            ->getJson(route('wali_kelas.student.index', ['search' => 'Ahmad']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertUnauthorized();
    }

    private function assertLiveHtmlContains(string $url, string $expected, string $unexpected): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson($url, [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk()->assertJsonStructure(['html']);

        $html = $response->json('html');

        $this->assertStringContainsString($expected, $html);
        $this->assertStringNotContainsString($unexpected, $html);
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
        ];
    }

    private function waliSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'selected_role' => 'wali_kelas',
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'catatan_siswa',
            'audit_logs',
            'prestasis',
            'ekstrakurikulers',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
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
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
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
            $table->boolean('must_change_password')->default(false);
            $table->string('photo')->nullable();
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
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('photo')->nullable();
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
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->string('pembina');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id');
            $table->foreignId('siswa_id');
            $table->string('jenis_prestasi');
            $table->text('keterangan')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->text('catatan')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->string('type')->default('umum');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ubayClassId = $this->insertClass(1, 'Ubay');
        $this->zaidClassId = $this->insertClass(1, 'Zaid');

        $this->wali = Guru::create([
            'nama' => 'Budi Pengajar',
            'jenis_kelamin' => 'Laki-laki',
            'jabatan' => 'guru_wali',
            'username' => 'budi',
            'password' => Hash::make('password'),
        ]);

        $sariId = DB::table('gurus')->insertGetId([
            'nama' => 'Sari Pengajar',
            'jenis_kelamin' => 'Perempuan',
            'jabatan' => 'guru',
            'username' => 'sari',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $this->wali->id,
                'kelas_id' => $this->ubayClassId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $this->wali->id,
                'kelas_id' => $this->ubayClassId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $sariId,
                'kelas_id' => $this->zaidClassId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->ahmadId = $this->insertStudent('2701001', '9100000001', 'Ahmad Fauzan', 'Laki-laki', $this->ubayClassId);
        $this->sitiId = $this->insertStudent('2701002', '9100000002', 'Siti Aisyah', 'Perempuan', $this->ubayClassId);
        $bimaId = $this->insertStudent('2701003', '9100000003', 'Bima Zaid', 'Laki-laki', $this->zaidClassId);

        $mathId = $this->insertSubject('Matematika', $this->wali->id, $this->ubayClassId);
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $mathId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tujuan_pembelajarans')->insert([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => 'TP 1',
            'deskripsi_tp' => 'Mengenal bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertSubject('Bahasa Indonesia', $sariId, $this->zaidClassId);

        DB::table('ekstrakurikulers')->insert([
            [
                'nama_ekstrakurikuler' => 'Pramuka',
                'pembina' => 'Budi Pengajar',
                'tahun_ajaran_id' => $this->yearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nama_ekstrakurikuler' => 'Memanah',
                'pembina' => 'Sari Pengajar',
                'tahun_ajaran_id' => $this->yearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('prestasis')->insert([
            [
                'kelas_id' => $this->ubayClassId,
                'siswa_id' => $this->ahmadId,
                'jenis_prestasi' => 'Tahfidz',
                'keterangan' => 'Juara Tahfidz',
                'tahun_ajaran_id' => $this->yearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'kelas_id' => $this->ubayClassId,
                'siswa_id' => $this->sitiId,
                'jenis_prestasi' => 'Sains',
                'keterangan' => 'Juara Sains',
                'tahun_ajaran_id' => $this->yearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('catatan_siswa')->insert([
            'siswa_id' => $this->ahmadId,
            'catatan' => 'Catatan Ahmad',
            'tahun_ajaran_id' => $this->yearId,
            'semester' => 1,
            'type' => 'umum',
            'created_by' => $this->wali->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $nisn, string $name, string $gender, int $classId): int
    {
        $studentId = DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'jenis_kelamin' => $gender,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $this->yearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $studentId;
    }

    private function insertSubject(string $name, int $guruId, int $classId): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
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
}
