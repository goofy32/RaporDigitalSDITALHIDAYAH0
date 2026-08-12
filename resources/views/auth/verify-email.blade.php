@extends('layouts.app')

@section('content')
<div class="mx-auto mt-14 max-w-xl rounded-lg bg-white p-6 shadow-lg">
    <h1 class="text-2xl font-bold text-green-700">Verifikasi Email</h1>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 p-4 text-sm text-green-800" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($guru->email)
        <p class="mt-3 text-sm text-gray-600">
            Kirim tautan verifikasi ke alamat email yang tersimpan agar email dapat digunakan untuk pemulihan password.
        </p>

        <form method="POST" action="{{ route('guru.verification.send') }}" class="mt-5">
            @csrf
            <button type="submit" class="rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                Kirim Tautan Verifikasi
            </button>
        </form>
    @else
        <p class="mt-3 text-sm text-gray-600">
            Belum ada alamat email pada akun Anda. Silakan hubungi Admin sekolah untuk memperbarui data atau mereset password.
        </p>
    @endif
</div>
@endsection
