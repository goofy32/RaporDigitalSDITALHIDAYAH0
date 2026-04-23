import Alpine from 'alpinejs';

export function registerBobotNilaiForm() {
    Alpine.data('bobotNilaiForm', () => ({
        bobotData: {
            bobot_tp: 0.25,
            bobot_lm: 0.25,
            bobot_as: 0.50
        },

        get isTotalValid() {
            const total = parseFloat(this.bobotData.bobot_tp) +
                parseFloat(this.bobotData.bobot_lm) +
                parseFloat(this.bobotData.bobot_as);
            return Math.abs(total - 1) < 0.01;
        },

        init() {
            this.fetchBobotData();
        },

        getSubjectIndexUrl() {
            const url = this.$el?.dataset?.subjectIndexUrl;

            if (url && !url.includes('undefined')) {
                return url;
            }

            return '/admin/subject';
        },

        async fetchBobotData() {
            try {
                const response = await fetch('/admin/bobot-nilai/data');
                const data = await response.json();
                this.bobotData = {
                    bobot_tp: data.bobot_tp,
                    bobot_lm: data.bobot_lm,
                    bobot_as: data.bobot_as
                };
            } catch (error) {
                console.error('Error fetching bobot data:', error);
            }
        },

        async saveBobot() {
            if (!this.isTotalValid) {
                this.showAlert('error', 'Total bobot harus 100%');
                return;
            }

            const confirmMessage = `
            Perhatian! Perubahan bobot nilai akan mempengaruhi:
            1. Perhitungan nilai akhir rapor semua siswa
            2. Nilai yang sudah diinput sebelumnya akan dihitung ulang
            
            Apakah Anda yakin ingin menyimpan perubahan bobot nilai ini?
            `;

            const isConfirmed = await Swal.fire({
                title: 'Konfirmasi Perubahan Bobot Nilai',
                html: confirmMessage,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal'
            }).then((result) => result.isConfirmed);

            if (!isConfirmed) return;

            try {
                Swal.fire({
                    title: 'Menyimpan Bobot Nilai...',
                    text: 'Mohon tunggu...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const response = await fetch('/admin/bobot-nilai', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.bobotData)
                });

                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: 'Bobot nilai berhasil disimpan',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    window.location.href = this.getSubjectIndexUrl();
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan bobot nilai');
                }
            } catch (error) {
                console.error('Error saving bobot nilai:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan bobot nilai');
            }
        },

        showAlert(type, message) {
            if (typeof Swal === 'undefined') {
                alert(message);
                return;
            }

            Swal.fire({
                icon: type,
                title: type === 'success' ? 'Berhasil!' : 'Perhatian!',
                text: message,
                timer: 3000,
                showConfirmButton: false
            });
        }
    }));
}
