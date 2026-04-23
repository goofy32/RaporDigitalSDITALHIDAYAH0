export const raporManagerCore = {
    init() {
        this.activeTab = this.$el.dataset.activeTab || 'UTS';
        this.tahunAjaranId = this.$el.dataset.tahunAjaranId || '';
        this.semester = parseInt(this.$el.dataset.semester || '0', 10);
        this.initializeTemplates();
    },

    async initializeTemplates() {
        try {
            const data = await this.checkActiveTemplates();
            this.templateUTSActive = data.UTS_active || false;
            this.templateUASActive = data.UAS_active || false;

            if (this.activeTab === 'UTS' && !this.templateUTSActive) {
                this.activeTab = this.templateUASActive ? 'UAS' : 'UTS';
            } else if (this.activeTab === 'UAS' && !this.templateUASActive) {
                this.activeTab = this.templateUTSActive ? 'UTS' : 'UAS';
            }

            const savedTab = localStorage.getItem('activeRaporTab');
            if (savedTab && ((savedTab === 'UAS' && this.templateUASActive) || (savedTab === 'UTS' && this.templateUTSActive))) {
                this.activeTab = savedTab;
            }

            localStorage.setItem('activeRaporTab', this.activeTab);
            this.initialized = true;
        } catch (error) {
            console.error('Error initializing templates:', error);
            this.initialized = true;
            this.templateUTSActive = true;
            this.templateUASActive = false;
        }
    },

    async checkActiveTemplates() {
        try {
            const response = await fetch('/wali-kelas/rapor/check-templates', {
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
                UAS_active: data.UAS_active || false
            };
        } catch (error) {
            console.error('Error checking templates:', error);
            return { UTS_active: true, UAS_active: false };
        }
    },

    setActiveTab(tab) {
        if (tab === 'UAS' && !this.templateUASActive) {
            Swal.fire({ icon: 'info', title: 'Rapor UAS Belum Aktif', text: 'Admin belum mengaktifkan template rapor UAS.' });
            return;
        }

        if (tab === 'UTS' && !this.templateUTSActive) {
            Swal.fire({ icon: 'info', title: 'Rapor UTS Belum Aktif', text: 'Admin belum mengaktifkan template rapor UTS.' });
            return;
        }

        this.activeTab = tab;
        localStorage.setItem('activeRaporTab', tab);
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
