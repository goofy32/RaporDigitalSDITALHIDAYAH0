<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Guru;
use App\Models\User;
use App\Services\AccountIdentifierService;
use App\Services\DatabaseSessionRevocationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

class AdminPasswordController extends Controller
{
    private const GENERIC_RECOVERY_MESSAGE = 'Jika akun dan email Anda memenuhi syarat untuk pemulihan, petunjuk pengaturan ulang password akan dikirim. Jika Anda tidak menerima email, silakan hubungi Admin sekolah.';

    public function createForgot(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request, AccountIdentifierService $identifiers): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.string' => 'Email harus berupa teks.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
        ]);

        $email = $identifiers->normalizeEmail($validated['email']);
        $admin = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $guru = Guru::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        try {
            if (($admin ? 1 : 0) + ($guru ? 1 : 0) !== 1) {
                $this->consumeRecoveryTimebox();
            } elseif ($admin) {
                Password::broker('users')->sendResetLink(['email' => $admin->email]);
            } elseif ($guru?->hasVerifiedEmail()) {
                Password::broker('gurus')->sendResetLink(['email' => $guru->email]);
            } else {
                Password::broker('users')->sendResetLink(['email' => $email]);
            }
        } catch (Throwable $exception) {
            Log::error('Password recovery notification could not be sent.', [
                'exception' => $exception::class,
            ]);
        }

        return back()->with('status', self::GENERIC_RECOVERY_MESSAGE);
    }

    public function createReset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(
        Request $request,
        AccountIdentifierService $identifiers,
        DatabaseSessionRevocationService $sessions
    ): RedirectResponse
    {
        $input = $request->only(['token', 'email', 'password', 'password_confirmation']);
        $validator = Validator::make($input, [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'token.required' => 'Token pemulihan tidak tersedia.',
            'token.string' => 'Token pemulihan tidak valid.',
            'email.required' => 'Email wajib diisi.',
            'email.string' => 'Email harus berupa teks.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
            'password.required' => 'Password baru wajib diisi.',
            'password.string' => 'Password baru harus berupa teks.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
            'password_confirmation.string' => 'Konfirmasi password harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput(['email' => $input['email'] ?? '']);
        }

        $validated = $validator->validated();
        $email = $identifiers->normalizeEmail($validated['email']);
        $validated['email'] = $email;
        $admin = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $guru = Guru::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (($admin ? 1 : 0) + ($guru ? 1 : 0) !== 1 || ($guru && ! $guru->hasVerifiedEmail())) {
            return $this->invalidResetResponse($validated['email']);
        }

        $validated['email'] = $admin?->email ?? $guru->email;

        $broker = $admin ? 'users' : 'gurus';
        $guard = $admin ? 'web' : 'guru';
        $resetAccount = null;
        $status = Password::broker($broker)->reset(
            $validated,
            function (User|Guru $account, string $password) use (&$resetAccount): void {
                $attributes = [
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ];

                if ($account instanceof Guru) {
                    $attributes['must_change_password'] = false;
                }

                $account->forceFill($attributes)->save();
                $resetAccount = $account;
                event(new PasswordReset($account));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->invalidResetResponse($validated['email']);
        }

        if ($resetAccount instanceof User || $resetAccount instanceof Guru) {
            $sessions->revoke($guard, $resetAccount->getKey());
        }

        return redirect()->route('login')
            ->with('success', 'Password berhasil diatur ulang. Silakan masuk menggunakan password baru.');
    }

    public function edit(): View
    {
        return view('auth.admin-change-password');
    }

    public function update(Request $request, DatabaseSessionRevocationService $sessions): RedirectResponse
    {
        $input = $request->only(['current_password', 'password', 'password_confirmation']);
        $validator = Validator::make($input, [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.string' => 'Password saat ini harus berupa teks.',
            'password.required' => 'Password baru wajib diisi.',
            'password.string' => 'Password baru harus berupa teks.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
            'password_confirmation.string' => 'Konfirmasi password harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        if (! Hash::check($validator->validated()['current_password'], $admin->password)) {
            return back()->withErrors([
                'current_password' => 'Password saat ini tidak sesuai.',
            ]);
        }

        $admin->forceFill([
            'password' => $validator->validated()['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $sessions->revoke('web', $admin->getKey());
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect()->route('admin.password.change.edit')
            ->with('success', 'Password Admin berhasil diubah.');
    }

    private function consumeRecoveryTimebox(): void
    {
        app(Timebox::class)->call(
            static fn () => null,
            (int) config('auth.timebox_duration', 200000)
        );
    }

    private function invalidResetResponse(string $email): RedirectResponse
    {
        return back()
            ->withErrors(['email' => 'Tautan pemulihan tidak valid atau sudah kedaluwarsa.'])
            ->withInput(['email' => $email]);
    }
}
