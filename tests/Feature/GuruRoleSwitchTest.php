<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Models\Guru;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuruRoleSwitchTest extends TestCase
{
    private Guru $multiRoleGuru;

    private Guru $pengajarOnlyGuru;

    private int $tahunAjaranId;

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

    public function test_valid_pengajar_switch_redirects_to_pengajar_dashboard(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'wali_kelas',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('auth.switch.role', ['role' => 'pengajar']))
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('selected_role', 'pengajar');
    }

    public function test_valid_wali_switch_redirects_to_wali_dashboard(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('auth.switch.role', ['role' => 'wali_kelas']))
            ->assertRedirect(route('wali_kelas.dashboard'))
            ->assertSessionHas('selected_role', 'wali_kelas');
    }

    public function test_invalid_role_switch_is_denied(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('auth.switch.role', ['role' => 'admin']))
            ->assertForbidden();
    }

    public function test_guru_without_active_year_wali_assignment_cannot_switch_to_wali(): void
    {
        $this->actingAs($this->pengajarOnlyGuru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('auth.switch.role', ['role' => 'wali_kelas']))
            ->assertForbidden();
    }

    public function test_pengajar_sidebar_no_longer_renders_current_role_box(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'pengajar',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $html = view('components.pengajar.sidebar')->render();

        $this->assertStringNotContainsString('PERAN SAAT INI', $html);
        $this->assertStringNotContainsString('Beralih ke Wali Kelas', $html);
    }

    public function test_wali_sidebar_no_longer_renders_current_role_box(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $html = view('components.wali-kelas.sidebar')->render();

        $this->assertStringNotContainsString('PERAN SAAT INI', $html);
        $this->assertStringNotContainsString('Beralih ke Pengajar', $html);
    }

    public function test_profile_dropdown_role_switching_remains_available(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'pengajar',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $html = view('components.admin.topbar')->render();

        $this->assertStringContainsString('Beralih ke Wali Kelas', $html);
        $this->assertStringContainsString(route('auth.switch.role', ['role' => 'wali_kelas']), $html);
    }

    public function test_role_mismatch_warning_offers_valid_role_switch_without_useless_back_button(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $response = $this->runRoleMiddleware('pengajar');
        $content = $response->getContent();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Akses halaman ini membutuhkan role Pengajar.', $content);
        $this->assertStringContainsString('Pilih Role Pengajar', $content);
        $this->assertStringContainsString(route('auth.switch.role', ['role' => 'pengajar']), $content);
        $this->assertStringContainsString('Kembali ke Dashboard', $content);
        $this->assertStringNotContainsString('>Kembali</a>', $content);
        $this->assertStringNotContainsString('logout terlebih dahulu', $content);
    }

    public function test_role_mismatch_warning_explains_missing_role_access_without_switch_link(): void
    {
        DB::table('guru_kelas')
            ->where('guru_id', $this->multiRoleGuru->id)
            ->where('role', 'pengajar')
            ->delete();

        DB::table('mata_pelajarans')
            ->where('guru_id', $this->multiRoleGuru->id)
            ->update(['guru_id' => null]);

        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $response = $this->runRoleMiddleware('pengajar');
        $content = $response->getContent();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Akun Anda belum memiliki akses sebagai Pengajar untuk halaman ini. Hubungi admin sekolah jika akses ini diperlukan.', $content);
        $this->assertStringNotContainsString('Pilih Role Pengajar', $content);
        $this->assertStringNotContainsString(route('auth.switch.role', ['role' => 'pengajar']), $content);
        $this->assertStringNotContainsString('>Kembali</a>', $content);
    }

    public function test_revoked_selected_pengajar_role_is_rejected_on_next_protected_request(): void
    {
        DB::table('guru_kelas')
            ->where('guru_id', $this->multiRoleGuru->id)
            ->where('role', 'pengajar')
            ->delete();

        DB::table('mata_pelajarans')
            ->where('guru_id', $this->multiRoleGuru->id)
            ->update(['guru_id' => null]);

        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'pengajar',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $response = $this->runRoleMiddleware('pengajar');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(session()->has('selected_role'));
    }

    public function test_revoked_selected_wali_role_is_rejected_on_next_protected_request(): void
    {
        DB::table('guru_kelas')
            ->where('guru_id', $this->multiRoleGuru->id)
            ->where('role', 'wali_kelas')
            ->delete();

        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $response = $this->runRoleMiddleware('wali_kelas');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(session()->has('selected_role'));
    }

    public function test_soft_deleted_guru_is_blocked_on_next_protected_request(): void
    {
        $this->multiRoleGuru->forceFill(['deleted_at' => now()]);

        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'pengajar',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $response = $this->runRoleMiddleware('pengajar');

        $this->assertTrue($response->isRedirect(route('login')));
        $this->assertSame('Akun guru sudah tidak aktif. Silakan hubungi admin.', session('error'));
        $this->assertFalse(session()->has('selected_role'));
    }

    private function createSchema(): void
    {
        foreach ([
            'nilais',
            'kkms',
            'mata_pelajarans',
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

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedFixture(): void
    {
        $this->tahunAjaranId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kelasId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $multiRoleGuruId = $this->insertGuru('Guru Budi', 'budi');
        $pengajarOnlyGuruId = $this->insertGuru('Guru Ani', 'ani');

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $multiRoleGuruId,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $multiRoleGuruId,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $pengajarOnlyGuruId,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            [
                'nama_pelajaran' => 'Matematika',
                'kelas_id' => $kelasId,
                'guru_id' => $multiRoleGuruId,
                'semester' => 1,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nama_pelajaran' => 'Bahasa Indonesia',
                'kelas_id' => $kelasId,
                'guru_id' => $pengajarOnlyGuruId,
                'semester' => 1,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->multiRoleGuru = Guru::findOrFail($multiRoleGuruId);
        $this->pengajarOnlyGuru = Guru::findOrFail($pengajarOnlyGuruId);
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

    private function runRoleMiddleware(string $role)
    {
        $request = Request::create('/_test/protected-role', 'GET');
        $request->setLaravelSession(app('session.store'));

        return app(CheckRole::class)->handle($request, fn () => response('ok'), $role);
    }
}
