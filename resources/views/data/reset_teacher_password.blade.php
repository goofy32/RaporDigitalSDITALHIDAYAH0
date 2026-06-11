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
        <div class="max-w-xl mx-auto mt-20">
            <div class="mb-4">
                <a href="{{ route('teacher.show', $teacher->id) }}"
                    class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-green-700">
                    Kembali ke detail guru
                </a>
            </div>

            <div class="overflow-hidden bg-white border border-gray-200 rounded-lg shadow-sm">
                <div class="px-6 py-5 border-b border-gray-100 bg-green-50">
                    <p class="text-xs font-semibold tracking-wide text-green-700 uppercase">Keamanan Akun Guru</p>
                    <h1 class="mt-1 text-2xl font-bold text-gray-900">Reset Password Guru</h1>
                    <p class="mt-2 text-sm leading-6 text-gray-700">
                        Buat password sementara untuk <span class="font-semibold">{{ $teacher->nama }}</span>.
                        Password lama tidak dapat dilihat oleh admin.
                    </p>
                </div>

                <div class="p-6">
                    <div class="p-4 mb-5 text-sm text-yellow-800 border border-yellow-200 rounded-lg bg-yellow-50">
                        Setelah reset, guru wajib mengganti password saat login berikutnya.
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
                            <label for="password" class="block text-sm font-medium text-gray-700">Password sementara baru</label>
                            <div class="flex flex-col gap-2 mt-1 sm:flex-row">
                                <div class="relative flex-1">
                                    <input id="password" name="password" type="password" autocomplete="new-password" required
                                        class="block w-full pr-11 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <button type="button"
                                        class="absolute inset-y-0 right-0 flex items-center justify-center w-11 text-gray-500 hover:text-green-700"
                                        data-password-toggle="password"
                                        aria-label="Tampilkan atau sembunyikan password sementara baru">
                                        <svg class="w-5 h-5 eye-open" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M10 3.5c-4.2 0-7.4 3.8-8.4 5.1a2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1s7.4-3.8 8.4-5.1a2.2 2.2 0 000-2.8c-1-1.3-4.2-5.1-8.4-5.1zm0 10.2a3.7 3.7 0 110-7.4 3.7 3.7 0 010 7.4zm0-1.6a2.1 2.1 0 100-4.2 2.1 2.1 0 000 4.2z" />
                                        </svg>
                                        <svg class="hidden w-5 h-5 eye-closed" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M3.3 2.3a1 1 0 00-1.4 1.4l2.2 2.2a15.1 15.1 0 00-2.5 2.7 2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1 1.3 0 2.5-.4 3.7-.9l2.6 2.6a1 1 0 001.4-1.4L3.3 2.3zm6.7 11.4a3.7 3.7 0 01-3.7-3.7c0-.7.2-1.3.5-1.8l1.4 1.4V10a1.8 1.8 0 002.2 1.8l1.4 1.4c-.5.3-1.1.5-1.8.5zm8.4-2.3c-.4.6-1.4 1.7-2.7 2.7L13.5 12a3.7 3.7 0 00-5.5-5.5L6.3 4.8c1.1-.7 2.3-1.3 3.7-1.3 4.2 0 7.4 3.8 8.4 5.1.6.8.6 2 0 2.8z" />
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" id="generate-password"
                                    class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100">
                                    Generate
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Minimal 8 karakter. Tombol Generate akan mengisi password dan konfirmasi sekaligus.
                            </p>
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi password sementara</label>
                            <div class="relative mt-1">
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                                    class="block w-full pr-11 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <button type="button"
                                    class="absolute inset-y-0 right-0 flex items-center justify-center w-11 text-gray-500 hover:text-green-700"
                                    data-password-toggle="password_confirmation"
                                    aria-label="Tampilkan atau sembunyikan konfirmasi password sementara">
                                    <svg class="w-5 h-5 eye-open" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 3.5c-4.2 0-7.4 3.8-8.4 5.1a2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1s7.4-3.8 8.4-5.1a2.2 2.2 0 000-2.8c-1-1.3-4.2-5.1-8.4-5.1zm0 10.2a3.7 3.7 0 110-7.4 3.7 3.7 0 010 7.4zm0-1.6a2.1 2.1 0 100-4.2 2.1 2.1 0 000 4.2z" />
                                    </svg>
                                    <svg class="hidden w-5 h-5 eye-closed" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M3.3 2.3a1 1 0 00-1.4 1.4l2.2 2.2a15.1 15.1 0 00-2.5 2.7 2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1 1.3 0 2.5-.4 3.7-.9l2.6 2.6a1 1 0 001.4-1.4L3.3 2.3zm6.7 11.4a3.7 3.7 0 01-3.7-3.7c0-.7.2-1.3.5-1.8l1.4 1.4V10a1.8 1.8 0 002.2 1.8l1.4 1.4c-.5.3-1.1.5-1.8.5zm8.4-2.3c-.4.6-1.4 1.7-2.7 2.7L13.5 12a3.7 3.7 0 00-5.5-5.5L6.3 4.8c1.1-.7 2.3-1.3 3.7-1.3 4.2 0 7.4 3.8 8.4 5.1.6.8.6 2 0 2.8z" />
                                    </svg>
                                </button>
                            </div>
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
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                if (!input) {
                    return;
                }

                const showPassword = input.type === 'password';
                input.type = showPassword ? 'text' : 'password';
                button.querySelector('.eye-open')?.classList.toggle('hidden', showPassword);
                button.querySelector('.eye-closed')?.classList.toggle('hidden', !showPassword);
            });
        });

        document.getElementById('generate-password')?.addEventListener('click', () => {
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
            const password = Array.from({ length: 12 }, () => alphabet[Math.floor(Math.random() * alphabet.length)]).join('');
            document.getElementById('password').value = password;
            document.getElementById('password_confirmation').value = password;
        });
    </script>
</body>
</html>
