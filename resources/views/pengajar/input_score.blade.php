@extends('layouts.pengajar.app')

@section('title', 'Input Nilai Siswa')

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

<div class="p-4 mt-16 bg-white shadow-md rounded-lg">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700 flex items-center gap-2">
            <span>{{ $subject['class'] }} - {{ $mataPelajaran->nama_pelajaran }}</span>
        </h2>

        <div class="flex gap-4">
            <button type="button" 
                    x-data
                    @click="window.saveData()"
                    x-bind:disabled="$store.formProtection.isSubmitting || {{ count($students) == 0 ? 'true' : 'false' }}"
                    class="bg-green-700 text-white px-4 py-2 rounded-lg hover:bg-green-800 disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-text="$store.formProtection.isSubmitting ? 'Menyimpan...' : 'Simpan'"></span>
            </button>
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
                        <li>Sumatif TP: {{ number_format($bobotNilai->bobot_tp * 100, 0) }}%</li>
                        <li>Sumatif LM: {{ number_format($bobotNilai->bobot_lm * 100, 0) }}%</li>
                        <li>Sumatif Akhir Semester: {{ number_format($bobotNilai->bobot_as * 100, 0) }}%</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <form id="saveForm" method="POST" action="{{ route('pengajar.score.save_scores', $subject['id']) }}" x-data="formProtection" >
        @csrf

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">
        <input type="hidden" name="mata_pelajaran_id" value="{{ $mataPelajaran->id }}">

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
                        <tr class="hover:bg-gray-50">
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
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-semester"
                                       value="{{ $existingScores[$student['id']]['nilai_tes'] ?? '' }}"
                                       min="0"
                                       max="100">
                            </td>
                            
                            <!-- Nilai Non-Tes -->
                            <td class="px-4 py-2 border">
                                <input type="number" 
                                       name="scores[{{ $student['id'] }}][nilai_non_tes]"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 nilai-semester"
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
// Single source of truth for form change state
let formChanged = false;

// Initialize form change tracking - only attach once
document.addEventListener('DOMContentLoaded', function() {
    // Remove any existing event listeners first to avoid duplicates
    document.querySelectorAll('input').forEach(input => {
        input.removeEventListener('change', markFormChanged);
        input.removeEventListener('input', updateCalculations);
        
        // Add listeners
        input.addEventListener('change', markFormChanged);
        input.addEventListener('input', updateCalculations);
    });
    
    // Initialize calculations
    document.querySelectorAll('#students-table tbody tr').forEach(row => {
        // Don't recalculate existing final scores on page load
        // Just highlight values below KKM
        highlightBelowKkm(row);
        
        // Calculate intermediate values like NA_TP and NA_LM if they're empty
        calculateIntermediateValues(row);
    });
    
    // Only add these event listeners once
    setupNavigationListeners();
});

function markFormChanged() {
    // Set our local formChanged variable
    formChanged = true;
    
    // Try to access the Alpine store only if it exists
    try {
        if (window.Alpine && Alpine.store('formProtection')) {
            Alpine.store('formProtection').markAsChanged();
        }
    } catch (e) {
        console.warn('Could not access Alpine formProtection store', e);
    }
}

function updateCalculations(e) {
    // Tandai baris sebagai telah berubah untuk semua jenis input
    const row = e.target.closest('tr');
    if (row) {
        row.dataset.scoresChanged = 'true';
        
        calculateAverages(row);
        
        // Mark form as changed without relying on Alpine.js
        formChanged = true;
        
        // Try to use Alpine store if available
        try {
            if (window.Alpine && Alpine.store('formProtection')) {
                Alpine.store('formProtection').markAsChanged();
            }
        } catch (e) {
            console.warn('Could not access Alpine formProtection store', e);
        }
    }
}

