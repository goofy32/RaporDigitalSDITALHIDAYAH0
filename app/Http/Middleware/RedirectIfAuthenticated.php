<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        // Cek jika user sudah login sebagai admin
        if (Auth::guard('web')->check()) {
            return redirect()->route('admin.dashboard');
        }

        // Cek jika user sudah login sebagai guru
        if (Auth::guard('guru')->check()) {
            $selectedRole = session('selected_role');

            if ($selectedRole === 'wali_kelas') {
                return redirect()->route('wali_kelas.dashboard');
            } else if (in_array($selectedRole, ['guru', 'pengajar', null], true)) {
                return redirect()->route('pengajar.dashboard');
            }
        }

        return $next($request);
    }
}
