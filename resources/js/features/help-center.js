import Alpine from 'alpinejs';

let helpCenterRegistered = false;

export function registerHelpCenter() {
    if (helpCenterRegistered) {
        return;
    }

    helpCenterRegistered = true;

    Alpine.data('helpCenter', () => ({
        isOpen: false,
        message: '',
        messages: [],
        suggestions: [],
        isLoading: false,
        error: '',

        init() {
            this.loadSuggestions();
        },

        togglePanel() {
            this.isOpen = !this.isOpen;

            if (this.isOpen && this.suggestions.length === 0) {
                this.loadSuggestions();
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

        async loadSuggestions() {
            await this.fetchFaq();
        },

        async sendMessage() {
            const query = this.message.trim();

            if (!query || this.isLoading) {
                return;
            }

            this.messages.push({ role: 'user', content: query });
            this.message = '';

            const data = await this.fetchFaq({ q: query });
            this.addResponse(data);
        },

        async selectSuggestion(question) {
            if (!question || this.isLoading) {
                return;
            }

            this.messages.push({ role: 'user', content: question });

            const data = await this.fetchFaq({ question });
            this.addResponse(data);
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

                const data = await response.json();
                this.suggestions = data.suggested_questions || [];

                return data;
            } catch (error) {
                this.error = error.message || 'Panduan belum dapat dimuat.';

                return {
                    results: [],
                    answer: null,
                };
            } finally {
                this.isLoading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        addResponse(data) {
            const results = data.results || [];

            if (data.answer) {
                this.messages.push({
                    role: 'assistant',
                    content: data.answer,
                });
                return;
            }

            if (results.length > 0) {
                this.messages.push({
                    role: 'assistant',
                    content: results
                        .map((item) => `${item.question}\n${item.answer}`)
                        .join('\n\n'),
                });
                return;
            }

            this.messages.push({
                role: 'assistant',
                content: 'Panduan yang cocok belum ditemukan. Coba gunakan kata kunci lain atau pilih pertanyaan yang tersedia.',
            });
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

        scrollToBottom() {
            if (!this.$refs.chatContainer) {
                return;
            }

            this.$refs.chatContainer.scrollTop = this.$refs.chatContainer.scrollHeight;
        },
    }));
}
