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
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 transform translate-y-3"
        x-transition:enter-end="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 transform translate-y-3"
        class="absolute bottom-16 right-0 w-[min(24rem,calc(100vw-2rem))] bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden"
        style="display: none; max-height: min(34rem, calc(100vh - 7rem));"
        role="dialog"
        aria-label="Pusat Bantuan Rapor Digital"
    >
        <div class="flex items-start justify-between gap-4 p-4 bg-green-700 text-white">
            <div>
                <h3 class="font-semibold text-base">Pusat Bantuan</h3>
                <p class="text-green-100 text-xs mt-1">Cari bantuan singkat atau buka panduan lengkap.</p>
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
            <label for="help-center-search" class="sr-only">Cari bantuan singkat</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"></path>
                </svg>
                <input
                    id="help-center-search"
                    type="search"
                    x-model.debounce.250ms="searchQuery"
                    placeholder="Cari bantuan singkat..."
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

        <div class="p-4 overflow-y-auto bg-gray-50" style="max-height: 21rem;">
            <div x-show="isLoading" class="flex items-center justify-center py-8 text-sm text-gray-500">
                <svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 0 1 4 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Memuat panduan...
            </div>

            <div x-show="error" class="mb-3 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="error"></div>

            <div x-show="!isLoading && displayedTopics().length === 0" class="py-8 text-center">
                <p class="text-sm font-medium text-gray-700">Bantuan belum ditemukan.</p>
                <p class="text-xs text-gray-500 mt-1">Coba kata kunci lain atau buka panduan lengkap.</p>
            </div>

            <div x-show="!isLoading && displayedTopics().length > 0" class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bantuan cepat</p>

                <template x-for="(topic, index) in displayedTopics()" :key="topicKey(topic, index)">
                    <article class="border border-gray-200 rounded-lg bg-white overflow-hidden">
                        <button
                            type="button"
                            @click="toggleTopic(topic, index)"
                            class="w-full flex items-start justify-between gap-3 px-3 py-2.5 text-left hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500"
                            :aria-expanded="isTopicOpen(topic, index)"
                        >
                            <span class="text-sm font-medium text-gray-800" x-text="topic.question"></span>
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0 transition-transform" :class="{ 'rotate-180': isTopicOpen(topic, index) }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <div x-show="isTopicOpen(topic, index)" class="px-3 pb-3 text-sm text-gray-700 leading-relaxed border-t border-gray-100">
                            <p class="pt-3" x-html="formatText(topic.answer)"></p>
                        </div>
                    </article>
                </template>
            </div>
        </div>

        <div class="p-4 border-t border-gray-100 bg-white">
            <a
                :href="fullHelpUrl"
                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-green-700 hover:bg-green-800 text-white text-sm font-medium focus:outline-none focus:ring-4 focus:ring-green-300"
            >
                Buka Pusat Bantuan Lengkap
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
    </div>
</div>
