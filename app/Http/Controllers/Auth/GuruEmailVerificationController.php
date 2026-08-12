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
        $sent = $verification->sendIfRequired($guru);

        if (! $sent && is_string($guru->email) && $guru->email !== '' && ! $guru->hasVerifiedEmail()) {
            return back()->with('error', 'Email verifikasi belum dapat dikirim. Silakan coba lagi nanti.');
        }

        return back()->with(
            'status',
            'Jika alamat email tersedia dan belum diverifikasi, tautan verifikasi akan dikirim.'
        );
    }

    public function sendAsAdmin(Guru $guru, GuruEmailVerificationService $verification): RedirectResponse
    {
        if (! is_string($guru->email) || $guru->email === '') {
            return back()->with('error', 'Alamat email Guru belum tersedia.');
        }

        if ($guru->hasVerifiedEmail()) {
            return back()->with('success', 'Email Guru tersebut sudah diverifikasi.');
        }

        if (! $verification->sendIfRequired($guru)) {
            return back()->with('error', 'Email verifikasi belum dapat dikirim. Silakan coba lagi nanti.');
        }

        return back()->with('success', 'Email verifikasi berhasil dikirim ke '.$guru->email.'.');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('admin.dashboard')->with(
                'error',
                'Tautan ini digunakan untuk verifikasi email Guru. Anda sedang masuk sebagai Admin. Silakan keluar dan masuk menggunakan akun Guru yang terkait.'
            );
        }

        if (! Auth::guard('guru')->check()) {
            return redirect()->guest(route('login'))->with(
                'error',
                'Untuk memverifikasi email, silakan masuk menggunakan akun Guru yang terkait.'
            );
        }

        /** @var Guru $guru */
        $guru = Auth::guard('guru')->user();

        if ($guru->getKey() !== $id) {
            return redirect()->route($this->destinationRoute($guru))->with(
                'error',
                'Tautan verifikasi ini bukan untuk akun Guru yang sedang digunakan.'
            );
        }

        if (
            ! is_string($guru->email)
            || $guru->email === ''
            || ! hash_equals(sha1($guru->getEmailForVerification()), $hash)
        ) {
            return redirect()->route($this->destinationRoute($guru))->with(
                'error',
                'Tautan verifikasi tidak lagi berlaku untuk alamat email saat ini.'
            );
        }

        if ($guru->hasVerifiedEmail()) {
            return redirect()->route($this->destinationRoute($guru))
                ->with('success', 'Email sudah diverifikasi.');
        }

        $guru->markEmailAsVerified();
        event(new Verified($guru));

        return redirect()->route($this->destinationRoute($guru))
            ->with('success', 'Email berhasil diverifikasi.');
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
