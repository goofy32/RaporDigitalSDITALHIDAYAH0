@extends('layouts.pengajar.app')

@section('title', 'Data Pembelajaran')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')

<div data-page="pengajar-score" class="p-4 bg-white mt-14 rounded-lg">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-green-700 mb-4">Data Pembelajaran</h2>
    </div>

    <!-- Debug information -->
    @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('error') }}</span>
        </div>
    @endif

    <!-- Dashboard KKM Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 mt-4">
        @php
        $totalMataPelajaran = 0;
        $mapelDenganNilaiRendah = [];
        $siswaDibawahKKM = [];

        // Get KKM notification settings using the Setting model
        $completeScoresOnly = \App\Models\Setting::getBool('kkm_notification_complete_scores_only', false);

        foreach($kelasData as $kelas) {
            foreach($kelas->mataPelajarans as $mapel) {
                $totalMataPelajaran++;
                
                // Ambil KKM untuk mata pelajaran ini
                $kkm = \App\Models\Kkm::where('mata_pelajaran_id', $mapel->id)
                    ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
                    ->first();
                
                $kkmValue = $kkm ? $kkm->nilai : 70; // Default ke 70 jika tidak ada
                
                // Build query for students with scores below KKM
                $query = \App\Models\Nilai::where('mata_pelajaran_id', $mapel->id)
                    ->whereNotNull('nilai_akhir_rapor')
                    ->where('nilai_akhir_rapor', '<', $kkmValue);
                    
                // If we require complete scores, add conditions for all components
                if ($completeScoresOnly) {
                    $query->whereNotNull('nilai_tp')
                        ->whereNotNull('nilai_lm')
                        ->whereNotNull('nilai_tes')
                        ->whereNotNull('nilai_non_tes');
                }
                
                $lowScores = $query->count();
                    
                if ($lowScores > 0) {
                    $mapelDenganNilaiRendah[] = [
                        'mapel' => $mapel,
                        'kelas' => $kelas,
                        'kkm' => $kkmValue,
                        'jumlah_siswa' => $lowScores
                    ];
                    
                    // Get students with low scores
                    $siswaLowQuery = \App\Models\Nilai::where('mata_pelajaran_id', $mapel->id)
                        ->whereNotNull('nilai_akhir_rapor')
                        ->where('nilai_akhir_rapor', '<', $kkmValue);
                        
                    // If requiring complete scores, add the same conditions
                    if ($completeScoresOnly) {
                        $siswaLowQuery->whereNotNull('nilai_tp')
                            ->whereNotNull('nilai_lm')
                            ->whereNotNull('nilai_tes')
                            ->whereNotNull('nilai_non_tes');
                    }
                    
                    $siswaLow = $siswaLowQuery->with('siswa')->get();
                        
                    foreach($siswaLow as $nilai) {
                        if (!isset($siswaDibawahKKM[$nilai->siswa_id])) {
                            $siswaDibawahKKM[$nilai->siswa_id] = [
                                'siswa' => $nilai->siswa,
                                'mapel' => []
                            ];
                        }
                        
                        $siswaDibawahKKM[$nilai->siswa_id]['mapel'][] = [
                            'nama' => $mapel->nama_pelajaran,
                            'nilai' => $nilai->nilai_akhir_rapor,
                            'kkm' => $kkmValue,
                            'complete' => $nilai->nilai_tp !== null && 
                                        $nilai->nilai_lm !== null && 
                                        $nilai->nilai_tes !== null && 
                                        $nilai->nilai_non_tes !== null
                        ];
                    }
                }
            }
        }

        $totalSiswaDibawahKKM = count($siswaDibawahKKM);
        $totalMapelBermasalah = count($mapelDenganNilaiRendah);
        @endphp

        <!-- Card: Total Mata Pelajaran -->
        <div class="bg-white rounded-lg shadow p-4 border-l-4 {{ $totalMapelBermasalah > 0 ? 'border-green-700' : 'border-green-700' }}">
            <div class="flex items-center">
                <div class="p-3 rounded-full {{ $totalMapelBermasalah > 0 ? 'bg-green-100 text-green-800' : 'bg-green-100 text-green-800' }} mr-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total Mata Pelajaran</p>
                    <p class="text-xl font-semibold">{{ $totalMataPelajaran }}</p>
                    <p class="text-sm {{ $totalMapelBermasalah > 0 ? 'text-yellow-600' : 'text-green-600' }}">
                        {{ $totalMapelBermasalah > 0 ? $totalMapelBermasalah . ' mata pelajaran memiliki nilai di bawah KKM' : 'Semua mata pelajaran memenuhi KKM' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Card: Siswa Dibawah KKM -->
        <div class="bg-white rounded-lg shadow p-4 border-l-4 {{ $totalSiswaDibawahKKM > 0 ? 'border-green-700' : 'border-green-700' }}">
            <div class="flex items-center">
                <div class="p-3 rounded-full {{ $totalSiswaDibawahKKM > 0 ? 'bg-green-100 text-green-800' : 'bg-green-100 text-green-800' }} mr-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Siswa Dibawah KKM</p>
                    <p class="text-xl font-semibold">{{ $totalSiswaDibawahKKM }}</p>
                    <p class="text-sm {{ $totalSiswaDibawahKKM > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $totalSiswaDibawahKKM > 0 ? 'Perlu perhatian lebih' : 'Semua siswa memenuhi KKM' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Card: KKM Terendah -->
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-700">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-800 mr-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Nilai KKM Default</p>
                    <p class="text-xl font-semibold">70</p>
                    <p class="text-sm text-green-600">
                        Diatur di pengaturan rapor
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning Alerts sesuai kondisi -->
    @if($totalMapelBermasalah > 0)
    <div x-data="{ open: true }" x-show="open" class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4 rounded">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">
                    Perhatian: Ada {{ $totalMapelBermasalah }} mata pelajaran dengan nilai dibawah KKM
                </h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>Mata pelajaran yang perlu perhatian:</p>
                    <ul class="list-disc pl-5 space-y-1 mt-1">
                        @foreach($mapelDenganNilaiRendah as $item)
                        <li>
                            <strong>{{ $item['mapel']->nama_pelajaran }}</strong> 
                            (Kelas {{ $item['kelas']->nomor_kelas }} {{ $item['kelas']->nama_kelas }}) - 
                            {{ $item['jumlah_siswa'] }} siswa dibawah KKM {{ $item['kkm'] }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                <div class="mt-3">
                    <button 
                        @click="open = false" 
                        type="button" 
                        class="text-sm font-medium text-yellow-800 hover:text-yellow-700"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if($totalSiswaDibawahKKM > 0)
    <div x-data="{ showDetails: false, open: true }" x-show="open" class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3 w-full">
                <h3 class="text-sm font-medium text-red-800">
                    Ada {{ $totalSiswaDibawahKKM }} siswa dengan nilai dibawah KKM
                </h3>
                <div class="mt-2 text-sm text-red-700">
                    <p>Sebaiknya lakukan remedi untuk siswa-siswa berikut:</p>
                    <button 
                        @click="showDetails = !showDetails"
                        class="mt-1 px-2 py-1 bg-red-100 text-red-800 text-xs rounded-md hover:bg-red-200 focus:outline-none"
                    >
                        <span x-text="showDetails ? 'Sembunyikan detail' : 'Lihat detail siswa'"></span>
                    </button>
                </div>

                <div x-show="showDetails" class="mt-3 max-h-60 overflow-y-auto text-sm">
                    <table class="min-w-full divide-y divide-red-200">
                        <thead class="bg-red-50">
                            <tr>
                                <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Nama Siswa
                                </th>
                                <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Mata Pelajaran
                                </th>
                                <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-red-700 uppercase tracking-wider">
                                    Nilai/KKM
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-red-100">
                            @foreach($siswaDibawahKKM as $siswaData)
                                @foreach($siswaData['mapel'] as $index => $mapelData)
                                    <tr>
                                        @if($index === 0)
                                        <td class="px-6 py-2 whitespace-nowrap" rowspan="{{ count($siswaData['mapel']) }}">
                                            {{ $siswaData['siswa']->nama }}
                                        </td>
                                        @endif
                                        <td class="px-6 py-2 whitespace-nowrap">
                                            {{ $mapelData['nama'] }}
                                        </td>
                                        <td class="px-6 py-2 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                {{ $mapelData['nilai'] }} / {{ $mapelData['kkm'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <button 
                        @click="open = false" 
                        type="button" 
                        class="text-sm font-medium text-red-800 hover:text-red-700"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Pencarian (dipindahkan ke sini) -->
    <div class="mb-6">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                </svg>
            </div>
            <input 
                type="text" 
                id="searchInput"
                class="block w-full p-4 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-green-500 focus:border-green-500"
                placeholder="Cari kelas atau mata pelajaran..."
                onkeyup="searchTable()"
            >
        </div>
    </div>

    <!-- Tabel Data Pembelajaran -->
    <div class="overflow-x-auto">
        <table id="pembelajaranTable" class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3">No</th>
                    <th scope="col" class="px-6 py-3">Kelas</th>
                    <th scope="col" class="px-6 py-3">Mata Pelajaran</th>
                    <th scope="col" class="px-6 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @if($kelasData->isEmpty())
                    <tr class="bg-white border-b">
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                            Tidak ada data pembelajaran yang tersedia
                        </td>
                    </tr>
                @else
                    @php $nomor = 1; @endphp <!-- Counter terpisah untuk nomor urut -->
                    @foreach($kelasData as $kelas)
                        @foreach($kelas->mataPelajarans as $mapel)
                            <tr class="bg-white border-b hover:bg-gray-50">
                                <td class="px-6 py-4">{{ $nomor++ }}</td> <!-- Increment counter di sini -->
                                <td class="px-6 py-4">Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</td>
                                <td class="px-6 py-4">{{ $mapel->nama_pelajaran }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2">
                                    @if($mapel->lingkupMateris->every(function($lm) { return $lm->tujuanPembelajarans->isNotEmpty(); }))
                                        @if(!$mapel->nilais()->exists())
                                        <a href="{{ route('pengajar.score.input_score', $mapel->id) }}"
                                        class="text-green-600 hover:text-green-800" title="Masukkan Nilai">
                                                <img src="{{ asset('images/icons/edit.png') }}" alt="Input Icon" class="w-5 h-5">
                                            </a>
                                        @else
                                        <a href="{{ route('pengajar.score.preview_score', $mapel->id) }}" 
                                        class="text-blue-600 hover:text-blue-800" title="Lihat atau Ubah Nilai">
                                            <img src="{{ asset('images/icons/detail.png') }}" alt="View Icon" class="w-5 h-5">
                                        </a>
                                        @endif
                                        @else
                                            <button type="button" 
                                                    class="text-yellow-600 hover:text-yellow-800"
                                                    onclick="alert('Harap isi Tujuan Pembelajaran untuk mata pelajaran ini terlebih dahulu.')" title="Anda belum menambah Tujuan Pembelajaran ke Mata Pelajaran Ini">
                                                <img src="{{ asset('images/icons/warning.png') }}" alt="Warning Icon" class="w-5 h-5">
                                            </button>
                                        @endif

                                            <form action="{{ route('pengajar.subject.destroy', $mapel->id) }}" 
                                                method="POST" 
                                                onsubmit="return confirm('Apakah Anda yakin ingin menghapus mata pelajaran ini?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800" title="Hapus Data">
                                                    <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endif
                </tbody>
        </table>
    </div>
</div>

@endsection
