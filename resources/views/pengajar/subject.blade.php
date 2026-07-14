@extends('layouts.pengajar.app')

@section('title', 'Data Mata Pelajaran')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Mata Pelajaran</h2>
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
                        <th scope="col" class="w-24 px-1 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subjects as $index => $subject)
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <td class="px-6 py-4">{{ $index + 1 }}</td>
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
                        <td class="w-24 px-1 py-3 text-center whitespace-nowrap">
                            <div class="mx-auto inline-flex w-fit items-center justify-center gap-0">
                                <!-- Edit TP Button -->
                                <a href="{{ route('pengajar.tujuan_pembelajaran.create', $subject->id) }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-emerald-700 transition hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-300"
                                    title="Kelola TP"
                                    aria-label="Kelola TP">
                                    <img src="{{ asset('images/icons/edittp.png') }}" alt="" class="h-6 w-6 object-contain">
                                </a>

                                <a href="{{ route('pengajar.subject.copy_lm_tp', $subject->id) }}"
                                    class="inline-flex h-7 shrink-0 items-center justify-center rounded-md px-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-50 hover:text-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-300"
                                    title="Salin LM/TP dari kelas lain"
                                    aria-label="Salin LM/TP">
                                    Salin
                                </a>

                                <!-- Edit Subject Button -->
                                <a href="{{ route('pengajar.subject.edit', $subject->id) }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-green-700 transition hover:bg-green-50 hover:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-300"
                                    title="Edit"
                                    aria-label="Edit">
                                    <img src="{{ asset('images/icons/edit.png') }}" alt="" class="h-5 w-5 object-contain">
                                </a>

                                <!-- Delete Button -->
                                <form action="{{ route('pengajar.subject.destroy', $subject->id) }}" method="POST" class="inline-flex items-center">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-red-700 transition hover:bg-red-50 hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-300"
                                        onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')"
                                        title="Hapus"
                                        aria-label="Hapus">
                                        <img src="{{ asset('images/icons/delete.png') }}" alt="" class="h-5 w-5 object-contain">
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

        <!-- Pagination -->
        <div class="mt-4">
            {{ $subjects->links('vendor.pagination.custom') }}
        </div>
    </div>
</div>
@endsection
