<div x-data="helpCenter" class="fixed bottom-4 right-4 sm:right-4 sm:left-auto z-50 help-center-container" x-cloak>
    <button
        type="button"
        @click="togglePanel()"
        class="inline-flex items-center gap-2 bg-green-700 hover:bg-green-800 text-white px-4 py-3 rounded-full shadow-xl transition-all duration-200 hover:scale-105 focus:outline-none focus:ring-4 focus:ring-green-300"
        title="Pusat Bantuan Rapor Digital"
        aria-label="Buka Pusat Bantuan Rapor Digital"
    >
        <svg x-show="!isOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093M12 17h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <svg x-show="isOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
        <span class="hidden sm:inline text-sm font-semibold">Pusat Bantuan</span>
    </button>

    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95 transform translate-y-4"
        x-transition:enter-end="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 transform translate-y-4"
        class="absolute bottom-16 right-0 w-[min(44rem,calc(100vw-2rem))] bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden"
        style="display: none; max-height: calc(100vh - 7rem);"
        role="dialog"
        aria-label="Pusat Bantuan Rapor Digital"
    >
        <div class="flex items-start justify-between gap-4 p-4 bg-gradient-to-r from-green-700 to-green-800 text-white">
            <div>
                <h3 class="font-semibold text-lg">Pusat Bantuan Rapor Digital</h3>
                <p class="text-green-100 text-xs mt-1">Panduan singkat untuk Admin, Pengajar, dan Wali Kelas.</p>
            </div>

            <button
                type="button"
                @click="isOpen = false"
                class="text-white hover:text-green-100 transition-colors p-1 rounded-full hover:bg-white hover:bg-opacity-10 focus:outline-none focus:ring-2 focus:ring-white"
                title="Tutup"
                aria-label="Tutup Pusat Bantuan"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="p-4 border-b border-gray-100 bg-white">
            <label for="help-center-search" class="sr-only">Cari bantuan</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"></path>
                </svg>
                <input
                    id="help-center-search"
                    type="search"
                    x-model.debounce.250ms="searchQuery"
                    placeholder="Cari topik bantuan..."
                    class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"
                    maxlength="120"
                >
                <button
                    type="button"
                    x-show="hasSearch()"
                    @click="clearSearch()"
                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600"
                    title="Bersihkan pencarian"
                    aria-label="Bersihkan pencarian"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-[12rem_1fr] max-h-[calc(100vh-17rem)]">
            <aside class="border-b md:border-b-0 md:border-r border-gray-100 bg-gray-50 p-3 overflow-x-auto md:overflow-y-auto">
                <div class="flex md:flex-col gap-2 min-w-max md:min-w-0">
                    <template x-for="category in categories" :key="category">
                        <button
                            type="button"
                            @click="selectCategory(category)"
                            class="text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors whitespace-nowrap md:whitespace-normal"
                            :class="activeCategory === category ? 'bg-green-100 text-green-800' : 'text-gray-600 hover:bg-white hover:text-green-700'"
                        >
                            <span x-text="category"></span>
                        </button>
                    </template>
                </div>
            </aside>

            <section class="bg-white overflow-y-auto p-4">
                <div x-show="isLoading" class="flex items-center justify-center py-10 text-sm text-gray-500">
                    <svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 0 1 4 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Memuat panduan...
                </div>

                <div x-show="error" class="mb-3 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="error"></div>

                <div x-show="!isLoading && filteredTopics().length === 0" class="py-10 text-center">
                    <p class="text-sm font-medium text-gray-700">Topik bantuan belum ditemukan.</p>
                    <p class="text-xs text-gray-500 mt-1">Coba kata kunci lain atau pilih kategori berbeda.</p>
                </div>

                <div x-show="!isLoading && filteredTopics().length > 0" class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-800" x-text="activeCategory || 'Pusat Bantuan'"></p>
                            <p class="text-xs text-gray-500">Buka topik yang dibutuhkan. Tidak semua topik ditampilkan terbuka agar halaman tetap rapi.</p>
                        </div>
                        <span class="text-xs text-gray-500" x-text="filteredTopics().length + ' topik'"></span>
                    </div>

                    <template x-for="(topic, index) in filteredTopics()" :key="topicKey(topic, index)">
                        <article class="border border-gray-200 rounded-lg overflow-hidden bg-white">
                            <button
                                type="button"
                                @click="toggleTopic(topic, index)"
                                class="w-full flex items-start justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500"
                                :aria-expanded="isTopicOpen(topic, index)"
                            >
                                <span>
                                    <span class="block text-sm font-semibold text-gray-800" x-text="topic.question"></span>
                                    <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600" x-text="topic.category"></span>
                                </span>
                                <svg class="w-4 h-4 mt-1 text-gray-400 shrink-0 transition-transform" :class="{ 'rotate-180': isTopicOpen(topic, index) }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>

                            <div x-show="isTopicOpen(topic, index)" class="px-4 pb-4 text-sm text-gray-700 leading-relaxed border-t border-gray-100">
                                <p class="pt-3" x-html="formatText(topic.answer)"></p>
                            </div>
                        </article>
                    </template>
                </div>
            </section>
        </div>
    </div>
</div>
