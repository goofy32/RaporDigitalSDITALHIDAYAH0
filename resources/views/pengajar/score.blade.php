@extends('layouts.pengajar.app')

@section('title', 'Data Pembelajaran')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')

<div data-page="pengajar-score" class="p-4 bg-white mt-14 rounded-lg">
    @php
        $readyPembelajarans = $kelasData->flatMap(function ($kelas) {
            return $kelas->mataPelajarans
                ->filter(fn ($mapel) => !$mapel->requires_lm_tp_setup)
                ->map(function ($mapel) use ($kelas) {
                    return [
                        'id' => $mapel->id,
                        'label' => 'Kelas '.$kelas->nomor_kelas.' '.$kelas->nama_kelas.' - '.$mapel->nama_pelajaran,
                        'url' => route('pengajar.score.import_template', $mapel->id),
                    ];
                });
        })->values();
    @endphp

    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-green-700 mb-4">Data Pembelajaran</h2>
    </div>

    <!-- Action Buttons -->
    <div x-data="{ openTemplateModal: false, selectedTemplateUrl: @js($readyPembelajarans->first()['url'] ?? '') }" class="mb-6">
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap gap-2">
                @if($readyPembelajarans->isNotEmpty())
                    <button type="button"
                            @click="openTemplateModal = true"
                            class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                        Download Template Nilai
                    </button>
                @else
                    <button type="button"
                            disabled
                            class="text-white bg-gray-400 font-medium rounded-lg text-sm px-4 py-2 cursor-not-allowed">
                        Download Template Nilai
                    </button>
                @endif
            </div>

            @if($readyPembelajarans->isEmpty())
                <div class="inline-flex max-w-xl items-start gap-2 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                    <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-yellow-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <span>Template nilai belum bisa diunduh karena belum ada pembelajaran yang lengkap. Pastikan setiap Lingkup Materi memiliki Tujuan Pembelajaran.</span>
                </div>
            @endif
        </div>

        @if($readyPembelajarans->isNotEmpty())
            <div x-show="openTemplateModal"
                 x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
                 @keydown.escape.window="openTemplateModal = false">
                <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-lg" @click.outside="openTemplateModal = false">
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-green-700">Download Template Nilai</h3>
                        <p class="mt-1 text-sm text-gray-600">Pilih kelas dan mata pelajaran untuk template nilai Excel.</p>
                    </div>

                    <label for="template_pembelajaran_url" class="mb-2 block text-sm font-medium text-gray-700">Pembelajaran</label>
                    <select id="template_pembelajaran_url"
                            x-model="selectedTemplateUrl"
                            class="mb-5 block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500">
                        @foreach($readyPembelajarans as $pembelajaran)
                            <option value="{{ $pembelajaran['url'] }}">{{ $pembelajaran['label'] }}</option>
                        @endforeach
                    </select>

                    <div class="flex justify-end gap-2">
                        <button type="button"
                                @click="openTemplateModal = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Batal
                        </button>
                        <button type="button"
                                @click="if (selectedTemplateUrl) window.location.href = selectedTemplateUrl"
                                class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                            Download
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Debug information -->
    @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('error') }}</span>
        </div>
    @endif

    @php
    $mapelDenganNilaiRendah = [];
    $siswaDibawahKKM = [];

    // Pengaturan completeScoresOnly diabaikan.
    // Notifikasi KKM selalu menampilkan semua nilai di bawah KKM.

    foreach($kelasData as $kelas) {
        foreach($kelas->mataPelajarans as $mapel) {
            // Ambil KKM untuk mata pelajaran ini
            $kkm = \App\Models\Kkm::where('mata_pelajaran_id', $mapel->id)
                ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
                ->first();
            
            $kkmValue = $kkm ? $kkm->nilai : 70; // Default ke 70 jika tidak ada
            
            // Build query for students with scores below KKM
            $query = \App\Models\Nilai::where('mata_pelajaran_id', $mapel->id)
                ->where('is_submitted', true)
                ->where('nilai_akhir_rapor', '<', $kkmValue);
                
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
                    ->where('is_submitted', true)
                    ->where('nilai_akhir_rapor', '<', $kkmValue);
                    
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
                            @php
                                $readinessMessages = $mapel->readiness_messages ?: [$mapel->lm_tp_warning_message];
                                $readinessMessages = collect($readinessMessages)->filter()->values()->all();
                                $readinessTitle = implode(' ', $readinessMessages);
                            @endphp
                            <tr class="bg-white border-b hover:bg-gray-50">
                                <td class="px-6 py-4">{{ $nomor++ }}</td> <!-- Increment counter di sini -->
                                <td class="px-6 py-4">Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</td>
                                <td class="px-6 py-4">{{ $mapel->nama_pelajaran }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2">
                                    @if(!$mapel->requires_lm_tp_setup)
                                        @if(!$mapel->has_saved_scores)
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
                                                    x-data="{ mapelName: @js($mapel->nama_pelajaran), readinessMessages: @js($readinessMessages) }"
                                                    @click.prevent="showLmTpWarning(mapelName, readinessMessages)"
                                                    class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-sm text-gray-400 opacity-80 transition hover:text-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-300 focus:ring-offset-2"
                                                    title="{{ $readinessTitle }}"
                                                    data-readiness-warning="true"
                                                    aria-label="{{ $readinessTitle }} Buka menu Data Mata Pelajaran, lalu klik ikon TP pada mata pelajaran ini untuk menambahkan Tujuan Pembelajaran.">
                                                <img src="{{ asset('images/icons/warning.png') }}" alt="Detail pembelajaran belum lengkap" class="w-5 h-5">
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

@push('scripts')
<script>
    function escapeWarningHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function showLmTpWarning(namaPelajaran, readinessMessages = []) {
        const messages = Array.isArray(readinessMessages) && readinessMessages.length > 0
            ? readinessMessages
            : ['Belum lengkap: Lingkup Materi dan Tujuan Pembelajaran belum lengkap.'];
        const reasonList = messages
            .map((message) => '<li>' + escapeWarningHtml(message) + '</li>')
            .join('');

        window.Swal.fire({
            title: 'Pembelajaran Belum Lengkap',
            html: '<div class="text-left">' +
                '<p class="mb-3">Mata pelajaran <strong>' + escapeWarningHtml(namaPelajaran) + '</strong> belum siap untuk input nilai atau unduh template.</p>' +
                '<ul class="mb-3 list-disc space-y-1 pl-5">' + reasonList + '</ul>' +
                '<p>Buka menu <strong>Data Mata Pelajaran</strong>, lalu klik ikon <strong>TP</strong> pada mata pelajaran ini untuk menambahkan Tujuan Pembelajaran.</p>' +
                '</div>',
            icon: 'warning',
            confirmButtonText: 'Mengerti',
            confirmButtonColor: '#3F7858'
        });
    }
</script>
@endpush
