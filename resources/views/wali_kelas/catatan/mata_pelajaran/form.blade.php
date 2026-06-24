@extends('layouts.wali_kelas.app')

@section('title', 'Catatan Mata Pelajaran')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-sm mt-14">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Catatan Mata Pelajaran</h2>
            <p class="text-gray-600 mt-1">
                Mata Pelajaran: <span class="font-semibold">{{ $mataPelajaran->nama_pelajaran }}</span> - 
                Semester: <span class="font-semibold">{{ $mataPelajaran->semester }}</span>
            </p>
        </div>
        <a href="{{ route('wali_kelas.catatan.mata_pelajaran.index') }}" 
           class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
            Kembali
        </a>
    </div>

    <!-- Form -->
    <form action="{{ route('wali_kelas.catatan.mata_pelajaran.store', $mataPelajaran->id) }}" method="POST" 
          x-data="catatanForm()" x-init="initForm()">
        @csrf
        
        <!-- Filter/Search -->
        <div class="mb-6 bg-gray-50 p-4 rounded-lg">
            <div class="flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Cari Siswa</label>
                    <input type="text" 
                           id="search" 
                           x-model="searchTerm"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                           placeholder="Ketik nama siswa untuk mencari...">
                </div>
                <div class="flex-shrink-0">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tab Catatan</label>
                    <div class="flex space-x-2">
                        <button type="button" @click="activeTab = 'umum'" 
                                :class="activeTab === 'umum' ? 'bg-green-600 text-white' : 'bg-white text-gray-700'"
                                class="px-4 py-2 rounded border hover:bg-green-50">
                            Umum
                        </button>
                        <button type="button" @click="activeTab = 'uts'" 
                                :class="activeTab === 'uts' ? 'bg-green-600 text-white' : 'bg-white text-gray-700'"
                                class="px-4 py-2 rounded border hover:bg-green-50">
                            UTS
                        </button>
                        <button type="button" @click="activeTab = 'uas'" 
                                :class="activeTab === 'uas' ? 'bg-green-600 text-white' : 'bg-white text-gray-700'"
                                class="px-4 py-2 rounded border hover:bg-green-50">
                            UAS
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students List -->
        <div class="space-y-4">
            @foreach($siswaList as $siswa)
                <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 transition-colors"
                     x-show="filterSiswa(@js($siswa->nama))"
                     x-transition>
                    
                    <!-- Student Header -->
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                                <span class="text-green-600 font-medium text-sm">
                                    {{ substr($siswa->nama, 0, 2) }}
                                </span>
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900">{{ $siswa->nama }}</h3>
                                <p class="text-sm text-gray-500">NIS: {{ $siswa->nis }}</p>
                            </div>
                        </div>
                        <button type="button" 
                                @click="toggleSiswa({{ $siswa->id }})"
                                class="text-green-600 hover:text-green-800">
                            <svg xmlns="http://www.w3.org/2000/svg" 
                                 :class="openSiswa[{{ $siswa->id }}] ? 'rotate-180' : ''"
                                 class="w-5 h-5 transition-transform" 
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7" />
                            </svg>
                        </button>
                    </div>

                    <!-- Catatan Form for Student -->
                    <div x-show="openSiswa[{{ $siswa->id }}]" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 transform scale-95"
                         x-transition:enter-end="opacity-100 transform scale-100">
                        
                        <!-- Umum Tab -->
                        <div x-show="activeTab === 'umum'" class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Catatan Umum</label>
                            <textarea 
                                name="catatan[{{ $siswa->id }}][umum]"
                                rows="3"
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                                placeholder="Tulis catatan umum untuk {{ $siswa->nama }}...">{{ $existingCatatan[$siswa->id]['umum'][0]->catatan ?? '' }}</textarea>
                        </div>

                        <!-- UTS Tab -->
                        <div x-show="activeTab === 'uts'" class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Catatan UTS</label>
                            <textarea 
                                name="catatan[{{ $siswa->id }}][uts]"
                                rows="3"
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                                placeholder="Tulis catatan UTS untuk {{ $siswa->nama }}...">{{ $existingCatatan[$siswa->id]['uts'][0]->catatan ?? '' }}</textarea>
                            <p class="text-sm text-gray-500">Catatan ini akan muncul di rapor UTS</p>
                        </div>

                        <!-- UAS Tab -->
                        <div x-show="activeTab === 'uas'" class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Catatan UAS</label>
                            <textarea 
                                name="catatan[{{ $siswa->id }}][uas]"
                                rows="3"
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                                placeholder="Tulis catatan UAS untuk {{ $siswa->nama }}...">{{ $existingCatatan[$siswa->id]['uas'][0]->catatan ?? '' }}</textarea>
                            <p class="text-sm text-gray-500">Catatan ini akan muncul di rapor UAS</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end mt-8 pt-6 border-t border-gray-200">
            <button type="submit" 
                    class="bg-green-700 text-white px-6 py-3 rounded-lg hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium">
                Simpan Semua Catatan
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
function catatanForm() {
    return {
        searchTerm: '',
        activeTab: 'umum',
        openSiswa: {},
        
        initForm() {
            // Open first student by default
            const firstSiswaId = Object.keys(this.openSiswa)[0] || {{ $siswaList->first()->id ?? 'null' }};
            if (firstSiswaId) {
                this.openSiswa[firstSiswaId] = true;
            }
        },
        
        filterSiswa(namaStr) {
            if (!this.searchTerm) return true;
            return namaStr.toLowerCase().includes(this.searchTerm.toLowerCase());
        },
        
        toggleSiswa(siswaId) {
            this.openSiswa[siswaId] = !this.openSiswa[siswaId];
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-resize all textareas
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });
});
</script>
@endpush
@endsection
