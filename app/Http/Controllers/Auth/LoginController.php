<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Guru;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\AuditService;

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

            session([
                'selected_role' => 'pengajar',
                'last_activity' => time(),
            ]);

            AuditService::logLogin('success', $identifier);

            return redirect()->route('pengajar.dashboard');
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

        if (!in_array($role, ['pengajar', 'wali_kelas'])) {
            return redirect()->back()
                ->with('error', 'Role tidak valid.');
        }

        if ($role === 'wali_kelas' && !$guru->isWaliKelas()) {
            return redirect()->back()
                ->with('error', 'Anda bukan wali kelas.');
        }

        session(['selected_role' => $role]);

        if ($role === 'wali_kelas') {
            return redirect()->route('wali_kelas.dashboard');
        }

        return redirect()->route('pengajar.dashboard');
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
