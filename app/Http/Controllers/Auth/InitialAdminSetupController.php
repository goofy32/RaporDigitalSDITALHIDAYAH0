<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountIdentifierService;
use App\Services\InitialAdminSetupService;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InitialAdminSetupController extends Controller
{
    public function create(InitialAdminSetupService $setup): View
    {
        $this->ensureAvailable($setup);

        return view('auth.initial-admin-setup');
    }

    public function store(
        Request $request,
        InitialAdminSetupService $setup,
        AccountIdentifierService $identifiers
    ): RedirectResponse
    {
        $this->ensureAvailable($setup);

        $input = Arr::only($request->request->all(), [
            'name',
            'username',
            'email',
            'password',
            'password_confirmation',
            'setup_token',
        ]);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'setup_token' => ['required', 'string', 'max:512'],
        ], [
            'name.required' => 'Nama wajib diisi.',
            'name.string' => 'Nama harus berupa teks.',
            'name.max' => 'Nama maksimal 255 karakter.',
            'username.required' => 'Username wajib diisi.',
            'username.string' => 'Username harus berupa teks.',
            'username.max' => 'Username maksimal 255 karakter.',
            'username.unique' => 'Username tersebut sudah digunakan.',
            'email.required' => 'Email wajib diisi.',
            'email.string' => 'Email harus berupa teks.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email tersebut sudah digunakan.',
            'password.required' => 'Password wajib diisi.',
            'password.string' => 'Password harus berupa teks.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
            'password_confirmation.string' => 'Konfirmasi password harus berupa teks.',
            'setup_token.required' => 'Token setup wajib diisi.',
            'setup_token.string' => 'Token setup harus berupa teks.',
            'setup_token.max' => 'Token setup maksimal 512 karakter.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($this->safeOldInput($input));
        }

        $validated = $validator->validated();
        $providedToken = (string) $validated['setup_token'];

        if ($identifiers->conflictsWithGuru($validated['username'], $validated['email'])) {
            return back()
                ->withErrors(['email' => 'Username atau email tersebut sudah digunakan.'])
                ->withInput($this->safeOldInput($input));
        }

        if (! $setup->tokenMatches($providedToken)) {
            return back()
                ->withErrors(['setup_token' => 'Token setup tidak valid.'])
                ->withInput($this->safeOldInput($input));
        }

        try {
            $user = $setup->create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ], $providedToken);
        } catch (LockTimeoutException) {
            return back()
                ->withErrors(['setup' => 'Setup Admin sedang diproses. Silakan coba kembali.'])
                ->withInput($this->safeOldInput($input));
        } catch (QueryException $exception) {
            if (User::query()->exists()) {
                abort(404);
            }

            throw $exception;
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->withInput($this->safeOldInput($input));
        }

        abort_if($user === null, 404);

        return redirect()->route('login')
            ->with('success', 'Admin berhasil dibuat. Silakan masuk menggunakan akun tersebut.');
    }

    private function ensureAvailable(InitialAdminSetupService $setup): void
    {
        abort_if(
            Auth::guard('web')->check()
                || Auth::guard('guru')->check()
                || ! $setup->isAvailable(),
            404
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function safeOldInput(array $input): array
    {
        return Arr::only($input, ['name', 'username', 'email']);
    }
}
