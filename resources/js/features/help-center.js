import Alpine from 'alpinejs';

let helpCenterRegistered = false;

export function registerHelpCenter() {
    if (helpCenterRegistered) {
        return;
    }

    helpCenterRegistered = true;

    Alpine.data('helpCenter', () => ({
        isOpen: false,
        searchQuery: '',
        topics: [],
        openTopicKey: '',
        isLoading: false,
        error: '',
        preferredQuestions: [
            'Apa bedanya UTS dan UAS di aplikasi?',
            'Kenapa PDF lama disiapkan?',
            'Kenapa nilai tidak muncul di rapor?',
            'Kenapa template rapor tidak bisa digunakan?',
            'Kenapa tombol download template nilai tidak aktif?',
            'Error umum saat upload nilai Excel',
            'Upload Nilai Excel dan preview',
            'Data Siswa dan import Excel',
            'Notifikasi untuk Admin',
            'Notifikasi untuk Wali Kelas',
        ],

        init() {
            this.loadTopics();
        },

        togglePanel() {
            this.isOpen = !this.isOpen;

            if (this.isOpen && this.topics.length === 0) {
                this.loadTopics();
            }
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

        async loadTopics() {
            const data = await this.fetchFaq({ all: '1' });
            this.topics = data.results || [];
        },

        async fetchFaq(params = {}) {
            this.error = '';
            this.isLoading = true;

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
                });

                if (!response.ok) {
                    throw new Error('Panduan belum dapat dimuat.');
                }

                return await response.json();
            } catch (error) {
                this.error = error.message || 'Panduan belum dapat dimuat.';

                return {
                    results: [],
                };
            } finally {
                this.isLoading = false;
            }
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
            const haystack = this.normalize([
                topic.category,
                topic.question,
                topic.answer,
                (topic.keywords || []).join(' '),
            ].join(' '));

            return haystack.includes(query);
        },

        hasSearch() {
            return this.searchQuery.trim().length > 0;
        },

        clearSearch() {
            this.searchQuery = '';
            this.openTopicKey = '';
        },

        topicKey(topic, index) {
            return `${topic.category}-${topic.question}-${index}`;
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
