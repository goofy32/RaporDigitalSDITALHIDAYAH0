<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\SyncGuruSelectedRoleSession;
use App\Http\Middleware\TahunAjaranMiddleware;
use App\Models\Guru;
use App\Services\GuruSelectedRoleSessionState;
use App\Services\TahunAjaranContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class GuruRoleSwitchTest extends TestCase
{
    private Guru $multiRoleGuru;

    private Guru $pengajarOnlyGuru;

    private int $tahunAjaranId;

    protected function setUp(): void
    {
        parent::setUp();

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
        $response = $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession($this->roleSession('wali_kelas'))
            ->post(route('auth.switch.role', ['role' => 'pengajar']), $this->csrfPayload())
            ->assertRedirect(route('pengajar.dashboard'))
            ->assertSessionHas('selected_role', 'pengajar');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('Akses Tidak Sesuai Role')
            ->assertDontSee('Pilih Role Pengajar');
    }

    public function test_valid_wali_switch_redirects_to_wali_dashboard(): void
    {
        $response = $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession($this->roleSession('pengajar'))
            ->post(route('auth.switch.role', ['role' => 'wali_kelas']), $this->csrfPayload())
            ->assertRedirect(route('wali_kelas.dashboard'))
            ->assertSessionHas('selected_role', 'wali_kelas');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('Akses Tidak Sesuai Role');
    }

    public function test_get_switch_route_no_longer_changes_role(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'wali_kelas',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('auth.switch.role', ['role' => 'pengajar']))
            ->assertStatus(405)
            ->assertSessionHas('selected_role', 'wali_kelas');
    }

    public function test_post_switch_without_csrf_is_rejected(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app->instance('env', 'production');

        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('auth.switch.role', ['role' => 'wali_kelas']))
            ->assertStatus(419)
            ->assertSessionHas('selected_role', 'pengajar');
    }

    public function test_invalid_role_switch_is_denied(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession($this->roleSession('pengajar'))
            ->post(route('auth.switch.role', ['role' => 'admin']), $this->csrfPayload())
            ->assertForbidden();
    }

    public function test_guru_without_active_year_wali_assignment_cannot_switch_to_wali(): void
    {
        $this->actingAs($this->pengajarOnlyGuru, 'guru')
            ->withSession($this->roleSession('pengajar'))
            ->post(route('auth.switch.role', ['role' => 'wali_kelas']), $this->csrfPayload())
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
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('data-turbo="false"', $html);
        $this->assertStringContainsString('data-turbo-prefetch="false"', $html);
        $this->assertStringContainsString('data-role-switch-submit', $html);
    }

    public function test_profile_dropdown_switches_from_wali_to_pengajar(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $html = view('components.admin.topbar')->render();

        $this->assertStringContainsString('Beralih ke Pengajar', $html);
        $this->assertStringContainsString(route('auth.switch.role', ['role' => 'pengajar']), $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('data-turbo="false"', $html);
        $this->assertStringContainsString('data-turbo-prefetch="false"', $html);
        $this->assertStringContainsString('data-role-switch-submit', $html);
    }

    public function test_role_specific_navigation_is_not_turbo_permanent(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $this->assertStringNotContainsString('data-turbo-permanent', view('components.admin.topbar')->render());
        $this->assertStringNotContainsString(
            'data-turbo-permanent',
            view('components.wali-kelas.sidebar')->render()
        );
        $this->assertStringNotContainsString(
            'data-turbo-permanent',
            view('components.pengajar.sidebar')->render()
        );
    }

    public function test_direct_pengajar_dashboard_request_with_wali_role_is_still_rejected(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'wali_kelas',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('pengajar.dashboard'))
            ->assertForbidden()
            ->assertSee('Akses Tidak Sesuai Role');
    }

    public function test_direct_wali_dashboard_request_with_pengajar_role_is_still_rejected(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('wali_kelas.dashboard'))
            ->assertForbidden()
            ->assertSee('Akses Tidak Sesuai Role');
    }

    public function test_wali_student_edit_actions_render_in_header_and_submit_the_form(): void
    {
        $this->actingAs($this->multiRoleGuru, 'guru');
        session([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);
        view()->share('errors', new ViewErrorBag());

        $html = view('wali_kelas.edit_student', [
            'student' => (object) [
                'id' => 10,
                'nis' => '1001',
                'nisn' => '2001',
                'nama' => 'Siswa Contoh',
                'tanggal_lahir' => '2015-01-01',
                'jenis_kelamin' => 'Laki-laki',
                'agama' => 'Islam',
                'alamat' => 'Alamat',
                'photo' => null,
                'nama_ayah' => 'Ayah',
                'nama_ibu' => 'Ibu',
                'pekerjaan_ayah' => null,
                'pekerjaan_ibu' => null,
                'alamat_orangtua' => null,
                'wali_siswa' => null,
                'pekerjaan_wali' => null,
            ],
            'kelas' => (object) [
                'id' => 5,
                'nomor_kelas' => 5,
                'nama_kelas' => 'A',
            ],
        ])->render();

        $titlePosition = strpos($html, 'Form Edit Data Siswa');
        $formPosition = strpos($html, 'id="wali-student-edit-form"');
        $buttonPosition = strpos($html, 'form="wali-student-edit-form"');
        $backPosition = strpos($html, route('wali_kelas.student.index'));

        $this->assertNotFalse($titlePosition);
        $this->assertNotFalse($formPosition);
        $this->assertNotFalse($buttonPosition);
        $this->assertNotFalse($backPosition);
        $this->assertLessThan($formPosition, $buttonPosition);
        $this->assertLessThan($formPosition, $backPosition);
        $this->assertSame(1, substr_count($html, 'form="wali-student-edit-form"'));
        $this->assertSame(1, substr_count($html, '>Update</button>'));
        $this->assertSame(1, substr_count($html, '>Kembali</a>'));
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
        $this->assertStringContainsString('method="POST"', $content);
        $this->assertStringContainsString('data-turbo="false"', $content);
        $this->assertStringContainsString('data-turbo-prefetch="false"', $content);
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

    public function test_tahun_ajaran_middleware_initializes_reusable_request_context(): void
    {
        session([
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);

        $request = Request::create('/_test/context', 'GET');
        $request->setLaravelSession(app('session.store'));

        $response = app(TahunAjaranMiddleware::class)->handle($request, function (Request $request) {
            /** @var TahunAjaranContext $context */
            $context = app(TahunAjaranContext::class);

            $this->assertTrue($context->isInitialized());
            $this->assertSame($this->tahunAjaranId, $context->selectedId());
            $this->assertSame(1, $context->semester());
            $this->assertSame($this->tahunAjaranId, $context->systemActive()?->id);
            $this->assertTrue($context->hasActiveTahunAjaran());
            $this->assertTrue($context->hasAnyTahunAjaran());
            $this->assertSame($context, $request->attributes->get('tahun_ajaran_context'));

            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
    }

    public function test_tahun_ajaran_context_handles_empty_year_state_without_exception(): void
    {
        DB::table('nilais')->delete();
        DB::table('kkms')->delete();
        DB::table('mata_pelajarans')->delete();
        DB::table('guru_kelas')->delete();
        DB::table('kelas')->delete();
        DB::table('tahun_ajarans')->delete();
        Cache::flush();
        session()->flush();

        $request = Request::create('/_test/no-year', 'GET');
        $request->setLaravelSession(app('session.store'));

        $response = app(TahunAjaranMiddleware::class)->handle($request, function () {
            /** @var TahunAjaranContext $context */
            $context = app(TahunAjaranContext::class);

            $this->assertTrue($context->isInitialized());
            $this->assertNull($context->selected());
            $this->assertNull($context->systemActive());
            $this->assertFalse($context->hasActiveTahunAjaran());
            $this->assertFalse($context->hasAnyTahunAjaran());
            $this->assertTrue(session('no_tahun_ajaran'));

            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
    }

    public function test_available_roles_are_memoized_for_the_same_guru_year_and_semester(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $roles = $this->multiRoleGuru->availableRoles($this->tahunAjaranId, 1);
        $queryCountAfterFirstCall = count(DB::getQueryLog());

        $this->assertSame(['pengajar', 'wali_kelas'], $roles);
        $this->assertGreaterThan(0, $queryCountAfterFirstCall);

        $this->assertSame(['pengajar', 'wali_kelas'], $this->multiRoleGuru->availableRoles($this->tahunAjaranId, 1));
        $this->assertSame($queryCountAfterFirstCall, count(DB::getQueryLog()));

        DB::disableQueryLog();
    }

    public function test_overlapping_successful_request_cannot_restore_previous_selected_role(): void
    {
        $this->useStableSession('role-overlap-session');

        Route::middleware('web')->get('/_test/role-overlap-background', function (Request $request) {
            app(GuruSelectedRoleSessionState::class)->publish($request->session(), 'pengajar');

            // Simulates the stale in-memory payload from a request that started
            // before the switch and finishes after the switch.
            $request->session()->put(GuruSelectedRoleSessionState::ROLE_KEY, 'wali_kelas');
            $request->session()->put(GuruSelectedRoleSessionState::VERSION_KEY, 1);

            return response()->json(['ok' => true]);
        });

        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession($this->roleSession('wali_kelas'))
            ->get('/_test/role-overlap-background')
            ->assertOk()
            ->assertSessionHas('selected_role', 'pengajar');

        $this->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertDontSee('Akses Tidak Sesuai Role');
    }

    public function test_stale_dashboard_request_reconciles_before_role_middleware(): void
    {
        $this->useStableSession('role-stale-dashboard-session');

        Route::middleware('web')->get('/_test/prime-authoritative-role', function (Request $request) {
            app(GuruSelectedRoleSessionState::class)->publish($request->session(), 'pengajar');

            return response('ok');
        });

        $this->actingAs($this->multiRoleGuru, 'guru')
            ->withSession($this->roleSession('wali_kelas'))
            ->get('/_test/prime-authoritative-role')
            ->assertOk()
            ->assertSessionHas('selected_role', 'pengajar');

        $session = app('session.store');
        $session->put(GuruSelectedRoleSessionState::ROLE_KEY, 'wali_kelas');
        $session->put(GuruSelectedRoleSessionState::VERSION_KEY, 1);
        $session->save();

        $this->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertDontSee('Akses Tidak Sesuai Role')
            ->assertSessionHas('selected_role', 'pengajar');
    }

    public function test_forbidden_response_can_reconcile_without_changing_status(): void
    {
        $session = app('session.store');
        $session->setId('role-forbidden-session');
        $session->start();
        $session->put(GuruSelectedRoleSessionState::ROLE_KEY, 'wali_kelas');
        $session->put(GuruSelectedRoleSessionState::VERSION_KEY, 1);

        $state = app(GuruSelectedRoleSessionState::class);
        $state->publish($session, 'pengajar');

        $session->put(GuruSelectedRoleSessionState::ROLE_KEY, 'wali_kelas');
        $session->put(GuruSelectedRoleSessionState::VERSION_KEY, 1);

        $request = Request::create('/wali-kelas/dashboard', 'GET');
        $request->setLaravelSession($session);

        $response = app(SyncGuruSelectedRoleSession::class)->handle(
            $request,
            fn () => response('forbidden', 403)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('pengajar', $session->get(GuruSelectedRoleSessionState::ROLE_KEY));
    }

    public function test_role_publish_versions_are_monotonic_and_payload_is_consistent(): void
    {
        $session = app('session.store');
        $session->setId('role-publish-session');
        $session->start();

        $state = app(GuruSelectedRoleSessionState::class);
        $firstVersion = $state->publish($session, 'pengajar');
        $secondVersion = $state->publish($session, 'wali_kelas');

        $this->assertGreaterThan($firstVersion, $secondVersion);

        $session->put(GuruSelectedRoleSessionState::ROLE_KEY, 'pengajar');
        $session->put(GuruSelectedRoleSessionState::VERSION_KEY, $firstVersion);

        $this->assertTrue($state->reconcile($session));
        $this->assertSame('wali_kelas', $session->get(GuruSelectedRoleSessionState::ROLE_KEY));
        $this->assertSame($secondVersion, $session->get(GuruSelectedRoleSessionState::VERSION_KEY));
    }

    private function roleSession(string $role): array
    {
        return [
            'selected_role' => $role,
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
            '_token' => 'role-switch-token',
        ];
    }

    private function csrfPayload(): array
    {
        return [
            '_token' => 'role-switch-token',
        ];
    }

    private function useStableSession(string $id): void
    {
        $id = substr(hash('sha256', $id), 0, 40);
        $session = app('session.store');
        $session->setId($id);

        $this->withCookie($session->getName(), $id);
    }

    private function createSchema(): void
    {
        foreach ([
            'nilai_ekstrakurikuler',
            'absensis',
            'siswa_kelas_semester',
            'siswas',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
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

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->string('judul_lingkup_materi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->string('tujuan_pembelajaran')->nullable();
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
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_unique_context');
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('ekstrakurikuler_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
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
