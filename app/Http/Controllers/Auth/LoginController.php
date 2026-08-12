<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Guru;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\TahunAjaran;
use App\Services\AuditService;
use App\Services\AccountIdentifierService;
use App\Services\GuruSelectedRoleSessionState;
use App\Services\TahunAjaranContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function login(
        Request $request,
        GuruSelectedRoleSessionState $roleSessionState,
        AccountIdentifierService $identifiers
    )
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($credentials['username']);
        $password = $credentials['password'];
        $users = $identifiers->matchingUsers($identifier);
        $gurus = $identifiers->matchingGurus($identifier);

        if ($users->count() + $gurus->count() !== 1) {
            return $this->failedLogin($request, $identifier);
        }

        /** @var User|null $admin */
        $admin = $users->first();

        if ($admin && Hash::check($password, $admin->password)) {
            Auth::guard('web')->login($admin);
            $request->session()->regenerate();
            $request->session()->put('last_activity', time());
            AuditService::logLogin('success', $identifier);

            return redirect()->route('admin.dashboard');
        }

        /** @var Guru|null $guru */
        $guru = $gurus->first();

        if ($guru && Hash::check($password, $guru->password)) {
            Auth::guard('guru')->login($guru);
            $request->session()->regenerate();

            $selectedRole = $this->defaultGuruRole($guru);

            $roleSessionState->publish($request->session(), $selectedRole);
            $request->session()->put('last_activity', time());

            AuditService::logLogin('success', $identifier);

            if ((bool) $guru->must_change_password) {
                return redirect()->route('guru.force-password.edit');
            }

            return redirect()->route($this->dashboardRouteForRole($selectedRole));
        }
    
        return $this->failedLogin($request, $identifier);
    }

    public function switchRole(Request $request, string $role, GuruSelectedRoleSessionState $roleSessionState)
    {
        $guru = Auth::guard('guru')->user();

        if (!$guru) {
            return redirect()->route('login');
        }

        if (!in_array($role, ['pengajar', 'wali_kelas'], true)) {
            abort(403);
        }

        if (!in_array($role, $this->availableGuruRoles($guru), true)) {
            abort(403);
        }

        $selectedRoleBefore = $request->session()->get(GuruSelectedRoleSessionState::ROLE_KEY);
        $roleSessionState->publish($request->session(), $role);

        Log::info('Guru mengganti role aktif.', [
            'request_id' => (string) Str::uuid(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'selected_role_before' => $selectedRoleBefore,
            'target_role' => $role,
            'selected_role_after' => $request->session()->get(GuruSelectedRoleSessionState::ROLE_KEY),
        ]);

        return redirect()->route($this->dashboardRouteForRole($role));
    }

    private function defaultGuruRole(Guru $guru): string
    {
        $roles = $this->availableGuruRoles($guru);

        if (in_array('pengajar', $roles, true)) {
            return 'pengajar';
        }

        if (in_array('wali_kelas', $roles, true)) {
            return 'wali_kelas';
        }

        return 'pengajar';
    }

    private function availableGuruRoles(Guru $guru): array
    {
        $tahunAjaran = $this->currentTahunAjaran();

        return $guru->availableRoles(
            $tahunAjaran?->id,
            $tahunAjaran?->semester
        );
    }

    private function currentTahunAjaran(): ?TahunAjaran
    {
        $context = app(TahunAjaranContext::class);

        if ($context->selected()) {
            return $context->selected();
        }

        $tahunAjaranId = session('tahun_ajaran_id');

        if ($tahunAjaranId) {
            return TahunAjaran::find($tahunAjaranId);
        }

        return TahunAjaran::where('is_active', true)->first();
    }

    private function dashboardRouteForRole(string $role): string
    {
        return $role === 'wali_kelas'
            ? 'wali_kelas.dashboard'
            : 'pengajar.dashboard';
    }

    public function logout(Request $request)
    {
        $message = 'Anda telah berhasil logout.';
        
        // Log logout event before actually logging out
        AuditService::logLogout();

        if ($request->hasSession()) {
            app(GuruSelectedRoleSessionState::class)->forget($request->session());
        }
        
        // Clear all possible auth guards
        Auth::guard('web')->logout();
        Auth::guard('guru')->logout();
    
        // Completely invalidate and regenerate session
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        // Clear all session data
        $request->session()->flush();
        
        // If it's an AJAX request (like from session timeout)
        if ($request->wantsJson() || $request->hasHeader('Turbo-Frame')) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'redirect' => route('login')
            ]);
        }
    
        return redirect()->route('login')
            ->with('success', $message)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    private function failedLogin(Request $request, string $identifier)
    {
        AuditService::logLogin('failed', $identifier);

        return back()->withErrors([
            'username' => 'Username, email, atau password salah.',
        ])->withInput($request->except('password'));
    }

    protected function authenticated(Request $request, $user)
    {
        $profilSekolah = ProfilSekolah::first();
        $tahunAjaran = TahunAjaran::first();
        
        if (!$profilSekolah || !$tahunAjaran) {
            if (!$profilSekolah && !$tahunAjaran) {
                session()->flash('warning', 'Selamat datang! Silakan lengkapi Profil Sekolah dan buat Tahun Ajaran terlebih dahulu.');
            } elseif (!$profilSekolah) {
                session()->flash('warning', 'Selamat datang! Silakan lengkapi Profil Sekolah terlebih dahulu.');
            } else {
                session()->flash('warning', 'Selamat datang! Silakan buat Tahun Ajaran terlebih dahulu.');
            }
        }
    }
}
