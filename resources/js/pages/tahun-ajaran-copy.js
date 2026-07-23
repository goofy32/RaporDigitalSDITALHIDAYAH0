import {
    bindAcademicYearValidation,
    bindDateRangeValidation,
    validateAcademicYearFormat,
    validateDateRange,
} from '../features/tahun-ajaran-form';

export function initTahunAjaranCopyPage() {
    var pageRoot = document.querySelector('[data-page="tahun-ajaran-copy"]');
    if (!pageRoot) return;

    var form = document.getElementById('formCopyTahunAjaran');
    var submitButton = document.querySelector('button[form="formCopyTahunAjaran"][type="submit"]');
    var tahunAjaranInput = document.getElementById('tahun_ajaran');
    var tanggalMulaiInput = document.getElementById('tanggal_mulai');
    var tanggalSelesaiInput = document.getElementById('tanggal_selesai');
    var confirmationInput = document.getElementById('transition_confirmation_next_year');
    var requiredConfirmation = pageRoot.dataset.confirmationPhrase || 'BUAT TAHUN AJARAN BERIKUTNYA';
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var forceDeleteUrlTemplate = pageRoot.dataset.forceDeleteUrlTemplate || '';
    var indexUrl = pageRoot.dataset.indexUrl || '';
    var isSubmitting = false;
    var defaultButtonHtml = submitButton ? submitButton.innerHTML : '';

    if (!form || !submitButton || form.dataset.tahunAjaranBound === 'true') return;

    form.dataset.tahunAjaranBound = 'true';
    bindAcademicYearValidation(tahunAjaranInput);
    bindDateRangeValidation(tanggalMulaiInput, tanggalSelesaiInput);

    function setSubmittingState(active) {
        isSubmitting = active;
        submitButton.disabled = active || !confirmationMatches();
        submitButton.innerHTML = active
            ? `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Membuat Tahun Ajaran...
            `
            : defaultButtonHtml;
    }

    function confirmationMatches() {
        return (confirmationInput?.value || '').trim() === requiredConfirmation;
    }

    function updateSubmitState() {
        if (!isSubmitting) {
            submitButton.disabled = !confirmationMatches();
        }
    }

    function getErrorMessage(data, fallbackMessage) {
        if (data && data.message) {
            return data.message;
        }

        if (data && data.errors) {
            var firstKey = Object.keys(data.errors)[0];
            if (firstKey && data.errors[firstKey] && data.errors[firstKey][0]) {
                return data.errors[firstKey][0];
            }
        }

        return fallbackMessage;
    }

    async function deleteArchivedRecord(archivedId) {
        var deleteUrl = forceDeleteUrlTemplate.replace('__ID__', String(archivedId));
        var response = await fetch(deleteUrl, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        var data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {};
        }

        if (!response.ok || !data.success) {
            throw new Error(getErrorMessage(data, 'Gagal menghapus data tahun ajaran dari arsip.'));
        }
    }

    async function submitCopyRequest() {
        var formData = new FormData(form);
        var response = await fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });

        var data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {};
        }

        if (response.ok && data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: data.message || 'Tahun ajaran berikutnya berhasil dibuat.',
                timer: 1600,
                showConfirmButton: false,
            });

            window.location.href = data.redirect || indexUrl || form.action;
            return;
        }

        if (response.status === 409 && data.conflict === 'archived') {
            var result = await Swal.fire({
                icon: 'warning',
                title: 'Tahun Ajaran Sudah Ada di Arsip',
                html: `Tahun ajaran <b>${tahunAjaranInput.value.trim()}</b> sudah ada di arsip. Hapus dari arsip terlebih dahulu untuk melanjutkan copy?`,
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Hapus dari Arsip & Lanjutkan',
                cancelButtonText: 'Batal',
            });

            if (result.isConfirmed && data.archived_id) {
                await deleteArchivedRecord(data.archived_id);
                await submitCopyRequest();
            }

            return;
        }

        if (response.status === 409 && data.conflict === 'active') {
            await Swal.fire({
                icon: 'error',
                title: 'Tahun Ajaran Sudah Ada',
                text: getErrorMessage(data, 'Tahun ajaran tersebut sudah ada.'),
            });
            return;
        }

        if (response.status === 422) {
            await Swal.fire({
                icon: 'error',
                title: 'Validasi Gagal',
                text: getErrorMessage(data, 'Mohon periksa kembali data yang diisikan.'),
            });
            return;
        }

        throw new Error(getErrorMessage(data, 'Gagal membuat tahun ajaran berikutnya.'));
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (isSubmitting) {
            return false;
        }

        if (!validateAcademicYearFormat(tahunAjaranInput)) {
            await Swal.fire({
                icon: 'error',
                title: 'Format Tidak Valid',
                text: 'Format tahun ajaran harus XXXX/XXXX, contoh: 2024/2025.',
            });
            tahunAjaranInput.focus();
            return false;
        }

        if (!validateDateRange(tanggalMulaiInput, tanggalSelesaiInput)) {
            await Swal.fire({
                icon: 'error',
                title: 'Tanggal Tidak Valid',
                text: 'Tanggal selesai harus setelah tanggal mulai.',
            });
            tanggalSelesaiInput.focus();
            return false;
        }

        if (!confirmationMatches()) {
            await Swal.fire({
                icon: 'error',
                title: 'Konfirmasi Tidak Sesuai',
                text: 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.',
            });
            confirmationInput?.focus();
            updateSubmitState();
            return false;
        }

        var confirmed = await Swal.fire({
            icon: 'question',
            title: 'Buat Tahun Ajaran Berikutnya?',
            html: `
                <div class="text-left space-y-2">
                    <p>Tahun ajaran berikutnya akan dibuat berdasarkan struktur yang tersedia saat ini.</p>
                    <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                        <li>Perubahan pada tahun ajaran sumber setelah proses ini tidak otomatis disalin ke target</li>
                        <li>Target dibuat belum aktif dan perlu diperiksa sebelum digunakan</li>
                        <li>Siswa ditempatkan melalui proses Kenaikan Kelas</li>
                    </ul>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'Ya, Buat',
            cancelButtonText: 'Batal',
        });

        if (!confirmed.isConfirmed) {
            return false;
        }

        setSubmittingState(true);

        try {
            await submitCopyRequest();
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'Gagal Membuat Tahun Ajaran',
                text: error.message || 'Terjadi kesalahan. Silakan coba lagi.',
            });
        } finally {
            setSubmittingState(false);
        }

        return false;
    });

    if (typeof window.ensureFlowbiteLoaded === 'function') {
        window.ensureFlowbiteLoaded();
    } else if (typeof window.initFlowbite === 'function') {
        window.initFlowbite();
    }

    confirmationInput?.addEventListener('input', updateSubmitState);
    updateSubmitState();
}
