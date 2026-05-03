{{-- resources/views/wali_kelas/student.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('title', 'Data Siswa')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Siswa</h2>
        </div>

        <!-- Search Bar -->
        <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
            <form action="{{ route('wali_kelas.student.index') }}" method="GET" class="w-full">
                <div class="flex gap-2">
                    <input type="text" name="search" value="{{ request('search') }}"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2"
                        placeholder="Cari siswa...">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
  
        <!-- Table -->
        <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">No</th>
                        <th class="px-6 py-3">NIS</th>
                        <th class="px-6 py-3">NISN</th>
                        <th class="px-6 py-3">Nama</th>
                        <th class="px-6 py-3">Kelas</th>
                        <th class="px-6 py-3">Jenis Kelamin</th>
                        <th class="px-6 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $student)
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <td class="px-6 py-4">{{ ($students->currentPage() - 1) * $students->perPage() + $loop->iteration }}</td>
                        <td class="px-6 py-4">{{ str_starts_with($student->nis, 'S2-') ? substr($student->nis, 3) : $student->nis }}</td>
                        <td class="px-6 py-4">{{ str_starts_with($student->nisn, 'S2-') ? substr($student->nisn, 3) : $student->nisn }}</td>
                        <td class="px-6 py-4">{{ $student->nama }}</td> 
                        <td class="px-6 py-4">
                            @if($student->kelas)
                                {{ $student->kelas->nomor_kelas }} - {{ $student->kelas->nama_kelas }}
                            @else
                                <span class="text-gray-400">Tidak ada kelas</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">{{ $student->jenis_kelamin }}</td>
                        <td class="px-6 py-4">
                            <div class="flex justify-center gap-2">
                                <!-- Detail -->
                                <a href="{{ route('wali_kelas.student.show', $student->id) }}" 
                                   class="text-blue-600 hover:text-blue-800"
                                   title="Detail Siswa">
                                    <img src="{{ asset('images/icons/detail.png') }}" alt="Detail Icon" class="w-5 h-5">
                                </a>
                                
                                <!-- Catatan Siswa-->
                                <a href="{{ route('wali_kelas.catatan.siswa.show', $student->id) }}" 
                                   class="text-green-600 hover:text-green-800"
                                   title="Buat Catatan Guru Untuk Siswa">
                                    <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                                </a>
                                
                                <!-- Delete -->
                                <form action="{{ route('wali_kelas.student.destroy', $student->id) }}" method="POST" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" 
                                            class="text-red-600 hover:text-red-800" 
                                            title="Hapus Siswa"
                                            onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')">
                                        <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center">Tidak ada data siswa</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $students->links('vendor.pagination.custom') }}
        </div>
    </div>
</div>
@endsection
