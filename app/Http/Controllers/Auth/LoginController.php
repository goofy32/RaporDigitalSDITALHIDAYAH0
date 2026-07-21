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
use App\Services\TahunAjaranContext;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = $credentials['username'];
        $password = $credentials['password'];

        if (
            Auth::guard('web')->attempt(['username' => $identifier, 'password' => $password]) ||
            Auth::guard('web')->attempt(['email' => $identifier, 'password' => $password])
        ) {
            session(['last_activity' => time()]);
            AuditService::logLogin('success', $identifier);

            return redirect()->route('admin.dashboard');
        }

        $guru = Guru::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($guru && Hash::check($password, $guru->password)) {
            Auth::guard('guru')->login($guru);

            $selectedRole = $this->defaultGuruRole($guru);

            session([
                'selected_role' => $selectedRole,
                'last_activity' => time(),
            ]);

            AuditService::logLogin('success', $identifier);

            if ((bool) $guru->must_change_password) {
                return redirect()->route('guru.force-password.edit');
            }

            return redirect()->route($this->dashboardRouteForRole($selectedRole));
        }
    
        // Log failed login attempt
        AuditService::logLogin('failed', $identifier);
        
        return back()->withErrors([
            'username' => 'Username atau password salah.',
        ])->withInput($request->except('password'));
    }

    public function switchRole(string $role)
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

        session(['selected_role' => $role]);

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
    
        return redirect('/login')
            ->with('success', $message)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
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
