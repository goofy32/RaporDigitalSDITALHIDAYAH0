@extends('layouts.app')

@section('title', 'Manajemen Tahun Ajaran')

@section('content')
<div class="p-4 bg-white"
     data-page="tahun-ajaran-index"
     data-set-active-base-url="{{ url('admin/tahun-ajaran') }}">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Manajemen Tahun Ajaran</h2>
        <div class="flex gap-2">
            <!-- Modified toggle button with direct link instead of JavaScript -->
            @if($tampilkanArsip || $archivedCount > 0)
                <a href="{{ route('tahun.ajaran.index', ['showArchived' => $tampilkanArsip ? null : 'true']) }}" 
                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-150 ease-in-out"
                id="toggleArchiveBtn">
                    <i class="fas fa-archive mr-2"></i> 
                    {{ $tampilkanArsip ? 'Sembunyikan Arsip' : 'Tampilkan Arsip' }}
                </a>
            @else
                <!-- When there are no archives, show disabled button with info popup -->
                <button type="button" 
                class="px-4 py-2 bg-gray-400 text-white rounded-lg cursor-not-allowed transition duration-150 ease-in-out"
                id="disabledArchiveBtn">
                    <i class="fas fa-archive mr-2"></i> Tampilkan Arsip
                </button>
            @endif
            
            <a href="{{ route('tahun.ajaran.create') }}" 
            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-150 ease-in-out">
                <i class="fas fa-plus mr-2"></i> Tambah Tahun Ajaran
            </a>
        </div>
    </div>

    <!-- Show archive status indicator if we're viewing archived items -->
    @if($tampilkanArsip && $archivedCount > 0)
    <div class="bg-orange-50 border-l-4 border-orange-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-orange-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-orange-700">
                    <strong>Menampilkan tahun ajaran terarsip.</strong> Anda melihat daftar yang menyertakan tahun ajaran yang telah diarsipkan.
                </p>
            </div>
        </div>
    </div>
    @endif

    @if(session('success'))
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
        <p>{{ session('success') }}</p>
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p>{{ session('error') }}</p>
    </div>
    @endif

    <!-- Tahun Ajaran Aktif -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Tahun Ajaran Aktif</h3>
        @php
            $activeTahunAjaran = $tahunAjarans->where('is_active', true)->first();
        @endphp

        @if($activeTahunAjaran)
            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center">
                            <h4 class="text-xl font-bold text-green-800">{{ $activeTahunAjaran->tahun_ajaran }}</h4>
                            <span class="ml-3 px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">AKTIF</span>
                        </div>
                        <p class="text-green-600">Semester {{ $activeTahunAjaran->semester }} ({{ $activeTahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }})</p>
                        <p class="text-sm text-gray-600">{{ date('d F Y', strtotime($activeTahunAjaran->tanggal_mulai)) }} - {{ date('d F Y', strtotime($activeTahunAjaran->tanggal_selesai)) }}</p>
                    </div>
                </div>
                @if($activeTahunAjaran->deskripsi)
                <div class="mt-2 p-2 bg-white rounded border border-green-100">
                    <p class="text-sm text-gray-600">{{ $activeTahunAjaran->deskripsi }}</p>
                </div>
                @endif
            </div>
            @else
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <p class="text-yellow-700">Tidak ada tahun ajaran yang aktif saat ini. Silakan aktifkan salah satu tahun ajaran.</p>
            </div>
        @endif
    </div>

    <!-- Daftar Tahun Ajaran -->
    <div>
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Daftar Semua Tahun Ajaran</h3>
        
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tahun Ajaran</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Semester</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tanggal Mulai</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tanggal Selesai</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($tahunAjarans as $tahunAjaran)
                    @php
                        $permanentDeleteProtectionMessage = $permanentDeleteProtectionMessages[$tahunAjaran->id] ?? null;
                    @endphp
                    <tr class="hover:bg-gray-50 {{ $tahunAjaran->trashed() ? 'bg-gray-100' : '' }}">
                        <td class="py-4 px-4 border-b">
                            <div class="font-medium text-gray-900">
                                {{ $tahunAjaran->tahun_ajaran }}
                            </div>
                            @if($tahunAjaran->deskripsi)
                            <div class="text-sm text-gray-500">{{ Str::limit($tahunAjaran->deskripsi, 50) }}</div>
                            @endif
                        </td>
                        <td class="py-4 px-4 border-b">
                            {{ $tahunAjaran->semester }} ({{ $tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }})
                        </td>
                        <td class="py-4 px-4 border-b">
                            {{ date('d/m/Y', strtotime($tahunAjaran->tanggal_mulai)) }}
                        </td>
                        <td class="py-4 px-4 border-b">
                            {{ date('d/m/Y', strtotime($tahunAjaran->tanggal_selesai)) }}
                        </td>
                        <td class="py-4 px-4 border-b">
                            <div class="flex flex-col gap-1">
                            @if($tahunAjaran->trashed())
                                <span class="px-2 py-1 text-xs font-semibold text-orange-800 bg-orange-100 rounded-full">Diarsipkan</span>
                            @elseif($tahunAjaran->is_active)
                                <span class="px-2 py-1 text-xs font-semibold text-black-800 bg-green-100 rounded-full">Aktif</span>
                            @else
                                <span class="px-2 py-1 text-xs font-semibold text-black-800 bg-gray-100 rounded-full">Tidak Aktif</span>
                            @endif
                            <span class="text-xs font-semibold {{ $tahunAjaran->semester == 1 ? 'text-black-800' : 'text-black-800' }}">
                                Semester {{ $tahunAjaran->semester }} ({{ $tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }})
                            </span>
                            </div>
                        </td>
                        <td class="py-4 px-4 border-b text-sm">
                            <div class="flex items-center space-x-4">
                                <!-- Tombol Detail -->
                                <a href="{{ route('tahun.ajaran.show', $tahunAjaran->id) }}" title="Detail">
                                    <img src="{{ asset('images/icons/detail.png') }}" alt="Detail" class="w-5 h-5">
                                </a>
                                
                                @if(!$tahunAjaran->trashed())
                                    <!-- Tombol Edit (hanya untuk yang tidak diarsipkan) -->
                                    <a href="{{ route('tahun.ajaran.edit', $tahunAjaran->id) }}" title="Edit">
                                        <img src="{{ asset('images/icons/edit.png') }}" alt="Edit" class="w-5 h-5">
                                    </a>
                                @endif
                                
                                <!-- Simple checkbox for active status (in action column) -->
                                @if(!$tahunAjaran->trashed())
                                    <div class="flex items-center">
                                        <div class="relative">
                                            <input 
                                                type="checkbox" 
                                                class="h-5 w-5 rounded border-gray-300 text-green-600 focus:ring-green-500"
                                                id="active-{{ $tahunAjaran->id }}"
                                                {{ $tahunAjaran->is_active ? 'checked' : '' }}
                                                {{ $tahunAjaran->is_active ? 'disabled' : '' }}
                                                @if(!$tahunAjaran->is_active)
                                                    data-activate-id="{{ $tahunAjaran->id }}"
                                                    data-tahun-ajaran-name="{{ $tahunAjaran->tahun_ajaran }}"
                                                @endif
                                            >
                                        </div>
                                    </div>
                                @endif
                            

                                @if($tahunAjaran->trashed())
                                    <!-- Tombol Restore untuk tahun ajaran yang diarsipkan -->
                                    <form action="{{ route('tahun.ajaran.restore', $tahunAjaran->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="border-0 bg-transparent p-0"
                                                title="Pulihkan"
                                                onclick="return confirm('Apakah Anda yakin ingin memulihkan tahun ajaran ini?')">

                                            <svg xmlns="http://www.w3.org/2000/svg"
                                                class="w-5 h-5"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M3 12a9 9 0 1 0 3-6.7" />
                                                <path d="M3 4v6h6" />
                                            </svg>

                                        </button>
                                    </form>

                                    @if($permanentDeleteProtectionMessage)
                                        <div class="max-w-xs text-xs text-gray-600" title="{{ $permanentDeleteProtectionMessage }}">
                                            <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-1 font-semibold text-yellow-800">Dilindungi</span>
                                            <p class="mt-1 leading-snug">{{ $permanentDeleteProtectionMessage }}</p>
                                        </div>
                                    @else
                                        <!-- Tombol Hapus Permanen untuk tahun ajaran yang diarsipkan dan aman dihapus -->
                                        <form action="{{ route('tahun.ajaran.force-delete', $tahunAjaran->id) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="border-0 bg-transparent p-0" title="Hapus Permanen" 
                                                    onclick="return confirm('PERHATIAN: Tindakan ini tidak dapat dibatalkan. Tahun ajaran hanya dapat dihapus permanen jika tidak memiliki data siswa/enrollment atau riwayat terkait.\n\nApakah Anda benar-benar yakin?')">
                                                <img src="{{ asset('images/icons/delete.png') }}" alt="Hapus Permanen" class="w-5 h-5">
                                            </button>
                                        </form>
                                    @endif
                                @elseif(!$tahunAjaran->is_active)
                                    <!-- Tombol Arsip untuk tahun ajaran yang tidak diarsipkan -->
                                    <form action="{{ route('tahun.ajaran.destroy', $tahunAjaran->id) }}" method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="border-0 bg-transparent p-0" title="Arsipkan"
                                                onclick="return confirm('Apakah Anda yakin ingin mengarsipkan tahun ajaran {{ $tahunAjaran->tahun_ajaran }}?\n\nData terkait masih dapat diakses setelah diarsipkan dengan menampilkan tahun ajaran terarsip.')">
                                            <img src="{{ asset('images/icons/delete.png') }}" alt="Arsipkan" class="w-5 h-5">
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-4 px-4 text-center text-gray-500">
                            Tidak ada data tahun ajaran. Silakan tambahkan tahun ajaran baru.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Tooltip styles - untuk memastikan tooltip bekerja dengan baik */
.tooltip {
    position: relative;
    display: inline-block;
}

.tooltip[title]:hover:after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background-color: rgba(55, 65, 81, 0.9);
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    white-space: nowrap;
    font-size: 12px;
    pointer-events: none;
    z-index: 10;
}

/* Add transition effects */
.transition {
    transition-property: background-color, border-color, color, fill, stroke;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}
</style>

@endsection
