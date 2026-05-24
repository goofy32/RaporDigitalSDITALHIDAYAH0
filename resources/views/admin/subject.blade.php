@extends('layouts.app')

@section('title', 'Data Pelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Mata Pelajaran</h2>
        </div>

        <div class="flex justify-start mb-4 gap-2">
            <a href="{{ route('subject.create') }}" class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Tambah Data 
            </a>
            
            <a href="{{ route('admin.subject.bobot-nilai') }}" class="flex items-center justify-center text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                </svg>
                Bobot Nilai
            </a>
            
            <a href="{{ route('admin.subject.kkm') }}" class="flex items-center justify-center text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                Kriteria Ketuntasan Minimum
            </a>
        </div>

        <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
            <form action="{{ route('subject.index') }}" method="GET" class="w-full" data-turbo="false">
                <div class="flex gap-2">
                    <input type="text" name="search" value="{{ request('search') }}"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2"
                        placeholder="Cari nama mata pelajaran">
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
                        <th scope="col" class="px-6 py-3">No</th>
                        <th scope="col" class="px-6 py-3">Mata Pelajaran</th>
                        <th scope="col" class="px-6 py-3">Kelas</th>
                        <th scope="col" class="px-6 py-3">Semester</th>
                        <th scope="col" class="px-6 py-3">Guru Pengampu</th>
                        <th scope="col" class="px-6 py-3">Lingkup Materi</th>
                        <th scope="col" class="px-6 py-3 text-center min-w-[100px] w-28">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subjects as $index => $subject)
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <td class="px-6 py-4">{{ ($subjects->currentPage() - 1) * $subjects->perPage() + $loop->iteration }}</td>
                        <td class="px-6 py-4">{{ $subject->nama_pelajaran }}</td>
                        <td class="px-6 py-4">{{ $subject->kelas->nomor_kelas }}-{{ $subject->kelas->nama_kelas }}</td>
                        <td class="px-6 py-4">Semester {{ $subject->semester }}</td>
                        <td class="px-6 py-4">{{ $subject->guru->nama }}</td>
                        <td class="px-6 py-4">
                            @if($subject->lingkupMateris->isNotEmpty())
                                <ul class="list-disc list-inside">
                                    @foreach($subject->lingkupMateris as $lm)
                                        <li>{{ $lm->judul_lingkup_materi }}</li>
                                    @endforeach
                                </ul>
                            @else
                                Tidak ada Lingkup Materi
                            @endif
                        </td>

                        <td class="px-1 py-4 text-center whitespace-nowrap">
                            <div class="flex items-center justify-center gap-2">
                                <a href="{{ route('tujuan_pembelajaran.create', $subject->id) }}"
                                   class="inline-flex items-center justify-center text-green-600 hover:text-green-800"
                                   title="Ubah atau Lihat Tujuan Pembelajaran">
                                    <img src="{{ asset('images/icons/edittp.png') }}" alt="Extracurricular Icon" class="w-5 h-5 object-contain">
                                </a>

                                <a href="{{ route('subject.edit', $subject->id) }}"
                                   data-turbo-action="replace"
                                   class="inline-flex items-center justify-center text-green-600 hover:text-green-800"
                                   title="Ubah Data">
                                    <img src="{{ asset('images/icons/edit.png') }}" alt="Extracurricular Icon" class="w-5 h-5 object-contain">
                                </a>

                                <form action="{{ route('subject.destroy', $subject->id) }}" method="POST" class="inline-flex items-center">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center justify-center text-red-600 hover:text-red-800"
                                            onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')"
                                            title="Hapus Data">
                                        <img src="{{ asset('images/icons/delete.png') }}" alt="Extracurricular Icon" class="w-5 h-5 object-contain">
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr class="bg-white border-b">
                        <td colspan="7" class="px-6 py-4 text-center">Tidak ada data mata pelajaran</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $subjects->links('vendor.pagination.custom') }}
        </div>
    </div>
</div>

<!-- Flowbite JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.js"></script>
@endsection
