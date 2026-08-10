export const raporManagerCore = {
    init() {
        this.activeTab = this.$el.dataset.activeTab || 'UTS';
        this.openedReportType = this.$el.dataset.openedReportType || this.activeTab;
        this.tahunAjaranId = this.$el.dataset.tahunAjaranId || '';
        this.semester = parseInt(this.$el.dataset.semester || '0', 10);
        this.pdfStatusUrl = this.$el.dataset.pdfStatusUrl || '';
        this.dashboardWarmupEnabled = this.$el.dataset.dashboardWarmupEnabled === '1';
        this.docxPrepareUrl = this.$el.dataset.docxPrepareUrl || '';
        this.batchPackageUrl = this.$el.dataset.batchPackageUrl || '';
        try {
            this.batchStudentIds = [...new Set(
                JSON.parse(this.$el.dataset.batchStudentIds || '[]')
                    .map((id) => Number(id))
                    .filter((id) => Number.isInteger(id) && id > 0)
            )];
        } catch (error) {
            console.error('Error parsing batch student IDs:', error);
            this.batchStudentIds = [];
        }
        try {
            this.pdfTemplateAvailability = JSON.parse(this.$el.dataset.pdfTemplateAvailability || '{}');
        } catch (error) {
            console.error('Error parsing PDF template availability:', error);
            this.pdfTemplateAvailability = {};
        }
        try {
            this.pdfStatuses = JSON.parse(this.$el.dataset.pdfStatuses || '{}');
        } catch (error) {
            console.error('Error parsing PDF statuses:', error);
            this.pdfStatuses = {};
        }
        this.$watch('activeTab', () => this.schedulePdfStatusRefresh());
        this.initializeTemplates();
    },

    destroy() {
        this.clearPdfStatusRefresh();
    },

    async initializeTemplates() {
        try {
            const data = await this.checkActiveTemplates();
            this.templateUTSActive = data.UTS_active || false;
            this.templateUASActive = data.UAS_active || false;
            this.openedReportType = data.opened_report_type || this.openedReportType || this.activeTab;

            if (this.activeTab === 'UTS' && !this.templateUTSActive) {
                this.activeTab = this.templateUASActive ? 'UAS' : 'UTS';
            } else if (this.activeTab === 'UAS' && !this.templateUASActive) {
                this.activeTab = this.templateUTSActive ? 'UTS' : 'UAS';
            }

            const savedTab = localStorage.getItem('activeRaporTab');
            if (savedTab && savedTab === this.openedReportType && ((savedTab === 'UAS' && this.templateUASActive) || (savedTab === 'UTS' && this.templateUTSActive))) {
                this.activeTab = savedTab;
            }

            localStorage.setItem('activeRaporTab', this.activeTab);
            this.initialized = true;
            this.schedulePdfStatusRefresh();
        } catch (error) {
            console.error('Error initializing templates:', error);
            this.initialized = true;
            this.templateUTSActive = true;
            this.templateUASActive = false;
            this.schedulePdfStatusRefresh();
        }
    },

    async checkActiveTemplates() {
        try {
            const query = this.tahunAjaranId ? `?tahun_ajaran_id=${encodeURIComponent(this.tahunAjaranId)}` : '';
            const response = await fetch(`/wali-kelas/rapor/check-templates${query}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            return {
                UTS_active: data.UTS_active || false,
                UAS_active: data.UAS_active || false,
                opened_report_type: data.opened_report_type || this.openedReportType
            };
        } catch (error) {
            console.error('Error checking templates:', error);
            return { UTS_active: true, UAS_active: false };
        }
    },

    setActiveTab(tab) {
        if (tab !== this.openedReportType) {
            Swal.fire({ icon: 'info', title: `Rapor ${tab} Belum Dibuka`, text: `Rapor ${tab} belum dibuka oleh admin.` });
            return;
        }

        if (tab === 'UAS' && !this.templateUASActive) {
            Swal.fire({ icon: 'info', title: 'Template UAS Belum Aktif', text: 'Admin belum mengaktifkan template rapor UAS.' });
            return;
        }

        if (tab === 'UTS' && !this.templateUTSActive) {
            Swal.fire({ icon: 'info', title: 'Template UTS Belum Aktif', text: 'Admin belum mengaktifkan template rapor UTS.' });
            return;
        }

        this.activeTab = tab;
        localStorage.setItem('activeRaporTab', tab);
        this.schedulePdfStatusRefresh();
    },

    handleSearch(event) {
        const searchValue = event.target.value.toLowerCase();
        document.querySelectorAll('tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchValue) ? '' : 'none';
        });
    },

    validateData(nilaiCount, hasAbsensi) {
        const messages = [];
        if (!nilaiCount || nilaiCount === 0) messages.push('- Data nilai belum lengkap');
        if (!hasAbsensi) messages.push('- Data kehadiran belum lengkap');
        if (!this.tahunAjaranId) messages.push('- Tahun ajaran tidak ditemukan');

        if (messages.length > 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Data Tidak Lengkap',
                html: `<p>Tidak bisa melanjutkan karena:</p><ul class="text-left mt-2">${messages.map(msg => `<li>${msg}</li>`).join('')}</ul><p class="mt-2">Semester aktif saat ini: ${this.semester}</p>`,
                confirmButtonText: 'Mengerti'
            });
            return false;
        }
        return true;
    },

    hasPdfTemplate(siswaId) {
        return Boolean(this.pdfTemplateAvailability?.[siswaId]?.[this.activeTab]);
    },

    pdfStatus(siswaId) {
        return this.pdfStatuses?.[siswaId]?.[this.activeTab] || 'missing';
    },

    updatePdfStatus(siswaId, status, type = null) {
        const id = String(siswaId);
        const targetType = type || this.activeTab;

        if (!this.pdfStatuses[id]) {
            this.pdfStatuses[id] = {};
        }

        this.pdfStatuses[id][targetType] = status;
        this.schedulePdfStatusRefresh();
    },

    pdfStatusStudentIds() {
        return Object.keys(this.pdfStatuses || {})
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0);
    },

    pdfStatusPollDelay() {
        const statuses = this.pdfStatusStudentIds().map((id) => this.pdfStatus(id));

        if (statuses.includes('preparing')) {
            return 5000;
        }

        if (this.dashboardWarmupEnabled && statuses.includes('missing')) {
            return 10000;
        }

        return null;
    },

    clearPdfStatusRefresh() {
        if (this.pdfStatusTimer) {
            clearTimeout(this.pdfStatusTimer);
            this.pdfStatusTimer = null;
        }
    },

    schedulePdfStatusRefresh() {
        this.clearPdfStatusRefresh();

        const delay = this.pdfStatusPollDelay();
        if (!delay || !this.pdfStatusUrl) {
            return;
        }

        this.pdfStatusTimer = setTimeout(() => this.refreshPdfStatuses(), delay);
    },

    async refreshPdfStatuses() {
        const studentIds = this.pdfStatusStudentIds();

        if (!studentIds.length || !this.pdfStatusUrl) {
            return;
        }

        try {
            const url = new URL(this.pdfStatusUrl, window.location.origin);
            url.searchParams.set('type', this.activeTab);

            if (this.tahunAjaranId) {
                url.searchParams.set('tahun_ajaran_id', this.tahunAjaranId);
            }

            studentIds.forEach((id) => url.searchParams.append('student_ids[]', id));

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            Object.entries(data.statuses || {}).forEach(([id, status]) => {
                this.updatePdfStatus(id, status, data.type || this.activeTab);
            });

            this.pdfStatusFailures = 0;
        } catch (error) {
            this.pdfStatusFailures += 1;

            if (this.pdfStatusFailures >= 3) {
                this.clearPdfStatusRefresh();
                return;
            }
        }

        this.schedulePdfStatusRefresh();
    },

    pdfStatusLabel(siswaId) {
        const labels = {
            ready: 'PDF siap',
            preparing: 'Sedang disiapkan',
            missing: 'Belum siap'
        };

        return labels[this.pdfStatus(siswaId)] || labels.missing;
    },

    pdfStatusClass(siswaId) {
        const classes = {
            ready: 'bg-green-100 text-green-800',
            preparing: 'bg-yellow-100 text-yellow-800',
            missing: 'bg-gray-100 text-gray-700'
        };

        return classes[this.pdfStatus(siswaId)] || classes.missing;
    },

    pdfStatusTitle(siswaId) {
        const titles = {
            ready: 'PDF sudah tersedia dari cache.',
            preparing: 'PDF sedang disiapkan oleh antrean latar belakang.',
            missing: 'PDF belum tersedia dan akan disiapkan saat preview atau unduh diminta.'
        };

        return titles[this.pdfStatus(siswaId)] || titles.missing;
    },

    pdfActionTitle(siswaId, availableTitle) {
        if (this.activeTab !== this.openedReportType) {
            return `Rapor ${this.activeTab} belum dibuka oleh admin.`;
        }

        if (this.hasPdfTemplate(siswaId)) {
            return availableTitle;
        }

        return `Belum ada template ${this.activeTab} aktif untuk kelas ini. Silakan hubungi admin.`;
    },

    validatePdfTemplate(siswaId) {
        if (this.activeTab !== this.openedReportType) {
            Swal.fire({
                icon: 'info',
                title: `Rapor ${this.activeTab} Belum Dibuka`,
                text: `Rapor ${this.activeTab} belum dibuka oleh admin.`,
                confirmButtonText: 'Mengerti'
            });
            return false;
        }

        if (this.hasPdfTemplate(siswaId)) {
            return true;
        }

        Swal.fire({
            icon: 'info',
            title: `Template ${this.activeTab} Belum Tersedia`,
            text: `Belum ada template ${this.activeTab} aktif untuk kelas ini. Silakan hubungi admin.`,
            confirmButtonText: 'Mengerti'
        });
        return false;
    },

    async handlePreview(siswaId, nilaiCount, hasAbsensi) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        try {
            this.loading = true;
            const response = await fetch(`/wali-kelas/rapor/preview/${siswaId}?tahun_ajaran_id=${this.tahunAjaranId}&type=${this.activeTab}`, {
                method: 'GET',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (!response.ok) throw new Error(`Server error: ${response.status}`);
            const data = await response.json();
            if (data.success) {
                this.previewContent = data.html;
                this.showPreview = true;
            } else {
                throw new Error(data.message || 'Preview tidak berhasil');
            }
        } catch (error) {
            console.error('Error in handlePreview:', error);
            Swal.fire({ icon: 'error', title: 'Gagal Memuat Preview', text: error.message });
        } finally {
            this.loading = false;
        }
    },

    async handleGenerate(siswaId, nilaiCount, hasAbsensi, namaSiswa) {
        if (!this.validateData(nilaiCount, hasAbsensi)) return;
        try {
            this.loading = true;
            const response = await fetch(`/wali-kelas/rapor/generate/${siswaId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ type: this.activeTab, tahun_ajaran_id: this.tahunAjaranId, action: 'download' })
            });

            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                if (!response.ok) {
                    Swal.fire({ icon: 'error', title: 'Gagal Generate Rapor', text: data.message || 'Terjadi kesalahan saat memproses rapor' });
                    return;
                }
                if (data.success && data.file_url) {
                    window.location.href = data.file_url;
                    return;
                }
            }

            if (response.ok) {
                const blob = await response.blob();
                const cleanName = namaSiswa.replace(/[^\w\s]/gi, '').replace(/\s+/g, '_');
                await this.downloadFile(blob, `Rapor_${this.activeTab}_${cleanName}.docx`);
                Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Rapor berhasil digenerate dan diunduh', timer: 2000, showConfirmButton: false });
            } else {
                throw new Error(`Gagal mengunduh rapor: ${response.status}`);
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({ icon: 'error', title: 'Gagal Generate Rapor', text: error.message });
        } finally {
            this.loading = false;
        }
    },

    batchStatusText() {
        if (this.batchState === 'preparing') {
            return `Menyiapkan rapor... ${this.batchCurrent} / ${this.batchTotal}`;
        }

        if (this.batchState === 'packaging') {
            return 'Menyusun paket rapor...';
        }

        return this.batchMessage;
    },

    async handleBatchDownload() {
        if (this.batchProcessing) return;

        if (!this.batchStudentIds.length) {
            Swal.fire({
                icon: 'info',
                title: 'Belum Ada Siswa',
                text: 'Tidak ada siswa yang dapat disiapkan pada konteks rapor ini.'
            });
            return;
        }

        const operation = Object.freeze({
            studentIds: [...this.batchStudentIds],
            type: this.activeTab,
            yearId: this.tahunAjaranId
        });

        if (!['UTS', 'UAS'].includes(operation.type) || !operation.yearId || !this.docxPrepareUrl || !this.batchPackageUrl) {
            Swal.fire({
                icon: 'error',
                title: 'Konteks Rapor Tidak Tersedia',
                text: 'Muat ulang halaman lalu coba kembali.'
            });
            return;
        }

        this.batchProcessing = true;
        this.batchState = 'preparing';
        this.batchCurrent = 0;
        this.batchTotal = operation.studentIds.length;
        this.batchMessage = '';
        this.batchPreparationFailures = [];

        try {
            for (const [index, studentId] of operation.studentIds.entries()) {
                const prepared = await this.prepareBatchStudentDocx(studentId, operation);
                if (!prepared) {
                    this.batchPreparationFailures.push(studentId);
                }
                this.batchCurrent = index + 1;
            }

            this.batchState = 'packaging';
            const response = await fetch(this.batchPackageUrl, {
                method: 'POST',
                headers: this.jsonRequestHeaders(),
                body: JSON.stringify({
                    siswa_ids: operation.studentIds,
                    type: operation.type,
                    tahun_ajaran_id: operation.yearId
                })
            });
            const data = await this.readJsonResponse(response);

            if (!response.ok || data.success !== true || !data.download_url) {
                this.handleBatchFailure(response, data);
                return;
            }

            const total = Number(data.stats?.total) || operation.studentIds.length;
            const success = Number(data.stats?.success) || 0;
            const unavailable = Number(data.stats?.unavailable) || 0;
            const partial = unavailable > 0 || success < total;

            this.batchState = partial ? 'partial' : 'completed';
            this.batchMessage = partial
                ? `${success} dari ${total} rapor berhasil disiapkan. ${unavailable} rapor belum dapat disertakan.`
                : `${success} rapor berhasil disiapkan.`;

            Swal.fire({
                icon: partial ? 'warning' : 'success',
                title: partial ? 'Paket Rapor Disiapkan Sebagian' : 'Paket Rapor Siap',
                text: this.batchMessage,
                confirmButtonText: 'Mengerti'
            });
            window.location.href = data.download_url;
        } catch (error) {
            console.error('Batch report preparation failed:', error);
            this.setBatchFailure('Paket rapor belum dapat disiapkan. Silakan coba lagi nanti.');
        } finally {
            this.batchProcessing = false;
        }
    },

    async prepareBatchStudentDocx(studentId, operation) {
        const url = this.docxPrepareUrl.replace('__student__', encodeURIComponent(String(studentId)));
        const maxRetries = 2;

        for (let attempt = 0; attempt <= maxRetries; attempt += 1) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: this.jsonRequestHeaders(),
                    body: JSON.stringify({
                        type: operation.type,
                        tahun_ajaran_id: operation.yearId,
                        action: 'preview'
                    })
                });

                if (response.status === 429 && attempt < maxRetries) {
                    await this.wait(this.retryDelay(response.headers.get('Retry-After'), attempt));
                    continue;
                }

                const data = await this.readJsonResponse(response);
                return response.ok && data.success === true && Boolean(data.file_url);
            } catch (error) {
                console.warn(`Rapor siswa ${studentId} belum dapat disiapkan.`);
                return false;
            }
        }

        return false;
    },

    retryDelay(retryAfter, attempt) {
        let delay = Number(retryAfter) * 1000;

        if (!Number.isFinite(delay) || delay <= 0) {
            const retryAt = Date.parse(retryAfter || '');
            delay = Number.isNaN(retryAt) ? 2000 * (attempt + 1) : retryAt - Date.now();
        }

        return Math.min(Math.max(delay, 1000), 60000);
    },

    wait(milliseconds) {
        return new Promise((resolve) => setTimeout(resolve, milliseconds));
    },

    jsonRequestHeaders() {
        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'X-Requested-With': 'XMLHttpRequest'
        };
    },

    async readJsonResponse(response) {
        try {
            return await response.json();
        } catch (error) {
            return {};
        }
    },

    handleBatchFailure(response, data) {
        let message = 'Paket rapor belum dapat disiapkan. Silakan coba lagi nanti.';

        if (response.status === 422 && data.error_type === 'docx_cache_unavailable') {
            message = 'Belum ada rapor yang dapat disiapkan. Periksa kelengkapan data siswa lalu coba kembali.';
        } else if (response.status === 403) {
            message = 'Anda tidak memiliki akses untuk menyiapkan paket rapor tersebut.';
        } else if (response.status === 404) {
            message = 'Template atau konteks rapor belum tersedia.';
        } else if (response.status === 422) {
            message = 'Data permintaan rapor tidak valid. Muat ulang halaman lalu coba kembali.';
        }

        this.setBatchFailure(message);
    },

    setBatchFailure(message) {
        this.batchState = 'failed';
        this.batchMessage = message;
        Swal.fire({
            icon: 'error',
            title: 'Gagal Menyiapkan Paket Rapor',
            text: message,
            confirmButtonText: 'Mengerti'
        });
    },

    async downloadFile(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    },

    refreshPage() {
        window.location.reload();
    }
};
