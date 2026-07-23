@props([
    'canCreate' => false,
])

<div data-notification-dashboard-preview>
    <div class="mb-3 flex items-center justify-between">
        <button
            data-testid="notification-open-button"
            type="button"
            @click="$store.notification.openModal()"
            class="relative inline-flex items-center rounded-lg bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-300"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            Informasi
            <span
                x-show="$store.notification.unreadCount > 0"
                x-text="$store.notification.unreadCount > 99 ? '99+' : $store.notification.unreadCount"
                class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs font-bold text-white"
            ></span>
        </button>

        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="$store.notification.toggleHideRead()"
                :title="$store.notification.hideRead ? 'Tampilkan semua' : 'Sembunyikan yang sudah dibaca'"
                class="flex min-h-10 min-w-10 items-center justify-center rounded p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
            >
                <svg x-show="!$store.notification.hideRead" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
                <svg x-show="$store.notification.hideRead" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                </svg>
            </button>

            @if($canCreate)
                <button
                    type="button"
                    @click="showModal = true"
                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-transparent text-sm text-gray-400 hover:bg-gray-200 hover:text-gray-900"
                    title="Tambah informasi"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </button>
            @endif
        </div>
    </div>

    <div data-testid="notification-dashboard-timeline" class="h-[150px] overflow-y-auto notifications-container">
        <div class="relative pl-14">
            <div class="absolute bottom-0 left-5 top-0 w-[2px] bg-gray-200"></div>

            <template x-for="item in $store.notification.dashboardItems" :key="item.id">
                <div
                    class="notification-item relative mb-4 min-h-[80px] cursor-pointer"
                    @click="$store.notification.markAsRead(item.id)"
                >
                    <div class="absolute -left-12 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-gray-200">
                        <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>

                    <div
                        :class="item.is_read ? 'bg-white' : 'bg-green-50'"
                        class="notification-content rounded-lg border p-3 shadow-sm transition-colors"
                    >
                        <div class="flex items-start justify-between">
                            <div class="min-w-0 flex-1 pr-2">
                                @if($canCreate)
                                    <p class="mb-1 truncate text-xs text-gray-500">
                                        <span class="font-medium">Untuk: </span>
                                        <span x-text="item.target_display || item.source_label || '-'"></span>
                                    </p>
                                @endif

                                <div class="mb-1 flex items-center justify-between">
                                    <h3 class="truncate text-sm font-medium text-gray-900" x-text="item.title"></h3>
                                </div>

                                <p class="break-words whitespace-normal text-xs text-gray-600" x-text="item.content"></p>
                                <p class="mt-2 text-xs text-gray-400 @if($canCreate) text-right @endif" x-text="item.created_at_formatted"></p>
                            </div>

                            @if($canCreate)
                                <button
                                    type="button"
                                    @click.stop="$store.notification.deleteNotification(item.id)"
                                    :disabled="$store.notification.deletingNotificationIds.has(String(item.id))"
                                    :class="$store.notification.deletingNotificationIds.has(String(item.id)) ? 'cursor-not-allowed opacity-60' : ''"
                                    class="ml-2 flex-shrink-0 text-red-500 hover:text-red-700"
                                    title="Hapus informasi"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="$store.notification.dashboardItems.length === 0">
                <div class="flex h-[150px] items-center justify-center">
                    <p class="text-sm text-gray-500">Tidak ada informasi baru</p>
                </div>
            </template>
        </div>
    </div>
</div>

<div
    data-testid="notification-modal"
    x-cloak
    x-show="$store.notification.showModal"
    x-transition.opacity
    class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="notification-panel-title"
