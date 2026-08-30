<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminVerifyNewEmailNotification;
use App\Services\AccountIdentifierService;
use App\Services\AdminPendingEmailChangeService;
use App\Services\AuditService;
use App\Services\DatabaseSessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminAccountController extends Controller
{
    public function edit(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        return view('auth.admin-account-settings', compact('admin'));
    }

    public function updateUsername(
        Request $request,
        AccountIdentifierService $identifiers,
        DatabaseSessionRevocationService $sessions
    ): RedirectResponse {
        $username = $request->input('username');

        if (is_string($username)) {
            $username = trim($username);
        }

        $input = [
            'username' => $username,
            'current_password' => $request->input('current_password'),
        ];
        $validator = Validator::make($input, [
            'username' => ['required', 'string', 'max:255'],
            'current_password' => ['required', 'string'],
        ], [
            'username.required' => 'Username baru wajib diisi.',
            'username.string' => 'Username baru harus berupa teks.',
            'username.max' => 'Username baru maksimal 255 karakter.',
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.string' => 'Password saat ini harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator, 'usernameUpdate')
                ->withInput(['username' => $input['username']]);
        }

        /** @var User $admin */
        $admin = Auth::guard('web')->user();
        $validated = $validator->validated();

        if (! Hash::check($validated['current_password'], $admin->password)) {
            return back()
                ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'], 'usernameUpdate')
                ->withInput(['username' => $validated['username']]);
        }

        if (
            $identifiers->conflictsWithOtherUser((int) $admin->getKey(), $validated['username'], null)
            || $identifiers->conflictsWithGuru($validated['username'], null)
        ) {
            return back()
                ->withErrors(['username' => 'Username tersebut sudah digunakan.'], 'usernameUpdate')
                ->withInput(['username' => $validated['username']]);
        }

        $oldUsername = $admin->username;

        try {
            DB::transaction(function () use ($admin, $oldUsername, $validated): void {
                $admin->forceFill([
                    'username' => $validated['username'],
                    'remember_token' => Str::random(60),
                ])->save();

                AuditService::log(
                    'admin_username_changed',
                    User::class,
                    (int) $admin->getKey(),
                    'Admin mengubah username akun.',
                    ['username' => $oldUsername],
                    ['username' => $admin->username]
                );
            });
        } catch (ValidationException) {
            return back()
                ->withErrors(['username' => 'Username tersebut sudah digunakan.'], 'usernameUpdate')
                ->withInput(['username' => $validated['username']]);
        }

        $this->preserveCurrentSession($request, $sessions, $admin);

        return redirect()->route('admin.account.edit')
            ->with('success', 'Username Admin berhasil diubah.');
    }

    public function updateEmail(
        Request $request,
        AccountIdentifierService $identifiers,
        AdminPendingEmailChangeService $emailChanges
    ): RedirectResponse {
        $input = [
            'email' => $identifiers->normalizeEmail($request->input('email')),
            'current_password' => $request->input('current_password'),
        ];
        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string'],
        ], [
            'email.required' => 'Email baru wajib diisi.',
            'email.string' => 'Email baru harus berupa teks.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email baru maksimal 255 karakter.',
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.string' => 'Password saat ini harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator, 'emailUpdate')
                ->withInput(['email' => $input['email']]);
        }

        /** @var User $admin */
        $admin = Auth::guard('web')->user();
        $validated = $validator->validated();

        if (! Hash::check($validated['current_password'], $admin->password)) {
            return back()
                ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'], 'emailUpdate')
                ->withInput(['email' => $validated['email']]);
        }

        try {
            $pending = $emailChanges->initiate((int) $admin->getKey(), $validated['email']);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors(), 'emailUpdate')
                ->withInput(['email' => $validated['email']]);
        }

        try {
            Notification::route('mail', $pending['email'])->notifyNow(
                new AdminVerifyNewEmailNotification(
                    (int) $admin->getKey(),
                    (string) $admin->name,
                    $pending['token']
                )
            );
        } catch (Throwable $exception) {
            $emailChanges->clearIfTokenHashMatches(
                (int) $admin->getKey(),
                $pending['token_hash']
            );

            Log::warning('Admin new email verification could not be sent.', [
                'user_id' => $admin->getKey(),
                'exception' => $exception::class,
            ]);

            return back()->with(
                'error',
                'Email verifikasi belum dapat dikirim. Silakan coba lagi nanti.'
            );
        }

        return redirect()->route('admin.account.edit')
            ->with('success', 'Tautan verifikasi telah dikirim. Email aktif belum berubah.');
    }

    public function verifyEmail(
        Request $request,
        int $user,
        #[\SensitiveParameter] string $token,
        AdminPendingEmailChangeService $emailChanges
    ): RedirectResponse {
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        if ((int) $admin->getKey() !== $user) {
            return redirect()->route('admin.account.edit')->with(
                'error',
                'Tautan verifikasi tidak berlaku untuk akun Admin yang sedang digunakan.'
            );
        }

        try {
            $result = $emailChanges->activate($user, $token);
        } catch (Throwable $exception) {
            Log::warning('Admin new email activation failed.', [
                'user_id' => $admin->getKey(),
                'exception' => $exception::class,
            ]);

            return redirect()->route('admin.account.edit')->with(
                'error',
                'Perubahan email belum dapat diselesaikan. Silakan coba lagi nanti.'
            );
        }

        if ($result === AdminPendingEmailChangeService::COLLISION) {
            return redirect()->route('admin.account.edit')->with(
                'error',
                'Email baru tidak dapat digunakan. Silakan ajukan perubahan email kembali.'
            );
        }

        if ($result !== AdminPendingEmailChangeService::ACTIVATED) {
            return redirect()->route('admin.account.edit')->with(
                'error',
                'Tautan verifikasi tidak valid, sudah kedaluwarsa, atau sudah digunakan.'
            );
        }

        $admin = User::query()->findOrFail($user);
        $this->reestablishCurrentSession($request, $admin);

        return redirect()->route('admin.account.edit')
            ->with('success', 'Email Admin berhasil diverifikasi dan diaktifkan.');
    }

    public function cancelEmailChange(AdminPendingEmailChangeService $emailChanges): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('web')->user();
        $cancelled = $emailChanges->cancel((int) $admin->getKey());

        return redirect()->route('admin.account.edit')->with(
            $cancelled ? 'success' : 'status',
            $cancelled
                ? 'Perubahan email yang menunggu verifikasi telah dibatalkan.'
                : 'Tidak ada perubahan email yang menunggu verifikasi.'
        );
    }

    private function preserveCurrentSession(
        Request $request,
        DatabaseSessionRevocationService $sessions,
        User $admin
    ): void {
        $sessions->revoke('web', $admin->getKey());
        $this->reestablishCurrentSession($request, $admin);
    }

    private function reestablishCurrentSession(Request $request, User $admin): void
    {
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        Auth::guard('web')->setUser($admin);
    }
}
