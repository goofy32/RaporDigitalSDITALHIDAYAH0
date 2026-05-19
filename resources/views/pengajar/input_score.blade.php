@extends('layouts.pengajar.app')

@section('title', 'Input Nilai Siswa')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<style>
    /* Remove spinner buttons from number inputs */
    input[type="number"] {
        -moz-appearance: textfield;
    }
    
    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
</style>

<div data-page="pengajar-input-score" class="p-4 mt-16 bg-white shadow-md rounded-lg">
    @php
        $completionCount = collect($students)->filter(function ($student) use ($existingScores) {
            return !empty($existingScores[$student['id']]['is_submitted']);
        })->count();
    @endphp

    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700 flex items-center gap-2">
            <span>{{ $subject['class'] }} - {{ $mataPelajaran->nama_pelajaran }}</span>
        </h2>

        <div class="flex flex-col items-end gap-2">
            <div id="completion-counter"
                 data-total-students="{{ count($students) }}"
                 class="text-sm text-gray-600">
                {{ $completionCount }} dari {{ count($students) }} siswa lengkap
            </div>
            <div class="flex gap-4">
                <button type="button"
                        x-data
                        @click="window.handleKembali()"
                        class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50">
                    Kembali
                </button>
                <button type="button" 
                        x-data
                        @click="window.saveData()"
                        x-bind:disabled="$store.formProtection.isSubmitting || {{ count($students) == 0 ? 'true' : 'false' }}"
                        class="bg-green-700 text-white px-4 py-2 rounded-lg hover:bg-green-800 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span x-text="$store.formProtection.isSubmitting ? 'Menyimpan...' : 'Simpan'"></span>
                </button>
            </div>
        </div>
    </div>

    <div class="flex justify-between items-center mb-4">
        <div class="bg-white rounded-lg p-4 shadow border border-gray-200">
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Informasi Penilaian</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600 mb-1">KKM: <span class="font-semibold">{{ $kkmValue }}</span></p>
                    <p class="text-sm text-gray-500">Nilai minimum untuk lulus mata pelajaran ini</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Bobot Nilai:</p>
                    <ul class="text-sm text-gray-500 list-disc list-inside ml-2">
                        <li>Sumatif TP: {{ $bobotNilai->bobot_tp }} ({{ $bobotNilai->getTpPercentage() }}%)</li>
                        <li>Sumatif LM: {{ $bobotNilai->bobot_lm }} ({{ $bobotNilai->getLmPercentage() }}%)</li>
                        <li>Sumatif Akhir Semester: {{ $bobotNilai->bobot_as }} ({{ $bobotNilai->getAsPercentage() }}%)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <form id="saveForm" method="POST" action="{{ route('pengajar.score.save_scores', $subject['id']) }}" data-delete-nilai-url="{{ route('pengajar.score.nilai.delete') }}" x-data="formProtection" >
        @csrf

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">
        <input type="hidden" name="mata_pelajaran_id" value="{{ $mataPelajaran->id }}">

        <p class="mb-2 text-xs italic text-gray-400">
            Geser tabel ke kanan untuk melihat semua kolom nilai
        </p>

        <div class="overflow-x-auto">
            <table id="students-table" class="min-w-full text-sm text-left text-gray-500 border-collapse">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th rowspan="2" class="px-4 py-2 border">No</th>
                        <th rowspan="2" class="px-4 py-2 border">Nama Siswa</th>
                        <th colspan="{{ $mataPelajaran->lingkupMateris->sum(function($lm) { 
                            return $lm->tujuanPembelajarans->count(); 
                        }) }}" class="px-4 py-2 border text-center">
                            Sumatif Tujuan Pembelajaran
                        </th>
                        <th colspan="{{ $mataPelajaran->lingkupMateris->count() }}" 
                            class="px-4 py-2 border text-center">Sumatif Lingkup Materi</th>
                        <th rowspan="2" class="px-4 py-2 border">NA Sumatif TP</th>
                        <th rowspan="2" class="px-4 py-2 border">NA Sumatif LM</th>
                        <th colspan="2" class="px-4 py-2 border text-center">Sumatif Akhir Semester</th>
                        <th rowspan="2" class="px-4 py-2 border">NA Sumatif Akhir Semester</th>
                        <th rowspan="2" class="px-4 py-2 border">Nilai Akhir (Rapor)</th>
                        <th rowspan="2" class="px-4 py-2 border text-center">Status</th>
                        <th rowspan="2" class="px-4 py-2 border">Aksi</th>
                    </tr>
                    <tr>
                        @foreach($mataPelajaran->lingkupMateris as $lm)
                            @foreach($lm->tujuanPembelajarans as $tp)
                                <th class="px-4 py-2 border">TP {{ $tp->kode_tp }}</th>
                            @endforeach
                        @endforeach
                        @foreach($mataPelajaran->lingkupMateris as $lm)
                            <th class="px-4 py-2 border">{{ $lm->judul_lingkup_materi }}</th>
                        @endforeach
                        <th class="px-4 py-2 border">Nilai Tes</th>
                        <th class="px-4 py-2 border">Nilai Non-Tes</th>
                    </tr>
                </thead>

                @php
                $siswas = $mataPelajaran->kelas->siswas()->orderBy('nama', 'asc')->get();
                @endphp

                <tbody>
                    @foreach($students as $index => $student)
                        @php
                            $isSubmitted = (bool) ($existingScores[$student['id']]['is_submitted'] ?? false);
                        @endphp
                        <tr class="student-score-row bg-white hover:bg-gray-50 {{ $isSubmitted ? 'bg-green-50/60' : '' }}"
                            data-student-id="{{ $student['id'] }}">
                            <td class="px-4 py-2 border">{{ $index + 1 }}</td>
                            <td class="px-4 py-2 border student-name">{{ $student['name'] }}</td>
                            
                            <!-- Nilai TP -->
                            @foreach($mataPelajaran->lingkupMateris as $lm)
                                @foreach($lm->tujuanPembelajarans as $tp)
                                    <td class="px-4 py-2 border">
                                        <input type="number" 
                                               name="scores[{{ $student['id'] }}][tp][{{ $lm->id }}][{{ $tp->id }}]"
                                               class="w-20 border border-gray-300 rounded px-2 py-1 tp-score"
                                               data-lm="{{ $lm->id }}"
                                               value="{{ $existingScores[$student['id']]['tp'][$lm->id][$tp->id] ?? '' }}"
                                               min="0"
                                               max="100">
                                    </td>
                                @endforeach
                            @endforeach
                            

                            <!-- Nilai LM -->
                            @foreach($mataPelajaran->lingkupMateris as $lm)
                                <td class="px-4 py-2 border">
                                    <input type="number" 
                                           name="scores[{{ $student['id'] }}][lm][{{ $lm->id }}]"
                                           class="w-20 border border-gray-300 rounded px-2 py-1 lm-score"
                                           value="{{ $existingScores[$student['id']]['lm'][$lm->id] ?? '' }}"
                                           min="0"
                                           max="100">
                                </td>
                            @endforeach
                            
                            <!-- NA TP -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][na_tp]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 na-tp"
                                       value="{{ $existingScores[$student['id']]['na_tp'] ?? '' }}"
                                       min="0"
                                       max="100"
                                       readonly>
                            </td>
                            
                            <!-- NA LM -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][na_lm]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 na-lm"
                                       value="{{ $existingScores[$student['id']]['na_lm'] ?? '' }}"
                                       min="0"
                                       max="100"
                                       readonly>
                            </td>
                            
                            <!-- Nilai Tes -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][nilai_tes]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-semester nilai-tes"
                                       value="{{ $existingScores[$student['id']]['nilai_tes'] ?? '' }}"
                                       min="0"
                                       max="100">
                            </td>
                            
                            <!-- Nilai Non-Tes -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][nilai_non_tes]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-semester nilai-non-tes"
                                       value="{{ $existingScores[$student['id']]['nilai_non_tes'] ?? '' }}"
                                       min="0"
                                       max="100">
                            </td>
                            
                            <!-- NA Sumatif Akhir Semester -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][nilai_akhir]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-akhir"
                                       value="{{ $existingScores[$student['id']]['nilai_akhir_semester'] ?? '' }}"
                                       min="0"
                                       max="100"
                                       readonly>
                            </td>
                            
                            <!-- Nilai Akhir Rapor -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][nilai_akhir_rapor]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-akhir-rapor"
                                       value="{{ $existingScores[$student['id']]['nilai_akhir_rapor'] ?? '' }}"
                                       readonly>
                            </td>

                            <td class="px-4 py-2 border text-center">
                                <span
                                    class="completion-status inline-flex items-center justify-center rounded-full px-2 py-1 text-xs font-medium {{ $isSubmitted ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}"
                                    data-siswa-id="{{ $student['id'] }}">
                                    @if($isSubmitted)
                                        &#10003; Lengkap
                                    @else
                                        Belum Lengkap
                                    @endif
                                </span>
                            </td>
                            
                            <!-- Aksi -->
                            <td class="px-4 py-2 border">
                                <button type="button" 
                                        class="text-red-600 hover:text-red-800"
                                        onclick="deleteNilai({{ $student['id'] }}, {{ $subject['id'] }})">
                                    <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    @if(count($students) == 0)
                        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4">
                            <p class="font-bold">Perhatian!</p>
                            <p>Belum ada murid yang terdaftar di kelas ini. Silahkan tambahkan murid terlebih dahulu.</p>
                        </div>
                    @endif
                </tbody>
            </table>
        </div>
    </form>
</div>


<script>
    window.kkmValue = {{ json_encode($kkmValue ?? 70) }};
    window.bobotNilai = @json($bobotNilai ?? ['bobot_tp' => 1, 'bobot_lm' => 1, 'bobot_as' => 2]);
</script>
@endsection