// New function to only calculate intermediate values without affecting final scores
function calculateIntermediateValues(row) {
    // 1. Calculate NA Sumatif TP if empty
    let naTPInput = row.querySelector('.na-tp');
    if (!naTPInput.value) {
        let tpInputs = row.querySelectorAll('.tp-score');
        let tpSum = 0;
        let validTpCount = 0;

        tpInputs.forEach(input => {
            let value = parseFloat(input.value);
            if (!isNaN(value)) { // Dihapus kondisi "value > 0"
                tpSum += value;
                validTpCount++;
            }
        });

        if (validTpCount > 0) {
            let naTP = tpSum / validTpCount;
            naTPInput.value = naTP.toFixed(2);
        }
    }

    // 2. Calculate NA Sumatif LM if empty
    let naLMInput = row.querySelector('.na-lm');
    if (!naLMInput.value) {
        let lmInputs = row.querySelectorAll('.lm-score');
        let lmSum = 0;
        let validLmCount = 0;

        lmInputs.forEach(input => {
            let value = parseFloat(input.value);
            if (!isNaN(value)) { // Hapus kondisi && value > 0
                lmSum += value;
                validLmCount++;
            }
        });

        if (validLmCount > 0) {
            let naLM = lmSum / validLmCount;
            naLMInput.value = naLM.toFixed(2);
        }
    }

    // 3. Calculate NA Sumatif Akhir Semester if empty
    let nilaiAkhirInput = row.querySelector('input[name*="[nilai_akhir]"]');
    if (!nilaiAkhirInput.value) {
        let nilaiTes = parseFloat(row.querySelector('input[name*="[nilai_tes]"]').value) || 0;
        let nilaiNonTes = parseFloat(row.querySelector('input[name*="[nilai_non_tes]"]').value) || 0;

        let nilaiAkhirSemester = (nilaiTes * 0.6) + (nilaiNonTes * 0.4);
        nilaiAkhirInput.value = nilaiAkhirSemester.toFixed(2);
    }
}

function calculateAverages(row) {
    // 1. Hitung rata-rata Nilai TP
    let tpInputs = row.querySelectorAll('.tp-score');
    let tpSum = 0;
    let validTpCount = 0;

    tpInputs.forEach(input => {
        let value = parseFloat(input.value);
        // Only count values that are not empty and not NaN
        if (!isNaN(value) && input.value !== '') {
            tpSum += value;
            validTpCount++;
        }
    });

    if (validTpCount > 0) {
        let naTP = tpSum / validTpCount;
        row.querySelector('.na-tp').value = naTP.toFixed(2);
    } else {
        // If no valid inputs, clear the average
        row.querySelector('.na-tp').value = '';
    }

    // 2. Hitung rata-rata Nilai LM 
    let lmInputs = row.querySelectorAll('.lm-score');
    let lmSum = 0;
    let validLmCount = 0;

    lmInputs.forEach(input => {
        let value = parseFloat(input.value);
        if (!isNaN(value) && input.value !== '') {
            lmSum += value;
            validLmCount++;
        }
    });

    if (validLmCount > 0) {
        let naLM = lmSum / validLmCount;
        row.querySelector('.na-lm').value = naLM.toFixed(2);
    } else {
        // If no valid inputs, clear the average
        row.querySelector('.na-lm').value = '';
    }

    // 3. Hitung Nilai Akhir Semester
    let nilaiTesInput = row.querySelector('input[name*="[nilai_tes]"]');
    let nilaiNonTesInput = row.querySelector('input[name*="[nilai_non_tes]"]');
    let nilaiTes = nilaiTesInput.value !== '' ? parseFloat(nilaiTesInput.value) : null;
    let nilaiNonTes = nilaiNonTesInput.value !== '' ? parseFloat(nilaiNonTesInput.value) : null;
    
    let nilaiAkhirSemesterInput = row.querySelector('input[name*="[nilai_akhir]"]');
    
    // Calculate only if both test scores are available
    if (nilaiTes !== null && nilaiNonTes !== null) {
        let nilaiAkhirSemester = (nilaiTes * 0.6) + (nilaiNonTes * 0.4);
        nilaiAkhirSemesterInput.value = nilaiAkhirSemester.toFixed(2);
    } else {
        nilaiAkhirSemesterInput.value = '';
    }

    // 4. Hitung Nilai Akhir Rapor dengan bobot dinamis
    let naTPInput = row.querySelector('.na-tp');
    let naLMInput = row.querySelector('.na-lm');
    let naTP = naTPInput.value !== '' ? parseFloat(naTPInput.value) : null;
    let naLM = naLMInput.value !== '' ? parseFloat(naLMInput.value) : null;
    let nilaiAkhirSemester = nilaiAkhirSemesterInput.value !== '' ? parseFloat(nilaiAkhirSemesterInput.value) : null;

    // Ambil bobot dari variabel global
    let bobotTP = parseFloat(window.bobotNilai?.bobot_tp || 0.25);
    let bobotLM = parseFloat(window.bobotNilai?.bobot_lm || 0.25);
    let bobotAS = parseFloat(window.bobotNilai?.bobot_as || 0.50);

    let nilaiAkhirRaporInput = row.querySelector('input[name*="[nilai_akhir_rapor]"]');
    
    // Calculate final grade only if all components are available
    if (naTP !== null && naLM !== null && nilaiAkhirSemester !== null) {
        let nilaiAkhirRapor = (naTP * bobotTP) + (naLM * bobotLM) + (nilaiAkhirSemester * bobotAS);
        nilaiAkhirRaporInput.value = Math.round(nilaiAkhirRapor);
    } else {
        nilaiAkhirRaporInput.value = '';
    }
    
    // 5. Sorot nilai yang dibawah KKM
    highlightBelowKkm(row);
}

