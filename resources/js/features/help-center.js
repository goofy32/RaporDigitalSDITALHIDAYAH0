import Alpine from 'alpinejs';

const FAQ_CACHE_TTL_MS = 10 * 60 * 1000;
const FAQ_LOAD_ERROR_MESSAGE = 'FAQ belum dapat dimuat. Silakan coba lagi.';
const helpCenterInstances = new Set();
let helpCenterRegistered = false;
let helpCenterLifecycleListenersBound = false;

function ensureHelpCenterLifecycleListeners() {
    if (helpCenterLifecycleListenersBound) {
        return;
    }

    helpCenterLifecycleListenersBound = true;
    document.addEventListener('turbo:before-cache', () => {
        Array.from(helpCenterInstances).forEach(instance => {
            if (!instance.$el?.isConnected) {
                instance.destroy?.();
                return;
            }

            instance.prepareForCache?.();
        });
    });
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

export function registerHelpCenter() {
    if (helpCenterRegistered) {
        return;
    }

    helpCenterRegistered = true;

    Alpine.data('helpCenter', () => ({
        isOpen: false,
        initialized: false,
        destroyed: false,
        searchQuery: '',
        topics: [],
        openTopicKey: '',
        isLoading: false,
        error: '',
        faqPromise: null,
        faqAbortController: null,
        faqLoadGeneration: 0,
        faqLoaded: false,
        faqLoadError: false,
        faqLoadedAt: null,
        pagePath: window.location.pathname,
        preferredQuestions: [
            'Mulai cepat untuk Admin',
            'Masuk dengan Username atau Email',
            'Menggunakan Lupa password?',
            'Memverifikasi email Guru',
            'Input Nilai manual dan cara menyimpan',
            'Siswa yang terlihat pada kelas Wali',
            'Perbedaan UTS, UAS, Ganjil, dan Genap',
            'Nilai yang dipakai pada Rapor UTS',
            'Unduh Semua Rapor dalam ZIP',
            'Kapan pilihan PDF aplikasi tersedia?',
            'Melanjutkan Semester Ganjil ke Genap',
            'Import Siswa dari Excel',
        ],

        init() {
            if (this.initialized) {
                return;
            }

            this.initialized = true;
            ensureHelpCenterLifecycleListeners();
            helpCenterInstances.add(this);
        },

        togglePanel() {
            this.isOpen = !this.isOpen;

            if (this.isOpen) {
                this.loadTopics();
            }
        },

        destroy() {
            if (this.destroyed) {
                return;
            }

            this.destroyed = true;
            this.invalidateActiveFaqLoad();
            helpCenterInstances.delete(this);
        },

        prepareForCache() {
            this.isOpen = false;
            this.error = '';
            this.isLoading = false;
            this.openTopicKey = '';
            this.invalidateActiveFaqLoad();
            this.clearFaqCache();
        },

        get endpoint() {
            const path = window.location.pathname;

            if (path.startsWith('/pengajar')) {
                return '/pengajar/help/faq';
            }

            if (path.startsWith('/wali-kelas')) {
                return '/wali-kelas/help/faq';
            }

            return '/admin/help/faq';
        },

        get fullHelpUrl() {
            const path = window.location.pathname;

            if (path.startsWith('/pengajar')) {
                return '/pengajar/help';
            }

            if (path.startsWith('/wali-kelas')) {
                return '/wali-kelas/help';
            }

            return '/admin/help';
        },

        isComponentCurrent() {
            return !this.destroyed
                && this.$el?.isConnected
                && document.body.contains(this.$el)
                && window.location.pathname === this.pagePath;
        },

        isCurrentFaqRequest(generation, controller) {
            return this.isComponentCurrent()
                && this.faqLoadGeneration === generation
                && this.faqAbortController === controller
                && !controller.signal.aborted;
        },

        isFaqCacheFresh() {
            if (!this.faqLoaded || !this.faqLoadedAt) {
                return false;
            }

            return Date.now() - this.faqLoadedAt < FAQ_CACHE_TTL_MS;
        },

        invalidateActiveFaqLoad() {
            this.faqLoadGeneration += 1;
            this.faqAbortController?.abort();
            this.faqAbortController = null;
            this.faqPromise = null;
            this.isLoading = false;
        },

        clearFaqCache() {
            this.faqLoaded = false;
            this.faqLoadedAt = null;
            this.faqLoadError = false;
            this.topics = [];
        },

        async loadTopics({ force = false } = {}) {
            if (force) {
                this.invalidateActiveFaqLoad();
                this.clearFaqCache();
            }

            if (!force && this.isFaqCacheFresh()) {
                return Promise.resolve(true);
            }

            if (this.faqPromise) {
                return this.faqPromise;
            }

            const controller = new AbortController();
            const generation = this.faqLoadGeneration + 1;
            this.faqLoadGeneration = generation;
            this.faqAbortController = controller;
            this.isLoading = true;
            this.error = '';
            this.faqLoadError = false;

            this.faqPromise = this.fetchFaq({ all: '1' }, { generation, controller })
                .then(data => {
                    if (!this.isCurrentFaqRequest(generation, controller)) {
                        return false;
                    }

                    if (!this.isValidFaqPayload(data)) {
                        throw new Error(FAQ_LOAD_ERROR_MESSAGE);
                    }

                    this.topics = this.normalizeFaqTopics(data.results);
                    this.faqLoaded = true;
                    this.faqLoadedAt = Date.now();
                    this.faqLoadError = false;

                    return true;
                })
                .catch(error => {
                    if (!this.isCurrentFaqRequest(generation, controller)) {
                        return false;
                    }

                    if (isAbortError(error)) {
                        return false;
                    }

                    this.faqLoaded = false;
                    this.faqLoadedAt = null;
                    this.faqLoadError = true;
                    this.error = FAQ_LOAD_ERROR_MESSAGE;

                    return false;
                })
                .finally(() => {
                    if (this.isCurrentFaqRequest(generation, controller)) {
                        this.isLoading = false;
                        this.faqAbortController = null;
                        this.faqPromise = null;
                    }
                });

            return this.faqPromise;
        },

        retryTopics() {
            return this.loadTopics({ force: true });
        },

        async fetchFaq(params = {}, options = {}) {
            try {
                const url = new URL(this.endpoint, window.location.origin);

                Object.entries(params).forEach(([key, value]) => {
                    if (value) {
                        url.searchParams.set(key, value);
                    }
                });

                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: options.controller?.signal,
                });

                if (!response.ok) {
                    throw new Error(FAQ_LOAD_ERROR_MESSAGE);
                }

                return await response.json();
            } catch (error) {
                if (isAbortError(error)) {
                    throw error;
                }

                throw new Error(FAQ_LOAD_ERROR_MESSAGE);
            }
        },

        isValidFaqPayload(data) {
            return data
                && typeof data === 'object'
                && !Array.isArray(data)
                && Array.isArray(data.results);
        },

        normalizeFaqTopics(results) {
            return results.filter(topic => topic && typeof topic === 'object' && !Array.isArray(topic));
        },

        displayedTopics() {
            const query = this.normalize(this.searchQuery);

            if (query) {
                return this.topics
                    .filter((topic) => this.matchesQuery(topic, query))
                    .slice(0, 8);
            }

            const preferred = this.preferredQuestions
                .map((question) => this.topics.find((topic) => topic.question === question))
                .filter(Boolean);

            const fallback = this.topics.filter((topic) => !preferred.includes(topic));

            return [...preferred, ...fallback].slice(0, 8);
        },

        matchesQuery(topic, query) {
            if (!topic || typeof topic !== 'object') {
                return false;
            }

            const keywords = Array.isArray(topic.keywords) ? topic.keywords.join(' ') : '';
            const haystack = this.normalize([
                topic.category,
                topic.question,
                topic.answer,
                keywords,
            ].join(' '));

            return query.split(' ').every(keyword => haystack.includes(keyword));
        },

        hasSearch() {
            return this.searchQuery.trim().length > 0;
        },

        clearSearch() {
            this.searchQuery = '';
            this.openTopicKey = '';
        },

        topicKey(topic, index) {
            return `${topic?.category || 'faq'}-${topic?.question || 'topic'}-${index}`;
        },

        toggleTopic(topic, index) {
            const key = this.topicKey(topic, index);
            this.openTopicKey = this.openTopicKey === key ? '' : key;
        },

        isTopicOpen(topic, index) {
            return this.openTopicKey === this.topicKey(topic, index);
        },

        formatText(text) {
            const escaped = this.escapeHtml(text || '');

            return escaped.replace(/\n/g, '<br>');
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;

            return div.innerHTML;
        },

        normalize(value) {
            return (value || '').toString().toLowerCase().replace(/\s+/g, ' ').trim();
        },
    }));
}
