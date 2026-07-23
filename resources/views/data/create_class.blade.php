@extends('layouts.app')

@section('title', 'Tambah Data Kelas')

@section('content')
<div class="w-full">
    <div class="p-4 bg-white mt-14 rounded-lg shadow">
        <!-- Error Messages -->
        @if ($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium">Terdapat beberapa kesalahan:</h3>
                    <ul class="mt-2 list-disc list-inside text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        @endif

         <!-- Error Specific Message -->
         @if(session('error'))
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm">{{ session('error') }}</p>
                </div>
            </div>
        </div>
        @endif


        <!-- Success Message -->
        @if (session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">{{ session('success') }}</p>
                </div>
            </div>
        </div>
        @endif


        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Tambah Kelas</h2>
            <div class="flex space-x-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    Kembali
                </button>
                <button form="createClassForm" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
                </button>
            </div>
        </div>

        @if(isset($targetTahunAjaran) && $targetTahunAjaran)
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 p-4 rounded-lg">
            <p class="font-medium">Tahun ajaran tujuan: {{ $targetTahunAjaran->tahun_ajaran }} Semester {{ $targetTahunAjaran->semester }}</p>
            <p class="text-sm mt-1">Kelas akan dibuat untuk konteks tahun ajaran ini tanpa perlu mengaktifkannya terlebih dahulu.</p>
        </div>
        @endif

        <!-- Form -->
        <form id="createClassForm" action="{{ route('kelas.store') }}" method="POST" data-needs-protection data-turbo="false" x-data="formProtection">
            @csrf
            <input type="hidden" name="target_tahun_ajaran_id" value="{{ old('target_tahun_ajaran_id', $tahunAjaranId ?? session('tahun_ajaran_id')) }}">
            <input type="hidden" name="tahun_ajaran_id" value="{{ old('tahun_ajaran_id', $tahunAjaranId ?? session('tahun_ajaran_id')) }}">
            @if(!empty($redirectTo))
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
            @endif

            <div class="space-y-6 w-full">  <!-- Tambahkan w-full -->
                <!-- Nomor Kelas -->
                <div class="w-full">  <!-- Tambahkan w-full -->
                    <label class="block text-sm font-medium text-gray-700">Nomor Kelas</label>
                    <input type="number" name="nomor_kelas" min="1" max="99" value="{{ old('nomor_kelas') }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 @error('nomor_kelas') border-red-500 @enderror"
                        placeholder="Masukkan nomor kelas">
                    <p class="mt-1 text-sm text-gray-500"></p>
                    @error('nomor_kelas')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Nama Kelas -->
                <div class="w-full">  <!-- Tambahkan w-full -->
                    <label class="block text-sm font-medium text-gray-700">Nama Kelas</label>
                    <input type="text" name="nama_kelas" value="{{ old('nama_kelas') }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 @error('nama_kelas') border-red-500 @enderror"
                        placeholder="Contoh: Al-Farabi, Ibnu Sina">
                    @error('nama_kelas')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </form>
    </div>
</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Pastikan elemen nomor_kelas ada sebelum menambahkan event listener
    const nomorKelasInput = document.getElementById('nomor_kelas');
    
    if (nomorKelasInput) {
        nomorKelasInput.addEventListener('input', function(e) {
            // Hapus angka 0 di depan
            if(this.value.length > 0) {
                this.value = parseInt(this.value.replace(/^0+/, '')) || '';
            }
            
            // Jika nilai 0, kosongkan input
            if(this.value === '0') {
                this.value = '';
            }
            
            // Batasi input maksimal 99
            if(parseInt(this.value) > 99) {
                this.value = '99';
            }
        });
    }
});
</script>
@endpush
@endsection
