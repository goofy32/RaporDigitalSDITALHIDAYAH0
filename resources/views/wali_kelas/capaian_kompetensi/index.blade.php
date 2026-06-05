{{-- resources/views/wali_kelas/capaian_kompetensi/index.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('content')
<div class="p-4 bg-white mt-14">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Capaian Kompetensi</h2>
        <div class="text-sm text-gray-600">
            Kelas: {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}
        </div>
    </div>

    <!-- Info Section -->
    <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-green-700">
                    <strong>Tentang Capaian Kompetensi:</strong> Sistem otomatis menghasilkan capaian kompetensi berdasarkan nilai siswa. 
                    Anda dapat menambahkan kustomisasi untuk setiap siswa sesuai kebutuhan. Capaian ini akan muncul di rapor UTS dan UAS.
                </p>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">No</th>
                    <th class="px-6 py-3">Mata Pelajaran</th>
                    <th class="px-6 py-3">Guru Pengampu</th>
                    <th class="px-6 py-3">Semester</th>
                    <th class="px-6 py-3 text-center">Status Kustomisasi</th>
                    <th class="px-6 py-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($mataPelajarans as $index => $mataPelajaran)
                    @php
                        $customCount = (int) ($customCounts[$mataPelajaran->id] ?? 0);
                    @endphp
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <td class="px-6 py-4">{{ $index + 1 }}</td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-gray-900">{{ $mataPelajaran->nama_pelajaran }}</div>
                            @if($mataPelajaran->is_muatan_lokal)
                                <div class="text-xs text-orange-600 font-medium">Muatan Lokal</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">{{ $mataPelajaran->guru->nama ?? 'Belum ditentukan' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Semester {{ $mataPelajaran->semester }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($customCount > 0)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    {{ $customCount }}/{{ $totalSiswa }} dikustomisasi
                                </span>
                            @else
                                <span class="text-xs text-gray-500">Otomatis</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex justify-center">
                                <a href="{{ route('wali_kelas.capaian_kompetensi.edit', $mataPelajaran->id) }}" 
                                   class="text-yellow-600 hover:text-yellow-800"
                                   title="Kelola Capaian">
                                    <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center">Tidak ada mata pelajaran</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