// Fungsi untuk highlight nilai di bawah KKM
function highlightBelowKkm(row) {
    const kkmValue = parseFloat(window.kkmValue || 70);
    
    // Nilai TP
    row.querySelectorAll('.tp-score').forEach(input => {
        const value = parseFloat(input.value);
        if (!isNaN(value) && value < kkmValue) { // Dihapus kondisi "value > 0"
            input.classList.add('bg-red-50', 'border-red-300', 'text-red-800');
        } else {
            input.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
        }
    });
    
    // Nilai LM
    row.querySelectorAll('.lm-score').forEach(input => {
        const value = parseFloat(input.value);
        if (!isNaN(value) && value < kkmValue) { // Hapus kondisi && value > 0
            input.classList.add('bg-red-50', 'border-red-300', 'text-red-800');
        } else {
            input.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
        }
    });

    
    // Nilai Tes dan Non-Tes
    row.querySelectorAll('input[name*="[nilai_tes]"], input[name*="[nilai_non_tes]"]').forEach(input => {
        const value = parseFloat(input.value);
        if (!isNaN(value) && value < kkmValue) { // Hapus kondisi && value > 0
            input.classList.add('bg-red-50', 'border-red-300', 'text-red-800');
        } else {
            input.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
        }
    });
    
    // NA TP, NA LM, dan Nilai Akhir Semester
    ['na-tp', 'na-lm'].forEach(className => {
        const input = row.querySelector(`.${className}`);
        if (input) {
            const value = parseFloat(input.value);
            if (!isNaN(value) && value < kkmValue) { // Hapus `value > 0`
                input.classList.add('bg-red-50', 'border-red-300', 'text-red-800');
            } else {
                input.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
            }
        }
    });
    
    // Nilai Akhir Semester
    const nilaiAkhirInput = row.querySelector('input[name*="[nilai_akhir]"]');
    if (nilaiAkhirInput) {
        const value = parseFloat(nilaiAkhirInput.value);
        if (!isNaN(value) && value < kkmValue) { // Hapus `value > 0`
            nilaiAkhirInput.classList.add('bg-red-50', 'border-red-300', 'text-red-800');
        } else {
            nilaiAkhirInput.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
        }
    }
}

function validateForm() {
    const form = document.getElementById('saveForm');
    const inputs = form.querySelectorAll('input[type="number"]:not([readonly])');
    let hasEmptyValues = false;

    inputs.forEach(input => {
        if (!input.value && !input.readOnly) {
            hasEmptyValues = true;
        }
    });

    if (hasEmptyValues) {
        return confirm('Beberapa nilai masih kosong. Apakah Anda yakin ingin melanjutkan?');
    }
    return true;
}

