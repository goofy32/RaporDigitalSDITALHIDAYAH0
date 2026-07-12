<?php

namespace App\Http\Middleware;

use App\Models\Guru;
use App\Models\TahunAjaran;
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

            /** @var Guru|null $guru */
            $guru = Auth::guard('guru')->user();

            if (!$guru || (method_exists($guru, 'trashed') && $guru->trashed())) {
                Auth::guard('guru')->logout();
                $request->session()->forget('selected_role');

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Akun guru sudah tidak aktif. Silakan hubungi admin.',
                    ], 403);
                }

                return redirect()->route('login')
                    ->with('error', 'Akun guru sudah tidak aktif. Silakan hubungi admin.');
            }

            $selectedRole = session('selected_role');
            $normalizedRequestedRole = $this->normalizeGuruRole($role);
            $normalizedSelectedRole = $this->normalizeGuruRole($selectedRole);
            $availableRoles = $this->availableGuruRoles($guru);
            
            // Fallback aman untuk session lama/kosong
            if (!$normalizedSelectedRole && in_array($normalizedRequestedRole, $availableRoles, true)) {
                session(['selected_role' => $normalizedRequestedRole]);
                $selectedRole = $normalizedRequestedRole;
                $normalizedSelectedRole = $normalizedRequestedRole;
            }

            if (!$normalizedSelectedRole || !in_array($normalizedSelectedRole, $availableRoles, true)) {
                $request->session()->forget('selected_role');

                return $this->roleMismatchResponse(
                    $request,
                    $selectedRole,
                    $role,
                    'Role guru sudah tidak tersedia. Silakan pilih role kembali atau hubungi admin.',
                    $availableRoles
                );
            }

            // Pastikan role yang diminta sesuai dengan yang dipilih saat login
            if ($normalizedRequestedRole === $normalizedSelectedRole) {
                return $next($request);
            }
    
            // Jika mencoba akses role yang berbeda, tampilkan error
            return $this->roleMismatchResponse($request, $selectedRole, $role, availableRoles: $availableRoles);
        }
        
        // Jika role tidak dikenal
        return redirect()->route('login')
            ->with('error', 'Unauthorized access');
    }

    private function normalizeGuruRole(?string $role): ?string
    {
        return $role === 'guru' ? 'pengajar' : $role;
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
        $tahunAjaranId = session('tahun_ajaran_id');

        if ($tahunAjaranId) {
            return TahunAjaran::find($tahunAjaranId);
        }

        return TahunAjaran::where('is_active', true)->first();
    }

    private function roleMismatchResponse(
        Request $request,
        ?string $selectedRole,
        string $attemptedRole,
        string $message = 'Anda tidak memiliki akses ke role ini.',
        array $availableRoles = []
    ) {
        $normalizedAttemptedRole = $this->normalizeGuruRole($attemptedRole);
        $normalizedSelectedRole = $this->normalizeGuruRole($selectedRole);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
            ], 403);
        }

        return response()->view('errors.role-mismatch', [
            'current_role' => $normalizedSelectedRole,
            'attempted_role' => $normalizedAttemptedRole,
            'available_roles' => $availableRoles,
            'message' => $message,
        ], 403);
    }
}
