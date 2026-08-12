<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use App\Notifications\AdminResetPasswordNotification;
use App\Notifications\GuruResetPasswordNotification;
use App\Notifications\GuruVerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminPasswordRecoveryTest extends TestCase
{
    private const ADMIN_PASSWORD = 'AdminPassword123!';

    private const GURU_PASSWORD = 'GuruPassword123!';

    private User $admin;

    private Guru $guru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        config()->set('session.encrypt', false);
        config()->set('session.serialization', 'php');
        config()->set('mail.default', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('cache')->forgetDriver('array');
        Cache::flush();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_shared_login_is_role_neutral_and_has_one_forgot_password_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Masuk ke Rapor Digital')
            ->assertSee('Lupa password?')
            ->assertDontSee('name="role"', false)
            ->assertDontSee('Login Admin')
            ->assertDontSee('Login Guru');

        $this->assertFalse(Route::has('admin.login'));
        $this->assertFalse(Route::has('admin.login.post'));
    }

    public function test_admin_can_login_through_shared_portal_with_username_and_email(): void
    {
        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->assertGuest('guru');

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.post'), [
            'username' => $this->admin->email,
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->assertGuest('guru');
    }

    public function test_guru_can_login_through_shared_portal_with_username_and_email(): void
    {
        $this->post(route('login.post'), [
            'username' => $this->guru->username,
            'password' => self::GURU_PASSWORD,
        ])->assertRedirect(route('pengajar.dashboard'));
        $this->assertAuthenticatedAs($this->guru, 'guru');
        $this->assertGuest('web');

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.post'), [
            'username' => $this->guru->email,
            'password' => self::GURU_PASSWORD,
        ])->assertRedirect(route('pengajar.dashboard'));
        $this->assertAuthenticatedAs($this->guru, 'guru');
        $this->assertGuest('web');
    }

    public function test_successful_shared_login_regenerates_the_session_id(): void
    {
        $this->withSession(['before_login' => true]);
        $oldSessionId = app('session')->driver()->getId();

        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertNotSame($oldSessionId, app('session')->driver()->getId());
    }

    public function test_ambiguous_legacy_identifier_is_rejected_without_selecting_a_guard(): void
    {
        DB::table('gurus')->insert([
            'nama' => 'Guru Ambigu',
            'username' => $this->admin->email,
            'email' => 'ambigu@example.test',
            'password' => Hash::make(self::ADMIN_PASSWORD),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('login.post'), [
            'username' => $this->admin->email,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors([
            'username' => 'Username, email, atau password salah.',
        ]);

        $this->assertGuest('web');
        $this->assertGuest('guru');
    }

    public function test_shared_login_is_rate_limited_and_csrf_protected(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.post'), [
                'username' => $this->admin->username,
                'password' => 'WrongPassword123!',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => 'WrongPassword123!',
        ])->assertTooManyRequests();

        Cache::flush();
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');

        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertStatus(419);
    }

    public function test_expired_admin_and_guru_sessions_both_redirect_to_shared_login(): void
    {
        Route::middleware('web')->get('/admin/test/expired-session', fn () => response('ok'));
        Route::middleware('web')->get('/guru/test/expired-session', fn () => response('ok'));
        $expired = time() - ((int) config('session.lifetime') * 60) - 1;

        $this->actingAs($this->admin, 'web')
            ->withSession(['last_activity' => $expired])
            ->get('/admin/test/expired-session')
            ->assertRedirect(route('login'));

        $this->actingAs($this->guru, 'guru')
            ->withSession(['last_activity' => $expired])
            ->get('/guru/test/expired-session')
            ->assertRedirect(route('login'));
    }

    public function test_admin_recovery_sends_synchronous_notification_and_stores_only_hashed_token(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => $this->admin->email])
            ->assertRedirect()
            ->assertSessionHas('status', $this->genericRecoveryMessage());

        Notification::assertSentTo($this->admin, AdminResetPasswordNotification::class, function ($notification): bool {
            $this->assertNotInstanceOf(ShouldQueue::class, $notification);
            $rawToken = $this->tokenFromNotification($notification, $this->admin);
            $storedToken = DB::table('password_reset_tokens')->where('email', $this->admin->email)->value('token');

            $this->assertNotSame($rawToken, $storedToken);
            $this->assertTrue(Hash::check($rawToken, $storedToken));

            return true;
        });
    }

    public function test_verified_guru_recovery_uses_separate_broker_and_hashed_token(): void
    {
        Notification::fake();
        $this->guru->forceFill(['email_verified_at' => now()])->save();

        $this->post(route('password.email'), ['email' => $this->guru->email])
            ->assertRedirect()
            ->assertSessionHas('status', $this->genericRecoveryMessage());

        Notification::assertSentTo($this->guru, GuruResetPasswordNotification::class, function ($notification): bool {
            $this->assertNotInstanceOf(ShouldQueue::class, $notification);
            $rawToken = $this->tokenFromNotification($notification, $this->guru);
            $storedToken = DB::table('guru_password_reset_tokens')->where('email', $this->guru->email)->value('token');

            $this->assertNotSame($rawToken, $storedToken);
            $this->assertTrue(Hash::check($rawToken, $storedToken));

            return true;
        });
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_forgot_password_response_is_identical_for_all_eligibility_states(): void
    {
        Notification::fake();
        $verifiedGuru = $this->createGuru('guru-terverifikasi', 'verified@example.test', true);

        foreach ([
            $this->admin->email,
            $verifiedGuru->email,
            $this->guru->email,
            'tidak-ada@example.test',
        ] as $email) {
            $response = $this->post(route('password.email'), ['email' => $email]);
            $response->assertRedirect()->assertSessionHas('status', $this->genericRecoveryMessage());
            $this->assertSame($this->genericRecoveryMessage(), session('status'));
        }

        Notification::assertSentTo($this->admin, AdminResetPasswordNotification::class);
        Notification::assertSentTo($verifiedGuru, GuruResetPasswordNotification::class);
        Notification::assertNotSentTo($this->guru, GuruResetPasswordNotification::class);
        $this->assertDatabaseMissing('guru_password_reset_tokens', ['email' => $this->guru->email]);
    }

    public function test_forgot_password_is_rate_limited_and_csrf_protected(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('password.email'), [
                'email' => 'tidak-ada-'.$attempt.'@example.test',
            ])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'dibatasi@example.test'])
            ->assertTooManyRequests();

        Cache::flush();
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        $this->post(route('password.email'), ['email' => $this->admin->email])
            ->assertStatus(419);
    }

    public function test_guru_can_send_and_complete_signed_email_verification(): void
    {
        Notification::fake();

        $this->actingAs($this->guru, 'guru')
            ->post(route('guru.verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($this->guru, GuruVerifyEmailNotification::class, function ($notification): bool {
            $this->assertNotInstanceOf(ShouldQueue::class, $notification);
            $url = $notification->toMail($this->guru)->actionUrl;

            $this->actingAs($this->guru, 'guru')->get($url)
                ->assertRedirect(route('pengajar.dashboard'))
                ->assertSessionHas('success');

            return true;
        });

        $this->assertNotNull($this->guru->fresh()->email_verified_at);
    }

    public function test_invalid_expired_and_old_email_verification_links_are_rejected(): void
    {
        $validUrl = $this->verificationUrl($this->guru);

        $this->actingAs($this->guru, 'guru')
            ->get(route('guru.verification.verify', [
                'id' => $this->guru->id,
                'hash' => sha1($this->guru->email),
            ]))
            ->assertForbidden();

        $this->travel(61)->minutes();
        $this->actingAs($this->guru, 'guru')->get($validUrl)->assertForbidden();
        $this->travelBack();

        $oldUrl = $this->verificationUrl($this->guru);
        $this->guru->update(['email' => 'guru-baru@example.test']);
        $this->assertNull($this->guru->fresh()->email_verified_at);
        $this->actingAs($this->guru->fresh(), 'guru')->get($oldUrl)->assertForbidden();
    }

    public function test_guru_without_email_remains_valid_and_receives_no_verification_email(): void
    {
        Notification::fake();
        $guru = $this->createGuru('guru-tanpa-email', null, false);

        $this->actingAs($guru, 'guru')
            ->get(route('guru.verification.notice'))
            ->assertOk()
            ->assertSee('Belum ada alamat email');

        $this->post(route('guru.verification.send'))->assertRedirect();
        Notification::assertNothingSent();
    }

    public function test_admin_token_resets_existing_admin_once_without_reopening_setup(): void
    {
        $newPassword = 'NewAdminPassword456!';
        $token = Password::broker('users')->createToken($this->admin);

        $this->post(route('password.update'), $this->resetPayload($this->admin->email, $token, $newPassword))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $freshAdmin = $this->admin->fresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(Hash::check(self::ADMIN_PASSWORD, $freshAdmin->password));
        $this->assertTrue(Hash::check($newPassword, $freshAdmin->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->admin->email]);
        $this->assertGuest('web');

        $this->post(route('login.post'), [
            'username' => $freshAdmin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username');

        $this->post(route('login.post'), [
            'username' => $freshAdmin->username,
            'password' => $newPassword,
        ])->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));
        $this->post(route('login.post'), [
            'username' => $freshAdmin->email,
            'password' => $newPassword,
        ])->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));

        $this->post(route('password.update'), $this->resetPayload($this->admin->email, $token, 'AnotherPassword789!'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.token');

        config()->set('initial_admin_setup.token_hash', hash('sha256', Str::random(64)));
        $this->get(route('initial-admin-setup.create'))->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guru_token_resets_only_verified_existing_guru_once(): void
    {
        $this->guru->forceFill(['email_verified_at' => now()])->save();
        $newPassword = 'NewGuruPassword456!';
        $token = Password::broker('gurus')->createToken($this->guru);

        $this->post(route('password.update'), $this->resetPayload($this->guru->email, $token, $newPassword))
            ->assertRedirect(route('login'));

        $freshGuru = $this->guru->fresh();
        $this->assertTrue(Hash::check($newPassword, $freshGuru->password));
        $this->assertFalse((bool) $freshGuru->must_change_password);
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));
        $this->assertDatabaseMissing('guru_password_reset_tokens', ['email' => $this->guru->email]);

        $this->post(route('login.post'), [
            'username' => $freshGuru->username,
            'password' => self::GURU_PASSWORD,
        ])->assertSessionHasErrors('username');

        $this->post(route('login.post'), [
            'username' => $freshGuru->username,
            'password' => $newPassword,
        ])->assertRedirect(route('pengajar.dashboard'));
        $this->post(route('logout'));

        $this->post(route('password.update'), $this->resetPayload($this->guru->email, $token, 'AnotherGuruPassword789!'))
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check($newPassword, $freshGuru->fresh()->password));
    }

    public function test_invalid_and_expired_reset_tokens_are_rejected(): void
    {
        $this->post(route('password.update'), $this->resetPayload(
            $this->admin->email,
            Str::random(64),
            'NewAdminPassword456!'
        ))->assertSessionHasErrors('email');

        $token = Password::broker('users')->createToken($this->admin);
        DB::table('password_reset_tokens')
            ->where('email', $this->admin->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->post(route('password.update'), $this->resetPayload(
            $this->admin->email,
            $token,
            'NewAdminPassword456!'
        ))->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));
    }

    public function test_tokens_cannot_cross_password_brokers(): void
    {
        $this->guru->forceFill(['email_verified_at' => now()])->save();
        $adminToken = Password::broker('users')->createToken($this->admin);
        $guruToken = Password::broker('gurus')->createToken($this->guru);

        $this->post(route('password.update'), $this->resetPayload($this->guru->email, $adminToken, 'CrossPassword456!'))
            ->assertSessionHasErrors('email');
        $this->post(route('password.update'), $this->resetPayload($this->admin->email, $guruToken, 'CrossPassword456!'))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));
        $this->assertTrue(Hash::check(self::GURU_PASSWORD, $this->guru->fresh()->password));
    }

    public function test_cross_table_email_or_identifier_collision_is_rejected_on_model_write(): void
    {
        $this->expectException(ValidationException::class);

        Guru::query()->create([
            'nama' => 'Guru Konflik',
            'username' => 'guru-konflik',
            'email' => $this->admin->email,
            'password' => 'SecurePassword123!',
        ]);
    }

    public function test_cross_field_identifier_collision_between_gurus_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Guru::query()->create([
            'nama' => 'Guru Konflik Internal',
            'username' => $this->guru->email,
            'email' => 'guru-konflik-internal@example.test',
            'password' => 'SecurePassword123!',
        ]);
    }

    public function test_admin_reset_revokes_only_web_sessions_when_ids_overlap(): void
    {
        $this->insertGuardSessionsWithOverlappingIds();
        $token = Password::broker('users')->createToken($this->admin);
        $this->useDatabaseSessions();

        $this->post(route('password.update'), $this->resetPayload($this->admin->email, $token, 'NewAdminPassword456!'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => 'admin-session-lama']);
        $this->assertDatabaseHas('sessions', ['id' => 'guru-session-tetap']);
    }

    public function test_guru_reset_revokes_only_guru_sessions_when_ids_overlap(): void
    {
        $this->guru->forceFill(['email_verified_at' => now()])->save();
        $this->insertGuardSessionsWithOverlappingIds();
        $token = Password::broker('gurus')->createToken($this->guru);
        $this->useDatabaseSessions();

        $this->post(route('password.update'), $this->resetPayload($this->guru->email, $token, 'NewGuruPassword456!'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('sessions', ['id' => 'admin-session-lama']);
        $this->assertDatabaseMissing('sessions', ['id' => 'guru-session-tetap']);
    }

    public function test_authenticated_admin_can_change_password_without_flashing_sensitive_input(): void
    {
        $newPassword = 'ChangedAdminPassword456!';

        $response = $this->actingAs($this->admin, 'web')
            ->from(route('admin.password.change.edit'))
            ->put(route('admin.password.change.update'), [
                'current_password' => self::ADMIN_PASSWORD,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

        $response->assertRedirect(route('admin.password.change.edit'))
            ->assertSessionHas('success')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertTrue(Hash::check($newPassword, $this->admin->fresh()->password));
        $this->assertAuthenticatedAs($this->admin, 'web');
    }

    public function test_admin_change_password_rejects_wrong_current_password_and_confirmation(): void
    {
        $this->actingAs($this->admin, 'web')
            ->from(route('admin.password.change.edit'))
            ->put(route('admin.password.change.update'), [
                'current_password' => 'WrongPassword123!',
                'password' => 'ChangedAdminPassword456!',
                'password_confirmation' => 'DifferentPassword456!',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password');

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));
    }

    private function createGuru(string $username, ?string $email, bool $verified): Guru
    {
        $guru = Guru::query()->create([
            'nama' => 'Guru Tambahan',
            'username' => $username,
            'email' => $email,
            'password' => self::GURU_PASSWORD,
            'must_change_password' => false,
        ]);

        if ($verified) {
            $guru->forceFill(['email_verified_at' => now()])->save();
        }

        return $guru;
    }

    private function verificationUrl(Guru $guru): string
    {
        return URL::temporarySignedRoute(
            'guru.verification.verify',
            now()->addMinutes(60),
            ['id' => $guru->id, 'hash' => sha1($guru->getEmailForVerification())]
        );
    }

    private function tokenFromNotification(object $notification, User|Guru $account): string
    {
        $path = (string) parse_url($notification->toMail($account)->actionUrl, PHP_URL_PATH);

        return rawurldecode(basename($path));
    }

    /** @return array<string, string> */
    private function resetPayload(string $email, string $token, string $password): array
    {
        return [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    private function genericRecoveryMessage(): string
    {
        return 'Jika akun dan email Anda memenuhi syarat untuk pemulihan, petunjuk pengaturan ulang password akan dikirim. Jika Anda tidak menerima email, silakan hubungi Admin sekolah.';
    }

    private function insertGuardSessionsWithOverlappingIds(): void
    {
        $this->assertSame($this->admin->id, $this->guru->id);

        DB::table('sessions')->insert([
            [
                'id' => 'admin-session-lama',
                'user_id' => $this->admin->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => $this->sessionPayload('web', $this->admin->id),
                'last_activity' => time(),
            ],
            [
                'id' => 'guru-session-tetap',
                'user_id' => $this->guru->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => $this->sessionPayload('guru', $this->guru->id),
                'last_activity' => time(),
            ],
        ]);
    }

    private function sessionPayload(string $guard, int $accountId): string
    {
        return base64_encode(serialize([
            Auth::guard($guard)->getName() => $accountId,
        ]));
    }

    private function useDatabaseSessions(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.connection', 'sqlite');
        app('session')->forgetDrivers();
    }

    private function createSchema(): void
    {
        foreach ([
            'sessions', 'guru_password_reset_tokens', 'password_reset_tokens', 'audit_logs',
            'guru_kelas', 'mata_pelajarans', 'kelas', 'profil_sekolah', 'tahun_ajarans', 'gurus', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->tinyInteger('admin_singleton')->virtualAs('1');
            $table->unique('admin_singleton', 'users_admin_singleton_unique');
        });

        Schema::create('gurus', function (Blueprint $table): void {
            $table->id();
            $table->string('nama');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table): void {
            $table->id();
            $table->string('tahun_ajaran');
            $table->unsignedTinyInteger('semester')->default(1);
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id');
            $table->foreignId('wali_kelas_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guru_kelas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guru_id');
            $table->foreignId('kelas_id');
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role');
            $table->timestamps();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kelas_id');
            $table->foreignId('guru_id')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['password_reset_tokens', 'guru_password_reset_tokens'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
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
    }

    private function seedFixture(): void
    {
        $this->admin = User::query()->create([
            'name' => 'Admin Sekolah',
            'username' => 'admin-sekolah',
            'email' => 'admin@example.test',
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->guru = Guru::query()->create([
            'nama' => 'Guru Pengajar',
            'username' => 'guru-pengajar',
            'email' => 'guru@example.test',
            'password' => self::GURU_PASSWORD,
            'must_change_password' => false,
        ]);

        $tahunAjaranId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kelasId = DB::table('kelas')->insertGetId([
            'nama_kelas' => '4A',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('guru_kelas')->insert([
            'guru_id' => $this->guru->id,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
