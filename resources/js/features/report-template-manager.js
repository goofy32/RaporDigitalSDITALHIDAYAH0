import Alpine from 'alpinejs';

export function registerReportTemplateFeatures() {
    Alpine.data('reportTemplateManager', config => ({
        type: config.type,
        templates: config.templates || [],
        activeTemplate: config.activeTemplate,
        loading: false,
        showPlaceholderGuide: false,
        feedback: { type: '', message: '' },

        init() {},

        async handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            if (!file.name.endsWith('.docx')) {
                this.showFeedback('error', 'File harus berformat .docx');
                return;
            }

            const formData = new FormData();
            formData.append('template', file);
            formData.append('type', this.type);

            try {
                this.loading = true;
                const response = await fetch('/admin/report-template/upload', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });

                const result = await response.json();

                if (result.success) {
                    this.templates = [result.template, ...this.templates];
                    this.showFeedback('success', 'Template berhasil diupload');
                    event.target.value = '';
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                this.showFeedback('error', error.message || 'Gagal mengupload template');
            } finally {
                this.loading = false;
            }
        },

        async activateTemplate(template) {
            try {
                this.loading = true;
                const response = await fetch(`/admin/report-template/${template.id}/activate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    this.templates = this.templates.map(t => ({ ...t, is_active: t.id === template.id }));
                    this.activeTemplate = template;
                    this.showFeedback('success', 'Template berhasil diaktifkan');
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                this.showFeedback('error', error.message || 'Gagal mengaktifkan template');
            } finally {
                this.loading = false;
            }
        },

        async deleteTemplate(template) {
            if (!confirm('Anda yakin ingin menghapus template ini?')) return;

            try {
                this.loading = true;
                const response = await fetch(`/admin/report-template/${template.id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    this.templates = this.templates.filter(t => t.id !== template.id);
                    if (this.activeTemplate?.id === template.id) this.activeTemplate = null;
                    this.showFeedback('success', 'Template berhasil dihapus');
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                this.showFeedback('error', error.message || 'Gagal menghapus template');
            } finally {
                this.loading = false;
            }
        },

        previewTemplate(template) {
            window.open(`/admin/report-template/${template.id}/preview`, '_blank');
        },

        openPlaceholderGuide() {
            this.showPlaceholderGuide = true;
        },

        showFeedback(type, message) {
            this.feedback = { type, message };
            setTimeout(() => {
                this.feedback.message = '';
            }, 3000);
        },

        formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            });
        },
    }));
}
