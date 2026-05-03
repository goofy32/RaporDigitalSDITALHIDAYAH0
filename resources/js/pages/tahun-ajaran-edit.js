import {
    bindAcademicYearValidation,
    bindDateRangeValidation,
    validateAcademicYearFormat,
    validateDateRange,
} from '../features/tahun-ajaran-form';

export function initTahunAjaranEditPage() {
    var pageRoot = document.querySelector('[data-page="tahun-ajaran-edit"]');
    if (!pageRoot) return;

    var form = document.getElementById('formEditTahunAjaran');
    var tahunAjaranInput = document.getElementById('tahun_ajaran');
    var tanggalMulaiInput = document.getElementById('tanggal_mulai');
    var tanggalSelesaiInput = document.getElementById('tanggal_selesai');

    if (!form || form.dataset.tahunAjaranBound === 'true') return;

    form.dataset.tahunAjaranBound = 'true';
    bindAcademicYearValidation(tahunAjaranInput);
    bindDateRangeValidation(tanggalMulaiInput, tanggalSelesaiInput);

    form.addEventListener('submit', function (event) {
        var isFormatValid = validateAcademicYearFormat(tahunAjaranInput);
        var isDateValid = validateDateRange(tanggalMulaiInput, tanggalSelesaiInput);

        if (!isFormatValid || !isDateValid || form.querySelectorAll('.tahun-ajaran-error, .tanggal-error').length > 0) {
            event.preventDefault();
            alert('Mohon perbaiki error pada form sebelum melanjutkan.');
        }
    });
}
