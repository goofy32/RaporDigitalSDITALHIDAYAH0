@extends('layouts.app')

@section('title', 'Ubah Password Admin')

@section('content')
<div class="mt-6 w-full rounded-lg bg-white p-4 shadow-lg sm:p-6 lg:p-8">
    <form id="admin-change-password-form" method="POST" action="{{ route('admin.password.change.update') }}">
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-green-700">Ubah Password Admin</h1>
                <p class="mt-1 text-sm text-gray-600">Masukkan password saat ini sebelum membuat password baru.</p>
            </div>

            <button type="submit" class="w-full shrink-0 rounded-lg bg-green-700 px-4 py-2 text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 md:w-auto">
                Ubah Password
            </button>
        </div>

        @if (session('success'))
            <div class="mt-5 rounded-lg bg-green-50 p-4 text-sm text-green-800" role="status">
                {{ session('success') }}
            </div>
        @endif

        <div class="mt-6 space-y-5">
            <div>
                <label for="current_password" class="mb-1 block text-sm font-medium text-gray-700">Password Saat Ini</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('current_password') border-red-500 @else border-gray-300 @enderror">
                @error('current_password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Password Baru</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="w-full rounded-md border px-3 py-2 focus:border-green-500 focus:ring-green-500 @error('password') border-red-500 @else border-gray-300 @enderror">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
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
        </div>
    </form>
</div>
@endsection
