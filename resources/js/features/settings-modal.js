import Alpine from 'alpinejs';

const SETTINGS_DATA_TTL_MS = 5 * 60 * 1000;
const settingsInstances = new Set();
let lifecycleListenersBound = false;

function ensureSettingsLifecycleListeners() {
    if (lifecycleListenersBound) {
        return;
    }

    lifecycleListenersBound = true;
    document.addEventListener('turbo:before-cache', () => {
        settingsInstances.forEach(instance => instance.prepareForCache?.());
    });
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

export function registerSettingsModalFeatures() {
    Alpine.data('adminSettings', () => ({
        isOpen: false,
        activeTab: 'kkm',
        initialized: false,
        destroyed: false,
        settingsLoading: false,
        settingsLoadError: false,
        settingsLoaded: false,
        settingsLoadedAt: null,
        settingsDataPromise: null,
        settingsAbortController: null,
        settingsLoadGeneration: 0,
        pagePath: window.location.pathname,
        kelasLoaded: false,
        kelasLoadError: false,
        kkmLoaded: false,
        kkmLoadError: false,
        bobotLoaded: false,
        bobotLoadError: false,
        bobotSaving: false,
        notificationSettingsLoaded: false,
        notificationSettingsLoadError: false,
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
            bobot_tp: 1,
            bobot_lm: 1,
            bobot_as: 2
        },
        kkmNotificationSettings: {
            completeScoresOnly: false
        },

        init() {
            if (this.initialized) {
                return;
            }

            this.initialized = true;
            ensureSettingsLifecycleListeners();
            settingsInstances.add(this);
        },

        getFilteredKkmList() {
            if (!this.kkmData.mata_pelajaran_id) return [];

            return this.kkmList.filter(kkm =>
                kkm.mata_pelajaran_id === parseInt(this.kkmData.mata_pelajaran_id)
            );
        },

        get isTotalValid() {
            return this.bobotTpValue >= 1 && this.bobotLmValue >= 1 && this.bobotAsValue >= 1;
        },

        get canSaveKkm() {
            return this.kelasLoaded
                && this.kkmLoaded
                && !this.settingsLoading
                && !this.kelasLoadError
                && !this.kkmLoadError
                && Boolean(this.kkmData.mata_pelajaran_id);
        },

        get canSaveBobot() {
            return this.bobotLoaded
                && !this.settingsLoading
                && !this.bobotLoadError
                && !this.bobotSaving
                && this.isTotalValid;
        },

        get canSaveKkmNotificationSettings() {
            return this.notificationSettingsLoaded
                && !this.settingsLoading
                && !this.notificationSettingsLoadError;
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

        normalizeBobotValue(value) {
            const parsed = Number.parseInt(value, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        },

        open() {
            this.isOpen = true;
            this.loadSettingsData();
        },

        close() {
            this.isOpen = false;
            this.resetForms();
        },

        destroy() {
            if (this.destroyed) {
                return;
            }

            this.destroyed = true;
            this.invalidateActiveSettingsLoad();
            settingsInstances.delete(this);
        },

        prepareForCache() {
            this.isOpen = false;
            this.settingsLoading = false;
            this.settingsLoadError = false;
            this.invalidateActiveSettingsLoad();
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

        isComponentCurrent() {
            return !this.destroyed
                && this.$el?.isConnected
                && document.body.contains(this.$el)
                && window.location.pathname === this.pagePath;
        },

        isCurrentSettingsRequest(sequence, controller) {
            return this.isComponentCurrent()
                && this.settingsLoadGeneration === sequence
                && this.settingsAbortController === controller
                && !controller.signal.aborted;
        },

        isSettingsDataFresh() {
            if (!this.settingsLoaded || !this.settingsLoadedAt) {
                return false;
            }

            return Date.now() - this.settingsLoadedAt < SETTINGS_DATA_TTL_MS;
        },

        markSettingsDataStale() {
            this.invalidateActiveSettingsLoad();
            this.settingsLoaded = false;
            this.settingsLoadedAt = null;
        },

        resetEndpointReadinessForLoad() {
            this.kelasLoaded = false;
            this.kelasLoadError = false;
            this.kkmLoaded = false;
            this.kkmLoadError = false;
            this.bobotLoaded = false;
            this.bobotLoadError = false;
            this.notificationSettingsLoaded = false;
            this.notificationSettingsLoadError = false;
        },

        async refreshKkmListAfterMutation() {
            this.markSettingsDataStale();

            try {
                await this.fetchKkmList();
            } catch (error) {
                if (!isAbortError(error)) {
                    this.settingsLoadError = true;
                }
            }
        },

        invalidateActiveSettingsLoad() {
            this.settingsLoadGeneration += 1;
            this.settingsAbortController?.abort();
            this.settingsAbortController = null;
            this.settingsDataPromise = null;
            this.settingsLoading = false;
        },

        async requestJson(url, { signal } = {}) {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return response.json();
        },

        async loadSettingsData({ force = false } = {}) {
            if (force) {
                this.invalidateActiveSettingsLoad();
            }

            if (!force && this.isSettingsDataFresh()) {
                return Promise.resolve(true);
            }

            if (this.settingsDataPromise) {
                return this.settingsDataPromise;
            }

            const controller = new AbortController();
            const sequence = this.settingsLoadGeneration + 1;
            this.settingsLoadGeneration = sequence;
            this.settingsAbortController = controller;
            this.settingsLoading = true;
            this.settingsLoadError = false;
            this.settingsLoaded = false;
            this.settingsLoadedAt = null;
            this.resetEndpointReadinessForLoad();

            this.settingsDataPromise = Promise.allSettled([
                this.fetchKelasData({ sequence, controller }),
                this.fetchKkmList({ sequence, controller }),
                this.fetchBobotData({ sequence, controller }),
                this.initKkmNotificationSettings({ sequence, controller }),
            ]).then(results => {
                if (!this.isCurrentSettingsRequest(sequence, controller)) {
                    return false;
                }

                const hasFailure = results.some(result => result.status === 'rejected');

                results.forEach(result => {
                    if (result.status === 'rejected' && !isAbortError(result.reason)) {
                        console.error('Error loading admin settings data:', result.reason);
                    }
                });

                this.settingsLoaded = !hasFailure;
                this.settingsLoadedAt = hasFailure ? null : Date.now();
                this.settingsLoadError = hasFailure;

                return !hasFailure;
            }).finally(() => {
                if (this.isCurrentSettingsRequest(sequence, controller)) {
                    this.settingsLoading = false;
                    this.settingsAbortController = null;
                    this.settingsDataPromise = null;
                }
            });

            return this.settingsDataPromise;
        },

        shouldApplySettingsResponse(options = {}) {
            if (!options.sequence || !options.controller) {
                return this.isComponentCurrent();
            }

            return this.isCurrentSettingsRequest(options.sequence, options.controller);
        },

        async initKkmNotificationSettings(options = {}) {
            try {
                const data = await this.requestJson('/admin/kkm/notification-settings', {
                    signal: options.controller?.signal,
                });

                if (data.success && this.shouldApplySettingsResponse(options)) {
                    this.kkmNotificationSettings = data.settings;
                    this.notificationSettingsLoaded = true;
                    this.notificationSettingsLoadError = false;
                }

                return data;
            } catch (error) {
                if (isAbortError(error)) {
                    throw error;
                }

                if (this.shouldApplySettingsResponse(options)) {
                    this.notificationSettingsLoaded = false;
                    this.notificationSettingsLoadError = true;
                }

                throw error;
            }
        },

        async saveKkmNotificationSettings() {
            if (!this.canSaveKkmNotificationSettings) {
                this.showAlert('error', 'Data pengaturan notifikasi belum siap. Muat ulang sebelum menyimpan.');
                return;
            }

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
                    this.markSettingsDataStale();
                    this.showAlert('success', 'Pengaturan notifikasi KKM berhasil disimpan');
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan pengaturan notifikasi');
                }
            } catch (error) {
                console.error('Error saving KKM notification settings:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan pengaturan notifikasi');
            }
        },

        async fetchKelasData(options = {}) {
            try {
                const data = await this.requestJson('/admin/kelas/data', {
                    signal: options.controller?.signal,
                });

                if (!this.shouldApplySettingsResponse(options)) {
                    return data;
                }

                this.kelasData = data.kelas;
                this.kelasLoaded = true;
                this.kelasLoadError = false;

                return data;
            } catch (error) {
                if (isAbortError(error)) {
                    throw error;
                }

                if (this.shouldApplySettingsResponse(options)) {
                    this.kelasLoaded = false;
                    this.kelasLoadError = true;
                }

                console.error('Error fetching kelas data:', error);
                throw error;
            }
        },

        async fetchKkmList(options = {}) {
            try {
                const data = await this.requestJson('/admin/kkm/list', {
                    signal: options.controller?.signal,
                });

                if (!this.shouldApplySettingsResponse(options)) {
                    return data;
                }

                this.kkmList = data.kkms;
                this.kkmLoaded = true;
                this.kkmLoadError = false;

                return data;
            } catch (error) {
                if (isAbortError(error)) {
                    throw error;
                }

                if (this.shouldApplySettingsResponse(options)) {
                    this.kkmLoaded = false;
                    this.kkmLoadError = true;
                }

                console.error('Error fetching KKM list:', error);
                throw error;
            }
        },

        async fetchBobotData(options = {}) {
            try {
                const data = await this.requestJson('/admin/bobot-nilai/data', {
                    signal: options.controller?.signal,
                });

                if (!this.shouldApplySettingsResponse(options)) {
                    return data;
                }

                this.bobotData = {
                    bobot_tp: this.normalizeBobotValue(data.bobot_tp) || 1,
                    bobot_lm: this.normalizeBobotValue(data.bobot_lm) || 1,
                    bobot_as: this.normalizeBobotValue(data.bobot_as) || 2
                };
                this.bobotLoaded = true;
                this.bobotLoadError = false;

                return data;
            } catch (error) {
                if (isAbortError(error)) {
                    throw error;
                }

                if (this.shouldApplySettingsResponse(options)) {
                    this.bobotLoaded = false;
                    this.bobotLoadError = true;
                }

                console.error('Error fetching bobot data:', error);
                throw error;
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
                    await this.refreshKkmListAfterMutation();
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

            if (!this.canSaveKkm) {
                this.showAlert('error', 'Data KKM belum siap. Muat ulang sebelum menyimpan.');
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
                    await this.refreshKkmListAfterMutation();
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
                    await this.refreshKkmListAfterMutation();
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
            if (!this.bobotLoaded || this.settingsLoading || this.bobotLoadError || this.bobotSaving) {
                this.showAlert('error', 'Data Bobot belum berhasil dimuat. Muat ulang sebelum menyimpan.');
                return;
            }

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
            }).then((result) => {
                return result.isConfirmed;
            });

            if (!isConfirmed) {
                return;
            }

            try {
                this.bobotSaving = true;

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
                    body: JSON.stringify({
                        bobot_tp: this.bobotTpValue,
                        bobot_lm: this.bobotLmValue,
                        bobot_as: this.bobotAsValue
                    })
                });

                const data = await response.json();

                if (data.success) {
                    this.markSettingsDataStale();
                    this.bobotLoaded = true;
                    this.bobotLoadError = false;
                    this.showAlert('success', 'Bobot nilai berhasil disimpan dan akan diterapkan pada semua perhitungan nilai');
                } else {
                    this.showAlert('error', data.message || 'Gagal menyimpan bobot nilai');
                }
            } catch (error) {
                console.error('Error saving bobot nilai:', error);
                this.showAlert('error', 'Terjadi kesalahan saat menyimpan bobot nilai');
            } finally {
                this.bobotSaving = false;
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
