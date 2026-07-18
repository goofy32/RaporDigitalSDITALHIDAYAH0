import Alpine from 'alpinejs';

export function registerNotificationStore() {
    Alpine.store('notification', {
        items: [],
        unreadCount: 0,
        loading: false,
        refreshInterval: null,
        markingReadIds: new Set(),
        markingAllAsRead: false,
        deletingNotificationIds: new Set(),
        deletingAllNotifications: false,
        errorMessage: '',
        baseTitle: '',
        showModal: false,
        hideRead: false,
        activeFilter: 'all',
        statusFilter: 'all',
        sourceFilter: 'all',
        categoryFilter: 'all',
        visibilityHandlerBound: false,
        filterOptions: [
            { key: 'all', label: 'Semua' },
            { key: 'unread', label: 'Belum dibaca' },
            { key: 'read', label: 'Sudah dibaca' },
            { key: 'admin', label: 'Admin' },
            { key: 'guru', label: 'Guru/Pengajar' },
            { key: 'wali_kelas', label: 'Wali Kelas' },
            { key: 'sistem', label: 'Sistem' },
            { key: 'nilai', label: 'Nilai' },
            { key: 'rapor', label: 'Rapor' },
            { key: 'template', label: 'Template' },
            { key: 'tahun_ajaran', label: 'Tahun Ajaran' },
        ],

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

            if (path.includes('/admin/')) return '/admin/information';
            if (path.includes('/pengajar/')) return '/pengajar/notifications';
            if (path.includes('/wali-kelas/')) return '/wali-kelas/notifications';

            return null;
        },

        resolveDeleteBaseUrl() {
            return this.resolveReadBaseUrl();
        },

        isAdminContext() {
            return window.location.pathname.includes('/admin/');
        },

        clearError() {
            this.errorMessage = '';
        },

        setError(message) {
            this.errorMessage = message || 'Aksi notifikasi gagal diproses.';
        },

        async responseErrorMessage(response, fallback) {
            try {
                const data = await response.clone().json();
                return data.message || fallback;
            } catch (error) {
                return fallback;
            }
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
            const source = item.source || this.deriveSource(item);
            const category = item.category || this.deriveCategory(item);

            return {
                ...item,
                created_at_raw: item.created_at,
                created_at: this.formatTime(item.created_at),
                created_at_formatted: this.formatDateTime(item.created_at),
                is_read: Boolean(item.is_read),
                source,
                source_label: item.source_label || this.sourceLabel(source),
                category,
                category_label: item.category_label || this.categoryLabel(category),
            };
        },

        deriveSource(item) {
            const category = item.category || this.deriveCategory(item);

            if (category === 'nilai') return 'guru';
            if (category === 'tahun_ajaran' || category === 'rapor') return 'sistem';
            if (['all', 'guru', 'wali_kelas', 'specific'].includes(item.target)) return 'admin';

            return 'sistem';
        },

        deriveCategory(item) {
            const text = `${item.title || ''} ${item.content || ''}`.toLowerCase();

            if (text.includes('nilai') || text.includes('score')) return 'nilai';
            if (text.includes('rapor') || text.includes('pdf')) return 'rapor';
            if (text.includes('template')) return 'template';
            if (text.includes('tahun ajaran') || text.includes('semester')) return 'tahun_ajaran';

            return 'sistem';
        },

        sourceLabel(source) {
            const labels = {
                admin: 'Admin',
                guru: 'Guru/Pengajar',
                wali_kelas: 'Wali Kelas',
                sistem: 'Sistem',
            };

            return labels[source] || 'Sistem';
        },

        categoryLabel(category) {
            const labels = {
                nilai: 'Nilai',
                rapor: 'Rapor',
                template: 'Template',
                tahun_ajaran: 'Tahun Ajaran',
                sistem: 'Sistem',
            };

            return labels[category] || 'Sistem';
        },

        getUnreadItems() {
            return this.items.filter(item => item.is_read !== true);
        },

        getReadItems() {
            return this.items.filter(item => item.is_read === true);
        },

        get visibleItems() {
            return this.filteredItems;
        },

        get filteredItems() {
            return this.items.filter(item => this.matchesActiveFilter(item));
        },

        get previewItems() {
            return this.items.slice(0, 3);
        },

        get dashboardItems() {
            if (this.hideRead) {
                return this.items.filter(item => item.is_read !== true);
            }

            return this.items;
        },

        matchesActiveFilter(item) {
            if (this.hideRead && item.is_read === true) {
                return false;
            }

            if (this.statusFilter === 'unread' && item.is_read === true) {
                return false;
            }

            if (this.statusFilter === 'read' && item.is_read !== true) {
                return false;
            }

            if (this.sourceFilter !== 'all' && item.source !== this.sourceFilter) {
                return false;
            }

            if (this.categoryFilter !== 'all' && item.category !== this.categoryFilter) {
                return false;
            }

            return true;
        },

        setFilter(filter) {
            this.activeFilter = filter;

            if (['all', 'unread', 'read'].includes(filter)) {
                this.statusFilter = filter;
                return;
            }

            if (['admin', 'guru', 'wali_kelas', 'sistem'].includes(filter)) {
                this.sourceFilter = filter;
                return;
            }

            if (['nilai', 'rapor', 'template', 'tahun_ajaran'].includes(filter)) {
                this.categoryFilter = filter;
            }
        },

        setStatusFilter(filter) {
            this.statusFilter = filter;
            this.activeFilter = filter;
        },

        resetFilters() {
            this.activeFilter = 'all';
            this.statusFilter = 'all';
            this.sourceFilter = 'all';
            this.categoryFilter = 'all';
        },

        toggleHideRead() {
            this.hideRead = !this.hideRead;
        },

        async bootstrap() {
            this.bindVisibilityHandler();
            await this.fetchNotifications();
            this.startAutoRefresh();
        },

        bindVisibilityHandler() {
            if (this.visibilityHandlerBound) {
                return;
            }

            this.visibilityHandlerBound = true;

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.stopAutoRefresh();
                    return;
                }

                this.startAutoRefresh();
                this.fetchUnreadCount();
            });
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
                    throw new Error(await this.responseErrorMessage(response, 'Gagal memuat notifikasi.'));
                }

                const data = await response.json();
                this.items = (data.items || []).map(item => this.normalizeNotification(item));
                this.unreadCount = this.items.filter(item => item.is_read !== true).length;
                this.updateTabTitle();
            } catch (error) {
                console.error('Error fetching notifications:', error);
                this.setError(error.message || 'Gagal memuat notifikasi.');
            } finally {
                this.loading = false;
            }
        },

        async markAsRead(notificationId, options = {}) {
            const markingKey = String(notificationId);
            let acquiredMarkingLock = false;
            const previousItems = this.items.map(item => ({ ...item }));
            const previousUnreadCount = Number(this.unreadCount || 0);

            try {
                const item = this.items.find(entry => String(entry.id) === markingKey);
                if (item && item.is_read) {
                    return true;
                }

                if (this.markingReadIds.has(markingKey)) {
                    return true;
                }

                const baseUrl = this.resolveReadBaseUrl();
                if (!baseUrl) {
                    return false;
                }

                this.markingReadIds.add(markingKey);
                acquiredMarkingLock = true;
                this.clearError();

                if (item && item.is_read !== true) {
                    this.items = this.items.map(entry => String(entry.id) === markingKey ? { ...entry, is_read: true } : entry);
                    this.unreadCount = Math.max(0, previousUnreadCount - 1);
                    this.updateTabTitle();
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
                    throw new Error(await this.responseErrorMessage(response, 'Gagal menandai notifikasi sebagai dibaca.'));
                }

                const result = await response.json();
                if (result.success === false) {
                    throw new Error(result.message || 'Gagal menandai notifikasi sebagai dibaca.');
                }

                return true;
            } catch (error) {
                console.error('Error marking notification as read:', error);
                this.items = previousItems;
                this.unreadCount = previousUnreadCount;
                this.updateTabTitle();
                this.setError(error.message || 'Gagal menandai notifikasi sebagai dibaca.');
                return false;
            } finally {
                if (acquiredMarkingLock) {
                    this.markingReadIds.delete(markingKey);
                }
            }
        },

        async markAllAsRead() {
            if (this.items.filter(item => item.is_read !== true).length === 0) {
                return true;
            }

            if (this.markingAllAsRead) {
                return true;
            }

            const baseUrl = this.resolveReadBaseUrl();

            if (!baseUrl) {
                return false;
            }

            this.markingAllAsRead = true;
            const previousItems = this.items.map(item => ({ ...item }));
            const previousUnreadCount = Number(this.unreadCount || 0);
            this.clearError();
            this.items = this.items.map(item => ({ ...item, is_read: true }));
            this.unreadCount = 0;
            this.updateTabTitle();

            try {
                const response = await fetch(`${baseUrl}/mark-all-read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(await this.responseErrorMessage(response, 'Gagal menandai semua notifikasi sebagai dibaca.'));
                }

                const result = await response.json();
                if (result.success === false) {
                    throw new Error(result.message || 'Gagal menandai semua notifikasi sebagai dibaca.');
                }

                return true;
            } catch (error) {
                console.error('Error marking all notifications as read:', error);
                this.items = previousItems;
                this.unreadCount = previousUnreadCount;
                this.updateTabTitle();
                this.setError(error.message || 'Gagal menandai semua notifikasi sebagai dibaca.');
                return false;
            } finally {
                this.markingAllAsRead = false;
            }
        },

        async fetchUnreadCount() {
            try {
                const baseUrl = this.resolveReadBaseUrl();

                if (!baseUrl) {
                    this.unreadCount = this.items.filter(item => item.is_read !== true).length;
                    this.updateTabTitle();
                    return this.unreadCount;
                }

                const previousCount = Number(this.unreadCount || 0);
                const response = await fetch(`${baseUrl}/unread-count`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(await this.responseErrorMessage(response, 'Gagal memuat jumlah notifikasi.'));
                }

                const data = await response.json();
                const newCount = Number(data.count ?? 0);

                if (newCount > previousCount) {
                    await this.fetchNotifications();
                } else {
                    this.unreadCount = newCount;
                }

                this.updateTabTitle();
                return newCount;
            } catch (error) {
                console.error('Error fetching unread count:', error);
                this.setError(error.message || 'Gagal memuat jumlah notifikasi.');
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

        badgeClass(item) {
            const source = item.source || item.category;

            if (item.category === 'nilai' || source === 'guru') {
                return 'bg-green-100 text-green-700 border-green-200';
            }

            if (source === 'wali_kelas') {
                return 'bg-indigo-100 text-indigo-700 border-indigo-200';
            }

            if (item.category === 'rapor' || item.category === 'template') {
                return 'bg-blue-100 text-blue-700 border-blue-200';
            }

            if (item.category === 'tahun_ajaran') {
                return 'bg-amber-100 text-amber-700 border-amber-200';
            }

            return 'bg-gray-100 text-gray-700 border-gray-200';
        },

        itemCardClass(item) {
            return item.is_read
                ? 'border-gray-200'
                : 'border-gray-200 border-l-4 border-l-green-600';
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
                this.setError(error.message || 'Gagal menambahkan notifikasi.');
                return false;
            }
        },

        async deleteNotification(id) {
            const deleteKey = String(id);
            let acquiredDeleteLock = false;
            const previousItems = this.items.map(item => ({ ...item }));
            const previousUnreadCount = Number(this.unreadCount || 0);

            try {
                if (this.deletingNotificationIds.has(deleteKey)) {
                    return true;
                }

                const baseUrl = this.resolveDeleteBaseUrl();

                if (!baseUrl) {
                    return false;
                }

                this.deletingNotificationIds.add(deleteKey);
                acquiredDeleteLock = true;
                this.clearError();

                const deletedItem = this.items.find(item => String(item.id) === deleteKey);
                this.items = this.items.filter(item => String(item.id) !== deleteKey);

                if (deletedItem && deletedItem.is_read !== true) {
                    this.unreadCount = Math.max(0, previousUnreadCount - 1);
                    this.updateTabTitle();
                }

                const response = await fetch(`${baseUrl}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(await this.responseErrorMessage(response, 'Gagal menghapus notifikasi.'));
                }

                const result = await response.json();
                if (result.success === false) {
                    throw new Error(result.message || 'Gagal menghapus notifikasi.');
                }

                return true;
            } catch (error) {
                console.error('Error deleting notification:', error);
                this.items = previousItems;
                this.unreadCount = previousUnreadCount;
                this.updateTabTitle();
                this.setError(error.message || 'Gagal menghapus notifikasi.');
                return false;
            } finally {
                if (acquiredDeleteLock) {
                    this.deletingNotificationIds.delete(deleteKey);
                }
            }
        },

        async deleteAllNotifications() {
            if (this.deletingAllNotifications) {
                return true;
            }

            const message = this.isAdminContext()
                ? 'Hapus semua notifikasi secara global? Tindakan ini tidak dapat dibatalkan.'
                : 'Hapus semua notifikasi Anda? Tindakan ini tidak dapat dibatalkan.';

            if (!window.confirm(message)) {
                return false;
            }

            const baseUrl = this.resolveDeleteBaseUrl();

            if (!baseUrl) {
                return false;
            }

            const previousItems = this.items.map(item => ({ ...item }));
            const previousUnreadCount = Number(this.unreadCount || 0);
            this.deletingAllNotifications = true;
            this.clearError();
            this.items = [];
            this.unreadCount = 0;
            this.updateTabTitle();

            try {
                const response = await fetch(`${baseUrl}/delete-all`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(await this.responseErrorMessage(response, 'Gagal menghapus semua notifikasi.'));
                }

                const result = await response.json();
                if (result.success === false) {
                    throw new Error(result.message || 'Gagal menghapus semua notifikasi.');
                }

                return true;
            } catch (error) {
                console.error('Error deleting all notifications:', error);
                this.items = previousItems;
                this.unreadCount = previousUnreadCount;
                this.updateTabTitle();
                this.setError(error.message || 'Gagal menghapus semua notifikasi.');
                return false;
            } finally {
                this.deletingAllNotifications = false;
            }
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            if (!this.resolveReadBaseUrl()) {
                return;
            }

            this.refreshInterval = setInterval(() => {
                this.fetchUnreadCount();
            }, 60000);
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
