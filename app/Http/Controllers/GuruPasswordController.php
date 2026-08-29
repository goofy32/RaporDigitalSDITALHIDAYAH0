<?php

namespace App\Http\Controllers;

use App\Models\Guru;
use App\Services\AuditService;
use App\Services\DatabaseSessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class GuruPasswordController extends Controller
{
    public function editReset(Guru $guru)
    {
        return view('data.reset_teacher_password', [
            'teacher' => $guru,
        ]);
    }

    public function updateReset(
        Request $request,
        Guru $guru,
        DatabaseSessionRevocationService $sessions
    )
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Password sementara wajib diisi.',
            'password.min' => 'Password sementara minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password sementara tidak cocok.',
        ]);

        try {
            DB::transaction(function () use ($guru, $sessions, $validated): void {
                $guru->forceFill([
                    'password' => Hash::make($validated['password']),
                    'must_change_password' => true,
                ])->save();

                $sessions->revokeOrFail('guru', $guru->getKey());

                AuditService::log(
                    'guru_password_reset',
                    Guru::class,
                    $guru->id,
                    'Admin mereset password guru.',
                    null,
                    ['must_change_password' => true]
                );
            });
        } catch (Throwable $exception) {
            Log::warning('Admin-assisted Guru password reset failed.', [
                'guru_id' => $guru->getKey(),
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'password' => 'Password guru belum berhasil direset. Silakan coba kembali.',
            ]);
        }

        return redirect()->route('teacher.show', $guru->id)
            ->with('success', 'Password guru berhasil direset. Guru wajib mengganti password saat login berikutnya.');
    }

    public function editForceChange()
    {
        $guru = Auth::guard('guru')->user();

        if (! $guru) {
            return redirect()->route('login');
        }

        if (! (bool) $guru->must_change_password) {
            return redirect()->route($this->dashboardRoute());
        }

        return view('auth.guru_force_password');
    }

    public function updateForceChange(Request $request)
    {
        $guru = Auth::guard('guru')->user();

        if (! $guru) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ]);

        if (Hash::check($validated['password'], $guru->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password baru tidak boleh sama dengan password sementara.',
            ]);
        }

        $guru->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        AuditService::log(
            'guru_password_changed',
            Guru::class,
            $guru->id,
            'Guru mengganti password setelah reset admin.',
            null,
            ['must_change_password' => false]
        );

        return redirect()->route($this->dashboardRoute())
            ->with('success', 'Password berhasil diganti.');
    }

    private function dashboardRoute(): string
    {
        return session('selected_role') === 'wali_kelas'
            ? 'wali_kelas.dashboard'
            : 'pengajar.dashboard';
    }
}
