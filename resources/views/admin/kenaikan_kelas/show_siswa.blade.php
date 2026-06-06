@extends('layouts.app')

@section('title', 'Proses Kenaikan Kelas')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-md"
     data-page="kenaikan-kelas-show"
     data-rapor-status='@json($raporStatus)'
     data-session-details='@json(session('siswa_details', []))'
     data-session-action="{{ session('action_type') }}"
     data-session-status="{{ session('status') }}">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Proses {{ $isKelasAkhir ? 'Kelulusan' : 'Kenaikan Kelas' }}</h2>
        <a href="{{ route('admin.kenaikan-kelas.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
            <i class="fas fa-arrow-left mr-2"></i> Kembali
        </a>
    </div>

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

    @if(isset($promotionWritesEnabled) && !$promotionWritesEnabled)
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-4 mb-6 rounded-lg">
        <p class="font-medium">Mode perencanaan kenaikan kelas</p>
        <p class="text-sm mt-1">{{ $promotionWritesDisabledMessage ?? 'Kenaikan kelas berbasis enrollment belum diaktifkan.' }}</p>
    </div>
    @endif

    <div class="mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</h3>
        <p class="text-gray-600">Wali Kelas: {{ $kelas->waliKelasName }}</p>
        <p class="text-gray-600">Jumlah Siswa: {{ $siswaList->count() }}</p>
        @if(isset($tahunAjaranAktif))
            <p class="text-gray-500 text-sm mt-1">Sumber: {{ $tahunAjaranAktif->tahun_ajaran }} Semester {{ $tahunAjaranAktif->semester }}</p>
        @endif
        @if(isset($tahunAjaranBaru) && $tahunAjaranBaru)
            <p class="text-gray-500 text-sm">Tujuan: {{ $tahunAjaranBaru->tahun_ajaran }} Semester {{ $tahunAjaranBaru->semester }}</p>
        @endif
    </div>

    @if(isset($tahunAjaranBaru) && $tahunAjaranBaru)
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <h4 class="font-medium text-green-800 mb-2">Kandidat Naik Kelas</h4>
            @if(!$isKelasAkhir && $kelasTujuan->isNotEmpty())
                <ul class="text-sm text-green-700 space-y-1">
                    @foreach($kelasTujuan as $target)
                        <li>Kelas {{ $target->nomor_kelas }} {{ $target->nama_kelas }}</li>
                    @endforeach
                </ul>
            @elseif($isKelasAkhir)
                <p class="text-sm text-green-700">Kelas akhir dapat ditandai lulus, pindah/keluar, atau tidak aktif tanpa membuat enrollment baru.</p>
            @else
                <p class="text-sm text-red-700">Belum ada kelas tujuan tingkat berikutnya.</p>
            @endif
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <h4 class="font-medium text-yellow-800 mb-2">Kandidat Tinggal Kelas</h4>
            @if(isset($kelasTinggal) && $kelasTinggal->isNotEmpty())
                <ul class="text-sm text-yellow-700 space-y-1">
                    @foreach($kelasTinggal as $target)
                        <li>Kelas {{ $target->nomor_kelas }} {{ $target->nama_kelas }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-red-700">Belum ada kelas tujuan tingkat yang sama.</p>
            @endif
        </div>
    </div>
    @endif

    @if($siswaList->isEmpty())
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <p class="text-yellow-800">Tidak ada siswa yang perlu diproses di kelas ini. Semua siswa mungkin sudah dipindahkan atau diluluskan.</p>
    </div>
    @else

    @if(!$isKelasAkhir && $kelasTujuan->isEmpty())
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <p class="text-red-800">Tidak ada kelas tujuan yang tersedia di tahun ajaran baru. Pastikan kelas untuk tingkat berikutnya sudah dibuat.</p>
        <a href="{{ route('kelas.create') }}" class="text-blue-600 hover:underline mt-2 inline-block">Buat Kelas Baru</a>
    </div>
    @else

    <div class="mb-6">
        @if($promotionWritesEnabled)
        <div class="flex items-center mb-4">
            <input id="select-all" type="checkbox" class="h-4 w-4 text-green-600 focus:ring-green-500">
            <label for="select-all" class="ml-2 block text-sm text-gray-900">Pilih Semua Siswa</label>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        @if($promotionWritesEnabled)
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Pilih</th>
                        @endif
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">NIS</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Jenis Kelamin</th>
                        <th class="py-3 px-4 border-b text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status Rapor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($siswaList as $siswa)
                    <tr data-siswa-id="{{ $siswa->id }}">
                        @if($promotionWritesEnabled)
                        <td class="py-3 px-4 border-b">
                            <input type="checkbox" name="siswa_ids[]" value="{{ $siswa->id }}" class="student-checkbox h-4 w-4 text-green-600 focus:ring-green-500">
                        </td>
                        @endif
                        <td class="py-3 px-4 border-b">{{ $siswa->nis }}</td>
                        <td class="py-3 px-4 border-b">{{ $siswa->nama }}</td>
                        <td class="py-3 px-4 border-b">{{ $siswa->jenis_kelamin }}</td>
                        <td class="py-3 px-4 border-b">
                            @if($raporStatus[$siswa->id])
                                <span class="inline-flex items-center bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <span class="w-2 h-2 mr-1 bg-green-500 rounded-full"></span>
                                    Rapor Tersedia
                                </span>
                            @else
                                <span class="inline-flex items-center bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <span class="w-2 h-2 mr-1 bg-gray-500 rounded-full"></span>
                                    Belum Ada Rapor
                                </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($promotionWritesEnabled)
    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6" id="actionForms" style="display: none;">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Proses Siswa Terpilih</h3>
        <p class="mb-3">Anda telah memilih <span id="selectedCount" class="font-semibold">0</span> siswa.</p>

        @if($isKelasAkhir)
        <form action="{{ route('admin.kenaikan-kelas.process-kelulusan') }}" method="POST" class="space-y-4" id="kelulusanForm" x-data="{ selectedStatus: '' }">
            @csrf
            <input type="hidden" name="source_kelas_id" value="{{ $kelas->id }}">
            <input type="hidden" name="source_tahun_ajaran_id" value="{{ $tahunAjaranAktif->id }}">
            <div id="selectedKelulusanIds"></div>

            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                <h4 class="text-md font-medium text-blue-800 mb-2">Informasi Kelulusan dan Status Akhir</h4>
                <ul class="list-disc pl-5 text-sm space-y-1 text-blue-700">
                    <li>Siswa akan diperbarui statusnya sesuai pilihan.</li>
                    <li>Tidak ada enrollment tahun ajaran baru yang dibuat.</li>
                    <li>Riwayat kelas, nilai, rapor, dan data semester sebelumnya tetap tersimpan.</li>
                </ul>
            </div>

            <div>
                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                <select name="status" id="status" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" x-model="selectedStatus">
                    <option value="">-- Pilih Status --</option>
                    <option value="lulus">Lulus</option>
                    <option value="pindah">Pindah/Keluar</option>
                    <option value="dropout">Tidak Aktif</option>
                </select>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="check-rapor-btn px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" data-action="proses status akhir">
                    <span x-text="selectedStatus === 'pindah' ? 'Proses Pindah/Keluar' : (selectedStatus === 'dropout' ? 'Proses Tidak Aktif' : 'Proses Kelulusan')">Proses Kelulusan</span>
                </button>
            </div>
        </form>
        @else
        <div class="space-y-4">
            <div class="flex flex-col md:flex-row gap-4">
                <form action="{{ route('admin.kenaikan-kelas.process-kenaikan') }}" method="POST" class="flex-1 bg-white p-4 rounded-lg border border-gray-200" id="naik-kelas-form">
                    @csrf
                    <input type="hidden" name="source_kelas_id" value="{{ $kelas->id }}">
                    <input type="hidden" name="source_tahun_ajaran_id" value="{{ $tahunAjaranAktif->id }}">
                    <input type="hidden" name="target_tahun_ajaran_id" value="{{ $tahunAjaranBaru->id }}">
                    <div id="selectedNaikIds"></div>

                    <h4 class="text-md font-semibold text-green-700 mb-3">Naik Kelas</h4>

                    <div class="mb-4">
                        <label for="kelas_tujuan_id" class="block text-sm font-medium text-gray-700">Kelas Tujuan</label>
                        <select
                            name="kelas_tujuan_id"
                            id="kelas_tujuan_id"
                            required
                            class="mt-1 block w-full rounded-md border-green-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                            style="border-color: rgb(134, 239, 172); outline-color: rgb(34, 197, 94);">
                            <option value="">-- Pilih Kelas Tujuan --</option>
                            @foreach($kelasTujuan as $target)
                            <option value="{{ $target->id }}">Kelas {{ $target->nomor_kelas }} {{ $target->nama_kelas }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="check-rapor-btn px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" data-action="kenaikan kelas">
                            Proses Naik Kelas
                        </button>
                    </div>
                </form>

                <form action="{{ route('admin.kenaikan-kelas.process-tinggal') }}" method="POST" class="flex-1 bg-white p-4 rounded-lg border border-gray-200" id="tinggal-kelas-form">
                    @csrf
                    <input type="hidden" name="source_kelas_id" value="{{ $kelas->id }}">
                    <input type="hidden" name="source_tahun_ajaran_id" value="{{ $tahunAjaranAktif->id }}">
                    <input type="hidden" name="target_tahun_ajaran_id" value="{{ $tahunAjaranBaru->id }}">
                    <div id="selectedTinggalIds"></div>

                    <h4 class="text-md font-semibold text-red-700 mb-3">Tinggal Kelas</h4>

                    <div class="mb-4">
                        <label for="kelas_tinggal_id" class="block text-sm font-medium text-gray-700">Kelas Tujuan (Tingkat yang Sama)</label>
                        <select name="kelas_tujuan_id" id="kelas_tinggal_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-yellow-500">
                            <option value="">-- Pilih Kelas Tinggal --</option>
                            @foreach($kelasTinggal as $target)
                            <option value="{{ $target->id }}">Kelas {{ $target->nomor_kelas }} {{ $target->nama_kelas }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="check-rapor-btn px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-yellow-500" data-action="tinggal kelas">
                            Proses Tinggal Kelas
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
    @endif
    @endif
    @endif
</div>

<style>
    #kelas_tujuan_id {
        border-color: rgb(134, 239, 172) !important;
    }

    #kelas_tujuan_id:focus {
        border-color: rgb(34, 197, 94) !important;
        box-shadow: 0 0 0 1px rgb(34, 197, 94) !important;
    }

    #kelas_tinggal_id {
        border-color: rgb(252, 165, 165) !important;
    }

    #kelas_tinggal_id:focus {
        border-color: rgb(239, 68, 68) !important;
        box-shadow: 0 0 0 1px rgb(239, 68, 68) !important;
    }
</style>
@endsection
