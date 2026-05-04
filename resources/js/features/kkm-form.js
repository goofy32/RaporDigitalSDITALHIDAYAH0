import Alpine from 'alpinejs';

export function registerKkmForm() {
    Alpine.data('kkmForm', () => ({
        kelasData: [],
        kkmItems: [],
        selectedKelasId: '',
        loadingRows: false,
        savingBatch: false,
        globalKkmData: {
            nilai: 70,
            overwriteExisting: false
        },

        init() {
            this.fetchKelasData();
        },

        getKelasDataUrl() {
            return this.$el?.dataset?.kelasDataUrl || '/admin/kelas/data';
        },

        getByKelasUrl(kelasId) {
            var template = this.$el?.dataset?.byKelasUrlTemplate || '/admin/kkm/by-kelas/__KELAS__';
            return template.replace('__KELAS__', kelasId);
        },

        getBatchSaveUrl() {
            return this.$el?.dataset?.batchSaveUrl || '/admin/kkm/batch-save';
        },

        getGlobalSaveUrl() {
            return this.$el?.dataset?.globalSaveUrl || '/admin/kkm/global';
        },

        getTahunAjaranId() {
            return this.$el?.dataset?.tahunAjaranId || '';
        },

        async fetchKelasData() {
            try {
                var response = await fetch(this.getKelasDataUrl(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                var data = await response.json();
                this.kelasData = data.kelas || [];
            } catch (error) {
                console.error('Error fetching kelas data:', error);
                this.showAlert('error', 'Gagal memuat data kelas.');
            }
        },

        async loadKkmByKelas() {
            if (!this.selectedKelasId) {
                this.kkmItems = [];
                return;
            }

            this.loadingRows = true;

            try {
                var response = await fetch(this.getByKelasUrl(this.selectedKelasId), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                var data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Gagal memuat data KKM.');
                }

                this.kkmItems = (data.items || []).map(item => ({
                    ...item,
                    pendingDelete: false
                }));
            } catch (error) {
                console.error('Error loading KKM by kelas:', error);
                this.kkmItems = [];
                this.showAlert('error', error.message || 'Gagal memuat data KKM.');
            } finally {
                this.loadingRows = false;
            }
        },

        toggleDelete(item) {
            item.pendingDelete = !item.pendingDelete;
        },

        async saveBatchKkm() {
            if (!this.selectedKelasId || this.kkmItems.length === 0) {
                this.showAlert('error', 'Pilih kelas terlebih dahulu.');
                return;
            }

            this.savingBatch = true;

            try {
                var response = await fetch(this.getBatchSaveUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        kelas_id: this.selectedKelasId,
                        tahun_ajaran_id: this.getTahunAjaranId(),
                        items: this.kkmItems.map(item => ({
                            mata_pelajaran_id: item.mata_pelajaran_id,
                            nilai: item.nilai,
                            delete: !!item.pendingDelete
                        }))
                    })
                });
                var data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Gagal menyimpan KKM.');
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: data.message || 'KKM berhasil disimpan',
                    timer: 2000,
                    showConfirmButton: false
                });

                await this.loadKkmByKelas();
            } catch (error) {
                console.error('Error saving KKM batch:', error);
                this.showAlert('error', error.message || 'Terjadi kesalahan saat menyimpan KKM.');
            } finally {
                this.savingBatch = false;
            }
        },

        async applyGlobalKkm() {
            try {
                var confirmMessage = `Apakah Anda yakin ingin menerapkan nilai KKM ${this.globalKkmData.nilai} ke semua mata pelajaran?`;
                confirmMessage += this.globalKkmData.overwriteExisting
                    ? '<br/><br/><strong class="text-red-600">Perhatian!</strong> Tindakan ini akan menimpa nilai KKM yang sudah ada sebelumnya.'
                    : '<br/><br/>Hanya mata pelajaran yang belum memiliki KKM yang akan diperbarui.';

                var isConfirmed = await Swal.fire({
                    title: 'Konfirmasi Pengaturan KKM Massal',
                    html: confirmMessage,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Terapkan',
                    cancelButtonText: 'Batal'
                }).then(result => result.isConfirmed);

                if (!isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Menerapkan KKM Massal...',
                    text: 'Mohon tunggu...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                var response = await fetch(this.getGlobalSaveUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.globalKkmData)
                });
                var data = await response.json();

                if (data.success) {
                    if (this.selectedKelasId) {
                        await this.loadKkmByKelas();
                    }

                    this.showAlert('success', `KKM massal berhasil diterapkan. ${data.count} mata pelajaran diperbarui.`);
                } else {
                    this.showAlert('error', data.message || 'Gagal menerapkan KKM massal');
                }
            } catch (error) {
                console.error('Error applying global KKM:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menerapkan KKM massal');
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
