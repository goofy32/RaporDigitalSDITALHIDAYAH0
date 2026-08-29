<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guru;
use App\Models\User;
use App\Notifications\AdminResetPasswordNotification;
use App\Notifications\GuruResetPasswordNotification;
use App\Notifications\GuruVerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
            ->assertDontSee('Masuk ke Rapor Digital')
            ->assertSee('RAPOR DIGITAL')
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
        $submittedUsername = $this->admin->username;
        $submittedPassword = 'WrongPassword123!';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.post'), [
                'username' => $submittedUsername,
                'password' => $submittedPassword,
            ])->assertRedirect()
                ->assertSessionHasErrors([
                    'username' => 'Username, email, atau password salah.',
                ]);
        }

        $throttledResponse = $this->post(route('login.post'), [
            'username' => $submittedUsername,
            'password' => $submittedPassword,
        ]);

        $retryAfter = (int) $throttledResponse->headers->get('Retry-After');

        $throttledResponse
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', (string) $retryAfter)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertSee('Terlalu banyak percobaan masuk. Silakan tunggu')
            ->assertSee('data-retry-after="'.$retryAfter.'"', false)
            ->assertSee('id="login-throttle-countdown"', false)
            ->assertSee('id="login-submit-button"', false)
            ->assertSee('submitButton.disabled = remainingSeconds > 0', false)
            ->assertSee('value="'.$submittedUsername.'"', false)
            ->assertDontSee($submittedPassword)
            ->assertDontSee('Too Many Requests');
        $this->assertGreaterThan(0, $retryAfter);

        Cache::flush();
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');

        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertStatus(419);
    }

    public function test_unrelated_throttled_route_keeps_the_default_429_response(): void
    {
        Route::middleware(['web', 'throttle:1,1'])
            ->post('/test/unrelated-throttle', fn () => response('ok'))
            ->name('test.unrelated-throttle');

        $this->post('/test/unrelated-throttle')->assertOk();

        $this->post('/test/unrelated-throttle')
            ->assertTooManyRequests()
            ->assertDontSee('Terlalu banyak percobaan masuk')
            ->assertDontSee('RAPOR DIGITAL');
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

    public function test_logged_out_verification_link_guides_login_and_continues_for_correct_guru(): void
    {
        $url = $this->verificationUrl($this->guru);

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'error',
                'Untuk memverifikasi email, silakan masuk menggunakan akun Guru yang terkait.'
            )
            ->assertSessionHas('url.intended', $url);

        $this->post(route('login.post'), [
            'username' => $this->guru->username,
            'password' => self::GURU_PASSWORD,
        ])->assertRedirect($url);

        $this->get($url)
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('success', 'Email berhasil diverifikasi.');

        $this->assertNotNull($this->guru->fresh()->email_verified_at);
    }

    public function test_admin_opening_guru_verification_link_gets_explicit_guidance(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get($this->verificationUrl($this->guru))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas(
                'error',
                'Tautan ini digunakan untuk verifikasi email Guru. Anda sedang masuk sebagai Admin. Silakan keluar dan masuk menggunakan akun Guru yang terkait.'
            );

        $this->assertNull($this->guru->fresh()->email_verified_at);
    }

    public function test_logged_out_verification_link_explains_admin_or_wrong_guru_login(): void
    {
        $url = $this->verificationUrl($this->guru);
        $this->get($url)->assertRedirect(route('login'));

        $this->post(route('login.post'), [
            'username' => $this->admin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas(
                'error',
                'Tautan ini digunakan untuk verifikasi email Guru. Anda sedang masuk sebagai Admin. Silakan keluar dan masuk menggunakan akun Guru yang terkait.'
            );

        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('login'));
        $otherGuru = $this->createGuru('guru-intended-lain', 'guru-intended-lain@example.test', false);

        $this->post(route('login.post'), [
            'username' => $otherGuru->username,
            'password' => self::GURU_PASSWORD,
        ])->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('error', 'Tautan verifikasi ini bukan untuk akun Guru yang sedang digunakan.');

        $this->assertNull($this->guru->fresh()->email_verified_at);
    }

    public function test_wrong_guru_cannot_verify_another_guru_email(): void
    {
        $otherGuru = $this->createGuru('guru-lain', 'guru-lain@example.test', false);

        $this->actingAs($otherGuru, 'guru')
            ->get($this->verificationUrl($this->guru))
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('error', 'Tautan verifikasi ini bukan untuk akun Guru yang sedang digunakan.');

        $this->assertNull($this->guru->fresh()->email_verified_at);
        $this->assertNull($otherGuru->fresh()->email_verified_at);
    }

    public function test_already_verified_email_link_is_clear_and_idempotent(): void
    {
        $this->guru->forceFill(['email_verified_at' => now()])->save();
        $verifiedAt = $this->guru->fresh()->email_verified_at;

        $this->actingAs($this->guru, 'guru')
            ->get($this->verificationUrl($this->guru))
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('success', 'Email sudah diverifikasi.');

        $this->assertTrue($verifiedAt->equalTo($this->guru->fresh()->email_verified_at));
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
        $this->actingAs($this->guru->fresh(), 'guru')
            ->get($oldUrl)
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('error', 'Tautan verifikasi tidak lagi berlaku untuk alamat email saat ini.');
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

    public function test_account_pages_keep_admin_pengajar_and_wali_navigation_isolated(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('admin.account.edit'))
            ->assertOk()
            ->assertSee('Simulasi Staging')
            ->assertSee('Pengaturan Akun Admin')
            ->assertSee('Ubah Username')
            ->assertSee('Ubah Email')
            ->assertSee('Ubah Password');

        Auth::guard('web')->logout();

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->get(route('guru.verification.notice'))
            ->assertOk()
            ->assertSee('Data Nilai Pelajaran')
            ->assertDontSee('Simulasi Staging')
            ->assertDontSee('Kenaikan Kelas');

        $this->withSession(['selected_role' => 'wali_kelas'])
            ->get(route('guru.verification.notice'))
            ->assertOk()
            ->assertSee('Cetak Rapor HTML')
            ->assertDontSee('Simulasi Staging')
            ->assertDontSee('Format Rapor');
    }

    public function test_account_route_guards_reject_the_other_authentication_domain(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('guru.verification.notice'))
            ->assertRedirect(route('login'));

        Auth::guard('web')->logout();

        $this->actingAs($this->guru, 'guru')
            ->get(route('admin.account.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_account_page_and_dropdown_have_expected_navigation(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.account.edit'));

        $response->assertOk()
            ->assertSee('Pengaturan Akun Admin')
            ->assertSee($this->admin->name)
            ->assertSee($this->admin->username)
            ->assertSee($this->admin->email)
            ->assertSee('Pengaturan Akun')
            ->assertSee('Catatan Aktivitas')
            ->assertSee('href="'.route('admin.account.edit').'"', false)
            ->assertDontSee('href="'.route('admin.password.change.edit').'"', false);

        $this->get(route('admin.password.change.edit'))
            ->assertRedirect(route('admin.account.edit').'#password');
    }

    public function test_admin_account_routes_require_web_admin_authentication(): void
    {
        Auth::guard('web')->logout();
        $this->get(route('admin.account.edit'))->assertRedirect(route('login'));

        $this->actingAs($this->guru, 'guru')
            ->get(route('admin.account.edit'))
            ->assertRedirect(route('login'));

        foreach ([
            'admin.account.edit',
            'admin.account.username.update',
            'admin.account.email.update',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('auth:web', $middleware);
            $this->assertContains('role:admin', $middleware);
        }
    }

    public function test_admin_can_change_username_without_changing_other_credentials(): void
    {
        $oldUsername = $this->admin->username;
        $oldEmail = $this->admin->email;
        $oldPasswordHash = $this->admin->password;
        $verifiedAt = now()->subDay()->startOfSecond();
        $this->admin->forceFill([
            'email_verified_at' => $verifiedAt,
            'remember_token' => 'username-remember-token',
        ])->save();

        $response = $this->actingAs($this->admin, 'web')
            ->put(route('admin.account.username.update'), [
                'username' => '  admin-baru  ',
                'current_password' => self::ADMIN_PASSWORD,
            ]);

        $response->assertRedirect(route('admin.account.edit'))
            ->assertSessionHas('success', 'Username Admin berhasil diubah.')
            ->assertSessionMissing('_old_input.current_password');

        $freshAdmin = $this->admin->fresh();
        $this->assertSame('admin-baru', $freshAdmin->username);
        $this->assertSame($oldEmail, $freshAdmin->email);
        $this->assertSame($oldPasswordHash, $freshAdmin->password);
        $this->assertTrue($freshAdmin->email_verified_at->equalTo($verifiedAt));
        $this->assertNotSame('username-remember-token', $freshAdmin->remember_token);
        $this->assertAuthenticatedAs($freshAdmin, 'web');

        $audit = AuditLog::query()->where('action', 'admin_username_changed')->sole();
        $this->assertSame('Admin mengubah username akun.', $audit->description);
        $this->assertSame(['username' => $oldUsername], $audit->old_values);
        $this->assertSame(['username' => 'admin-baru'], $audit->new_values);
        $this->assertSame(User::class, $audit->model_type);
        $this->assertSame($freshAdmin->id, $audit->model_id);

        $this->post(route('logout'));
        $this->post(route('login.post'), [
            'username' => $oldUsername,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->post(route('login.post'), [
            'username' => 'admin-baru',
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_wrong_password_or_guru_collision_cannot_change_admin_username(): void
    {
        $originalUsername = $this->admin->username;

        $this->actingAs($this->admin, 'web')
            ->put(route('admin.account.username.update'), [
                'username' => 'admin-ditolak',
                'current_password' => 'WrongPassword123!',
            ])
            ->assertSessionHasErrors('current_password', null, 'usernameUpdate')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertSame($originalUsername, $this->admin->fresh()->username);

        $this->put(route('admin.account.username.update'), [
            'username' => strtoupper($this->guru->username),
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username', null, 'usernameUpdate');

        $this->put(route('admin.account.username.update'), [
            'username' => strtoupper($this->guru->email),
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username', null, 'usernameUpdate');

        $this->guru->delete();
        $this->put(route('admin.account.username.update'), [
            'username' => $this->guru->email,
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username', null, 'usernameUpdate');

        $this->assertSame($originalUsername, $this->admin->fresh()->username);
    }

    public function test_current_admin_identifiers_do_not_trigger_false_username_collision(): void
    {
        $this->actingAs($this->admin, 'web')
            ->put(route('admin.account.username.update'), [
                'username' => $this->admin->username,
                'current_password' => self::ADMIN_PASSWORD,
            ])
            ->assertRedirect(route('admin.account.edit'));

        $this->put(route('admin.account.username.update'), [
            'username' => $this->admin->email,
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.account.edit'));

        $this->assertSame($this->admin->email, $this->admin->fresh()->username);
    }

    public function test_admin_can_change_email_and_old_reset_token_is_invalidated(): void
    {
        Notification::fake();
        $oldEmail = $this->admin->email;
        $oldUsername = $this->admin->username;
        $oldPasswordHash = $this->admin->password;
        $verifiedAt = now()->subDay()->startOfSecond();
        $this->admin->forceFill([
            'email_verified_at' => $verifiedAt,
            'remember_token' => 'email-remember-token',
        ])->save();
        Password::broker('users')->createToken($this->admin);
        Password::broker('gurus')->createToken($this->guru);

        $response = $this->actingAs($this->admin, 'web')
            ->put(route('admin.account.email.update'), [
                'email' => '  ADMIN-BARU@EXAMPLE.TEST  ',
                'current_password' => self::ADMIN_PASSWORD,
            ]);

        $response->assertRedirect(route('admin.account.edit'))
            ->assertSessionHas('success', 'Email Admin berhasil diubah.')
            ->assertSessionMissing('_old_input.current_password');

        $freshAdmin = $this->admin->fresh();
        $this->assertSame('admin-baru@example.test', $freshAdmin->email);
        $this->assertSame($oldUsername, $freshAdmin->username);
        $this->assertSame($oldPasswordHash, $freshAdmin->password);
        $this->assertTrue($freshAdmin->email_verified_at->equalTo($verifiedAt));
        $this->assertNotSame('email-remember-token', $freshAdmin->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertDatabaseHas('guru_password_reset_tokens', ['email' => $this->guru->email]);
        $this->assertAuthenticatedAs($freshAdmin, 'web');

        $audit = AuditLog::query()->where('action', 'admin_email_changed')->sole();
        $this->assertSame('Admin mengubah email akun.', $audit->description);
        $this->assertSame(['email' => $oldEmail], $audit->old_values);
        $this->assertSame(['email' => 'admin-baru@example.test'], $audit->new_values);

        $this->post(route('logout'));
        $this->post(route('login.post'), [
            'username' => $oldEmail,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->post(route('login.post'), [
            'username' => 'admin-baru@example.test',
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));

        $this->post(route('password.email'), ['email' => 'admin-baru@example.test'])
            ->assertSessionHas('status', $this->genericRecoveryMessage());
        Notification::assertSentTo($freshAdmin, AdminResetPasswordNotification::class);
    }

    public function test_wrong_password_or_guru_collision_cannot_change_admin_email(): void
    {
        $originalEmail = $this->admin->email;
        $guruWithEmailUsername = $this->createGuru(
            'guru-reserved@example.test',
            'guru-reserved-contact@example.test',
            false
        );

        $this->actingAs($this->admin, 'web')
            ->put(route('admin.account.email.update'), [
                'email' => 'ditolak@example.test',
                'current_password' => 'WrongPassword123!',
            ])
            ->assertSessionHasErrors('current_password', null, 'emailUpdate')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertSame($originalEmail, $this->admin->fresh()->email);

        $this->put(route('admin.account.email.update'), [
            'email' => strtoupper($guruWithEmailUsername->username),
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('email', null, 'emailUpdate');

        $this->put(route('admin.account.email.update'), [
            'email' => strtoupper($this->guru->email),
            'current_password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('email', null, 'emailUpdate');

        $this->assertSame($originalEmail, $this->admin->fresh()->email);
    }

    public function test_username_and_email_changes_revoke_only_old_web_sessions(): void
    {
        foreach ([
            ['route' => 'admin.account.username.update', 'payload' => ['username' => 'admin-sesi-baru']],
            ['route' => 'admin.account.email.update', 'payload' => ['email' => 'admin-sesi-baru@example.test']],
        ] as $index => $change) {
            if ($index > 0) {
                DB::table('sessions')->delete();
            }

            $this->insertGuardSessionsWithOverlappingIds();
            $this->useDatabaseSessions();
            $this->actingAs($this->admin->fresh(), 'web')
                ->put(route($change['route']), array_merge($change['payload'], [
                    'current_password' => self::ADMIN_PASSWORD,
                ]))
                ->assertRedirect(route('admin.account.edit'));

            $this->assertDatabaseMissing('sessions', ['id' => 'admin-session-lama']);
            $this->assertDatabaseHas('sessions', ['id' => 'guru-session-tetap']);
            $this->assertAuthenticatedAs($this->admin->fresh(), 'web');
        }
    }

    public function test_unverified_guru_sees_verification_status_banner(): void
    {
        $this->actingAs($this->guru, 'guru');

        $html = Blade::render('<x-guru-email-verification-banner />');

        $this->assertStringContainsString('Email belum diverifikasi', $html);
        $this->assertStringContainsString($this->guru->email, $html);
        $this->assertStringContainsString(route('guru.verification.notice'), $html);

        $this->guru->forceFill(['email_verified_at' => now()])->save();

        $this->assertStringNotContainsString(
            'Email belum diverifikasi',
            Blade::render('<x-guru-email-verification-banner />')
        );
    }

    public function test_admin_token_resets_existing_admin_once_without_reopening_setup(): void
    {
        $newPassword = 'NewAdminPassword456!';
        $this->admin->forceFill(['remember_token' => 'admin-remember-token-before-reset'])->save();
        $token = Password::broker('users')->createToken($this->admin);

        $this->post(route('password.update'), $this->resetPayload($this->admin->email, $token, $newPassword))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $freshAdmin = $this->admin->fresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(Hash::check(self::ADMIN_PASSWORD, $freshAdmin->password));
        $this->assertTrue(Hash::check($newPassword, $freshAdmin->password));
        $this->assertNotSame('admin-remember-token-before-reset', $freshAdmin->remember_token);
        $this->assertNotNull($freshAdmin->remember_token);
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

    public function test_verified_guru_reset_without_remember_token_column_is_single_use_and_guard_isolated(): void
    {
        Notification::fake();
        $this->guru->forceFill(['email_verified_at' => now()])->save();
        $this->assertFalse(Schema::hasColumn('gurus', 'remember_token'));

        $this->post(route('password.email'), ['email' => $this->guru->email])
            ->assertRedirect()
            ->assertSessionHas('status', $this->genericRecoveryMessage());

        $token = null;
        Notification::assertSentTo(
            $this->guru,
            GuruResetPasswordNotification::class,
            function ($notification) use (&$token): bool {
                $token = $this->tokenFromNotification($notification, $this->guru);

                return true;
            }
        );
        $this->assertIsString($token);

        $this->insertGuardSessionsWithOverlappingIds();
        $this->useDatabaseSessions();

        $this->post(route('password.update'), $this->resetPayload($this->guru->email, $token, 'NewGuruPassword456!'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewGuruPassword456!', $this->guru->fresh()->password));
        $this->assertDatabaseMissing('guru_password_reset_tokens', ['email' => $this->guru->email]);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session-lama']);
        $this->assertDatabaseMissing('sessions', ['id' => 'guru-session-tetap']);
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));
        $this->assertGuest('web');
        $this->assertGuest('guru');

        $this->post(route('password.update'), $this->resetPayload(
            $this->guru->email,
            $token,
            'AnotherGuruPassword789!'
        ))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewGuruPassword456!', $this->guru->fresh()->password));
    }

    public function test_authenticated_admin_can_change_password_without_flashing_sensitive_input(): void
    {
        $newPassword = 'ChangedAdminPassword456!';
        $this->admin->forceFill(['remember_token' => 'password-remember-token'])->save();

        $response = $this->actingAs($this->admin, 'web')
            ->from(route('admin.account.edit').'#password')
            ->put(route('admin.password.change.update'), [
                'current_password' => self::ADMIN_PASSWORD,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

        $response->assertRedirect(route('admin.account.edit').'#password')
            ->assertSessionHas('success')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $freshAdmin = $this->admin->fresh();
        $this->assertTrue(Hash::check($newPassword, $freshAdmin->password));
        $this->assertNotSame('password-remember-token', $freshAdmin->remember_token);
        $this->assertAuthenticatedAs($freshAdmin, 'web');

        $audit = AuditLog::query()->where('action', 'admin_password_changed')->sole();
        $this->assertSame('Admin mengubah password akun.', $audit->description);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);
        $this->assertStringNotContainsString($newPassword, (string) $audit->description);

        $this->post(route('logout'));
        $this->post(route('login.post'), [
            'username' => $freshAdmin->username,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->post(route('login.post'), [
            'username' => $freshAdmin->username,
            'password' => $newPassword,
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_change_password_rejects_wrong_current_password_and_confirmation(): void
    {
        $this->actingAs($this->admin, 'web')
            ->from(route('admin.account.edit').'#password')
            ->put(route('admin.password.change.update'), [
                'current_password' => 'WrongPassword123!',
                'password' => 'ChangedAdminPassword456!',
                'password_confirmation' => 'DifferentPassword456!',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password');

        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $this->admin->fresh()->password));

        $this->put(route('admin.password.change.update'), [
            'current_password' => '',
            'password' => 'ChangedAdminPassword456!',
            'password_confirmation' => 'ChangedAdminPassword456!',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_admin_password_change_revokes_only_old_web_sessions(): void
    {
        $this->insertGuardSessionsWithOverlappingIds();
        $this->useDatabaseSessions();

        $this->actingAs($this->admin, 'web')
            ->put(route('admin.password.change.update'), [
                'current_password' => self::ADMIN_PASSWORD,
                'password' => 'ChangedAdminPassword456!',
                'password_confirmation' => 'ChangedAdminPassword456!',
            ])
            ->assertRedirect(route('admin.account.edit').'#password');

        $this->assertDatabaseMissing('sessions', ['id' => 'admin-session-lama']);
        $this->assertDatabaseHas('sessions', ['id' => 'guru-session-tetap']);
        $this->assertAuthenticatedAs($this->admin->fresh(), 'web');
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
            'guru_kelas', 'nilais', 'kkms', 'mata_pelajarans', 'kelas', 'profil_sekolah', 'tahun_ajarans', 'gurus', 'users',
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

        Schema::create('kkms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tahun_ajaran_id');
            $table->decimal('nilai', 5, 2);
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tahun_ajaran_id');
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
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
