@php
    $roleLabels = [
        'pengajar' => 'Pengajar',
        'wali_kelas' => 'Wali Kelas',
    ];
    $availableRoles = $available_roles ?? [];
    $attemptedRoleLabel = $roleLabels[$attempted_role] ?? 'role yang sesuai';
    $currentRoleLabel = $roleLabels[$current_role] ?? 'role yang aktif';
    $canSwitchToAttemptedRole = in_array($attempted_role, $availableRoles, true);
    $dashboardRole = in_array($current_role, $availableRoles, true)
        ? $current_role
        : ($availableRoles[0] ?? null);
    $dashboardUrl = $dashboardRole === 'wali_kelas'
        ? route('wali_kelas.dashboard')
        : ($dashboardRole === 'pengajar' ? route('pengajar.dashboard') : null);
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <title>Akses Tidak Sesuai Role</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <section class="w-full max-w-lg rounded-lg bg-white p-8 text-center shadow-md">
            <svg class="mx-auto h-12 w-12 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>

            <h1 class="mt-4 text-xl font-bold text-gray-900">Akses Tidak Sesuai Role</h1>

            @if($canSwitchToAttemptedRole)
                <p class="mt-3 text-sm leading-6 text-gray-600">
                    Akses halaman ini membutuhkan role {{ $attemptedRoleLabel }}. Saat ini Anda sedang menggunakan role {{ $currentRoleLabel }}.
                    Silakan pilih role {{ $attemptedRoleLabel }} melalui menu pergantian role, lalu buka kembali halaman ini.
                </p>
            @else
                <p class="mt-3 text-sm leading-6 text-gray-600">
                    Akun Anda belum memiliki akses sebagai {{ $attemptedRoleLabel }} untuk halaman ini. Hubungi admin sekolah jika akses ini diperlukan.
                </p>
            @endif

            @if(!empty($message) && $message !== 'Anda tidak memiliki akses ke role ini.')
                <p class="mt-3 rounded-lg bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                    {{ $message }}
                </p>
            @endif

            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                @if($dashboardUrl)
                    <a href="{{ $dashboardUrl }}"
                       class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        Kembali ke Dashboard
                    </a>
                @endif

                @if($canSwitchToAttemptedRole)
                    <form method="POST"
                          action="{{ route('auth.switch.role', ['role' => $attempted_role]) }}"
                          data-turbo="false"
                          data-turbo-prefetch="false"
                          onsubmit="this.querySelector('[data-role-switch-submit]').disabled = true">
                        @csrf
                        <button type="submit"
                                data-role-switch-submit
                                class="inline-flex w-full items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-300 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                            Pilih Role {{ $attemptedRoleLabel }}
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-200 sm:w-auto">
                        Logout
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
