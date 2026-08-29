<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-cache-control" content="no-cache">
    <title>Lupa Password - Rapor Digital SDIT Al-Hidayah Logam</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4">
    <main class="w-full max-w-md bg-white p-8 shadow-lg">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo Sekolah" class="mx-auto mb-4 h-24 w-24 object-contain">
            <h1 class="text-2xl font-bold text-green-700">Lupa Password</h1>
            <p class="mt-2 text-sm text-gray-600">Masukkan username atau email yang terdaftar pada akun Anda.</p>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf
            <div>
                <label for="identifier" class="mb-1 block text-sm font-medium text-gray-700">Username atau Email</label>
                <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus autocomplete="username" maxlength="255"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('identifier') border-red-500 @else border-gray-300 @enderror"
                    @error('identifier') aria-invalid="true" aria-describedby="identifier-error" @enderror>
                @error('identifier')
                    <p id="identifier-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                Kirim Petunjuk Pemulihan
            </button>
        </form>

        <a href="{{ route('login') }}" class="mt-5 block text-center text-sm font-medium text-green-700 hover:underline">
            Kembali ke Login
        </a>
    </main>
</body>
</html>
