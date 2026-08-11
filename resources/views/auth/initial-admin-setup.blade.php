<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-cache-control" content="no-cache">
    <title>Setup Admin - Rapor Digital SDIT Al-Hidayah Logam</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4">
    <main class="w-full max-w-lg bg-white p-6 shadow-md sm:p-8">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo Sekolah"
                class="mx-auto mb-3 h-24 w-24 object-contain">
            <h1 class="text-2xl font-bold text-green-700">Setup Admin Pertama</h1>
            <p class="mt-2 text-sm text-gray-600">Buat satu-satunya akun Admin untuk mengelola aplikasi.</p>
        </div>

        @if ($errors->any())
            <div class="mb-5 rounded-md bg-red-50 p-4 text-sm text-red-800" role="alert">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('initial-admin-setup.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Nama</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('name') border-red-500 @else border-gray-300 @enderror"
                    @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name')
                    <p id="name-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-700">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}" required autocomplete="username"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('username') border-red-500 @else border-gray-300 @enderror"
                    @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                @error('username')
                    <p id="username-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('email') border-red-500 @else border-gray-300 @enderror"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email')
                    <p id="email-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password') border-red-500 @else border-gray-300 @enderror"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                @error('password')
                    <p id="password-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700">Konfirmasi Password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password_confirmation') border-red-500 @else border-gray-300 @enderror"
                    @error('password_confirmation') aria-invalid="true" aria-describedby="password-confirmation-error" @enderror>
                @error('password_confirmation')
                    <p id="password-confirmation-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="setup_token" class="mb-1 block text-sm font-medium text-gray-700">Token Setup</label>
                <input id="setup_token" name="setup_token" type="password" required autocomplete="off"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('setup_token') border-red-500 @else border-gray-300 @enderror"
                    @error('setup_token') aria-invalid="true" aria-describedby="setup-token-error" @enderror>
                @error('setup_token')
                    <p id="setup-token-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                class="w-full rounded-md bg-green-700 px-4 py-2 font-medium text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                Buat Admin
            </button>
        </form>
    </main>
</body>

</html>
