import {
    bindAcademicYearValidation,
    bindDateRangeValidation,
    validateAcademicYearFormat,
    validateDateRange,
} from '../features/tahun-ajaran-form';

async function checkSemesterGenapOnLoad(pageRoot) {
    try {
        var response = await fetch(pageRoot.dataset.checkSemesterUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });

        if (!response.ok) return;

        var data = await response.json();
        if (!data.hasSemseterGenap) return;

        Swal.fire({
            title: 'Ditemukan Tahun Ajaran Semester Genap!',
            html: `
                <div class="text-left">
                    <p class="mb-3">Sistem menemukan tahun ajaran <strong>${data.tahunAjaran}</strong> semester genap.</p>
                    <p class="mb-3">Disarankan menggunakan fitur <strong>"Copy Tahun Ajaran"</strong> untuk:</p>
                    <ul class="list-disc ml-5 mb-3">
                        <li>Melanjutkan struktur kelas yang sama</li>
                        <li>Mempertahankan assignment guru</li>
                        <li>Menyalin pengaturan yang sudah ada</li>
                    </ul>
                    <p class="text-sm text-gray-600">Anda tetap bisa membuat tahun ajaran baru jika diperlukan.</p>
                </div>
            `,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Gunakan Copy Tahun Ajaran',
            cancelButtonText: 'Tetap Buat Baru',
            confirmButtonColor: '#059669',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
            allowOutsideClick: false,
            width: '600px',
        }).then(result => {
            if (result.isConfirmed) {
                window.location.href = data.copyUrl;
            }
        });
    } catch (error) {
        console.error('Error checking semester genap:', error);
    }
}

export function initTahunAjaranCreatePage() {
    var pageRoot = document.querySelector('[data-page="tahun-ajaran-create"]');
    if (!pageRoot) return;

    var form = document.getElementById('createTahunAjaranForm');
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

    checkSemesterGenapOnLoad(pageRoot);
}
