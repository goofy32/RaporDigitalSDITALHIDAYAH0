import Alpine from 'alpinejs';

export function registerKkmForm() {
    Alpine.data('kkmForm', () => ({
        kelasData: [],
        kkmList: [],
        filteredKkmList: [],
        filteredMataPelajaran: [],
        selectedKelasId: '',
        filterKelasId: '',
        kkmData: {
            mata_pelajaran_id: '',
            nilai: 70
        },
        globalKkmData: {
            nilai: 70,
            overwriteExisting: false
        },
        kkmNotificationSettings: {
            completeScoresOnly: false
        },

        init() {
            this.fetchKelasData();
            this.fetchKkmList();
            this.initKkmNotificationSettings();
        },

        getSubjectRedirectUrl() {
            const subjectId = Number.parseInt(this.kkmData.mata_pelajaran_id, 10);

            if (Number.isInteger(subjectId) && subjectId > 0) {
                const baseUrl = this.$el?.dataset?.subjectUrlBase || this.$el?.dataset?.subjectIndexUrl || '/admin/subject';
                return `${baseUrl.replace(/\/$/, '')}/${subjectId}`;
            }

            const indexUrl = this.$el?.dataset?.subjectIndexUrl;

            if (indexUrl && !indexUrl.includes('undefined')) {
                return indexUrl;
            }

            return '/admin/subject';
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
                this.filteredKkmList = this.kkmList;
            } catch (error) {
                console.error('Error fetching KKM list:', error);
            }
        },

        loadMataPelajaranByKelas() {
            if (!this.selectedKelasId) {
                this.filteredMataPelajaran = [];
                return;
            }

            const selectedKelas = this.kelasData.find(kelas => kelas.id == this.selectedKelasId);
            this.filteredMataPelajaran = selectedKelas ? selectedKelas.mata_pelajarans : [];
            this.kkmData.mata_pelajaran_id = '';
            this.kkmData.nilai = 70;
        },

        filterKkmList() {
            if (!this.filterKelasId) {
                this.filteredKkmList = this.kkmList;
                return;
            }

            this.filteredKkmList = this.kkmList.filter(kkm =>
                kkm.mata_pelajaran &&
                kkm.mata_pelajaran.kelas &&
                kkm.mata_pelajaran.kelas.id == this.filterKelasId
            );
        },

        async initKkmNotificationSettings() {
            try {
                const response = await fetch('/admin/kkm/notification-settings');
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                if (data.success) this.kkmNotificationSettings = data.settings;
            } catch (error) {
                console.error('Error fetching KKM notification settings:', error);
            }
        },

        async handleMapelChange() {
            const selectedMapelId = this.kkmData.mata_pelajaran_id;
            if (!selectedMapelId) return;
            const existingKkm = this.kkmList.find(kkm => kkm.mata_pelajaran_id === parseInt(selectedMapelId));
            this.kkmData.nilai = existingKkm ? existingKkm.nilai : 70;
        },

        async addToTable() {
            if (!this.kkmData.mata_pelajaran_id) {
                this.showAlert('error', 'Pilih mata pelajaran terlebih dahulu');
                return;
            }

            await this.saveKkm({ redirectAfterSave: false });
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

        async saveKkm({ redirectAfterSave = false } = {}) {
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
                    await Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'KKM berhasil disimpan', timer: 2000, showConfirmButton: false });

                    if (redirectAfterSave) {
                        window.location.href = this.getSubjectRedirectUrl();
                        return;
                    }

                    await this.fetchKkmList();
                    this.resetForms();
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
                confirmMessage += this.globalKkmData.overwriteExisting
                    ? '<br/><br/><strong class="text-red-600">Perhatian!</strong> Tindakan ini akan menimpa nilai KKM yang sudah ada sebelumnya.'
                    : '<br/><br/>Hanya mata pelajaran yang belum memiliki KKM yang akan diperbarui.';

                const isConfirmed = await Swal.fire({
                    title: 'Konfirmasi Pengaturan KKM Massal',
                    html: confirmMessage,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Terapkan',
                    cancelButtonText: 'Batal'
                }).then((result) => result.isConfirmed);

                if (!isConfirmed) return;

                Swal.fire({ title: 'Menerapkan KKM Massal...', text: 'Mohon tunggu...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const response = await fetch('/admin/kkm/global', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
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

        async saveKkmNotificationSettings() {
            try {
                Swal.fire({ title: 'Menyimpan pengaturan...', text: 'Mohon tunggu sebentar', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const response = await fetch('/admin/kkm/notification-settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(this.kkmNotificationSettings)
                });
                const data = await response.json();
                if (data.success) this.showAlert('success', 'Pengaturan notifikasi KKM berhasil disimpan');
                else this.showAlert('error', data.message || 'Gagal menyimpan pengaturan notifikasi');
            } catch (error) {
                console.error('Error saving KKM notification settings:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan pengaturan notifikasi');
            }
        },

        resetForms() {
            this.kkmData = { mata_pelajaran_id: '', nilai: 70 };
            this.selectedKelasId = '';
            this.filteredMataPelajaran = [];
        },

        showAlert(type, message) {
            if (window.Swal) {
                Swal.fire({ icon: type, title: type === 'success' ? 'Berhasil!' : 'Perhatian!', text: message, timer: 3000, showConfirmButton: false });
            } else {
                alert(message);
            }
        }
    }));
}
