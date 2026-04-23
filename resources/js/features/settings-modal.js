import Alpine from 'alpinejs';

export function registerSettingsModalFeatures() {
    Alpine.data('adminSettings', () => ({
        isOpen: false,
        activeTab: 'kkm',
        kelasData: [],
        kkmList: [],
        showAllKkm: false,
        kkmData: {
            mata_pelajaran_id: '',
            nilai: 70
        },
        globalKkmData: {
            nilai: 70,
            overwriteExisting: false
        },
        bobotData: {
            bobot_tp: 0.25,
            bobot_lm: 0.25,
            bobot_as: 0.50
        },
        kkmNotificationSettings: {
            completeScoresOnly: false
        },

        init() {
            this.fetchKelasData();
            this.fetchKkmList();
            this.fetchBobotData();
            this.initKkmNotificationSettings();
        },

        getFilteredKkmList() {
            if (!this.kkmData.mata_pelajaran_id) return [];

            return this.kkmList.filter(kkm =>
                kkm.mata_pelajaran_id === parseInt(this.kkmData.mata_pelajaran_id)
            );
        },

        get isTotalValid() {
            const total = parseFloat(this.bobotData.bobot_tp) +
                parseFloat(this.bobotData.bobot_lm) +
                parseFloat(this.bobotData.bobot_as);
            return Math.abs(total - 1) < 0.01;
        },

        open() {
            this.isOpen = true;
            this.fetchKelasData();
            this.fetchKkmList();
            this.fetchBobotData();
        },

        close() {
            this.isOpen = false;
            this.resetForms();
        },

        resetForms() {
            this.kkmData = {
                mata_pelajaran_id: '',
                nilai: 70
            };
            this.globalKkmData = {
                nilai: 70,
                overwriteExisting: false
            };
        },

        async initKkmNotificationSettings() {
            try {
                const response = await fetch('/admin/kkm/notification-settings');
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                if (data.success) {
                    this.kkmNotificationSettings = data.settings;
                }
            } catch (error) {
                console.error('Error fetching KKM notification settings:', error);
            }
        },

        async saveKkmNotificationSettings() {
            try {
                Swal.fire({
                    title: 'Menyimpan pengaturan...',
                    text: 'Mohon tunggu sebentar',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const response = await fetch('/admin/kkm/notification-settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.kkmNotificationSettings)
                });

                const data = await response.json();

                if (data.success) {
                    this.showAlert('success', 'Pengaturan notifikasi KKM berhasil disimpan');
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan pengaturan notifikasi');
                }
            } catch (error) {
                console.error('Error saving KKM notification settings:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan pengaturan notifikasi');
            }
        },

        async fetchKelasData() {
            try {
                const response = await fetch('/admin/kelas/data');
                const data = await response.json();
                this.kelasData = data.kelas;
            } catch (error) {
                console.error('Error fetching kelas data:', error);
            }
        },

        async fetchKkmList() {
            try {
                const response = await fetch('/admin/kkm/list');
                const data = await response.json();
                this.kkmList = data.kkms;
            } catch (error) {
                console.error('Error fetching KKM list:', error);
            }
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

        async handleMapelChange() {
            const selectedMapelId = this.kkmData.mata_pelajaran_id;
            if (!selectedMapelId) return;

            const existingKkm = this.kkmList.find(kkm =>
                kkm.mata_pelajaran_id === parseInt(selectedMapelId)
            );

            if (existingKkm) {
                this.kkmData.nilai = existingKkm.nilai;
            } else {
                this.kkmData.nilai = 70;
            }
        },

        async deleteKkm(id) {
            if (!confirm('Apakah Anda yakin ingin menghapus KKM ini?')) return;

            try {
                const response = await fetch(`/admin/kkm/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    this.fetchKkmList();
                    this.showAlert('success', 'KKM berhasil dihapus');
                } else {
                    this.showAlert('error', data.message || 'Gagal menghapus KKM');
                }
            } catch (error) {
                console.error('Error deleting KKM:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menghapus KKM');
            }
        },

        async saveKkm() {
            if (!this.kkmData.mata_pelajaran_id) {
                this.showAlert('error', 'Pilih mata pelajaran terlebih dahulu');
                return;
            }

            try {
                const response = await fetch('/admin/kkm', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.kkmData)
                });

                const data = await response.json();

                if (data.success) {
                    this.fetchKkmList();
                    this.resetForms();
                    this.showAlert('success', 'KKM berhasil disimpan');
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan KKM');
                }
            } catch (error) {
                console.error('Error saving KKM:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan KKM');
            }
        },

        async applyGlobalKkm() {
            try {
                let confirmMessage = `Apakah Anda yakin ingin menerapkan nilai KKM ${this.globalKkmData.nilai} ke semua mata pelajaran?`;

                if (this.globalKkmData.overwriteExisting) {
                    confirmMessage += '<br/><br/><strong class="text-red-600">Perhatian!</strong> Tindakan ini akan menimpa nilai KKM yang sudah ada sebelumnya.';
                } else {
                    confirmMessage += '<br/><br/>Hanya mata pelajaran yang belum memiliki KKM yang akan diperbarui.';
                }

                const isConfirmed = await Swal.fire({
                    title: 'Konfirmasi Pengaturan KKM Massal',
                    html: confirmMessage,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Terapkan',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    return result.isConfirmed;
                });

                if (!isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Menerapkan KKM Massal...',
                    text: 'Mohon tunggu...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const response = await fetch('/admin/kkm/global', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.globalKkmData)
                });

                const data = await response.json();

                if (data.success) {
                    this.fetchKkmList();
                    this.showAlert('success', `KKM massal berhasil diterapkan. ${data.count} mata pelajaran diperbarui.`);
                } else {
                    this.showAlert('error', data.message || 'Gagal menerapkan KKM massal');
                }
            } catch (error) {
                console.error('Error applying global KKM:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menerapkan KKM massal');
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
            }).then((result) => {
                return result.isConfirmed;
            });

            if (!isConfirmed) {
                return;
            }

            try {
                Swal.fire({
                    title: 'Menyimpan Bobot Nilai...',
                    text: 'Mohon tunggu...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
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
                    this.showAlert('success', 'Bobot nilai berhasil disimpan dan akan diterapkan pada semua perhitungan nilai');
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan bobot nilai');
                }
            } catch (error) {
                console.error('Error saving bobot nilai:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan bobot nilai');
            }
        },

        showAlert(type, message) {
            if (window.Swal) {
                Swal.fire({
                    icon: type,
                    title: type === 'success' ? 'Berhasil!' : 'Perhatian!',
                    text: message,
                    timer: 3000,
                    showConfirmButton: false
                });
            } else {
                alert(message);
            }
        }
    }));
}
