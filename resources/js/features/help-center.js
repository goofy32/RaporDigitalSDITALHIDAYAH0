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
        categories: [],
        activeCategory: '',
        openTopicKey: '',
        suggestions: [],
        isLoading: false,
        error: '',

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

        async loadTopics() {
            const data = await this.fetchFaq({ all: '1' });

            this.topics = data.results || [];
            this.categories = data.categories || [...new Set(this.topics.map((topic) => topic.category).filter(Boolean))];
            this.suggestions = data.suggested_questions || [];
            this.activeCategory = this.categories[0] || '';
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
                    categories: [],
                    results: [],
                    suggested_questions: [],
                };
            } finally {
                this.isLoading = false;
            }
        },

        selectCategory(category) {
            this.activeCategory = category;
            this.openTopicKey = '';
        },

        filteredTopics() {
            const query = this.normalize(this.searchQuery);

            return this.topics.filter((topic) => {
                const matchesCategory = !this.activeCategory || topic.category === this.activeCategory;

                if (!matchesCategory) {
                    return false;
                }

                if (!query) {
                    return true;
                }

                const haystack = this.normalize([
                    topic.category,
                    topic.question,
                    topic.answer,
                    (topic.keywords || []).join(' '),
                ].join(' '));

                return haystack.includes(query);
            });
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
