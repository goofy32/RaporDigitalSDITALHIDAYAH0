import Alpine from 'alpinejs';

export function registerNotificationHandler() {
    document.addEventListener('alpine:init', () => {
        Alpine.data('notificationHandler', () => ({
            showModal: false,
            isOpen: false,
            errorMessage: '',
            successMessage: '',
            isSubmitting: false,
            guruSearchTerm: '',
            notificationForm: {
                title: '',
                content: '',
                target: '',
                specific_users: [],
            },

            init() {
                this.$store.notification.fetchNotifications();
                this.$store.notification.fetchUnreadCount();
                this.$store.notification.startAutoRefresh();
            },

            formatTimeStamp(dateString) {
                if (!dateString) return '';

                try {
                    const date = new Date(dateString);
                    const now = new Date();
                    const yesterday = new Date(now);
                    yesterday.setDate(yesterday.getDate() - 1);

                    const isToday = date.toDateString() === now.toDateString();
                    const isYesterday = date.toDateString() === yesterday.toDateString();
                    const isCurrentYear = date.getFullYear() === now.getFullYear();

                    if (isToday) {
                        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false });
                    }
                    if (isYesterday) {
                        return `Kemarin ${date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })}`;
                    }
                    if (isCurrentYear) {
                        return `${date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })} ${date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })}`;
                    }
                    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
                } catch (e) {
                    console.error('Error formatting date:', e);
                    return dateString;
                }
            },

            getTargetText(item) {
                if (!item.target) return 'Semua';

                switch (item.target) {
                    case 'all': return 'Semua';
                    case 'guru': return 'Semua Guru';
                    case 'wali_kelas': return 'Semua Wali Kelas';
                    case 'specific':
                        if (item.specific_users && item.specific_users.length > 0) {
                            if (item.target_display) return item.target_display;
                            return item.specific_users.length === 1 ? 'Guru Tertentu (1)' : `${item.specific_users.length} Guru Tertentu`;
                        }
                        return 'Guru Tertentu';
                    default: return 'Semua';
                }
            },

            ensureTruncated(text, maxLength = 50) {
                if (!text) return '';
                return text.length > maxLength ? `${text.substring(0, maxLength)}...` : text;
            },

            async submitNotification() {
                if (this.isSubmitting) return;

                if (!this.notificationForm.title.trim()) {
                    this.errorMessage = 'Judul tidak boleh kosong';
                    setTimeout(() => { this.errorMessage = ''; }, 3000);
                    return;
                }

                if (!this.notificationForm.content.trim()) {
                    this.errorMessage = 'Isi tidak boleh kosong';
                    setTimeout(() => { this.errorMessage = ''; }, 3000);
                    return;
                }

                if (this.notificationForm.content.length > 100) {
                    this.notificationForm.content = this.notificationForm.content.substring(0, 100);
                }

                if (!this.notificationForm.target) {
                    this.errorMessage = 'Target notifikasi harus dipilih';
                    setTimeout(() => { this.errorMessage = ''; }, 3000);
                    return;
                }

                if (this.notificationForm.target === 'specific' && this.notificationForm.specific_users.length === 0) {
                    this.errorMessage = 'Pilih minimal satu guru untuk notifikasi';
                    setTimeout(() => { this.errorMessage = ''; }, 3000);
                    return;
                }

                try {
                    this.isSubmitting = true;
                    const result = await this.$store.notification.addNotification(this.notificationForm);

                    if (result) {
                        this.successMessage = 'Notifikasi berhasil ditambahkan';
                        this.resetForm();
                        this.showModal = false;
                        await this.$store.notification.fetchNotifications();
                    } else {
                        this.errorMessage = 'Gagal menambahkan notifikasi';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    this.errorMessage = `Terjadi kesalahan: ${error.message || 'Tidak dapat menambahkan notifikasi'}`;
                } finally {
                    this.isSubmitting = false;

                    if (this.errorMessage) {
                        setTimeout(() => { this.errorMessage = ''; }, 3000);
                    }

                    if (this.successMessage) {
                        setTimeout(() => { this.successMessage = ''; }, 3000);
                    }
                }
            },

            resetForm() {
                this.notificationForm = {
                    title: '',
                    content: '',
                    target: '',
                    specific_users: [],
                };
                this.guruSearchTerm = '';
            },

            toggleNotifications() {
                this.isOpen = !this.isOpen;
                if (this.isOpen) {
                    this.$store.notification.fetchNotifications();
                }
            },

            destroy() {
                this.$store.notification.stopAutoRefresh();
            },
        }));
    });
}
