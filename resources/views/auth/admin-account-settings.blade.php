@extends('layouts.app')

@section('title', 'Pengaturan Akun Admin')

@push('styles')
<style>
    .admin-account-sensitive-input {
        border-color: #D1D5DB !important;
    }

    .admin-account-sensitive-input[aria-invalid="true"] {
        border-color: #EF4444 !important;
    }

    .admin-account-sensitive-input:focus {
        border-color: #22C55E !important;
    }
</style>
@endpush

@section('content')
<div class="mt-6 w-full space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-green-700">Pengaturan Akun Admin</h1>
            <p class="mt-1 text-sm text-gray-600">Kelola username, email, dan password untuk akun Admin sekolah.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-4 text-sm text-green-800" role="status">
            {{ session('success') }}
        </div>
    @endif

    <dl class="grid gap-4 rounded-lg border border-green-100 bg-green-50 p-4 sm:grid-cols-3 sm:p-5">
        <div class="min-w-0">
            <dt class="text-xs font-semibold uppercase text-green-700">Nama Admin</dt>
            <dd class="mt-1 break-words text-sm font-medium text-gray-900">{{ $admin->name }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs font-semibold uppercase text-green-700">Username</dt>
            <dd class="mt-1 break-words text-sm font-medium text-gray-900">{{ $admin->username }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs font-semibold uppercase text-green-700">Email</dt>
            <dd class="mt-1 break-words text-sm font-medium text-gray-900">{{ $admin->email }}</dd>
        </div>
    </dl>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-lg bg-white p-4 shadow-lg sm:p-6" aria-labelledby="username-heading">
            <h2 id="username-heading" class="text-lg font-semibold text-green-700">Ubah Username</h2>
            <p class="mt-1 text-sm text-gray-600">Username saat ini: <span class="font-medium text-gray-900">{{ $admin->username }}</span></p>

            <form method="POST" action="{{ route('admin.account.username.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="username" class="mb-1 block text-sm font-medium text-gray-700">Username Baru</label>
                    <input id="username" name="username" type="text" required maxlength="255" autocomplete="username"
                        value="{{ old('username', $admin->username) }}"
                        class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 {{ $errors->usernameUpdate->has('username') ? 'border-red-500' : 'border-gray-300' }}">
                    @if ($errors->usernameUpdate->has('username'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->usernameUpdate->first('username') }}</p>
                    @endif
                </div>

                <div>
                    <label for="username_current_password" class="mb-1 block text-sm font-medium text-gray-700">Password Saat Ini</label>
                    <input id="username_current_password" name="current_password" type="password" required autocomplete="current-password"
                        aria-invalid="{{ $errors->usernameUpdate->has('current_password') ? 'true' : 'false' }}"
                        class="admin-account-sensitive-input w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 {{ $errors->usernameUpdate->has('current_password') ? 'border-red-500' : 'border-gray-300' }}">
                    @if ($errors->usernameUpdate->has('current_password'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->usernameUpdate->first('current_password') }}</p>
                    @endif
                </div>

                <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Ubah Username
                </button>
            </form>
        </section>

        <section class="rounded-lg bg-white p-4 shadow-lg sm:p-6" aria-labelledby="email-heading">
            <h2 id="email-heading" class="text-lg font-semibold text-green-700">Ubah Email</h2>
            <p class="mt-1 text-sm text-gray-600">Email saat ini: <span class="font-medium text-gray-900">{{ $admin->email }}</span></p>

            <form method="POST" action="{{ route('admin.account.email.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email Baru</label>
                    <input id="email" name="email" type="email" required maxlength="255" autocomplete="email"
                        value="{{ old('email', $admin->email) }}"
                        class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 {{ $errors->emailUpdate->has('email') ? 'border-red-500' : 'border-gray-300' }}">
                    @if ($errors->emailUpdate->has('email'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->emailUpdate->first('email') }}</p>
                    @endif
                </div>

                <div>
                    <label for="email_current_password" class="mb-1 block text-sm font-medium text-gray-700">Password Saat Ini</label>
                    <input id="email_current_password" name="current_password" type="password" required autocomplete="current-password"
                        aria-invalid="{{ $errors->emailUpdate->has('current_password') ? 'true' : 'false' }}"
                        class="admin-account-sensitive-input w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 {{ $errors->emailUpdate->has('current_password') ? 'border-red-500' : 'border-gray-300' }}">
                    @if ($errors->emailUpdate->has('current_password'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->emailUpdate->first('current_password') }}</p>
                    @endif
                </div>

                <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Ubah Email
                </button>
            </form>
        </section>

        <section id="password" class="rounded-lg bg-white p-4 shadow-lg sm:p-6" aria-labelledby="password-heading">
            <h2 id="password-heading" class="text-lg font-semibold text-green-700">Ubah Password</h2>
            <p class="mt-1 text-sm text-gray-600">Gunakan minimal 8 karakter untuk password baru.</p>

            <form id="admin-change-password-form" method="POST" action="{{ route('admin.password.change.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password" class="mb-1 block text-sm font-medium text-gray-700">Password Saat Ini</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                        aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                        class="admin-account-sensitive-input w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('current_password') border-red-500 @else border-gray-300 @enderror">
                    @error('current_password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Password Baru</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                        aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                        class="admin-account-sensitive-input w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password') border-red-500 @else border-gray-300 @enderror">
                    @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                        aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                        class="admin-account-sensitive-input w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password_confirmation') border-red-500 @else border-gray-300 @enderror">
                    @error('password_confirmation')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Ubah Password
                </button>
            </form>
        </section>
    </div>
</div>
@endsection
