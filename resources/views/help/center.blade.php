@extends($layout)

@section('title', 'Pusat Bantuan')

@section('content')
@php
    $topicSearchIndex = collect($topics)->map(fn (array $topic) => [
        'category' => $topic['category'],
        'text' => implode(' ', [
            $topic['category'],
            $topic['question'],
            $topic['answer'],
            implode(' ', $topic['keywords'] ?? []),
        ]),
    ])->values();
@endphp

<div
    class="max-w-7xl mx-auto"
    x-data="{
        search: '',
        category: @js($categories[0] ?? ''),
        open: null,
        topics: @js($topicSearchIndex),
        normalize(value) {
            return (value || '').toString().toLowerCase().replace(/\s+/g, ' ').trim();
        },
        matchesSearch(text) {
            const query = this.normalize(this.search);

            if (!query) {
                return true;
            }

            const searchableText = this.normalize(text);

            return query.split(' ').every(keyword => searchableText.includes(keyword));
        },
        visible(topicCategory, text) {
            const query = this.normalize(this.search);
            const matchesCategory = query || !this.category || topicCategory === this.category;

            return matchesCategory && this.matchesSearch(text);
        },
        visibleCount() {
            return this.topics.filter(topic => this.visible(topic.category, topic.text)).length;
        }
    }"
>
    <div class="mb-6 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Pusat Bantuan</h1>
            <p class="mt-1 text-sm text-gray-600">
                Panduan penggunaan Rapor Digital untuk {{ $roleLabel }}. Gunakan pencarian atau pilih kategori bantuan.
            </p>
        </div>

        <div class="w-full lg:w-96">
            <label for="help-page-search" class="sr-only">Cari bantuan</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"></path>
                </svg>
                <input
                    id="help-page-search"
                    type="search"
                    x-model.debounce.250ms="search"
                    @input="open = null"
                    class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"
                    placeholder="Cari login, nilai, rapor, email..."
                >
            </div>
            <p x-show="search" x-cloak class="mt-1 text-xs text-gray-500" aria-live="polite">
                <span x-text="visibleCount()"></span> topik ditemukan di semua kategori
            </p>
        </div>
    </div>

    <div class="mb-4 lg:hidden">
        <label for="help-mobile-category" class="mb-1 block text-sm font-medium text-gray-700">Kategori</label>
        <select
            id="help-mobile-category"
            x-model="category"
            @change="open = null"
            :disabled="Boolean(search)"
            class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500 disabled:bg-gray-100 disabled:text-gray-500"
        >
            <option value="">Semua topik</option>
            @foreach($categories as $category)
                <option value="{{ $category }}">{{ $category }}</option>
            @endforeach
        </select>
        <p x-show="search" x-cloak class="mt-1 text-xs text-gray-500">Pencarian mencakup semua kategori.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-6">
        <aside class="hidden lg:block bg-white border border-gray-200 rounded-lg p-3 h-fit lg:sticky lg:top-20">
            <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Kategori</p>
            <div class="space-y-1">
                <button
                    type="button"
                    @click="category = ''; open = null"
                    class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors"
                    :class="category === '' ? 'bg-green-100 text-green-800' : 'text-gray-600 hover:bg-gray-50 hover:text-green-700'"
                >
                    Semua topik
                </button>
                @foreach($categories as $category)
                    <button
                        type="button"
                        @click="category = @js($category); open = null"
                        class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors"
                        :class="category === @js($category) ? 'bg-green-100 text-green-800' : 'text-gray-600 hover:bg-gray-50 hover:text-green-700'"
                    >
                        {{ $category }}
                    </button>
                @endforeach
            </div>
        </aside>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3 px-1">
                <h2 class="text-sm font-semibold text-gray-700" x-text="search ? 'Hasil pencarian' : (category || 'Semua topik')"></h2>
                <span class="text-xs text-gray-500"><span x-text="visibleCount()"></span> topik</span>
            </div>

            @foreach($topics as $index => $topic)
                @php
                    $searchText = implode(' ', [
                        $topic['category'],
                        $topic['question'],
                        $topic['answer'],
                        implode(' ', $topic['keywords'] ?? []),
                    ]);
                @endphp

                <article
                    id="help-{{ \Illuminate\Support\Str::slug($topic['question']) }}-{{ $index }}"
                    class="bg-white border border-gray-200 rounded-lg overflow-hidden"
                    x-show="visible(@js($topic['category']), @js($searchText))"
                    data-help-topic
                >
                    <button
                        type="button"
                        @click="open = open === {{ $index }} ? null : {{ $index }}"
                        class="w-full flex items-start justify-between gap-4 px-5 py-4 text-left hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500"
                        :aria-expanded="open === {{ $index }}"
                    >
                        <span>
                            <span class="block text-base font-semibold text-gray-900">{{ $topic['question'] }}</span>
                            <span class="inline-flex mt-2 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ $topic['category'] }}</span>
                        </span>
                        <svg class="w-5 h-5 mt-1 text-gray-400 shrink-0 transition-transform" :class="{ 'rotate-180': open === {{ $index }} }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="open === {{ $index }}" class="px-5 pb-5 border-t border-gray-100">
                        <p class="pt-4 text-sm leading-7 text-gray-700">{!! nl2br(e($topic['answer'])) !!}</p>
                    </div>
                </article>
            @endforeach

            <div
                x-show="visibleCount() === 0"
                x-cloak
                class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center"
                role="status"
            >
                <p class="font-medium text-gray-800">Topik bantuan tidak ditemukan</p>
                <p class="mt-1 text-sm text-gray-500">Coba kata yang lebih singkat, misalnya password, nilai, rapor, DOCX, atau email.</p>
                <button
                    type="button"
                    @click="search = ''; category = ''; open = null"
                    class="mt-4 text-sm font-medium text-green-700 hover:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 rounded"
                >
                    Tampilkan semua topik
                </button>
            </div>
        </section>
    </div>
</div>
@endsection