function setupNavigationListeners() {
    // Single event listener for beforeunload - handles browser close/refresh
    window.addEventListener('beforeunload', (e) => {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
            return e.returnValue;
        }
    });

    // Single event listener for Turbo navigation
    document.addEventListener('turbo:before-visit', (event) => {
        if (formChanged) {
            if (!confirm('Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?')) {
                event.preventDefault();
            } else {
                // User confirmed to leave, reset the flag
                formChanged = false;
            }
        }
    });
}

function deleteNilai(siswaId, mapelId) {
    Swal.fire({
        title: 'Hapus Nilai?',
        text: "Nilai yang dihapus tidak dapat dikembalikan!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            // Find the row for this student
            const row = document.querySelector(`input[name^="scores[${siswaId}]"]`).closest('tr');
            
            if (row) {
                // Clear all input values (set to empty, not zero)
                row.querySelectorAll('input[type="number"]').forEach(input => {
                    input.value = '';
                    
                    // Trigger change event manually to update calculations
                    const event = new Event('input', { bubbles: true });
                    input.dispatchEvent(event);
                });
                
                // Mark form as changed
                formChanged = true;
                
                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Nilai berhasil dihapus dari form. Klik "Simpan" untuk menyimpan perubahan.',
                    confirmButtonColor: '#10b981'
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: 'Tidak dapat menemukan data siswa yang dipilih.',
                    confirmButtonColor: '#d33'
                });
            }
        }
    });
}

window.saveData = async function() {
    try {
        // Always validate first
        if (!validateForm()) {
            return;
        }

        // Show loading indicator
        Swal.fire({
            title: 'Menyimpan Nilai...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Create a FormData object from the form
        const form = document.getElementById('saveForm');
        const formData = new FormData(form);
        
        // Make the AJAX request
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        // Parse the response
        const data = await response.json();
        
        // Handle success
        if (data.success) {
            // Reset changed flags
            formChanged = false;
            
            // Try to use Alpine store if available
            try {
                if (window.Alpine && Alpine.store('formProtection')) {
                    Alpine.store('formProtection').reset();
                }
            } catch (e) {
                console.warn('Could not access Alpine formProtection store', e);
            }
            
            // Reset all row change flags
            document.querySelectorAll('#students-table tbody tr').forEach(row => {
                row.dataset.scoresChanged = 'false';
            });
            
            // Get navigation URLs
            const currentUrl = window.location.href;
            const previewUrl = currentUrl.replace('/input', '/preview');
            const scoreIndexUrl = '/pengajar/score';
            
            // Show success message with options
            const result = await Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Nilai berhasil disimpan!',
                confirmButtonText: 'Lihat Preview',
                confirmButtonColor: '#10b981',
                showCancelButton: true,
                cancelButtonText: 'Ok',
                cancelButtonColor: '#6b7280',
                reverseButtons: true
            });
            
            // Handle user choice
            if (result.isConfirmed) {
                // User chose "Lihat Preview"
                window.location.href = previewUrl;
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                // User chose "Ok"
                window.location.href = scoreIndexUrl;
            }
        } else {
            throw new Error(data.message || 'Terjadi kesalahan saat menyimpan nilai');
        }
    } catch (error) {
        console.error('Error:', error);
        
        // Try to reset Alpine store's submitting flag if available
        try {
            if (window.Alpine && Alpine.store('formProtection')) {
                Alpine.store('formProtection').isSubmitting = false;
            }
        } catch (e) {
            console.warn('Could not access Alpine formProtection store', e);
        }
        
        // Show error message
        await Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: error.message || 'Terjadi kesalahan saat menyimpan nilai'
        });
    }
};

// Initialize highlighting when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    // Add scoresChanged data attribute to all rows
    document.querySelectorAll('#students-table tbody tr').forEach(row => {
        row.dataset.scoresChanged = 'false';
        
        // Highlight any values below KKM
        highlightBelowKkm(row);
    });
});

// Global variables for KKM value and bobot nilai
// Note: These will be filled by the blade template with the actual values
window.kkmValue = 70; // Default value, will be overridden by blade template
window.bobotNilai = {
    bobot_tp: 0.25, // Default value, will be overridden by blade template
    bobot_lm: 0.25, // Default value, will be overridden by blade template
    bobot_as: 0.50  // Default value, will be overridden by blade template
};
</script>
@endsection
