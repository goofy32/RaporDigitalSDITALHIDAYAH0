<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset Password Guru</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50">
    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    <div class="p-4 sm:ml-64">
        <div class="max-w-2xl p-6 mx-auto mt-20 bg-white border border-gray-200 rounded-lg shadow-sm">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-green-700">Reset Password Guru</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Reset password untuk <span class="font-semibold text-gray-900">{{ $teacher->nama }}</span>.
                    Password lama tidak dapat dilihat. Setelah reset, guru wajib mengganti password saat login.
                </p>
            </div>

            @if(session('error'))
                <div class="p-4 mb-4 text-sm text-red-700 border border-red-200 rounded-lg bg-red-50">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="p-4 mb-4 text-sm text-red-700 border border-red-200 rounded-lg bg-red-50">
                    <ul class="pl-5 list-disc">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('teacher.reset-password.update', $teacher->id) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password Sementara Baru</label>
                    <div class="flex gap-2 mt-1">
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <button type="button" id="generate-password"
                            class="px-3 py-2 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100">
                            Generate
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        Minimal 8 karakter. Jangan gunakan password yang mudah ditebak.
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi Password Sementara</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                        class="block w-full mt-1 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                    <a href="{{ route('teacher.show', $teacher->id) }}"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                        Batal
                    </a>
                    <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                        Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('generate-password')?.addEventListener('click', () => {
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
            const password = Array.from({ length: 12 }, () => alphabet[Math.floor(Math.random() * alphabet.length)]).join('');
            document.getElementById('password').value = password;
            document.getElementById('password_confirmation').value = password;
        });
    </script>
</body>
</html>
