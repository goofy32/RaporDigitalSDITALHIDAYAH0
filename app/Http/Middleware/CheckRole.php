<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        // Handle admin role
        if ($role === 'admin') {
            if (!Auth::guard('web')->check()) {
                return redirect()->route('login');
            }
            return $next($request);
        }
        
        // Handle guru/pengajar dan wali_kelas roles
        if (in_array($role, ['guru', 'pengajar', 'wali_kelas'])) {
            // Pastikan user login sebagai guru
            if (!Auth::guard('guru')->check()) {
                return redirect()->route('login');
            }

            $selectedRole = session('selected_role');
            
            // Fallback aman untuk session lama/kosong
            if (!$selectedRole && Auth::guard('guru')->check()) {
                session(['selected_role' => 'pengajar']);
                $selectedRole = 'pengajar';
            }

            $normalizedRequestedRole = $role === 'guru' ? 'pengajar' : $role;
            $normalizedSelectedRole = $selectedRole === 'guru' ? 'pengajar' : $selectedRole;

            // Pastikan role yang diminta sesuai dengan yang dipilih saat login
            if ($normalizedRequestedRole === $normalizedSelectedRole) {
                return $next($request);
            }
    
            // Jika mencoba akses role yang berbeda, tampilkan error
            return response()->view('errors.role-mismatch', [
                'current_role' => $selectedRole,
                'attempted_role' => $role
            ], 403);
        }
        
        // Jika role tidak dikenal
        return redirect()->route('login')
            ->with('error', 'Unauthorized access');
    }
}
