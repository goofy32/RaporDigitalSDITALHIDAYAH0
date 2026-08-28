@extends('layouts.wali_kelas.app')

@section('title', 'Tambah Data Absensi')

@section('content')
<div class="p-6 bg-white mt-14">
    <!-- Header -->
    <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <h2 class="text-2xl font-bold text-green-700">Form Tambah Data Absensi</h2>
        <div class="flex flex-wrap gap-2 md:justify-end">
            <a href="{{ route('wali_kelas.absence.index') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                Kembali
            </a>
            <button type="submit" form="createAbsenceForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Simpan
            </button>
        </div>
    </div>

    <!-- Form -->
    <form id="createAbsenceForm" action="{{ route('wali_kelas.absence.store') }}" method="POST" x-data="formProtection" @submit="handleSubmit" class="space-y-6" data-turbo="false">
        @csrf

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

        <!-- NIS dan Nama Siswa -->
        <div>
            <label for="siswa_id" class="block mb-2 text-sm font-medium text-gray-900">Siswa</label>
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

        <div>
            <label for="semester" class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
            <div class="relative">
                <input type="text" 
                    value="Semester {{ $currentSemester }} ({{ $currentSemester == 1 ? 'Ganjil' : 'Genap' }})" 
                    class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed" 
                    disabled>
                <input type="hidden" name="semester" value="{{ $currentSemester }}">
                <!-- Add info icon with tooltip -->
                <span class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 cursor-help" title="Semester ditentukan oleh tahun ajaran aktif yang dipilih">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </span>
            </div>
            <p class="mt-1 text-xs text-gray-500">Semester otomatis ditentukan oleh tahun ajaran yang aktif ({{ $tahunAjaran->tahun_ajaran ?? 'Tidak diketahui' }})</p>
        </div>

        <!-- Sakit -->
        <div>
            <label for="sakit" class="block mb-2 text-sm font-medium text-gray-900">Sakit (Hari)</label>
            <input type="number" id="sakit" name="sakit" value="{{ old('sakit', 0) }}" min="0" required
                   class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
            @error('sakit')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Izin -->
        <div>
            <label for="izin" class="block mb-2 text-sm font-medium text-gray-900">Izin (Hari)</label>
            <input type="number" id="izin" name="izin" value="{{ old('izin', 0) }}" min="0" required
                   class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
            @error('izin')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Tanpa Keterangan -->
        <div>
            <label for="tanpa_keterangan" class="block mb-2 text-sm font-medium text-gray-900">Tanpa Keterangan (Hari)</label>
            <input type="number" id="tanpa_keterangan" name="tanpa_keterangan" value="{{ old('tanpa_keterangan', 0) }}" min="0" required
                   class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 text-gray-900">
            @error('tanpa_keterangan')
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
