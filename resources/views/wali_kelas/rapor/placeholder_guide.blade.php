<!-- resources/views/admin/report/placeholder_guide.blade.php -->
<div class="p-6 max-h-[80vh] overflow-y-auto" x-data="placeholderGuide" data-placeholders='@json($placeholders)'>
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-lg font-semibold">Panduan Placeholder Rapor</h3>
        <button @click="closePlaceholderGuide()" class="text-gray-400 hover:text-gray-500">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Search Box -->
    <div class="mb-4">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <input type="search" 
                   x-model="placeholderSearch"
                   class="block w-full p-2 pl-10 text-sm text-gray-900 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" 
                   placeholder="Cari placeholder...">
        </div>
    </div>

    <!-- Category Tabs -->
    <div class="mb-4 border-b border-gray-200">
        <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
            <li class="mr-2" role="presentation">
                <button @click="activeCategory = 'siswa'"
                        :class="{'text-blue-600 border-blue-600': activeCategory === 'siswa'}"
                        class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300">
                    Data Siswa
                </button>
            </li>
            <li class="mr-2" role="presentation">
                <button @click="activeCategory = 'nilai'"
                        :class="{'text-blue-600 border-blue-600': activeCategory === 'nilai'}"
                        class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300">
                    Nilai Akademik
                </button>
            </li>
            <li class="mr-2" role="presentation">
                <button @click="activeCategory = 'ekskul'"
                        :class="{'text-blue-600 border-blue-600': activeCategory === 'ekskul'}"
                        class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300">
                    Ekstrakurikuler
                </button>
            </li>
            <li role="presentation">
                <button @click="activeCategory = 'lainnya'"
                        :class="{'text-blue-600 border-blue-600': activeCategory === 'lainnya'}"
                        class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300">
                    Lainnya
                </button>
            </li>
        </ul>
    </div>

    <!-- Placeholder Lists -->
    <div class="space-y-4">
        <template x-for="(placeholders, category) in filteredPlaceholders" :key="category">
            <div x-show="activeCategory === category">
                <h4 class="text-lg font-medium mb-2" x-text="getCategoryLabel(category)"></h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <template x-for="placeholder in placeholders" :key="placeholder.key">
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-mono text-sm text-blue-600" x-text="placeholder.key"></p>
                                    <p class="text-sm text-gray-600 mt-1" x-text="placeholder.description"></p>
                                </div>
                                <button @click="copyPlaceholder(placeholder.key)"
                                        class="text-gray-400 hover:text-gray-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                </button>
                            </div>
                            <div x-show="placeholder.is_required" 
                                 class="mt-2">
                                <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    Wajib
                                </span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>

