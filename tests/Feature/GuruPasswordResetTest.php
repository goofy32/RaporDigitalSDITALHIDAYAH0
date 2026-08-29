<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use App\Services\DatabaseSessionRevocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GuruPasswordResetTest extends TestCase
{
    private User $admin;

    private Guru $guru;

    private int $tahunAjaranId;

    private int $kelasId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        $this->useDatabaseSessions();
        $this->seedFixture();
    }

    public function test_admin_can_reset_guru_password_without_current_password(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('teacher.show', $this->guru->id))
            ->assertSessionHas('success');

        $freshGuru = $this->guru->fresh();

        $this->assertTrue(Hash::check('Temporary123!', $freshGuru->password));
        $this->assertTrue((bool) $freshGuru->must_change_password);
        $this->assertSame('legacy-secret', $freshGuru->password_plain);
        $this->assertFalse(Hash::check('old-password', $freshGuru->password));
    }

    public function test_best_effort_revocation_remains_a_no_op_for_non_database_sessions(): void
    {
        $sessionId = str_repeat('f', 40);
        $this->insertDatabaseSession($sessionId, 'guru', $this->guru->id);
        $this->useArraySessions();

        app(DatabaseSessionRevocationService::class)->revoke('guru', $this->guru->id);

        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);
    }

    public function test_required_revocation_fails_closed_for_non_database_sessions(): void
    {
        $this->useArraySessions();
        $this->expectException(RuntimeException::class);

        app(DatabaseSessionRevocationService::class)->revokeOrFail('guru', $this->guru->id);
    }

    public function test_required_revocation_rejects_malformed_session_configuration(): void
    {
        $this->useDatabaseSessions();

        foreach ([
            ['session.table', '', RuntimeException::class],
            ['session.connection', '', RuntimeException::class],
            ['session.serialization', 'unsupported', LogicException::class],
            ['session.encrypt', 'invalid', LogicException::class],
        ] as [$key, $value, $exceptionClass]) {
            $original = config($key);
            config()->set($key, $value);

            try {
                app(DatabaseSessionRevocationService::class)->revokeOrFail('guru', $this->guru->id);
                $this->fail("Required revocation accepted malformed configuration: {$key}");
            } catch (Throwable $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
            } finally {
                config()->set($key, $original);
            }
        }
    }

    public function test_admin_assisted_reset_fails_closed_for_non_database_sessions(): void
    {
        $this->useArraySessions();

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.show', $this->guru->id))
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('teacher.show', $this->guru->id))
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('success');

        $freshGuru = $this->guru->fresh();

        $this->assertTrue(Hash::check('old-password', $freshGuru->password));
        $this->assertFalse((bool) $freshGuru->must_change_password);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'guru_password_reset',
            'model_id' => $this->guru->id,
        ]);
    }

    public function test_admin_reset_revokes_only_target_guru_database_sessions(): void
    {
        $this->assertSame($this->admin->id, $this->guru->id);
        $this->guru->forceFill(['password_plain' => null])->save();

        $otherGuru = Guru::query()->create([
            'nama' => 'Guru Lain',
            'email' => 'guru-lain@example.test',
            'username' => 'guru-lain',
            'password' => Hash::make('other-password'),
            'jabatan' => 'guru',
        ]);

        $targetSessionOne = str_repeat('a', 40);
        $targetSessionTwo = str_repeat('b', 40);
        $otherGuruSession = str_repeat('c', 40);
        $overlappingAdminSession = str_repeat('d', 40);

        $this->insertDatabaseSession($targetSessionOne, 'guru', $this->guru->id);
        $this->insertDatabaseSession($targetSessionTwo, 'guru', $this->guru->id);
        $this->insertDatabaseSession($otherGuruSession, 'guru', $otherGuru->id);
        $this->insertDatabaseSession($overlappingAdminSession, 'web', $this->admin->id);
        $this->useDatabaseSessions();

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('teacher.show', $this->guru->id))
            ->assertSessionHas('success');

        $freshGuru = $this->guru->fresh();

        $this->assertTrue(Hash::check('Temporary123!', $freshGuru->password));
        $this->assertTrue((bool) $freshGuru->must_change_password);
        $this->assertNull($freshGuru->password_plain);
        $this->assertDatabaseMissing('sessions', ['id' => $targetSessionOne]);
        $this->assertDatabaseMissing('sessions', ['id' => $targetSessionTwo]);
        $this->assertDatabaseHas('sessions', ['id' => $otherGuruSession]);
        $this->assertDatabaseHas('sessions', ['id' => $overlappingAdminSession]);
        $this->assertAuthenticatedAs($this->admin, 'web');

        $this->withCookie(config('session.cookie'), $targetSessionOne)
            ->get(route('guru.force-password.edit'))
            ->assertRedirect(route('login'));
        $this->assertGuest('guru');
    }

    public function test_failed_required_session_revocation_rolls_back_admin_assisted_reset(): void
    {
        $targetSession = str_repeat('e', 40);
        $this->insertDatabaseSession($targetSession, 'guru', $this->guru->id);
        $this->useDatabaseSessions();

        DB::statement(<<<'SQL'
            CREATE TRIGGER prevent_session_delete
            BEFORE DELETE ON sessions
            BEGIN
                SELECT RAISE(ABORT, 'session delete blocked');
            END
            SQL);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('teacher.show', $this->guru->id))
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('teacher.show', $this->guru->id))
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('success');

        $freshGuru = $this->guru->fresh();

        $this->assertTrue(Hash::check('old-password', $freshGuru->password));
        $this->assertFalse((bool) $freshGuru->must_change_password);
        $this->assertDatabaseHas('sessions', ['id' => $targetSession]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'guru_password_reset',
            'model_id' => $this->guru->id,
        ]);
    }

    public function test_reset_password_modal_trigger_is_only_shown_in_password_security_sections(): void
    {
        $resetUrl = route('teacher.reset-password.edit', $this->guru->id);
        $resetAction = route('teacher.reset-password.update', $this->guru->id);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher'))
            ->assertOk()
            ->assertDontSee('data-guru-password-reset-open', false)
            ->assertDontSee($resetUrl, false);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.show', $this->guru->id))
            ->assertOk()
            ->assertSee('Password tidak dapat ditampilkan demi keamanan.')
            ->assertSee('Reset password guru')
            ->assertSee('data-guru-password-reset-open', false)
            ->assertSee('data-guru-password-reset-modal', false)
            ->assertSee('action="'.$resetAction.'"', false)
            ->assertDontSee('href="'.$resetUrl.'"', false)
            ->assertDontSee('Reset Password</button>', false);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.edit', $this->guru->id))
            ->assertOk()
            ->assertSee('Keamanan Password')
            ->assertSee('Password tidak dapat ditampilkan demi keamanan.')
            ->assertSee('Reset password guru')
            ->assertSee('data-guru-password-reset-open', false)
            ->assertSee('data-guru-password-reset-modal', false)
            ->assertSee('action="'.$resetAction.'"', false)
            ->assertDontSee('href="'.$resetUrl.'"', false);
    }

    public function test_reset_password_page_has_polished_password_controls(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('teacher.reset-password.edit', $this->guru->id))
            ->assertOk()
            ->assertSee('Reset Password Guru')
            ->assertSee('Buat password sementara agar guru bisa login kembali.')
            ->assertSee('Password lama tidak dapat ditampilkan demi keamanan.')
            ->assertSee('Setelah reset, guru wajib mengganti password saat login berikutnya.')
            ->assertSee('Username')
            ->assertSee('budi')
            ->assertSee('Password sementara untuk login awal')
            ->assertSee('Buat Password')
            ->assertSee('Tombol Buat Password akan mengisi password dan konfirmasi sekaligus.')
            ->assertSee('reset-password-input::-ms-reveal', false)
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('data-password-toggle="password_confirmation"', false)
            ->assertSee('id="generate-password"', false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-password-toggle='));
    }

    public function test_reset_does_not_log_plaintext_password(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ]);

        $auditPayload = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode("\n");

        $this->assertStringNotContainsString('Temporary123!', $auditPayload);
        $this->assertStringNotContainsString('old-password', $auditPayload);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'guru_password_reset',
            'model_id' => $this->guru->id,
        ]);
    }

    public function test_old_password_fails_and_reset_password_redirects_to_force_change(): void
    {
        $this->resetGuruPassword('Temporary123!');
        $this->logoutAllGuards();

        $this->post(route('login.post'), [
            'username' => $this->guru->username,
            'password' => 'old-password',
        ])
            ->assertSessionHasErrors('username');

        $this->post(route('login.post'), [
            'username' => $this->guru->username,
            'password' => 'Temporary123!',
        ])
            ->assertRedirect(route('guru.force-password.edit'));
    }

    public function test_guru_with_reset_password_cannot_access_dashboard_before_changing_password(): void
    {
        $this->resetGuruPassword('Temporary123!');

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->get(route('pengajar.dashboard'))
            ->assertRedirect(route('guru.force-password.edit'));

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('wali_kelas'))
            ->get(route('wali_kelas.dashboard'))
            ->assertRedirect(route('guru.force-password.edit'));
    }

    public function test_guru_can_change_forced_password_and_access_dashboard(): void
    {
        $this->resetGuruPassword('Temporary123!');

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->put(route('guru.force-password.update'), [
                'password' => 'NewSecure123!',
                'password_confirmation' => 'NewSecure123!',
            ])
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('success');

        $freshGuru = $this->guru->fresh();

        $this->assertFalse((bool) $freshGuru->must_change_password);
        $this->assertTrue(Hash::check('NewSecure123!', $freshGuru->password));
        $this->assertSame('legacy-secret', $freshGuru->password_plain);

        $this->actingAs($freshGuru, 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->get(route('pengajar.dashboard'))
            ->assertOk();
    }

    public function test_forced_password_change_rejects_reusing_temporary_password(): void
    {
        $this->resetGuruPassword('Temporary123!');

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->from(route('guru.force-password.edit'))
            ->put(route('guru.force-password.update'), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('guru.force-password.edit'))
            ->assertSessionHasErrors('password');

        $this->assertTrue((bool) $this->guru->fresh()->must_change_password);
    }

    public function test_non_admin_cannot_reset_guru_password(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => 'Temporary123!',
                'password_confirmation' => 'Temporary123!',
            ])
            ->assertRedirect(route('login'));

        $freshGuru = $this->guru->fresh();

        $this->assertFalse((bool) $freshGuru->must_change_password);
        $this->assertTrue(Hash::check('old-password', $freshGuru->password));
    }

    public function test_role_switching_still_works_after_forced_password_change(): void
    {
        $this->resetGuruPassword('Temporary123!');

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->put(route('guru.force-password.update'), [
                'password' => 'NewSecure123!',
                'password_confirmation' => 'NewSecure123!',
            ]);

        $this->actingAs($this->guru->fresh(), 'guru')
            ->withSession($this->guruSession('pengajar'))
            ->post(route('auth.switch.role', ['role' => 'wali_kelas']))
            ->assertRedirect(route('wali_kelas.dashboard'))
            ->assertSessionHas('selected_role', 'wali_kelas');
    }

    private function resetGuruPassword(string $password): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('teacher.reset-password.update', $this->guru->id), [
                'password' => $password,
                'password_confirmation' => $password,
            ]);
    }

    private function logoutAllGuards(): void
    {
        auth()->guard('web')->logout();
        auth()->guard('guru')->logout();
        session()->flush();
    }

    private function createSchema(): void
    {
        foreach ([
            'sessions',
            'audit_logs',
            'nilais',
            'kkms',
            'siswa_kelas_semester',
            'siswas',
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

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
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
            $table->string('username')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->string('password_plain')->nullable();
            $table->string('jabatan')->nullable();
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
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
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
            $table->tinyInteger('semester');
            $table->timestamps();
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
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
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
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('admin-password'),
        ]);

        $this->tahunAjaranId = DB::table('tahun_ajarans')->insertGetId([
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

        $this->kelasId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Budi',
            'email' => 'budi@example.test',
            'username' => 'budi',
            'password' => Hash::make('old-password'),
            'password_plain' => 'legacy-secret',
            'jabatan' => 'guru_wali',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $guruId,
                'kelas_id' => $this->kelasId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $guruId,
                'kelas_id' => $this->kelasId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->kelasId,
            'guru_id' => $guruId,
            'semester' => 1,
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->guru = Guru::findOrFail($guruId);
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
            'last_activity' => time(),
        ];
    }

    private function guruSession(string $role): array
    {
        return [
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'selected_role' => $role,
            'no_tahun_ajaran' => false,
            'last_activity' => time(),
        ];
    }

    private function insertDatabaseSession(string $id, string $guard, int $accountId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $accountId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([
                Auth::guard($guard)->getName() => $accountId,
            ])),
            'last_activity' => time(),
        ]);
    }

    private function useDatabaseSessions(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.connection', 'sqlite');
        config()->set('session.encrypt', false);
        config()->set('session.serialization', 'php');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    private function useArraySessions(): void
    {
        config()->set('session.driver', 'array');
        config()->set('session.connection', null);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }
}
