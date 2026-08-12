<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-cache-control" content="no-cache">
    <title>Atur Ulang Password - Rapor Digital SDIT Al-Hidayah Logam</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4">
    <main class="w-full max-w-md bg-white p-8 shadow-lg">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo Sekolah" class="mx-auto mb-4 h-24 w-24 object-contain">
            <h1 class="text-2xl font-bold text-green-700">Atur Ulang Password</h1>
            <p class="mt-2 text-sm text-gray-600">Masukkan email dan password baru untuk melanjutkan.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('email') border-red-500 @else border-gray-300 @enderror"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email')
                    <p id="email-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Password Baru</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password') border-red-500 @else border-gray-300 @enderror"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                @error('password')
                    <p id="password-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password_confirmation') border-red-500 @else border-gray-300 @enderror">
                @error('password_confirmation')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                Simpan Password Baru
            </button>
        </form>

        <a href="{{ route('login') }}" class="mt-5 block text-center text-sm font-medium text-green-700 hover:underline">
            Kembali ke Login
        </a>
    </main>
</body>
</html>
