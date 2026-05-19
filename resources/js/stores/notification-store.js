import Alpine from 'alpinejs';

export function registerNotificationStore() {
    Alpine.store('notification', {
        items: [],
        unreadCount: 0,
        loading: false,
        refreshInterval: null,
        baseTitle: '',
        showModal: false,
        hideRead: false,

        init() {
            this.baseTitle = document.title.replace(/^\(\d+\)\s/, '');
            this.updateTabTitle();
        },

        updateTabTitle() {
            var baseTitle = this.baseTitle || document.title.replace(/^\(\d+\)\s/, '');

            if (!this.baseTitle) {
                this.baseTitle = baseTitle;
            }

            if (this.unreadCount > 0) {
                document.title = `(${this.unreadCount}) ${baseTitle}`;
                return;
            }

            document.title = baseTitle;
        },

        resolveListUrl() {
            const path = window.location.pathname;

            if (path.includes('/admin/')) return '/admin/information/list';
            if (path.includes('/pengajar/')) return '/pengajar/notifications';
            if (path.includes('/wali-kelas/')) return '/wali-kelas/notifications';

            return null;
        },

        resolveReadBaseUrl() {
            const path = window.location.pathname;

            if (path.includes('/pengajar/')) return '/pengajar/notifications';
            if (path.includes('/wali-kelas/')) return '/wali-kelas/notifications';

            return null;
        },

        formatTime(dateString) {
            if (!dateString) return '';

            const date = new Date(dateString);
            if (Number.isNaN(date.getTime())) {
                return dateString;
            }

            const now = new Date();
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return 'Baru saja';
            if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;
            if (diff < 86400) return `${Math.floor(diff / 3600)} jam lalu`;
            if (diff < 172800) return 'Kemarin';

            return `${Math.floor(diff / 86400)} hari lalu`;
        },

        formatDateTime(dateString) {
            if (!dateString) return '';

            const date = new Date(dateString);
            if (Number.isNaN(date.getTime())) {
                return dateString;
            }

            const monthNames = [
                'Januari',
                'Februari',
                'Maret',
                'April',
                'Mei',
                'Juni',
                'Juli',
                'Agustus',
                'September',
                'Oktober',
                'November',
                'Desember',
            ];

            const day = date.getDate();
            const month = monthNames[date.getMonth()];
            const year = date.getFullYear();
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');

            return `${day} ${month} ${year}, ${hours}:${minutes}`;
        },

        normalizeNotification(item) {
            return {
                ...item,
                created_at_raw: item.created_at,
                created_at: this.formatTime(item.created_at),
                created_at_formatted: this.formatDateTime(item.created_at),
                is_read: Boolean(item.is_read),
            };
        },

        getUnreadItems() {
            return this.items.filter(item => item.is_read !== true);
        },

        getReadItems() {
            return this.items.filter(item => item.is_read === true);
        },

        get visibleItems() {
            if (this.hideRead) {
                return this.items.filter(item => item.is_read !== true);
            }

            return this.items;
        },

        toggleHideRead() {
            this.hideRead = !this.hideRead;
        },

        async fetchNotifications() {
            if (this.loading) return;
            this.loading = true;

            try {
                const url = this.resolveListUrl();
                if (!url) return;

                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Network response was not ok: ${response.status}`);
                }

                const data = await response.json();
                this.items = (data.items || []).map(item => this.normalizeNotification(item));
                this.updateTabTitle();
            } catch (error) {
                console.error('Error fetching notifications:', error);
            } finally {
                this.loading = false;
            }
        },

        async markAsRead(notificationId, options = {}) {
            const { refreshCount = true } = options;

            try {
                const item = this.items.find(entry => entry.id === notificationId);
                if (item && item.is_read) {
                    return true;
                }

                const baseUrl = this.resolveReadBaseUrl();
                if (!baseUrl) {
                    return false;
                }

                const response = await fetch(`${baseUrl}/${notificationId}/read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    return false;
                }

                this.items = this.items.map(entry => entry.id === notificationId ? { ...entry, is_read: true } : entry);

                if (refreshCount) {
                    await this.fetchUnreadCount();
                } else {
                    this.unreadCount = Math.max(
                        0,
                        this.items.filter(entry => entry.is_read !== true).length
                    );
                    this.updateTabTitle();
                }

                return true;
            } catch (error) {
                console.error('Error marking notification as read:', error);
                return false;
            }
        },

        async markAllAsRead() {
            const unreadItems = this.items.filter(item => item.is_read !== true);

            if (unreadItems.length === 0) {
                return true;
            }

            const results = await Promise.all(
                unreadItems.map(item => this.markAsRead(item.id, { refreshCount: false }))
            );

            await this.fetchUnreadCount();
            return results.every(Boolean);
        },

        async fetchUnreadCount() {
            try {
                const response = await fetch('/notifications/unread-count', {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Network response was not ok: ${response.status}`);
                }

                const data = await response.json();
                this.unreadCount = data.count;
                this.updateTabTitle();
                return data.count;
            } catch (error) {
                console.error('Error fetching unread count:', error);
                this.updateTabTitle();
                return 0;
            }
        },

        async openModal() {
            this.showModal = true;
            await this.fetchNotifications();
            await this.fetchUnreadCount();
        },

        closeModal() {
            this.showModal = false;
        },

        async addNotification(notification) {
            try {
                const response = await fetch('/admin/information', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(notification),
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.message || 'Failed to add notification');
                }

                const result = await response.json();
                if (result.success) {
                    await this.fetchNotifications();
                    return true;
                }

                return false;
            } catch (error) {
                console.error('Error adding notification:', error);
                return false;
            }
        },

        async deleteNotification(id) {
            try {
                const response = await fetch(`/admin/information/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error('Failed to delete notification');

                const result = await response.json();
                if (result.success) {
                    this.items = this.items.filter(item => item.id !== id);
                    return true;
                }

                return false;
            } catch (error) {
                console.error('Error deleting notification:', error);
                return false;
            }
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            this.refreshInterval = setInterval(() => {
                this.fetchNotifications();
                this.fetchUnreadCount();
            }, 30000);
        },

        stopAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },
    });

    Alpine.store('notification').init();
}
