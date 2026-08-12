<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InitialAdminSetupTest extends TestCase
{
    private string $setupToken;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('cache')->forgetDriver('array');
        Cache::flush();

        $this->createSchema();

        $this->setupToken = Str::random(64);
        $this->password = Str::random(24).'9!';
        config()->set('initial_admin_setup.token_hash', hash('sha256', $this->setupToken));
    }

    public function test_setup_is_available_only_when_no_user_exists_and_token_is_configured(): void
    {
        $this->get(route('initial-admin-setup.create'))
            ->assertOk()
            ->assertSee('Setup Admin Pertama')
            ->assertSee('name="setup_token"', false);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_token_configuration_makes_get_and_post_unavailable(): void
    {
        config()->set('initial_admin_setup.token_hash');

        $this->get(route('initial-admin-setup.create'))->assertNotFound();
        $this->post(route('initial-admin-setup.store'), $this->validPayload())->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_token_does_not_create_user(): void
    {
        $wrongToken = Str::random(64);

        $this->from(route('initial-admin-setup.create'))
            ->post(route('initial-admin-setup.store'), $this->validPayload([
                'setup_token' => $wrongToken,
            ]))
            ->assertRedirect(route('initial-admin-setup.create'))
            ->assertSessionHasErrors('setup_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_token_is_only_accepted_from_post_body(): void
    {
        $payload = $this->validPayload();
        unset($payload['setup_token']);

        $this->post(route('initial-admin-setup.store').'?setup_token='.urlencode($this->setupToken), $payload)
            ->assertSessionHasErrors('setup_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_fields_show_clear_indonesian_field_errors(): void
    {
        $response = $this->from(route('initial-admin-setup.create'))
            ->followingRedirects()
            ->post(route('initial-admin-setup.store'), []);

        $response->assertOk()
            ->assertSee('Nama wajib diisi.')
            ->assertSee('Username wajib diisi.')
            ->assertSee('Email wajib diisi.')
            ->assertSee('Password wajib diisi.')
            ->assertSee('Konfirmasi password wajib diisi.')
            ->assertSee('Token setup wajib diisi.')
            ->assertSee('id="name-error"', false)
            ->assertSee('id="username-error"', false)
            ->assertSee('id="email-error"', false)
            ->assertSee('id="password-error"', false)
            ->assertSee('id="password-confirmation-error"', false)
            ->assertSee('id="setup-token-error"', false)
            ->assertDontSee('validation.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_email_short_password_and_confirmation_show_clear_messages_without_echoing_secrets(): void
    {
        $shortPassword = Str::random(7);
        $confirmation = Str::random(12);
        $token = Str::random(64);

        $response = $this->from(route('initial-admin-setup.create'))
            ->followingRedirects()
            ->post(route('initial-admin-setup.store'), $this->validPayload([
                'email' => 'email-tidak-valid',
                'password' => $shortPassword,
                'password_confirmation' => $confirmation,
                'setup_token' => $token,
            ]));

        $response->assertOk()
            ->assertSee('Format email tidak valid.')
            ->assertSee('Password minimal 8 karakter.')
            ->assertSee('Konfirmasi password tidak cocok.')
            ->assertDontSee('validation.')
            ->assertDontSee($shortPassword)
            ->assertDontSee($confirmation)
            ->assertDontSee($token);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_setup_token_shows_clear_message_without_echoing_token(): void
    {
        $wrongToken = Str::random(64);

        $response = $this->from(route('initial-admin-setup.create'))
            ->followingRedirects()
            ->post(route('initial-admin-setup.store'), $this->validPayload([
                'setup_token' => $wrongToken,
            ]));

        $response->assertOk()
            ->assertSee('Token setup tidak valid.')
            ->assertSee('id="setup-token-error"', false)
            ->assertDontSee('validation.')
            ->assertDontSee($wrongToken);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_valid_setup_creates_exactly_one_hashed_user_without_auto_login(): void
    {
        $this->post(route('initial-admin-setup.store'), $this->validPayload())
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('users', 1);
        $user = User::query()->sole();

        $this->assertSame('admin-awal', $user->username);
        $this->assertNotSame($this->password, $user->password);
        $this->assertTrue(Hash::check($this->password, $user->password));
        $this->assertGuest('web');
        $this->assertGuest('guru');
    }

    public function test_created_admin_can_login_with_username(): void
    {
        $this->createInitialAdmin();
        $user = User::query()->sole();

        $this->post(route('login.post'), [
            'username' => $user->username,
            'password' => $this->password,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_created_admin_can_login_with_email(): void
    {
        $this->createInitialAdmin();
        $user = User::query()->sole();

        $this->post(route('login.post'), [
            'username' => $user->email,
            'password' => $this->password,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_second_setup_attempt_is_unavailable(): void
    {
        $this->createInitialAdmin();

        $this->get(route('initial-admin-setup.create'))->assertNotFound();
        $this->post(route('initial-admin-setup.store'), $this->validPayload([
            'username' => 'admin-lain',
            'email' => 'admin-lain@example.test',
        ]))->assertNotFound();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_rejects_identifier_already_used_by_guru_without_flashing_secrets(): void
    {
        DB::table('gurus')->insert([
            'nama' => 'Guru Konflik',
            'username' => 'guru-konflik',
            'email' => 'guru-konflik@example.test',
            'password' => Hash::make('GuruPassword123!'),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->from(route('initial-admin-setup.create'))
            ->post(route('initial-admin-setup.store'), $this->validPayload([
                'email' => 'guru-konflik@example.test',
            ]))
            ->assertRedirect(route('initial-admin-setup.create'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.setup_token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_invariant_rejects_direct_second_user_insertion(): void
    {
        $this->createInitialAdmin();

        try {
            User::query()->create([
                'name' => 'Admin Lain',
                'username' => 'admin-lain',
                'email' => 'admin-lain@example.test',
                'password' => Str::random(24),
            ]);

            $this->fail('Database menerima row Admin kedua.');
        } catch (QueryException) {
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_setup_post_is_protected_from_request_forgery(): void
    {
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');

        $this->post(route('initial-admin-setup.store'), $this->validPayload())
            ->assertStatus(419);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_post_is_rate_limited(): void
    {
        $payload = $this->validPayload([
            'setup_token' => Str::random(64),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('initial-admin-setup.store'), $payload)
                ->assertRedirect();
        }

        $this->post(route('initial-admin-setup.store'), $payload)
            ->assertTooManyRequests();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_token_and_password_are_not_leaked_to_response_session_or_audit_data(): void
    {
        $wrongToken = Str::random(64);
        $response = $this->from(route('initial-admin-setup.create'))
            ->post(route('initial-admin-setup.store'), $this->validPayload([
                'setup_token' => $wrongToken,
            ]));

        $response->assertDontSee($wrongToken)
            ->assertDontSee($this->password)
            ->assertSessionMissing('_old_input.setup_token')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $oldInput = $response->getSession()->getOldInput();
        $this->assertSame(['name', 'username', 'email'], array_keys($oldInput));

        $this->createInitialAdmin();
        $auditPayload = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($this->setupToken, $auditPayload);
        $this->assertStringNotContainsString($this->password, $auditPayload);
    }

    public function test_authenticated_admin_cannot_use_setup(): void
    {
        $this->createInitialAdmin();
        $admin = User::query()->sole();

        $this->actingAs($admin, 'web')
            ->get(route('initial-admin-setup.create'))
            ->assertNotFound();

        $this->actingAs($admin, 'web')
            ->post(route('initial-admin-setup.store'), $this->validPayload())
            ->assertNotFound();
    }

    public function test_authenticated_guru_cannot_use_setup_and_guru_guard_remains_active(): void
    {
        $guru = Guru::query()->create([
            'nama' => 'Guru Setup Test',
            'username' => 'guru-setup-test',
            'email' => 'guru-setup@example.test',
            'password' => Str::random(24),
        ]);

        $this->actingAs($guru, 'guru')
            ->get(route('initial-admin-setup.create'))
            ->assertNotFound();

        $this->assertAuthenticatedAs($guru, 'guru');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('gurus', 1);
    }

    public function test_setup_uses_runtime_config_value_compatible_with_config_cache(): void
    {
        $runtimeConfigToken = Str::random(64);
        config()->set('initial_admin_setup.token_hash', hash('sha256', $runtimeConfigToken));

        $this->post(route('initial-admin-setup.store'), $this->validPayload([
            'setup_token' => $runtimeConfigToken,
        ]))->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Administrator Sekolah',
            'username' => 'admin-awal',
            'email' => 'admin-awal@example.test',
            'password' => $this->password,
            'password_confirmation' => $this->password,
            'setup_token' => $this->setupToken,
        ], $overrides);
    }

    private function createInitialAdmin(): void
    {
        $this->post(route('initial-admin-setup.store'), $this->validPayload())
            ->assertRedirect(route('login'));
    }

    private function createSchema(): void
    {
        foreach (['audit_logs', 'profil_sekolah', 'tahun_ajarans', 'gurus', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
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

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->unsignedTinyInteger('semester')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
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
    }
}
