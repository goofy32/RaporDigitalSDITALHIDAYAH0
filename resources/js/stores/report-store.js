import Alpine from 'alpinejs';

export function registerReportStore() {
    Alpine.store('report', {
        template: null,
        loading: false,
        error: null,
        feedback: null,
        previewContent: '',
        showPreview: false,

        async downloadPdf(siswaId) {
            try {
                const response = await fetch(`/wali-kelas/rapor/download-pdf/${siswaId}`);
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `rapor_${siswaId}.pdf`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } catch {
                this.setFeedback('Gagal mengunduh PDF', 'error');
            }
        },

        showPreviewModal(siswaId) {
            return this.handleAsync(async () => {
                const response = await fetch(`/wali-kelas/rapor/preview/${siswaId}`);
                const data = await response.json();

                if (data.success) {
                    this.previewContent = data.html;
                    this.showPreview = true;
                } else {
                    throw new Error(data.message);
                }
            });
        },

        async handleAsync(operation) {
            try {
                this.loading = true;
                await operation();
            } catch (error) {
                this.setFeedback(error.message, 'error');
            } finally {
                this.loading = false;
            }
        },

        async fetchActiveTemplate(type) {
            if (this.loading) return;
            this.loading = true;

            try {
                const response = await fetch(`/admin/report-template/${type}/active`);
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                this.template = data.template;
            } catch (error) {
                console.error('Error fetching template:', error);
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async uploadTemplate(file, type) {
            if (!file || this.loading) return;

            const formData = new FormData();
            formData.append('template', file);
            formData.append('type', type);

            this.loading = true;
            try {
                const response = await fetch('/admin/report-template/upload', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });

                const result = await response.json();
                if (!response.ok) throw new Error(result.message);

                this.template = result.template;
                this.setFeedback('Template berhasil diupload', 'success');
                return true;
            } catch (error) {
                this.setFeedback(error.message, 'error');
                return false;
            } finally {
                this.loading = false;
            }
        },

        async activateTemplate(templateId) {
            if (this.loading) return;
            this.loading = true;

            try {
                const response = await fetch(`/admin/report-template/${templateId}/activate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (!response.ok) throw new Error(result.message);

                if (this.template && this.template.id === templateId) {
                    this.template.is_active = true;
                }

                this.setFeedback('Template berhasil diaktifkan', 'success');
                return true;
            } catch (error) {
                this.setFeedback(error.message, 'error');
                return false;
            } finally {
                this.loading = false;
            }
        },

        async downloadSampleTemplate() {
            try {
                const response = await fetch('/admin/report-template/sample');
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'template_rapor_sample.docx';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } catch {
                this.setFeedback('Gagal mengunduh template contoh', 'error');
            }
        },

        closePreview() {
            this.showPreview = false;
            this.previewContent = '';
        },

        setFeedback(message, type = 'success') {
            this.feedback = { message, type };
            setTimeout(() => {
                this.feedback = null;
            }, 3000);
        },
    });
}
