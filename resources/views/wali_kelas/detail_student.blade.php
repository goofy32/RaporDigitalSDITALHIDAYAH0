@extends('layouts.wali_kelas.app')

@section('title', 'Detail Data Siswa')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div class="p-4 bg-white rounded-lg shadow-sm mt-14">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Detail Data Siswa</h2>
        <div class="flex gap-2">
            <a href="{{ route('wali_kelas.student.edit', $student->id) }}" 
                class="bg-green-700 text-white px-4 py-2 rounded-lg hover:bg-green-800 focus:ring-4 focus:ring-green-300">
                Edit
            </a>
            <button onclick="window.history.back()" 
                class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 focus:ring-4 focus:ring-gray-300">
                Kembali
            </button>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-8">
        <!-- Photo Section -->
        <div class="w-full md:w-1/4">
            <div class="bg-gray-100 rounded-lg p-4">
                <div class="flex items-start justify-center w-64 h-80 bg-gray-200 rounded-lg shadow-md overflow-hidden">
                    @if($student->photo)
                        <img src="{{ asset('storage/' . $student->photo) }}" 
                             alt="{{ $student->nama }}" 
                             class="object-cover w-full h-full rounded-lg">
                    @else
                        <div class="flex items-center justify-center w-full h-full">
                            <svg class="w-32 h-32 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    @endif
                </div>
                <h3 class="text-center mt-4 font-semibold text-lg">{{ $student->nama }}</h3>
            </div>
        </div>

        <!-- Details Section -->
        <div class="w-full md:w-3/4">
            <div class="grid grid-cols-1 gap-4">
                <div class="border rounded-lg overflow-hidden">
                    <table class="w-full">
                        <tbody>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50 w-1/3">NISN</th>
                                <td class="px-4 py-2">{{ str_starts_with($student->nisn, 'S2-') ? substr($student->nisn, 3) : $student->nisn }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">NIS</th>
                                <td class="px-4 py-2">{{ str_starts_with($student->nis, 'S2-') ? substr($student->nis, 3) : $student->nis }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Nama</th>
                                <td class="px-4 py-2">{{ $student->nama }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Kelas</th>
                                <td class="px-4 py-2">{{ $student->kelas->nomor_kelas }} {{ $student->kelas->nama_kelas }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Tanggal Lahir</th>
                                <td class="px-4 py-2">{{ \Carbon\Carbon::parse($student->tanggal_lahir)->isoFormat('D MMMM Y') }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Jenis Kelamin</th>
                                <td class="px-4 py-2">{{ $student->jenis_kelamin }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Agama</th>
                                <td class="px-4 py-2">{{ $student->agama }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Alamat</th>
                                <td class="px-4 py-2">{{ $student->alamat }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Nama Ayah</th>
                                <td class="px-4 py-2">{{ $student->nama_ayah ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Pekerjaan Ayah</th>
                                <td class="px-4 py-2">{{ $student->pekerjaan_ayah ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Nama Ibu</th>
                                <td class="px-4 py-2">{{ $student->nama_ibu ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Pekerjaan Ibu</th>
                                <td class="px-4 py-2">{{ $student->pekerjaan_ibu ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Alamat Orang Tua</th>
                                <td class="px-4 py-2">{{ $student->alamat_orangtua ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Wali Siswa</th>
                                <td class="px-4 py-2">{{ $student->wali_siswa ?? '-' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 bg-gray-50">Pekerjaan Wali</th>
                                <td class="px-4 py-2">{{ $student->pekerjaan_wali ?? '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
