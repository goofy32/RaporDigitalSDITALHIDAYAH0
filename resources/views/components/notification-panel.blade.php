@props([
    'canCreate' => false,
])

<div data-notification-dashboard-preview class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between gap-3">
        <button
            data-testid="notification-open-button"
            type="button"
            @click="$store.notification.openModal()"
            class="relative inline-flex items-center justify-center gap-2 rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800 focus:outline-none focus:ring-4 focus:ring-green-300"
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

        @if($canCreate)
            <button
                type="button"
                @click="showModal = true"
                class="rounded-lg bg-green-100 px-3 py-2 text-xs font-medium text-green-800 hover:bg-green-200"
            >
                Tambah
            </button>
        @endif
    </div>

    <div data-testid="notification-dashboard-snippets" class="mt-3 space-y-2">
        <template x-for="item in $store.notification.previewItems" :key="`preview-${item.id}`">
            <button
                type="button"
                @click="$store.notification.openModal()"
                class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left hover:bg-gray-50"
            >
                <span
                    class="mt-1 h-2 w-2 shrink-0 rounded-full"
                    :class="item.is_read ? 'bg-gray-300' : 'bg-green-600'"
                    aria-hidden="true"
                ></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-medium text-gray-800" x-text="item.title"></span>
                    <span class="block truncate text-[11px] text-gray-500" x-text="item.created_at"></span>
                </span>
            </button>
        </template>

        <template x-if="$store.notification.previewItems.length === 0">
            <p class="px-2 py-1 text-xs text-gray-500">Belum ada informasi.</p>
        </template>
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
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Tandai semua dibaca
                        </button>
                        <button
                            type="button"
                            @click="$store.notification.deleteAllOwn()"
                            class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50"
                        >
                            Hapus semua
                        </button>
                    </div>
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
                                        <button
                                            type="button"
                                            x-show="item.is_read !== true"
                                            @click="$store.notification.markAsRead(item.id)"
                                            class="self-start text-xs font-medium text-green-700 hover:text-green-800"
                                        >
                                            Tandai dibaca
                                        </button>
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
