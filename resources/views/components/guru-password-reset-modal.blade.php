@props(['teacher'])

@php
    $showResetModal = old('reset_password_modal') === '1';
@endphp

<style>
    .reset-password-input::-ms-reveal,
    .reset-password-input::-ms-clear {
        display: none;
    }

    .reset-password-input::-webkit-credentials-auto-fill-button,
    .reset-password-input::-webkit-contacts-auto-fill-button {
        display: none !important;
        visibility: hidden;
        pointer-events: none;
    }
</style>

<div
    class="fixed inset-0 z-50 {{ $showResetModal ? '' : 'hidden' }} overflow-y-auto"
    data-guru-password-reset-modal
    aria-labelledby="guru-password-reset-title"
    aria-modal="true"
    role="dialog"
>
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-50 transition-opacity" data-guru-password-reset-close></div>

        <div class="relative w-full max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 id="guru-password-reset-title" class="text-xl font-semibold text-gray-900">Reset Password Guru</h2>
                <p class="mt-1 text-sm text-gray-600">Buat password sementara agar guru bisa login kembali.</p>
            </div>

            <form method="POST" action="{{ route('teacher.reset-password.update', $teacher->id) }}" class="p-5 space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="reset_password_modal" value="1">

                <div class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs sm:grid-cols-3">
                    <div>
                        <p class="font-medium text-gray-500">Nama</p>
                        <p class="mt-1 text-gray-900">{{ $teacher->nama }}</p>
                    </div>
                    <div>
                        <p class="font-medium text-gray-500">Username</p>
                        <p class="mt-1 text-gray-900">{{ $teacher->username ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="font-medium text-gray-500">Email</p>
                        <p class="mt-1 text-gray-900">{{ $teacher->email ?? '-' }}</p>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs leading-5 text-gray-600">
                    <p>Password lama tidak dapat ditampilkan demi keamanan.</p>
                    <p>Password ini bersifat sementara. Guru wajib mengganti password saat login berikutnya.</p>
                </div>

                @if($showResetModal && $errors->any())
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <ul class="list-disc pl-5">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <label for="reset_password" class="block text-sm font-medium text-gray-700">Password sementara untuk login awal</label>
                    <div class="mt-1 flex flex-col gap-2 sm:flex-row sm:items-start">
                        <div class="relative w-full sm:max-w-sm">
                            <input id="reset_password" name="password" type="password" autocomplete="new-password" required
                                class="reset-password-input block w-full rounded-md border-gray-300 pr-12 shadow-sm focus:border-green-500 focus:ring-green-500">
                            <button type="button"
                                class="absolute inset-y-0 right-0 z-10 flex w-11 items-center justify-center text-gray-500 hover:text-green-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500"
                                data-password-toggle="reset_password"
                                aria-label="Tampilkan atau sembunyikan password sementara untuk login awal">
                                <svg class="h-5 w-5 eye-open" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M10 3.5c-4.2 0-7.4 3.8-8.4 5.1a2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1s7.4-3.8 8.4-5.1a2.2 2.2 0 000-2.8c-1-1.3-4.2-5.1-8.4-5.1zm0 10.2a3.7 3.7 0 110-7.4 3.7 3.7 0 010 7.4zm0-1.6a2.1 2.1 0 100-4.2 2.1 2.1 0 000 4.2z" />
                                </svg>
                                <svg class="hidden h-5 w-5 eye-closed" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M3.3 2.3a1 1 0 00-1.4 1.4l2.2 2.2a15.1 15.1 0 00-2.5 2.7 2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1 1.3 0 2.5-.4 3.7-.9l2.6 2.6a1 1 0 001.4-1.4L3.3 2.3zm6.7 11.4a3.7 3.7 0 01-3.7-3.7c0-.7.2-1.3.5-1.8l1.4 1.4V10a1.8 1.8 0 002.2 1.8l1.4 1.4c-.5.3-1.1.5-1.8.5zm8.4-2.3c-.4.6-1.4 1.7-2.7 2.7L13.5 12a3.7 3.7 0 00-5.5-5.5L6.3 4.8c1.1-.7 2.3-1.3 3.7-1.3 4.2 0 7.4 3.8 8.4 5.1.6.8.6 2 0 2.8z" />
                                </svg>
                            </button>
                        </div>
                        <button type="button" data-generate-password
                            class="inline-flex items-center justify-center rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm font-medium text-green-700 hover:bg-green-100 sm:shrink-0">
                            Buat Password
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        Minimal 8 karakter. Tombol Buat Password akan mengisi password dan konfirmasi sekaligus.
                    </p>
                </div>

                <div>
                    <label for="reset_password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi password sementara</label>
                    <div class="relative mt-1 w-full sm:max-w-sm">
                        <input id="reset_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                            class="reset-password-input block w-full rounded-md border-gray-300 pr-12 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <button type="button"
                            class="absolute inset-y-0 right-0 z-10 flex w-11 items-center justify-center text-gray-500 hover:text-green-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500"
                            data-password-toggle="reset_password_confirmation"
                            aria-label="Tampilkan atau sembunyikan konfirmasi password sementara">
                            <svg class="h-5 w-5 eye-open" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M10 3.5c-4.2 0-7.4 3.8-8.4 5.1a2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1s7.4-3.8 8.4-5.1a2.2 2.2 0 000-2.8c-1-1.3-4.2-5.1-8.4-5.1zm0 10.2a3.7 3.7 0 110-7.4 3.7 3.7 0 010 7.4zm0-1.6a2.1 2.1 0 100-4.2 2.1 2.1 0 000 4.2z" />
                            </svg>
                            <svg class="hidden h-5 w-5 eye-closed" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M3.3 2.3a1 1 0 00-1.4 1.4l2.2 2.2a15.1 15.1 0 00-2.5 2.7 2.2 2.2 0 000 2.8c1 1.3 4.2 5.1 8.4 5.1 1.3 0 2.5-.4 3.7-.9l2.6 2.6a1 1 0 001.4-1.4L3.3 2.3zm6.7 11.4a3.7 3.7 0 01-3.7-3.7c0-.7.2-1.3.5-1.8l1.4 1.4V10a1.8 1.8 0 002.2 1.8l1.4 1.4c-.5.3-1.1.5-1.8.5zm8.4-2.3c-.4.6-1.4 1.7-2.7 2.7L13.5 12a3.7 3.7 0 00-5.5-5.5L6.3 4.8c1.1-.7 2.3-1.3 3.7-1.3 4.2 0 7.4 3.8 8.4 5.1.6.8.6 2 0 2.8z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                    <button type="button" data-guru-password-reset-close
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Batal
                    </button>
                    <button type="submit"
                        class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                        Reset Password Guru
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (() => {
        const modal = document.querySelector('[data-guru-password-reset-modal]');
        if (!modal) {
            return;
        }

        const openModal = () => {
            modal.classList.remove('hidden');
            modal.querySelector('input[name="password"]')?.focus();
        };

        const closeModal = () => {
            modal.classList.add('hidden');
        };

        document.querySelectorAll('[data-guru-password-reset-open]').forEach((button) => {
            button.addEventListener('click', openModal);
        });

        modal.querySelectorAll('[data-guru-password-reset-close]').forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        modal.querySelectorAll('[data-password-toggle]').forEach((button) => {
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

        modal.querySelector('[data-generate-password]')?.addEventListener('click', () => {
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
            const password = Array.from({ length: 12 }, () => alphabet[Math.floor(Math.random() * alphabet.length)]).join('');
            modal.querySelector('input[name="password"]').value = password;
            modal.querySelector('input[name="password_confirmation"]').value = password;
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    })();
</script>
