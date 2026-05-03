export function initTahunAjaranIndexPage() {
    var pageRoot = document.querySelector('[data-page="tahun-ajaran-index"]');
    if (!pageRoot) return;

    document.getElementById('disabledArchiveBtn')?.addEventListener('click', function () {
        Swal.fire({
            icon: 'info',
            title: 'Tidak Ada Arsip',
            text: 'Tidak ada tahun ajaran yang diarsipkan saat ini.',
            confirmButtonText: 'Mengerti',
        });
    });

    pageRoot.querySelectorAll('[data-activate-id]').forEach(input => {
        if (input.dataset.activateBound === 'true') return;

        input.addEventListener('click', function (event) {
            event.preventDefault();

            var id = this.dataset.activateId;
            var tahunAjaranName = this.dataset.tahunAjaranName;

            Swal.fire({
                title: 'Aktivasi Tahun Ajaran',
                html: `Apakah Anda yakin ingin mengaktifkan tahun ajaran <strong>${tahunAjaranName}</strong>?<br><br>Mengaktifkan tahun ajaran ini akan menonaktifkan tahun ajaran lain.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3F7858',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Aktifkan',
                cancelButtonText: 'Batal',
            }).then(result => {
                if (!result.isConfirmed) {
                    this.checked = false;
                    return;
                }

                var form = document.createElement('form');
                form.method = 'POST';
                form.action = `${pageRoot.dataset.setActiveBaseUrl}/${id}/set-active`;

                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
                form.appendChild(csrfInput);
                document.body.appendChild(form);

                if (window.Alpine?.store('pageLoading')) {
                    window.Alpine.store('pageLoading').startLoading();
                }

                form.submit();
            });
        });

        input.dataset.activateBound = 'true';
    });
}
