import Alpine from 'alpinejs';

export function registerTopbarFeatures() {
    Alpine.data('tahunAjaranSelector', () => ({
        isOpen: false,

        toggleDropdown() {
            this.isOpen = !this.isOpen;
        },

        changeTahunAjaran(id, tahunAjaranText, isActive) {
            const activeTahunAjaran = document.querySelector('meta[name="active-tahun-ajaran"]')?.content || '';

            if (!isActive) {
                Swal.fire({
                    title: 'Perhatian!',
                    html: `Anda akan melihat data untuk tahun ajaran <strong>${tahunAjaranText}</strong>, sedangkan tahun ajaran aktif adalah <strong>${activeTahunAjaran}</strong>.<br><br>Data baru tetap akan disimpan di tahun ajaran aktif.`,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonText: 'Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#3F7858'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `/admin/set-tahun-ajaran/${id}`;
                    }
                });
            } else {
                window.location.href = `/admin/set-tahun-ajaran/${id}`;
            }
        }
    }));
}
