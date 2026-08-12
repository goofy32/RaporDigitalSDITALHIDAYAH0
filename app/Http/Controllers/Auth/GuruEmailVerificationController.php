<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Guru;
use App\Services\GuruEmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class GuruEmailVerificationController extends Controller
{
    public function notice(): View|RedirectResponse
    {
        /** @var Guru $guru */
        $guru = Auth::guard('guru')->user();

        if ($guru->hasVerifiedEmail()) {
            return redirect()->route($this->destinationRoute($guru));
        }

        return view('auth.verify-email', ['guru' => $guru]);
    }

    public function send(GuruEmailVerificationService $verification): RedirectResponse
    {
        /** @var Guru $guru */
        $guru = Auth::guard('guru')->user();
        $verification->sendIfRequired($guru);

        return back()->with(
            'status',
            'Jika alamat email tersedia dan belum diverifikasi, tautan verifikasi akan dikirim.'
        );
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        /** @var Guru $guru */
        $guru = Auth::guard('guru')->user();

        abort_unless($guru->getKey() === $id, 403);
        abort_unless(
            is_string($guru->email)
                && $guru->email !== ''
                && hash_equals(sha1($guru->getEmailForVerification()), $hash),
            403
        );

        if (! $guru->hasVerifiedEmail()) {
            $guru->markEmailAsVerified();
            event(new Verified($guru));
        }

        return redirect()->route($this->destinationRoute($guru))
            ->with('success', 'Email berhasil diverifikasi dan dapat digunakan untuk pemulihan password.');
    }

    private function destinationRoute(Guru $guru): string
    {
        if ((bool) $guru->must_change_password) {
            return 'guru.force-password.edit';
        }

        return session('selected_role') === 'wali_kelas'
            ? 'wali_kelas.dashboard'
            : 'pengajar.dashboard';
    }
}
