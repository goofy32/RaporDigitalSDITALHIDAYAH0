@extends('layouts.wali_kelas.app')

@section('title', 'Tambah Data Ekstrakurikuler')

@section('content')
<div class="p-6 bg-white mt-14">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Form Tambah Data Ekstrakurikuler</h2>
        <div>
            <a href="{{ route('wali_kelas.ekstrakurikuler.index') }}" class="px-4 py-2 mr-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                Kembali
            </a>
            <button type="submit" form="createEkskulForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Simpan
            </button>
        </div>
    </div>

    <!-- Form -->
    <form id="createEkskulForm" action="{{ route('wali_kelas.ekstrakurikuler.store') }}" x-data="formProtection" @submit="handleSubmit" method="POST" class="space-y-6" data-turbo="false">
        @csrf

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

        <!-- NIS -->
        <div>
            <label for="siswa_id" class="block mb-2 text-sm font-medium text-gray-900">NIS - Nama Siswa</label>
            <select id="siswa_id" name="siswa_id" required 
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
                <option value="">Pilih Siswa</option>
                @foreach($siswa as $s)
                    <option value="{{ $s->id }}" {{ old('siswa_id') == $s->id ? 'selected' : '' }}>
                        {{ $s->nis }} - {{ $s->nama }}
                    </option>
                @endforeach
            </select>
            @error('siswa_id')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Ekstrakurikuler -->
        <div>
            <label for="ekstrakurikuler_id" class="block mb-2 text-sm font-medium text-gray-900">Ekstrakurikuler</label>
            <select id="ekstrakurikuler_id" name="ekstrakurikuler_id" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
                <option value="">Pilih Ekstrakurikuler</option>
                @foreach($ekstrakurikuler as $ekskul)
                    <option value="{{ $ekskul->id }}" {{ old('ekstrakurikuler_id') == $ekskul->id ? 'selected' : '' }}>
                        {{ $ekskul->nama_ekstrakurikuler }}
                    </option>
                @endforeach
            </select>
            @error('ekstrakurikuler_id')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Predikat -->
        <div>
            <label for="predikat" class="block mb-2 text-sm font-medium text-gray-900">Predikat</label>
            <select id="predikat" name="predikat" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
                <option value="">Pilih Predikat</option>
                <option value="Sangat Baik" {{ old('predikat') == 'Sangat Baik' ? 'selected' : '' }}>Sangat Baik</option>
                <option value="Baik" {{ old('predikat') == 'Baik' ? 'selected' : '' }}>Baik</option>
                <option value="Cukup" {{ old('predikat') == 'Cukup' ? 'selected' : '' }}>Cukup</option>
                <option value="Kurang" {{ old('predikat') == 'Kurang' ? 'selected' : '' }}>Kurang</option>
            </select>
            @error('predikat')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Deskripsi -->
        <div>
            <label for="deskripsi" class="block mb-2 text-sm font-medium text-gray-900">Deskripsi</label>
            <textarea id="deskripsi" name="deskripsi" rows="4"
                      class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">{{ old('deskripsi') }}</textarea>
            @error('deskripsi')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>
    </form>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check for SweetAlert validation error in session
        @if(session('swal_validation_error'))
            Swal.fire({
                icon: 'error',
                title: 'Validasi Error',
                text: @json(session('swal_validation_error')),
                confirmButtonText: 'Oke'
            });
        @endif

        // Disable Turbo for this form
        const form = document.querySelector('form');
        if (form) {
            form.setAttribute('data-turbo', 'false');
        }
    });
</script>
@endpush
@endsection
