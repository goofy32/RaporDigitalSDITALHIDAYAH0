@extends('layouts.pengajar.app')

@section('title', 'Data Tujuan Pembelajaran')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Tujuan Pembelajaran - {{ $mataPelajaran->nama_pelajaran }}</h2>
            <div>
                <a href="{{ route('pengajar.tujuan_pembelajaran.create', $mataPelajaran->id) }}" 
                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Tambah Tujuan Pembelajaran
                </a>
            </div>
        </div>

        <!-- Table -->
        <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3">No</th>
                        <th scope="col" class="px-6 py-3">Lingkup Materi</th>
                        <th scope="col" class="px-6 py-3">Kode TP</th>
                        <th scope="col" class="px-6 py-3">Deskripsi TP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mataPelajaran->lingkupMateris as $lm)
                        @foreach($lm->tujuanPembelajarans as $index => $tp)
                        <tr class="bg-white border-b hover:bg-gray-50">
                            <td class="px-6 py-4">{{ $index + 1 }}</td>
                            <td class="px-6 py-4">{{ $lm->judul_lingkup_materi }}</td>
                            <td class="px-6 py-4">{{ $tp->kode_tp }}</td>
                            <td class="px-6 py-4">{{ $tp->deskripsi_tp }}</td>
                        </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
