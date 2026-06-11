<?php

namespace App\Http\Controllers;

use App\Models\Guru;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GuruPasswordController extends Controller
{
    public function editReset(Guru $guru)
    {
        return view('data.reset_teacher_password', [
            'teacher' => $guru,
        ]);
    }

    public function updateReset(Request $request, Guru $guru)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Password sementara wajib diisi.',
            'password.min' => 'Password sementara minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password sementara tidak cocok.',
        ]);

        $guru->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
        ])->save();

        AuditService::log(
            'guru_password_reset',
            Guru::class,
            $guru->id,
            'Admin mereset password guru.',
            null,
            ['must_change_password' => true]
        );

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
