<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ganti Password</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50">
    <main class="flex items-center justify-center min-h-screen px-4 py-10">
        <div class="w-full max-w-md p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
            <h1 class="text-2xl font-bold text-green-700">Ganti Password</h1>
            <p class="mt-2 text-sm text-gray-600">
                Password Anda direset oleh admin. Silakan buat password baru sebelum melanjutkan.
            </p>

            @if(session('warning'))
                <div class="p-3 mt-4 text-sm text-yellow-800 border border-yellow-200 rounded-lg bg-yellow-50">
                    {{ session('warning') }}
                </div>
            @endif

            @if($errors->any())
                <div class="p-3 mt-4 text-sm text-red-700 border border-red-200 rounded-lg bg-red-50">
                    <ul class="pl-5 list-disc">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form id="force-password-form" method="POST" action="{{ route('guru.force-password.update') }}" class="mt-6 space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password Baru</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required
                        class="block w-full mt-1 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                    <p class="mt-1 text-xs text-gray-500">Minimal 8 karakter dan berbeda dari password sementara.</p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                        class="block w-full mt-1 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                </div>

                <div class="flex items-center justify-between gap-3 pt-2">
                    <button type="submit" form="logout-form" class="text-sm font-medium text-gray-600 hover:text-gray-900">
                        Logout
                    </button>
                    <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                        Simpan Password
                    </button>
                </div>
            </form>
            <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
                @csrf
            </form>
        </div>
    </main>
</body>
</html>