>
    <div class="mx-auto flex min-h-full max-w-3xl items-center justify-center">
        <div
            x-show="$store.notification.showModal"
            x-transition
            @keydown.escape.window="$store.notification.closeModal()"
            @click.outside="$store.notification.closeModal()"
            class="w-full overflow-hidden rounded-xl bg-white shadow-xl"
        >
            <div class="border-b border-gray-200 p-4 sm:p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="notification-panel-title" class="text-lg font-semibold text-gray-900">Informasi</h2>
                        <p class="mt-1 text-sm text-gray-500">Baca informasi terbaru dan kelola notifikasi Anda.</p>
                    </div>
                    <button
                        type="button"
                        @click="$store.notification.closeModal()"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        aria-label="Tutup panel informasi"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                @click="$store.notification.setStatusFilter('all')"
                                class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                :class="$store.notification.statusFilter === 'all' ? 'border-green-700 bg-green-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                            >
                                Semua
                            </button>
                            <button
                                type="button"
                                @click="$store.notification.setStatusFilter('unread')"
                                class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                :class="$store.notification.statusFilter === 'unread' ? 'border-green-700 bg-green-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                            >
                                Belum dibaca
                            </button>
                            <button
                                type="button"
                                @click="$store.notification.setStatusFilter('read')"
                                class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                :class="$store.notification.statusFilter === 'read' ? 'border-green-700 bg-green-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                            >
                                Sudah dibaca
                            </button>
                        </div>

                        <details class="group rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <summary class="cursor-pointer text-xs font-medium text-gray-700 marker:text-gray-400">
                                Filter lanjutan
                            </summary>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label class="text-xs font-medium text-gray-600">
                                    Sumber
                                    <select
                                        x-model="$store.notification.sourceFilter"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"
                                    >
                                        <option value="all">Semua sumber</option>
                                        <option value="admin">Admin</option>
                                        <option value="guru">Guru/Pengajar</option>
                                        <option value="wali_kelas">Wali Kelas</option>
                                        <option value="sistem">Sistem</option>
                                    </select>
                                </label>
                                <label class="text-xs font-medium text-gray-600">
                                    Kategori
                                    <select
                                        x-model="$store.notification.categoryFilter"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"
                                    >
                                        <option value="all">Semua kategori</option>
                                        <option value="nilai">Nilai</option>
                                        <option value="rapor">Rapor</option>
                                        <option value="template">Template</option>
                                        <option value="tahun_ajaran">Tahun Ajaran</option>
                                        <option value="sistem">Sistem</option>
                                    </select>
                                </label>
                            </div>
                        </details>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="$store.notification.markAllAsRead()"
                            :disabled="$store.notification.markingAllAsRead"
                            :class="$store.notification.markingAllAsRead ? 'cursor-not-allowed opacity-60' : ''"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Tandai semua dibaca
                        </button>
                        <button
                            type="button"
                            @click="$store.notification.deleteAllNotifications()"
                            :disabled="$store.notification.deletingAllNotifications"
                            :class="$store.notification.deletingAllNotifications ? 'cursor-not-allowed opacity-60' : ''"
                            class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50"
                        >
                            Hapus semua
                        </button>
                    </div>
                </div>

                <div
                    x-cloak
                    x-show="$store.notification.errorMessage"
                    class="mt-4 flex items-start justify-between gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                    role="alert"
                >
                    <span x-text="$store.notification.errorMessage"></span>
                    <button
                        type="button"
                        @click="$store.notification.clearError()"
                        class="shrink-0 text-xs font-semibold text-red-700 hover:text-red-900"
                    >
                        Tutup
                    </button>
                </div>
            </div>

            <div class="max-h-[68vh] overflow-y-auto p-4 sm:p-5">
                <template x-if="$store.notification.loading">
                    <div class="rounded-lg border border-gray-200 p-3 text-sm text-gray-500">Memuat notifikasi...</div>
                </template>

                <div class="space-y-2">
                    <template x-for="item in $store.notification.filteredItems" :key="item.id">
                        <article
                            class="rounded-lg border bg-white p-3 transition"
                            :class="$store.notification.itemCardClass(item)"
                        >
                            <div class="flex gap-3">
                                <span
                                    class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
                                    :class="item.is_read ? 'bg-gray-300' : 'bg-green-600'"
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-1.5">
                                        <span
                                            class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                            :class="$store.notification.badgeClass(item)"
                                            x-text="item.category_label"
                                        ></span>
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600" x-text="item.source_label"></span>
                                        <span x-show="item.is_read !== true" class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-700">Baru</span>
                                    </div>
                                    <h3 class="truncate text-sm font-semibold text-gray-900" x-text="item.title"></h3>
                                    <p class="mt-0.5 line-clamp-2 text-sm text-gray-600" x-text="item.content"></p>
                                    <div class="mt-2 flex flex-col gap-2 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between">
                                        <p>
                                            <span x-text="item.created_at_formatted"></span>
                                            <span x-show="item.target_display"> &middot; Untuk: <span x-text="item.target_display"></span></span>
                                        </p>
                                        <div class="flex flex-wrap gap-3">
                                            <button
                                                type="button"
                                                x-show="item.is_read !== true"
                                                @click="$store.notification.markAsRead(item.id)"
                                                :disabled="$store.notification.markingReadIds.has(String(item.id))"
                                                :class="$store.notification.markingReadIds.has(String(item.id)) ? 'cursor-not-allowed opacity-60' : ''"
                                                class="self-start text-xs font-medium text-green-700 hover:text-green-800"
                                            >
                                                Tandai dibaca
                                            </button>
                                            <button
                                                type="button"
                                                @click="$store.notification.deleteNotification(item.id)"
                                                :disabled="$store.notification.deletingNotificationIds.has(String(item.id))"
                                                :class="$store.notification.deletingNotificationIds.has(String(item.id)) ? 'cursor-not-allowed opacity-60' : ''"
                                                class="self-start text-xs font-medium text-red-700 hover:text-red-800"
                                            >
                                                Hapus
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <template x-if="$store.notification.filteredItems.length === 0 && !$store.notification.loading">
                    <div class="rounded-lg border border-dashed border-gray-200 p-8 text-center">
                        <p class="text-sm font-medium text-gray-700">Tidak ada informasi sesuai filter.</p>
                        <p class="mt-1 text-xs text-gray-500">Pilih filter lain atau kosongkan filter lanjutan.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
