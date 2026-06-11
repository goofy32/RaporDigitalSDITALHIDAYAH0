<div x-data="helpCenter" class="fixed bottom-4 right-4 sm:right-4 sm:left-auto z-50 help-center-container" x-cloak>
    <button
        @click="togglePanel()"
        class="bg-green-600 hover:bg-green-700 text-white p-3 rounded-full shadow-xl transition-all duration-200 hover:scale-105 focus:outline-none focus:ring-4 focus:ring-green-300"
        title="Pusat Bantuan Rapor Digital"
    >
        <svg x-show="!isOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 10h.01M12 10h.01M16 10h.01M9 16h6m-8 4h10a4 4 0 004-4V8a4 4 0 00-4-4H7a4 4 0 00-4 4v8a4 4 0 004 4z"></path>
        </svg>
        <svg x-show="isOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </button>

    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95 transform translate-y-4"
        x-transition:enter-end="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 transform translate-y-4"
        class="absolute bottom-16 right-0 w-96 sm:w-96 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden"
        style="display: none; max-height: 32rem;"
    >
        <div class="flex items-center justify-between p-4 bg-gradient-to-r from-green-600 to-green-700 text-white">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093M12 17h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-semibold text-lg">Pusat Bantuan Rapor Digital</h3>
                    <p class="text-green-100 text-xs">Panduan penggunaan sistem</p>
                </div>
            </div>

            <button
                @click="isOpen = false"
                class="text-white hover:text-green-200 transition-colors p-1 rounded-full hover:bg-white hover:bg-opacity-10"
                title="Tutup"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div x-ref="chatContainer" class="p-4 overflow-y-auto bg-gray-50" style="height: 20rem; max-height: 20rem;">
            <div x-show="messages.length === 0 && !isLoading" class="space-y-4">
                <div class="bg-white text-gray-800 px-4 py-3 rounded-xl rounded-bl-md shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-800">Halo!</p>
                    <p class="text-xs text-gray-600 mt-1">
                        Pilih pertanyaan yang tersedia atau cari panduan singkat tentang penggunaan Rapor Digital.
                    </p>
                </div>

                <div class="space-y-2">
                    <p class="text-xs text-gray-500 font-medium px-1">Pertanyaan yang sering dicari:</p>
                    <template x-for="suggestion in suggestions.slice(0, 5)" :key="suggestion">
                        <button
                            @click="selectSuggestion(suggestion)"
                            class="w-full text-left bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 px-3 py-2 rounded-lg text-sm text-gray-700 hover:text-green-700 transition-colors"
                        >
                            <span x-text="suggestion"></span>
                        </button>
                    </template>
                </div>
            </div>

            <template x-for="(message, index) in messages" :key="index">
                <div class="mb-4">
                    <div x-show="message.role === 'user'" class="flex justify-end">
                        <div class="bg-green-600 text-white px-4 py-2 rounded-xl rounded-br-md max-w-xs lg:max-w-sm shadow-sm">
                            <p class="text-sm" x-text="message.content"></p>
                        </div>
                    </div>

                    <div x-show="message.role === 'assistant'" class="flex justify-start">
                        <div
                            class="bg-white text-gray-800 px-4 py-2 rounded-xl rounded-bl-md max-w-xs lg:max-w-sm shadow-sm border border-gray-100"
                            :class="{ 'bg-red-50 border-red-200 text-red-700': message.isError }"
                        >
                            <p class="text-sm" x-html="formatText(message.content)"></p>
                        </div>
                    </div>
                </div>
            </template>

            <div x-show="isLoading" class="flex justify-start mb-4">
                <div class="bg-white px-4 py-2 rounded-xl rounded-bl-md shadow-sm border border-gray-100">
                    <div class="flex items-center text-gray-500">
                        <svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 0 1 4 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm">Memuat panduan...</span>
                    </div>
                </div>
            </div>

            <div x-show="!isLoading && messages.length > 0 && suggestions.length > 0" class="flex flex-wrap gap-2 mt-2">
                <template x-for="suggestion in suggestions.slice(0, 3)" :key="suggestion">
                    <button
                        @click="selectSuggestion(suggestion)"
                        class="text-xs bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 px-3 py-1.5 rounded-full text-gray-700 hover:text-green-700 transition-colors"
                    >
                        <span x-text="suggestion.length > 32 ? suggestion.substring(0, 32) + '...' : suggestion"></span>
                    </button>
                </template>
            </div>
        </div>

        <div class="p-4 border-t bg-white">
            <form @submit.prevent="sendMessage()" class="flex space-x-2">
                <div class="flex-1 relative">
                    <input
                        type="text"
                        x-model="message"
                        placeholder="Cari bantuan..."
                        :disabled="isLoading"
                        class="w-full px-4 py-2.5 pr-12 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent disabled:bg-gray-100 disabled:text-gray-500 text-sm"
                        maxlength="160"
                    >

                    <div
                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-xs text-gray-400"
                        x-text="message.length + '/160'"
                    ></div>
                </div>

                <button
                    type="submit"
                    :disabled="isLoading || !message.trim()"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-4 py-2.5 rounded-xl transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    title="Cari"
                >
                    <svg x-show="!isLoading" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"></path>
                    </svg>

                    <svg x-show="isLoading" class="w-5 h-5 animate-spin" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 0 1 4 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>
            </form>

            <div x-show="error" class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-4 h-4 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-red-700 text-sm font-medium" x-text="error"></p>
                </div>
            </div>
        </div>
    </div>
</div>
