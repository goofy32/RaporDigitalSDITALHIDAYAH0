@extends(session('selected_role') === 'wali_kelas' ? 'layouts.wali_kelas.app' : 'layouts.pengajar.app')

@section('title', 'Verifikasi Email')

@section('content')
<div class="mt-6 w-full rounded-lg bg-white p-4 shadow-lg sm:p-6 lg:p-8">
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0 max-w-3xl">
            <h1 class="text-2xl font-bold text-green-700">Verifikasi Email</h1>

            @if ($guru->email)
                <p class="mt-2 text-sm text-gray-600">
                    Kirim tautan verifikasi ke alamat email yang tersimpan agar email dapat digunakan untuk pemulihan password.
                </p>
            @else
                <p class="mt-2 text-sm text-gray-600">
                    Belum ada alamat email pada akun Anda. Silakan hubungi Admin sekolah untuk memperbarui data atau mereset password.
                </p>
            @endif
        </div>

        @if ($guru->email)
            <form method="POST" action="{{ route('guru.verification.send') }}" class="shrink-0">
                @csrf
                <button type="submit" class="w-full rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 md:w-auto">
                    Kirim Tautan Verifikasi
                </button>
            </form>
        @endif
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-lg bg-green-50 p-4 text-sm text-green-800" role="status">
            {{ session('status') }}
        </div>
    @endif
</div>
@endsection
