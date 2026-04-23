@extends('layouts.wali_kelas.app')

@section('title', 'Manajemen Rapor')

@section('content')
@push('styles')
<style>
    [x-cloak] { display: none !important; }
    .action-icon {
        width: 20px;
        height: 20px;
        object-fit: contain;
    }
    .loading-overlay {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(2px);
    }
</style>
@endpush

<!-- Main Container with Single Alpine Instance -->
<div x-data="raporManager" x-cloak class="p-4 bg-white mt-14" data-active-tab="{{ $type }}" data-tahun-ajaran-id="{{ session('tahun_ajaran_id') }}" data-semester="{{ $semester }}">
    
    <!-- Loading State -->
    <div x-show="!initialized" class="flex items-center justify-center p-12">
        <div class="flex items-center space-x-2">
            <svg class="animate-spin h-8 w-8 text-green-600" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-gray-600">Memuat data rapor...</p>
        </div>
    </div>

    <!-- No Template Active State -->
    <div x-show="initialized && !templateUTSActive && !templateUASActive" class="text-center py-8">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">Tidak Ada Template Aktif</h3>
        <p class="mt-1 text-sm text-gray-500">Admin belum mengaktifkan template rapor untuk kelas ini.</p>
        <div class="mt-6">
            <button type="button" @click="refreshPage()" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Muat Ulang
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div x-show="initialized && (templateUTSActive || templateUASActive)">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">
                Manajemen Rapor Kelas {{ auth()->user()->kelasWali->nama_kelas ?? 'N/A' }}
            </h2>
        </div>

        <!-- Tabs -->
        <div class="mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button @click="setActiveTab('UTS')"
                            :class="{
                                'border-green-500 text-green-600': activeTab === 'UTS',
                                'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'UTS',
                                'cursor-not-allowed opacity-70': !templateUTSActive
                            }"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors"
                            type="button"
                            :disabled="!templateUTSActive">
                        Rapor UTS
                        <span x-show="!templateUTSActive" class="ml-1 text-xs text-red-500">(Nonaktif)</span>
                    </button>
                    <button @click="setActiveTab('UAS')"
                            :class="{
                                'border-green-500 text-green-600': activeTab === 'UAS',
                                'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'UAS',
                                'cursor-not-allowed opacity-70': !templateUASActive
                            }"
                            class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors"
                            type="button"
                            :disabled="!templateUASActive">
                        Rapor UAS
                        <span x-show="!templateUASActive" class="ml-1 text-xs text-red-500">(Nonaktif)</span>
                    </button>
                </nav>
            </div>
        </div>

        <!-- Search Box -->
        <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-4 h-4 text-gray-500" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <input type="search" 
                    x-model="searchQuery"
                    @input="handleSearch($event)"
                    class="block w-full p-2 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-green-500 focus:border-green-500" 
                    placeholder="Cari siswa...">
            </div>
        </div>        <!-- Data Table -->
        <div class="overflow-x-auto shadow-md rounded-lg">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">No</th>
                        <th class="px-6 py-3">NIS</th>
                        <th class="px-6 py-3">Nama Siswa</th>
                        <th class="px-6 py-3">Status Nilai</th>
                        <th class="px-6 py-3">Status Kehadiran</th>
                        <th class="px-6 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($siswa as $index => $s)
                    <tr class="bg-white border-b hover:bg-gray-50 transition-colors">                    
                        <td class="px-6 py-4">{{ $index + 1 }}</td>
                        <td class="px-6 py-4">{{ $s->nis }}</td>
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $s->nama }}</td>
                        
                        <!-- Status Nilai -->
                        <td class="px-6 py-4">
                            <div class="flex flex-col gap-1">
                                @if($diagnosisResults[$s->id]['nilai_status'])
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                        Lengkap
                                    </span>
                                @else
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full relative group">
                                        Belum Lengkap
                                        <div class="absolute left-0 top-full mt-2 w-64 p-2 bg-gray-800 text-white text-xs rounded shadow-lg 
                                                opacity-0 invisible group-hover:opacity-100 group-hover:visible transition z-10">
                                            <p>Masalah terdeteksi:</p>
                                            <p class="font-medium mt-1">{{ $diagnosisResults[$s->id]['nilai_message'] }}</p>
                                            <p class="mt-2">Solusi:</p>
                                            @if(strpos($diagnosisResults[$s->id]['nilai_message'], 'nilai akhir rapor belum dihitung') !== false)
                                                <p>Minta pengajar untuk menyimpan nilai dengan klik "Simpan & Preview"</p>
                                            @elseif(strpos($diagnosisResults[$s->id]['nilai_message'], 'Tidak ada mata pelajaran') !== false)
                                                <p>Tambahkan mata pelajaran untuk semester ini</p>
                                            @else
                                                <p>Minta pengajar mengisi nilai siswa terlebih dahulu</p>
                                            @endif
                                        </div>
                                    </span>
                                @endif
                            </div>
                        </td>

                        <!-- Status Kehadiran -->
                        <td class="px-6 py-4">
                            <div class="flex flex-col gap-1">
                                @if($diagnosisResults[$s->id]['absensi_status'])
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                        Lengkap
                                    </span>
                                @else
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full relative group">
                                        Belum Lengkap
                                        <div class="absolute left-0 top-full mt-2 w-64 p-2 bg-gray-800 text-white text-xs rounded shadow-lg 
                                                opacity-0 invisible group-hover:opacity-100 group-hover:visible transition z-10">
                                            <p>Masalah terdeteksi:</p>
                                            <p class="font-medium mt-1">{{ $diagnosisResults[$s->id]['absensi_message'] }}</p>
                                            <p class="mt-2">Solusi:</p>
                                            <p>Input data absensi dengan memilih semester {{ request('type', 'UTS') === 'UTS' ? '1 (Ganjil)' : '2 (Genap)' }}</p>
                                        </div>
                                    </span>
                                @endif
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="px-6 py-4">
                            <div class="flex items-center space-x-3">                                
                                <!-- Download DOCX Button -->
                                <button @click="handleGenerate({{ $s->id }}, {{ $nilaiCounts[$s->id] ?? 0 }}, {{ $s->absensi ? 'true' : 'false' }}, '{{ $s->nama }}')"
                                    :disabled="!{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }}"
                                    :class="{ 
                                        'opacity-50 cursor-not-allowed': !{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }}, 
                                        'text-green-600 hover:text-green-900': {{ $nilaiCounts[$s->id] ?? 0 }} && {{ $s->absensi ? 'true' : 'false' }} 
                                    }"
                                    class="transition-colors"
                                    title="Unduh Rapor DOCX">
                                    <img src="{{ asset('images/icons/download.png') }}" alt="Download" class="action-icon">
                                </button>
                                
                                <!-- Preview PDF Button -->
                                <button @click="handlePreviewPdf({{ $s->id }}, {{ $nilaiCounts[$s->id] ?? 0 }}, {{ $s->absensi ? 'true' : 'false' }})"
                                        :disabled="!{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }} || loading"
                                        :class="{ 
                                            'opacity-50 cursor-not-allowed': !{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }} || loading, 
                                            'text-purple-600 hover:text-purple-900': {{ $nilaiCounts[$s->id] ?? 0 }} && {{ $s->absensi ? 'true' : 'false' }} && !loading 
                                        }"
                                        class="transition-colors"
                                        title="Preview PDF">
                                    <img src="{{ asset('images/icons/detail.png') }}" alt="Preview" class="action-icon">
                                </button>

                                <button @click="handleDownloadPdf({{ $s->id }}, {{ $nilaiCounts[$s->id] ?? 0 }}, {{ $s->absensi ? 'true' : 'false' }}, '{{ $s->nama }}')"
                                        :disabled="!{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }} || loading"
                                        :class="{ 
                                            'opacity-50 cursor-not-allowed': !{{ $nilaiCounts[$s->id] ?? 0 }} || !{{ $s->absensi ? 'true' : 'false' }} || loading, 
                                            'text-red-600 hover:text-red-900': {{ $nilaiCounts[$s->id] ?? 0 }} && {{ $s->absensi ? 'true' : 'false' }} && !loading 
                                        }"
                                        class="transition-colors"
                                        title="Unduh Rapor PDF">
                                    <template x-if="loadingPdf === {{ $s->id }}">
                                        <svg class="action-icon animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                    </template>
                                    <template x-if="loadingPdf !== {{ $s->id }}">
                                        <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    </template>
                                </button>                                
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                            Tidak ada data siswa
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Preview Modal -->
    <div x-show="showPreview" 
         class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50"
         x-cloak
         @click.away="showPreview = false">
        <div class="relative bg-white rounded-lg mx-auto mt-10 max-w-4xl p-4 m-4">
            <button @click="showPreview = false" 
                    class="absolute top-4 right-4 text-gray-400 hover:text-gray-500 z-10">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            
            <div x-html="previewContent" class="mt-4 max-h-96 overflow-y-auto"></div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div x-show="loading" 
         class="fixed inset-0 loading-overlay flex items-center justify-center z-40"
         x-cloak>
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin h-6 w-6 text-green-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span class="text-gray-700">Memproses...</span>
            </div>
        </div>
    </div>
</div>

@endsection

