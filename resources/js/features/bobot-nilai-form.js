import Alpine from 'alpinejs';

export function registerBobotNilaiForm() {
    Alpine.data('bobotNilaiForm', () => ({
        bobotData: {
            bobot_tp: 1,
            bobot_lm: 1,
            bobot_as: 2
        },

        get isTotalValid() {
            return this.bobotTpValue >= 1 && this.bobotLmValue >= 1 && this.bobotAsValue >= 1;
        },

        get bobotTpValue() {
            return this.normalizeBobotValue(this.bobotData.bobot_tp);
        },

        get bobotLmValue() {
            return this.normalizeBobotValue(this.bobotData.bobot_lm);
        },

        get bobotAsValue() {
            return this.normalizeBobotValue(this.bobotData.bobot_as);
        },

        get totalBobot() {
            return this.bobotTpValue + this.bobotLmValue + this.bobotAsValue;
        },

        get tpPercentage() {
            return this.totalBobot > 0 ? ((this.bobotTpValue / this.totalBobot) * 100).toFixed(1) : '0.0';
        },

        get lmPercentage() {
            return this.totalBobot > 0 ? ((this.bobotLmValue / this.totalBobot) * 100).toFixed(1) : '0.0';
        },

        get asPercentage() {
            return this.totalBobot > 0 ? ((this.bobotAsValue / this.totalBobot) * 100).toFixed(1) : '0.0';
        },

        get ratioLabel() {
            return `${this.bobotTpValue} : ${this.bobotLmValue} : ${this.bobotAsValue}`;
        },

        init() {
            this.fetchBobotData();
        },

        normalizeBobotValue(value) {
            const parsed = Number.parseInt(value, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
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
                    bobot_tp: this.normalizeBobotValue(data.bobot_tp) || 1,
                    bobot_lm: this.normalizeBobotValue(data.bobot_lm) || 1,
                    bobot_as: this.normalizeBobotValue(data.bobot_as) || 2
                };
            } catch (error) {
                console.error('Error fetching bobot data:', error);
            }
        },

        async saveBobot() {
            if (!this.isTotalValid) {
                this.showAlert('error', 'Semua bobot harus berupa bilangan bulat minimal 1');
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
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
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
                    body: JSON.stringify({
                        bobot_tp: this.bobotTpValue,
                        bobot_lm: this.bobotLmValue,
                        bobot_as: this.bobotAsValue
                    })
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
