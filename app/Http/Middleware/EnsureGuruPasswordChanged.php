<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuruPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $guru = Auth::guard('guru')->user();

        if (! $guru || ! (bool) $guru->must_change_password) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Password harus diganti sebelum melanjutkan.',
                'redirect' => route('guru.force-password.edit'),
            ], 403);
        }

        return redirect()->route('guru.force-password.edit')
            ->with('warning', 'Password sementara harus diganti sebelum melanjutkan.');
    }
}
