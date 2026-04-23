export function initPengajarInputScorePage() {
    // Module imported by app.js; legacy globals below are intentionally page-scoped by DOM checks.
}

// Single source of truth for form change state
let formChanged = false;
let navigationListenersAttached = false;

function isInputScorePage() {
    return Boolean(document.getElementById('saveForm') && document.getElementById('students-table'));
}

function getFormProtectionStore() {
    try {
        return window.Alpine ? Alpine.store('formProtection') : null;
    } catch {
        return null;
    }
}

function hasUnsavedChanges() {
    return formChanged || Boolean(getFormProtectionStore()?.formChanged);
}

// Initialize form change tracking - only attach once
document.addEventListener('turbo:load', function() {
    if (!isInputScorePage()) return;

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
        setRawInputPlaceholders(row);

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
        } else {
            naTPInput.value = '0';
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
        } else {
            naLMInput.value = '0';
        }
    }

    // 3. Calculate NA Sumatif Akhir Semester if empty
    let nilaiAkhirInput = row.querySelector('input[name*="[nilai_akhir]"]');
    if (!nilaiAkhirInput.value) {
        let nilaiTesInput = row.querySelector('input[name*="[nilai_tes]"]');
        let nilaiNonTesInput = row.querySelector('input[name*="[nilai_non_tes]"]');
        let nilaiTes = parseFloat(nilaiTesInput.value) || 0;
        let nilaiNonTes = parseFloat(nilaiNonTesInput.value) || 0;

        let nilaiAkhirSemester = (nilaiTes * 0.6) + (nilaiNonTes * 0.4);
        nilaiAkhirInput.value = !nilaiTesInput.value && !nilaiNonTesInput.value
            ? '0'
            : nilaiAkhirSemester.toFixed(2);
    }

    // 4. Calculate Nilai Akhir Rapor if empty
    let nilaiAkhirRaporInput = row.querySelector('input[name*="[nilai_akhir_rapor]"]');
    if (!nilaiAkhirRaporInput.value) {
        let bobotTP = parseFloat(window.bobotNilai?.bobot_tp || 0.25);
        let bobotLM = parseFloat(window.bobotNilai?.bobot_lm || 0.25);
        let bobotAS = parseFloat(window.bobotNilai?.bobot_as || 0.50);
        let naTP = parseFloat(row.querySelector('.na-tp')?.value) || 0;
        let naLM = parseFloat(row.querySelector('.na-lm')?.value) || 0;
        let nilaiAkhirSemester = parseFloat(nilaiAkhirInput.value) || 0;

        nilaiAkhirRaporInput.value = Math.round((naTP * bobotTP) + (naLM * bobotLM) + (nilaiAkhirSemester * bobotAS));
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
        row.querySelector('.na-tp').value = '0';
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
        row.querySelector('.na-lm').value = '0';
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
        nilaiAkhirSemesterInput.value = '0';
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
        nilaiAkhirRaporInput.value = '0';
    }
    
    // 5. Sorot nilai yang dibawah KKM
    highlightBelowKkm(row);
}

function setRawInputPlaceholders(row) {
    row.querySelectorAll('.tp-score, .lm-score, input[name*="[nilai_tes]"], input[name*="[nilai_non_tes]"]').forEach(input => {
        input.placeholder = '-';
    });
}

function clearStudentScoreRow(row) {
    setRawInputPlaceholders(row);

    row.querySelectorAll('.tp-score, .lm-score, input[name*="[nilai_tes]"], input[name*="[nilai_non_tes]"]').forEach(input => {
        input.value = '';
        input.classList.remove('bg-red-50', 'border-red-300', 'text-red-800');
    });

    row.querySelectorAll('.na-tp, .na-lm, input[name*="[nilai_akhir]"], input[name*="[nilai_akhir_rapor]"]').forEach(input => {
        input.value = '0';
    });

    calculateAverages(row);
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
    if (navigationListenersAttached) return;
    window.addEventListener('beforeunload', handleBeforeUnload);
    document.addEventListener('turbo:before-visit', handleTurboBeforeVisit);
    document.addEventListener('turbo:before-cache', cleanupInputScorePage);
    navigationListenersAttached = true;
}

function handleBeforeUnload(event) {
    if (!isInputScorePage() || !hasUnsavedChanges()) return;
    event.preventDefault();
    event.returnValue = '';
    return event.returnValue;
}

function handleTurboBeforeVisit(event) {
    if (!isInputScorePage() || !hasUnsavedChanges()) return;
    if (!confirm('Perubahan belum disimpan. Yakin ingin keluar?')) {
        event.preventDefault();
        return;
    }
    formChanged = false;
    getFormProtectionStore()?.reset();
}

function cleanupInputScorePage() {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    document.removeEventListener('turbo:before-visit', handleTurboBeforeVisit);
    document.removeEventListener('turbo:before-cache', cleanupInputScorePage);
    navigationListenersAttached = false;
}

window.handleKembali = function() {
    if (!hasUnsavedChanges()) {
        window.history.back();
        return;
    }

    Swal.fire({
        title: 'Perubahan belum disimpan',
        text: 'Yakin ingin keluar? Nilai yang belum disimpan akan hilang.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Keluar tanpa simpan',
        cancelButtonText: 'Tetap di sini',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3F7858'
    }).then(result => {
        if (result.isConfirmed) {
            formChanged = false;
            getFormProtectionStore()?.reset();
            window.history.back();
        }
    });
};

window.deleteNilai = function(siswaId, mapelId) {
    Swal.fire({
        title: 'Hapus Nilai?',
        text: "Nilai yang dihapus tidak dapat dikembalikan!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then(async (result) => {
        if (result.isConfirmed) {
            // Find the row for this student
            const rowInput = document.querySelector(`input[name^="scores[${siswaId}]"]`);
            const row = rowInput?.closest('tr');
            
            if (row) {
                const form = document.getElementById('saveForm');
                const deleteUrl = form?.dataset.deleteNilaiUrl;

                if (!deleteUrl) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: 'URL hapus nilai tidak ditemukan.',
                        confirmButtonColor: '#d33'
                    });
                    return;
                }

                try {
                    Swal.fire({
                        title: 'Menghapus Nilai...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const response = await fetch(deleteUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({
                            siswa_id: siswaId,
                            mata_pelajaran_id: mapelId
                        })
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Gagal menghapus nilai.');
                    }

                    clearStudentScoreRow(row);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: data.message || 'Nilai berhasil dihapus.',
                        confirmButtonColor: '#10b981'
                    });
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: error.message || 'Gagal menghapus nilai.',
                        confirmButtonColor: '#d33'
                    });
                }
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
};

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
document.addEventListener('turbo:load', function() {
    if (!isInputScorePage()) return;

    // Add scoresChanged data attribute to all rows
    document.querySelectorAll('#students-table tbody tr').forEach(row => {
        row.dataset.scoresChanged = 'false';
        
        // Highlight any values below KKM
        highlightBelowKkm(row);
    });
});
