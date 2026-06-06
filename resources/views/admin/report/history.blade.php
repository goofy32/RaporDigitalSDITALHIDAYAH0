@extends('layouts.app')

@section('title', 'History Rapor')

@section('content')
<div class="p-4 bg-white mt-14"
     x-data="{
        async confirmDelete(id, filename) {
            var result = await Swal.fire({
                icon: 'warning',
                title: 'Hapus Riwayat?',
                html: 'Riwayat cetak dan file PDF akan <b>dihapus permanen</b> dan tidak dapat dikembalikan.',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            });

            if (!result.isConfirmed) {
                return;
            }

            try {
                var response = await fetch(`{{ url('/admin/report-history') }}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });

                var data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || `Gagal menghapus riwayat ${filename}`);
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Berhasil dihapus',
                    timer: 1500,
                    showConfirmButton: false
                });

                var row = document.getElementById(`report-row-${id}`);
                if (row) {
                    row.remove();
                }
            } catch (error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Gagal menghapus',
                    text: error.message || 'Terjadi kesalahan saat menghapus riwayat.'
                });
            }
        }
     }">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Riwayat Cetak Rapor</h2>
    </div>

    <!-- Filter Controls -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
    <div class="flex gap-2">
        <select id="tahun-ajaran-selector" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 p-2.5">
            <option value="">Semua Tahun Ajaran</option>
            @foreach($tahunAjarans as $ta)
                <option value="{{ $ta->id }}" {{ isset($tahunAjaranId) && $ta->id == $tahunAjaranId ? 'selected' : '' }}>
                    {{ $ta->tahun_ajaran }} - {{ $ta->semester == 1 ? 'Ganjil' : 'Genap' }}
                </option>
            @endforeach
        </select>
        <select id="filter-type" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 p-2.5">
            <option value="">Semua Tipe</option>
            <option value="UTS">UTS</option>
            <option value="UAS">UAS</option>
        </select>
        
        <select id="filter-kelas" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 p-2.5">
            <option value="">Semua Kelas</option>
            @php
                // Get the current tahun ajaran ID
                $tahunAjaranId = session('tahun_ajaran_id');
                
                // Fetch classes with tahun ajaran and remove duplicates
                $kelasList = \App\Models\Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->orderBy('nomor_kelas')
                ->orderBy('nama_kelas')
                ->get()
                ->unique(function($item) {
                    // Make unique by nomor_kelas + nama_kelas
                    return $item->nomor_kelas . '_' . $item->nama_kelas;
                });
            @endphp
            @foreach($kelasList as $kelas)
                <option value="{{ $kelas->id }}">{{ $kelas->full_kelas }}</option>
            @endforeach
        </select>
    </div>
    
    <!-- Search Box -->
    <div class="relative">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
            <svg class="w-4 h-4 text-gray-500" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="search" 
               id="search-input"
               class="block w-full p-2 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-green-500 focus:border-green-500" 
               placeholder="Cari siswa...">
    </div>
</div>

    <!-- Data Table -->
    <div class="overflow-x-auto shadow-md rounded-lg">
        <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
    <tr>
        <th class="px-6 py-3">No</th>
        <th class="px-6 py-3">NIS/Nama Siswa</th>
        <th class="px-6 py-3">Kelas</th>
        <th class="px-6 py-3">Tipe Rapor</th>
        <th class="px-6 py-3">Template</th> <!-- Kolom baru -->
        <th class="px-6 py-3">Tahun Ajaran</th>
        <th class="px-6 py-3">Dicetak Oleh</th>
        <th class="px-6 py-3">Waktu Cetak</th>
        <th class="px-6 py-3 text-center min-w-[120px] w-32">Aksi</th>
    </tr>
        </thead>
        <tbody>
            @forelse($reports as $index => $report)
            <tr id="report-row-{{ $report->id }}" class="bg-white border-b hover:bg-gray-50" 
                data-type="{{ $report->type }}" 
                data-kelas="{{ $report->kelas_id }}"
                data-tahun-ajaran="{{ $report->tahun_ajaran_id }}"
                data-search="{{ $report->siswa->nama }} {{ $report->siswa->nis }}">
                <td class="px-6 py-4">{{ $reports->firstItem() + $index }}</td>
                <td class="px-6 py-4 font-medium text-gray-900">
                    {{ $report->siswa->nis }} - {{ $report->siswa->nama }}
                </td>
                <td class="px-6 py-4">{{ $report->kelas?->full_kelas ?? '-' }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium {{ $report->type === 'UTS' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800' }} rounded-full">
                        {{ $report->type }}
                    </span>
                </td>
                <td class="px-6 py-4">
                    @if($report->template && $report->template->kelas_id)
                        <span class="text-xs">{{ $report->template->kelas->full_kelas }}</span>
                    @else
                        <span class="text-xs text-gray-500">Global</span>
                    @endif
                </td>
                <td class="px-6 py-4">{{ $report->tahun_ajaran }}</td>
                <td class="px-6 py-4">{{ $report->generator->nama }}</td>
                <td class="px-6 py-4">{{ $report->created_at->format('d M Y H:i') }}</td>
                <td class="px-1 py-4 text-center whitespace-nowrap">
                    <div class="flex items-center justify-center space-x-2">
                        <!-- Tombol Preview yang diperbarui -->
                        <button onclick="previewRapor({{ $report->id }})" 
                                class="text-blue-600 hover:text-blue-800"
                                title="Preview Rapor">
                            <img src="{{ asset('images/icons/detail.png') }}" alt="Preview" class="w-5 h-5 object-contain">
                        </button>
                        
                        <!-- Tombol Download - tampilkan dengan kondisi jika file ada -->
                        @if($report->generated_file && Storage::disk('public')->exists($report->generated_file))
                            <a href="{{ route('admin.report.history.download', $report->id) }}" 
                                class="text-green-600 hover:text-green-800"
                                title="Unduh Rapor">
                                <img src="{{ asset('images/icons/download.png') }}" alt="Preview" class="w-5 h-5 object-contain">
                            </a>
                        @else
                            <button onclick="showFileNotAvailableAlert()"
                                    class="text-gray-400 cursor-not-allowed"
                                    title="File Tidak Tersedia">
                                <img src="{{ asset('images/icons/download.png') }}" alt="Preview" class="w-5 h-5 object-contain">
                            </button>
                        @endif

                        <button @click="confirmDelete({{ $report->id }}, @js($report->generated_file))"
                                title="Hapus Riwayat"
                                class="text-red-600 hover:text-red-800">
                            <img src="{{ asset('images/icons/delete.png') }}"
                                 alt="Hapus" class="w-5 h-5 object-contain">
                        </button>
                        
                        <!-- Tombol Regenerate jika 
                        @if(!$report->generated_file || !Storage::disk('public')->exists($report->generated_file))
                            <button onclick="regenerateRapor({{ $report->id }})" 
                                    class="text-yellow-600 hover:text-yellow-900"
                                    title="Regenerate Rapor">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        @endif
                        file tidak ada -->
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-6 py-4 text-center text-gray-500">
                    <div class="flex flex-col items-center justify-center py-6">
                        <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <p class="text-gray-500 mb-2">Belum ada history cetak rapor</p>
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4">
        {{ $reports->links() }}
    </div>
</div>

<div id="previewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto max-w-4xl bg-white rounded shadow-lg p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-gray-900">Preview Rapor</h3>
            <button onclick="closePreviewModal()" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <!-- Content -->
        <div id="previewContent" class="overflow-y-auto max-h-[70vh]">
            <div class="flex justify-center items-center h-40">
                <svg class="animate-spin h-8 w-8 text-green-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="ml-2">Memuat preview...</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="mt-6 flex justify-end space-x-3">
            <button onclick="closePreviewModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                Tutup
            </button>
        </div>
    </div>
</div>


@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tahunAjaranSelector = document.getElementById('tahun-ajaran-selector');
        tahunAjaranSelector.addEventListener('change', function() {
            const selectedTahunAjaran = this.value;
            
            // If specific academic year is selected, only show those reports
            if (selectedTahunAjaran) {
                window.location.href = "{{ route('admin.report.history') }}?tahun_ajaran_id=" + selectedTahunAjaran;
            } else {
                window.location.href = "{{ route('admin.report.history') }}";
            }
        });
        // Filter berdasarkan tipe rapor
        const filterType = document.getElementById('filter-type');
        filterType.addEventListener('change', applyFilters);
        
        // Filter berdasarkan kelas
        const filterKelas = document.getElementById('filter-kelas');
        filterKelas.addEventListener('change', applyFilters);
        
        // Filter berdasarkan pencarian
        const searchInput = document.getElementById('search-input');
        searchInput.addEventListener('input', applyFilters);
        
        function applyFilters() {
            const typeFilter = filterType.value;
            const kelasFilter = filterKelas.value;
            const searchFilter = searchInput.value.toLowerCase();
            
            document.querySelectorAll('tbody tr').forEach(row => {
                const rowType = row.getAttribute('data-type');
                const rowKelas = row.getAttribute('data-kelas');
                const rowSearchText = row.getAttribute('data-search').toLowerCase();
                
                // Cek apakah baris memenuhi semua filter
                const matchesType = !typeFilter || rowType === typeFilter;
                const matchesKelas = !kelasFilter || rowKelas === kelasFilter;
                const matchesSearch = !searchFilter || rowSearchText.includes(searchFilter);
                
                // Tampilkan/sembunyikan baris berdasarkan hasil filter
                row.style.display = matchesType && matchesKelas && matchesSearch ? '' : 'none';
            });
        }
    });


    function previewRapor(reportId) {
        // Show modal
        document.getElementById('previewModal').classList.remove('hidden');
        
        // Show loading
        document.getElementById('previewContent').innerHTML = `
            <div class="flex justify-center items-center h-40">
                <svg class="animate-spin h-8 w-8 text-green-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="ml-2">Memuat preview...</p>
            </div>
        `;
        
        // Fetch preview
        fetch(`{{ url('/admin/report-history/preview') }}/${reportId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewContent').innerHTML = data.html;
                } else {
                    document.getElementById('previewContent').innerHTML = `
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
                            <p>Error: ${data.message || 'Gagal memuat preview'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error fetching preview:', error);
                document.getElementById('previewContent').innerHTML = `
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
                        <p>Error: Terjadi kesalahan saat memuat preview</p>
                    </div>
                `;
            });
    }
    
    function closePreviewModal() {
        document.getElementById('previewModal').classList.add('hidden');
    }
    
    function printPreview() {
        const printContent = document.getElementById('previewContent').innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = `
            <div class="p-8">
                <h1 class="text-2xl font-bold text-center mb-6">Preview Rapor</h1>
                ${printContent}
            </div>
        `;
        
        window.print();
        document.body.innerHTML = originalContent;
        
        // Reattach event listeners
        document.querySelectorAll('[onclick]').forEach(el => {
            const onclickAttr = el.getAttribute('onclick');
            if (onclickAttr) {
                el.onclick = new Function(onclickAttr);
            }
        });
    }
    
    function showFileNotAvailableAlert() {
        Swal.fire({
            icon: 'error',
            title: 'File Tidak Tersedia',
            text: 'File rapor tidak tersedia. Silakan gunakan tombol regenerate untuk membuat ulang file rapor.',
            confirmButtonColor: '#3085d6'
        });
    }
    
    function regenerateRapor(reportId) {
        Swal.fire({
            title: 'Regenerasi Rapor?',
            text: "Rapor akan digenerate ulang. Lanjutkan?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Generate Ulang',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Tampilkan loading
                Swal.fire({
                    title: 'Memproses...',
                    text: 'Sedang melakukan regenerasi rapor',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Ajax request untuk regenerasi
                fetch(`/admin/report-history/regenerate/${reportId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: 'Rapor berhasil digenerate ulang.',
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal!',
                            text: data.message || 'Terjadi kesalahan saat regenerasi rapor.',
                            confirmButtonColor: '#3085d6'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: 'Terjadi kesalahan pada server.',
                        confirmButtonColor: '#3085d6'
                    });
                });
            }
        });
    }
</script>
@endpush
@endsection
