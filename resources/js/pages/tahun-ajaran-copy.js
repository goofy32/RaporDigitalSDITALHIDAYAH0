import {
    bindAcademicYearValidation,
    validateAcademicYearFormat,
} from '../features/tahun-ajaran-form';

export function initTahunAjaranCopyPage() {
    var pageRoot = document.querySelector('[data-page="tahun-ajaran-copy"]');
    if (!pageRoot) return;

    var form = document.getElementById('formCopyTahunAjaran');
    var submitButton = document.querySelector('button[form="formCopyTahunAjaran"][type="submit"]');
    var tahunAjaranInput = document.getElementById('tahun_ajaran');
    var isSubmitting = false;

    if (!form || !submitButton || form.dataset.tahunAjaranBound === 'true') return;

    form.dataset.tahunAjaranBound = 'true';
    bindAcademicYearValidation(tahunAjaranInput);

    form.addEventListener('submit', function (event) {
        if (isSubmitting) {
            event.preventDefault();
            return false;
        }

        if (!validateAcademicYearFormat(tahunAjaranInput)) {
            event.preventDefault();
            alert('Format tahun ajaran harus XXXX/XXXX, contoh: 2024/2025');
            tahunAjaranInput.focus();
            return false;
        }

        var confirmed = confirm(`Apakah Anda yakin ingin membuat tahun ajaran berikutnya?

Tindakan ini akan:
• Menyalin struktur kelas dengan nama dan guru yang sama
• Menyalin pengaturan yang dipilih dari tahun ajaran saat ini
• Memulai tahun ajaran baru dengan data nilai kosong

Siswa dapat diatur kenaikan kelasnya secara manual menggunakan fitur Kenaikan Kelas.

Proses ini tidak dapat dibatalkan setelah dimulai.`);

        if (!confirmed) {
            event.preventDefault();
            return false;
        }

        isSubmitting = true;
        submitButton.disabled = true;
        submitButton.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Membuat Tahun Ajaran...
        `;
        return true;
    });

    if (typeof initFlowbite === 'function') {
        initFlowbite();
    }

    pageRoot.querySelectorAll('input[disabled]').forEach(checkbox => {
        checkbox.parentElement.title = 'Pengaturan ini wajib untuk menjaga konsistensi struktur kelas';
    });
}
