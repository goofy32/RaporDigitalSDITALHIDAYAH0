import Alpine from 'alpinejs';

export function registerNotificationStore() {
    Alpine.store('notification', {
        items: [],
        unreadCount: 0,
        loading: false,
        refreshInterval: null,

        async fetchNotifications() {
            if (this.loading) return;
            this.loading = true;

            try {
                const path = window.location.pathname;
                let url;

                if (path.includes('/admin/')) url = '/admin/information/list';
                else if (path.includes('/pengajar/')) url = '/pengajar/notifications';
                else if (path.includes('/wali-kelas/')) url = '/wali-kelas/notifications';

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
                this.items = data.items || [];
            } catch (error) {
                console.error('Error fetching notifications:', error);
            } finally {
                this.loading = false;
            }
        },

        async markAsRead(notificationId) {
            try {
                const path = window.location.pathname;
                let baseUrl = '';

                if (path.includes('/pengajar/')) baseUrl = '/pengajar/notifications';
                else if (path.includes('/wali-kelas/')) baseUrl = '/wali-kelas/notifications';

                if (!baseUrl) return false;

                const response = await fetch(`${baseUrl}/${notificationId}/read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok) {
                    this.items = this.items.map(item => item.id === notificationId ? { ...item, is_read: true } : item);
                    await this.fetchUnreadCount();
                    return true;
                }

                return false;
            } catch (error) {
                console.error('Error marking notification as read:', error);
                return false;
            }
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
                return data.count;
            } catch (error) {
                console.error('Error fetching unread count:', error);
                return 0;
            }
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
}
