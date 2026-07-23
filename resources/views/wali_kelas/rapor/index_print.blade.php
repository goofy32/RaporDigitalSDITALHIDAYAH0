{{-- resources/views/wali_kelas/rapor/index_print.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('title', 'Cetak Rapor HTML')

@section('content')
<div class="p-4 bg-white mt-14">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Cetak Rapor HTML</h2>
            <p class="text-gray-600">Kelas {{ $kelas->nomor_kelas }}{{ $kelas->nama_kelas }} - {{ $tahunAjaran->tahun_ajaran ?? '2024/2025' }}</p>
        </div>
        
        <div class="flex items-center space-x-2">
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-3 py-2 rounded">
                <i class="fas fa-info-circle mr-2"></i>
                Semester {{ $tahunAjaran->semester ?? 1 }} ({{ $tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }})
            </div>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    <strong>Catatan:</strong> Fitur cetak rapor HTML akan membuka halaman baru yang otomatis menampilkan dialog cetak. 
                    Pastikan data nilai dan absensi siswa sudah lengkap sebelum mencetak.
                </p>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-6 rounded-lg shadow border">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Siswa</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ $siswa->count() }}</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white p-6 rounded-lg shadow border">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Data Lengkap</p>
                    <p class="text-2xl font-semibold text-gray-900">
                        {{ $siswa->filter(function($s) use ($diagnosisResults) { 
                            return $diagnosisResults[$s->id]['complete'] ?? false; 
                        })->count() }}
                    </p>
                </div>
            </div>
        </div>
        
        <div class="bg-white p-6 rounded-lg shadow border">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-red-100 text-red-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L5.036 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Perlu Perhatian</p>
                    <p class="text-2xl font-semibold text-gray-900">
                        {{ $siswa->filter(function($s) use ($diagnosisResults) { 
                            return !($diagnosisResults[$s->id]['complete'] ?? false); 
                        })->count() }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Box -->
    <div class="mb-6">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <svg class="w-4 h-4 text-gray-500" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <input type="search" 
                id="searchSiswa"
                class="block w-full p-3 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-green-500 focus:border-green-500" 
                placeholder="Cari nama siswa atau NIS...">
        </div>
    </div>

    <!-- Data Table -->
    <div class="overflow-x-auto shadow-md rounded-lg">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">No</th>
                    <th class="px-6 py-3">NIS</th>
                    <th class="px-6 py-3">Nama Siswa</th>
                    <th class="px-6 py-3">Status Data</th>
                    <th class="px-6 py-3">Detail Status</th>
                    <th class="px-6 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody id="siswaTableBody">
                @forelse($siswa as $index => $s)
                <tr class="bg-white border-b hover:bg-gray-50 siswa-row" data-nama="{{ strtolower($s->nama) }}" data-nis="{{ $s->nis }}">
                    <td class="px-6 py-4 font-medium text-gray-900">{{ $index + 1 }}</td>
                    <td class="px-6 py-4">{{ $s->nis }}</td>
                    <td class="px-6 py-4 font-medium text-gray-900">{{ $s->nama }}</td>
                    
                    <!-- Status Data -->
                    <td class="px-6 py-4">
                        @if($diagnosisResults[$s->id]['complete'] ?? false)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                Lengkap
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                </svg>
                                Belum Lengkap
                            </span>
                        @endif
                    </td>
                    
                    <!-- Detail Status -->
                    <td class="px-6 py-4">
                        <div class="space-y-1">
                            <div class="flex items-center text-xs">
                                @if($diagnosisResults[$s->id]['nilai_status'] ?? false)
                                    <span class="text-green-600 mr-1">✓</span>
                                    <span class="text-green-600">Nilai: Lengkap</span>
                                @else
                                    <span class="text-red-600 mr-1">✗</span>
                                    <span class="text-red-600">Nilai: Belum lengkap</span>
                                @endif
                            </div>
                            <div class="flex items-center text-xs">
                                @if($diagnosisResults[$s->id]['absensi_status'] ?? false)
                                    <span class="text-green-600 mr-1">✓</span>
                                    <span class="text-green-600">Absensi: Lengkap</span>
                                @else
                                    <span class="text-red-600 mr-1">✗</span>
                                    <span class="text-red-600">Absensi: Belum lengkap</span>
                                @endif
                            </div>
                        </div>
                    </td>

                    <!-- Aksi -->
                    <td class="px-6 py-4">
                        <div class="flex items-center space-x-3">
                            @if($diagnosisResults[$s->id]['complete'] ?? false)
                                <!-- Tombol Print HTML -->
                                <a href="{{ route('wali_kelas.rapor.print_html', $s->id) }}" 
                                   target="_blank"
                                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-green-600 border border-transparent rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                   title="Cetak Rapor HTML">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                    </svg>
                                    Cetak HTML
                                </a>
                                
                                <!-- Tombol Preview -->
                                <button onclick="previewSiswa({{ $s->id }}, @js($s->nama))"
                                        class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                        title="Preview Data">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    Preview
                                </button>
                            @else
                                <!-- Tombol Disabled dengan tooltip -->
                                <div class="relative group">
                                    <button disabled
                                            class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-400 bg-gray-100 border border-gray-200 rounded-md cursor-not-allowed"
                                            title="Data belum lengkap">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                        </svg>
                                        Cetak HTML
                                    </button>
                                    
                                    <!-- Tooltip -->
                                    <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-3 py-2 text-xs text-white bg-gray-800 rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity z-10">
                                        <div class="text-center">
                                            <p class="font-medium">Data belum lengkap:</p>
                                            @if(!($diagnosisResults[$s->id]['nilai_status'] ?? false))
                                                <p>• {{ $diagnosisResults[$s->id]['nilai_message'] ?? 'Nilai belum diinput' }}</p>
                                            @endif
                                            @if(!($diagnosisResults[$s->id]['absensi_status'] ?? false))
                                                <p>• {{ $diagnosisResults[$s->id]['absensi_message'] ?? 'Absensi belum diinput' }}</p>
                                            @endif
                                        </div>
                                        <div class="absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-2 border-r-2 border-t-4 border-transparent border-t-gray-800"></div>
                                    </div>
                                </div>
                            @endif
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

    <!-- Info Footer -->
    <div class="mt-6 bg-gray-50 border border-gray-200 rounded-lg p-4">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-gray-800">Informasi Cetak Rapor HTML</h3>
                <div class="mt-2 text-sm text-gray-600">
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Rapor akan dibuka dalam tab baru dan otomatis menampilkan dialog cetak</li>
                        <li>Pastikan printer sudah terpasang dan siap digunakan</li>
                        <li>Untuk hasil terbaik, gunakan kertas A4 dengan orientasi portrait</li>
                        <li>Data yang ditampilkan sesuai dengan semester {{ $tahunAjaran->semester ?? 1 }} tahun ajaran {{ $tahunAjaran->tahun_ajaran ?? '2024/2025' }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal (Optional) -->
<div id="previewModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                            Preview Data Siswa
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500" id="previewContent">
                                Loading...
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="closePreview()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Search functionality
document.getElementById('searchSiswa').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.siswa-row');
    
    rows.forEach(row => {
        const nama = row.dataset.nama;
        const nis = row.dataset.nis;
        
        if (nama.includes(searchTerm) || nis.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Preview functionality
function previewSiswa(siswaId, namaSiswa) {
    document.getElementById('modal-title').textContent = `Preview Data - ${namaSiswa}`;
    document.getElementById('previewContent').textContent = 'Memuat data...';
    document.getElementById('previewModal').classList.remove('hidden');
    
    // You can add AJAX call here to fetch detailed data
    // For now, just show basic info
    setTimeout(() => {
        document.getElementById('previewContent').innerHTML = `
            <div class="space-y-2">
                <p><strong>Nama:</strong> ${namaSiswa}</p>
                <p><strong>Status:</strong> Data siap untuk dicetak</p>
                <p><strong>Semester:</strong> {{ $tahunAjaran->semester ?? 1 }}</p>
                <p><strong>Tahun Ajaran:</strong> {{ $tahunAjaran->tahun_ajaran ?? '2024/2025' }}</p>
            </div>
        `;
    }, 500);
}

function closePreview() {
    document.getElementById('previewModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePreview();
    }
});
</script>
@endpush
@endsection
