@php
    $verificationGuru = Auth::guard('guru')->user();
@endphp

@if($verificationGuru && $verificationGuru->email && ! $verificationGuru->hasVerifiedEmail())
    <div class="mb-4 rounded-md border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900" role="status">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="font-semibold">Email belum diverifikasi</div>
                <p class="mt-1">
                    Verifikasi {{ $verificationGuru->email }} agar dapat digunakan untuk pemulihan password.
                </p>
            </div>
            <a href="{{ route('guru.verification.notice') }}"
               class="inline-flex shrink-0 items-center justify-center rounded-md bg-yellow-600 px-3 py-2 font-medium text-white hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                Verifikasi Email
            </a>
        </div>
    </div>
@endif
