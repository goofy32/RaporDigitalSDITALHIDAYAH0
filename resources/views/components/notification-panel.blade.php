@props([
    'canCreate' => false,
])

@php
    $notificationFilters = [
        'all' => 'Semua',
        'unread' => 'Belum dibaca',
        'read' => 'Sudah dibaca',
        'admin' => 'Admin',
        'guru' => 'Guru/Pengajar',
        'wali_kelas' => 'Wali Kelas',
        'sistem' => 'Sistem',
        'nilai' => 'Nilai',
        'rapor' => 'Rapor',
        'template' => 'Template',
        'tahun_ajaran' => 'Tahun Ajaran',
    ];
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <button
            type="button"
            @click="$store.notification.openModal()"
            class="relative inline-flex w-full items-center justify-center gap-2 rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800 focus:outline-none focus:ring-4 focus:ring-green-300 sm:w-auto"
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

        <div class="flex items-center justify-end gap-2">
            <button
                type="button"
                @click="$store.notification.markAllAsRead()"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
            >
                Tandai semua dibaca
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
    </div>

    <p class="mt-2 text-xs text-gray-500">Buka panel informasi untuk membaca notifikasi lengkap, memfilter, dan mengelola notifikasi Anda.</p>

    <div class="mt-4 space-y-2">
        <template x-for="item in $store.notification.previewItems" :key="`preview-${item.id}`">
            <button
                type="button"
                @click="$store.notification.openModal(); $store.notification.markAsRead(item.id)"
                class="w-full rounded-lg border p-3 text-left transition hover:bg-gray-50"
                :class="$store.notification.itemCardClass(item)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <span
                            class="mb-1 inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium"
                            :class="$store.notification.badgeClass(item)"
                            x-text="item.category_label"
                        ></span>
                        <h3 class="truncate text-sm font-semibold text-gray-900" x-text="item.title"></h3>
                        <p class="line-clamp-2 text-xs text-gray-600" x-text="item.content"></p>
                    </div>
                    <span class="shrink-0 text-[11px] text-gray-400" x-text="item.created_at"></span>
                </div>
            </button>
        </template>

        <template x-if="$store.notification.previewItems.length === 0">
            <div class="rounded-lg border border-dashed border-gray-200 p-4 text-center text-sm text-gray-500">
                Tidak ada informasi baru
            </div>
        </template>
    </div>
</div>

<div
    x-cloak
    x-show="$store.notification.showModal"
    x-transition.opacity
    class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="notification-panel-title"
>
    <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">
        <div
            x-show="$store.notification.showModal"
            x-transition
            @click.outside="$store.notification.closeModal()"
            class="w-full overflow-hidden rounded-xl bg-white shadow-xl"
        >
            <div class="border-b border-gray-200 p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 id="notification-panel-title" class="text-xl font-semibold text-gray-900">Informasi</h2>
                        <p class="mt-1 text-sm text-gray-500">Kelola informasi dari admin, guru, wali kelas, dan sistem.</p>
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

                <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap gap-2">
                        @foreach($notificationFilters as $filterKey => $filterLabel)
                            <button
                                type="button"
                                @click="$store.notification.setFilter('{{ $filterKey }}')"
                                class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                :class="$store.notification.activeFilter === '{{ $filterKey }}' ? 'border-green-700 bg-green-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                            >
                                {{ $filterLabel }}
                            </button>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="$store.notification.markAllAsRead()"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Tandai semua dibaca
                        </button>
                        <button
                            type="button"
                            @click="$store.notification.deleteAllOwn()"
                            class="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                        >
                            Hapus semua
                        </button>
                    </div>
                </div>
            </div>

            <div class="max-h-[70vh] overflow-y-auto p-4 sm:p-5">
                <template x-if="$store.notification.loading">
                    <div class="rounded-lg border border-gray-200 p-4 text-sm text-gray-500">Memuat notifikasi...</div>
                </template>

                <div class="space-y-3">
                    <template x-for="item in $store.notification.filteredItems" :key="item.id">
                        <article
                            class="rounded-lg border p-4 transition"
                            :class="$store.notification.itemCardClass(item)"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span
                                            class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium"
                                            :class="$store.notification.badgeClass(item)"
                                            x-text="item.category_label"
                                        ></span>
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600" x-text="item.source_label"></span>
                                        <span x-show="item.is_read !== true" class="rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">Belum dibaca</span>
                                    </div>
                                    <h3 class="text-base font-semibold text-gray-900" x-text="item.title"></h3>
                                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700" x-text="item.content"></p>
                                    <p class="mt-3 text-xs text-gray-500">
                                        <span x-text="item.created_at_formatted"></span>
                                        <span x-show="item.target_display"> &middot; Untuk: <span x-text="item.target_display"></span></span>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    x-show="item.is_read !== true"
                                    @click="$store.notification.markAsRead(item.id)"
                                    class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Tandai dibaca
                                </button>
                            </div>
                        </article>
                    </template>
                </div>

                <template x-if="$store.notification.filteredItems.length === 0 && !$store.notification.loading">
                    <div class="rounded-lg border border-dashed border-gray-200 p-8 text-center">
                        <p class="text-sm font-medium text-gray-700">Tidak ada notifikasi pada filter ini.</p>
                        <p class="mt-1 text-xs text-gray-500">Pilih filter lain atau reset ke Semua.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
