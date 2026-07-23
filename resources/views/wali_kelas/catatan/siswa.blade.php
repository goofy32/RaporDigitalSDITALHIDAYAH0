{{-- resources/views/wali_kelas/catatan/siswa.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('title', 'Catatan Siswa')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-sm mt-14">
    <div class="flex flex-col gap-4 mb-6 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Catatan Siswa</h2>
            <p class="text-gray-600 mt-1">
                Siswa: <span class="font-semibold">{{ $siswa->nama }}</span> - 
                Kelas: <span class="font-semibold">{{ $siswa->kelas->nomor_kelas }} {{ $siswa->kelas->nama_kelas }}</span>
            </p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            <a href="{{ route('wali_kelas.student.index') }}"
               class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-200">
                Kembali
            </a>
            <button type="submit"
                    form="catatanSiswaForm"
                    class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 focus:outline-none focus:ring-4 focus:ring-green-300">
                Simpan Catatan
            </button>
        </div>
    </div>

    <!-- Form Catatan -->
    <form id="catatanSiswaForm" action="{{ route('wali_kelas.catatan.siswa.store', $siswa->id) }}" method="POST">
        @csrf
        
        <div class="grid grid-cols-1 gap-6">
            <!-- Catatan Umum -->
            <div>
                <h3 class="rounded-t-lg bg-green-700 px-4 py-2 font-medium text-white">Catatan Umum</h3>
                <div class="rounded-b-lg border border-gray-300 p-4">
                    <label for="catatan_umum" class="block text-sm font-medium text-gray-700">
                        Catatan Guru untuk siswa ini
                    </label>
                    <div class="mt-1 flex items-center justify-between gap-3 text-sm">
                        <p class="text-gray-500">Maksimal 1000 karakter</p>
                        <p class="text-gray-400" data-character-counter="catatan_umum"></p>
                    </div>
                    <textarea 
                        id="catatan_umum" 
                        name="catatan_umum" 
                        rows="7"
                        maxlength="1000"
                        class="mt-3 w-full rounded-lg border border-gray-300 p-3 text-sm leading-6 focus:border-green-500 focus:ring-green-500"
                        placeholder="Tulis catatan umum untuk siswa ini...">{{ old('catatan_umum', $catatanList['umum']->catatan ?? '') }}</textarea>
                </div>
            </div>

            <!-- Catatan UTS 
            <div>
                <h3 class="bg-green-600 text-white px-4 py-2 rounded-t">Catatan UTS (Tengah Semester)</h3>
                <div class="border border-gray-300 rounded-b p-4">
                    <label for="catatan_uts" class="block text-sm font-medium text-gray-700 mb-2">
                        Catatan khusus untuk rapor UTS
                    </label>
                    <textarea 
                        id="catatan_uts" 
                        name="catatan_uts" 
                        rows="4"
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        placeholder="Tulis catatan khusus untuk rapor UTS...">{{ $catatanList['uts']->catatan ?? old('catatan_uts') }}</textarea>
                    <p class="text-sm text-gray-500 mt-1">Catatan ini akan muncul di rapor UTS. Maksimal 1000 karakter</p>
                </div>
            </div>

            Catatan UAS 
            <div>
                <h3 class="bg-green-600 text-white px-4 py-2 rounded-t">Catatan UAS (Akhir Semester)</h3>
                <div class="border border-gray-300 rounded-b p-4">
                    <label for="catatan_uas" class="block text-sm font-medium text-gray-700 mb-2">
                        Catatan khusus untuk rapor UAS
                    </label>
                    <textarea 
                        id="catatan_uas" 
                        name="catatan_uas" 
                        rows="4"
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        placeholder="Tulis catatan khusus untuk rapor UAS...">{{ $catatanList['uas']->catatan ?? old('catatan_uas') }}</textarea>
                    <p class="text-sm text-gray-500 mt-1">Catatan ini akan muncul di rapor UAS. Maksimal 1000 karakter</p>
                </div>
            </div>
        </div>

        -->
    </form>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-resize textareas
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });
    
    // Character counter
    textareas.forEach(textarea => {
        const maxLength = 1000;
        const counter = document.querySelector(`[data-character-counter="${textarea.id}"]`);

        if (!counter) {
            return;
        }
        
        function updateCounter() {
            const length = textarea.value.length;
            counter.textContent = `${length}/${maxLength} karakter`;
            counter.className = length > maxLength ? 
                'text-sm text-red-500 text-right' :
                'text-sm text-gray-400 text-right';
        }
        
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });
});
</script>
@endpush
@endsection
