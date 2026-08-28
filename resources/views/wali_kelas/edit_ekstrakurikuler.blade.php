@extends('layouts.wali_kelas.app')

@section('title', 'Edit Data Ekstrakurikuler')

@section('content')
<div class="p-6 bg-white mt-14">
    <!-- Header -->
    <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <h2 class="text-2xl font-bold text-green-700">Form Edit Data Ekstrakurikuler</h2>
        <div class="flex flex-wrap gap-2 md:justify-end">
            <a href="{{ route('wali_kelas.ekstrakurikuler.index') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                Kembali
            </a>
            <button type="submit" form="editEkskulForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Simpan
            </button>
        </div>
    </div>

    <!-- Form -->
    <form id="editEkskulForm" action="{{ route('wali_kelas.ekstrakurikuler.update', $nilaiEkstrakurikuler->id) }}" x-data="formProtection" @submit="handleSubmit" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

        <!-- Info Siswa (readonly) -->
        <div>
            <label class="block mb-2 text-sm font-medium text-gray-900">NIS - Nama Siswa</label>
            <input type="text" value="{{ $nilaiEkstrakurikuler->siswa->nis }} - {{ $nilaiEkstrakurikuler->siswa->nama }}" 
                   class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-900" readonly>
        </div>

        <!-- Info Ekstrakurikuler (readonly) -->
        <div>
            <label class="block mb-2 text-sm font-medium text-gray-900">Ekstrakurikuler</label>
            <input type="text" value="{{ $nilaiEkstrakurikuler->ekstrakurikuler->nama_ekstrakurikuler }}" 
                   class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-900" readonly>
        </div>

        <!-- Predikat -->
        <div>
            <label for="predikat" class="block mb-2 text-sm font-medium text-gray-900">Predikat</label>
            <select id="predikat" name="predikat" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
                <option value="">Pilih Predikat</option>
                <option value="A" {{ old('predikat', $nilaiEkstrakurikuler->predikat) == 'A' ? 'selected' : '' }}>A</option>
                <option value="B" {{ old('predikat', $nilaiEkstrakurikuler->predikat) == 'B' ? 'selected' : '' }}>B</option>
                <option value="C" {{ old('predikat', $nilaiEkstrakurikuler->predikat) == 'C' ? 'selected' : '' }}>C</option>
                <option value="D" {{ old('predikat', $nilaiEkstrakurikuler->predikat) == 'D' ? 'selected' : '' }}>D</option>
            </select>
            @error('predikat')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Deskripsi -->
        <div>
            <label for="deskripsi" class="block mb-2 text-sm font-medium text-gray-900">Deskripsi</label>
            <textarea id="deskripsi" name="deskripsi" rows="4"
                      class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">{{ old('deskripsi', $nilaiEkstrakurikuler->deskripsi) }}</textarea>
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
