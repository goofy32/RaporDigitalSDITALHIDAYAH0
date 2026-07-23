<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolProfileRegressionTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

    private int $inactiveYearId;

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

    public function test_clean_database_admin_can_create_school_profile_without_academic_year(): void
    {
        DB::table('profil_sekolah')->delete();
        DB::table('tahun_ajarans')->delete();

        $response = $this->actingAs($this->admin, 'web')
            ->followingRedirects()
            ->post(route('profile.submit'), $this->validProfilePayload([
                'tahun_pelajaran' => '',
                'semester' => '',
            ]));

        $response->assertOk()
            ->assertSee('Profil sekolah berhasil disimpan.')
            ->assertSee('SDIT Al Hidayah');

        $this->assertDatabaseHas('profil_sekolah', [
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => null,
            'semester' => null,
        ]);
        $this->assertSame(1, DB::table('profil_sekolah')->count());
        $this->assertSame(0, DB::table('tahun_ajarans')->count());
    }

    public function test_clean_database_profile_validation_failure_is_visible_and_creates_no_row(): void
    {
        DB::table('profil_sekolah')->delete();
        DB::table('tahun_ajarans')->delete();

        $this->actingAs($this->admin, 'web')
            ->from(route('profile.edit'))
            ->post(route('profile.submit'), $this->validProfilePayload([
                'nama_sekolah' => '',
                'tahun_pelajaran' => '',
                'semester' => '',
            ]))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('nama_sekolah');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Profil sekolah belum dapat disimpan.');

        $this->assertSame(0, DB::table('profil_sekolah')->count());
        $this->assertSame(0, DB::table('tahun_ajarans')->count());
    }

    public function test_profile_update_persists_and_redirect_shows_new_value_without_changing_active_year(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->followingRedirects()
            ->post(route('profile.submit'), $this->validProfilePayload([
                'nama_sekolah' => 'SDIT Al Hidayah Baru',
                'kepala_sekolah' => 'Kepala Sekolah Baru',
            ]));

        $response->assertOk()
            ->assertSee('SDIT Al Hidayah Baru')
            ->assertSee('Kepala Sekolah Baru');

        $this->assertDatabaseHas('profil_sekolah', [
            'nama_sekolah' => 'SDIT Al Hidayah Baru',
            'kepala_sekolah' => 'Kepala Sekolah Baru',
        ]);
        $this->assertSame(true, (bool) DB::table('tahun_ajarans')->where('id', $this->activeYearId)->value('is_active'));
        $this->assertSame(false, (bool) DB::table('tahun_ajarans')->where('id', $this->inactiveYearId)->value('is_active'));
    }

    public function test_profile_update_does_not_modify_unrelated_academic_years(): void
    {
        $inactiveUpdatedAt = DB::table('tahun_ajarans')->where('id', $this->inactiveYearId)->value('updated_at');

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->post(route('profile.submit'), $this->validProfilePayload([
                'nama_instansi' => 'Yayasan Demo Diperbarui',
            ]))
            ->assertRedirect(route('profile'));

        $this->assertSame($inactiveUpdatedAt, DB::table('tahun_ajarans')->where('id', $this->inactiveYearId)->value('updated_at'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('is_active', true)->count());
        $this->assertSame($this->activeYearId, (int) DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_multiple_profile_updates_do_not_change_active_year_state(): void
    {
        foreach (['Update Pertama', 'Update Kedua'] as $name) {
            $this->actingAs($this->admin, 'web')
                ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
                ->post(route('profile.submit'), $this->validProfilePayload(['nama_sekolah' => $name]))
                ->assertRedirect(route('profile'));
        }

        $this->assertDatabaseHas('profil_sekolah', ['nama_sekolah' => 'Update Kedua']);
        $this->assertSame(1, DB::table('tahun_ajarans')->where('is_active', true)->count());
        $this->assertSame($this->activeYearId, (int) DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'nama_instansi' => 'Yayasan Pendidikan Demo',
            'nama_sekolah' => 'SDIT Al Hidayah',
            'npsn' => '90000000',
            'alamat' => 'Jl. Pendidikan No. 1',
            'kelurahan' => 'Demo Jaya',
            'kecamatan' => 'Demo Timur',
            'kabupaten' => 'Kota Demo',
            'provinsi' => 'Jawa Barat',
            'kode_pos' => '12345',
            'telepon' => '021000000',
            'email_sekolah' => 'demo@sdit.test',
            'website' => 'https://sdit.test',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'kepala_sekolah' => 'Hendra Prasetya',
            'nip_kepala_sekolah' => '197901012006041001',
            'nip_wali_kelas' => '',
            'guru_kelas' => 2,
            'kelas' => 2,
            'jumlah_siswa' => 4,
            'tempat_terbit' => 'Kota Demo',
            'tanggal_terbit' => '2026-12-20',
        ], $overrides);
    }

    private function createSchema(): void
    {
        foreach (['audit_logs', 'profil_sekolah', 'tahun_ajarans', 'users'] as $table) {
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
            $table->string('user_agent')->nullable();
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
            $table->string('logo')->nullable();
            $table->string('nama_instansi')->nullable();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->string('npsn')->nullable();
            $table->string('kepala_sekolah')->nullable();
            $table->string('nip_kepala_sekolah')->nullable();
            $table->string('nip_wali_kelas')->nullable();
            $table->text('alamat')->nullable();
            $table->integer('guru_kelas')->nullable();
            $table->string('kode_pos')->nullable();
            $table->integer('kelas')->nullable();
            $table->string('telepon')->nullable();
            $table->integer('jumlah_siswa')->nullable();
            $table->string('email_sekolah')->nullable();
            $table->string('tempat_terbit')->nullable();
            $table->date('tanggal_terbit')->nullable();
            $table->string('website')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten')->nullable();
            $table->string('provinsi')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2027-06-19',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inactiveYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 2,
            'is_active' => false,
            'tanggal_mulai' => '2025-07-14',
            'tanggal_selesai' => '2026-06-20',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('profil_sekolah')->insert(array_merge(
            $this->validProfilePayload(),
            ['created_at' => now(), 'updated_at' => now()]
        ));
    }
}
