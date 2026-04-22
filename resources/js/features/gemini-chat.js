import Alpine from 'alpinejs';

export function registerGeminiChat() {
    Alpine.data('geminiChat', () => ({
        isOpen: false,
        message: '',
        chats: [],
        isLoading: false,
        error: null,
        suggestions: [],
        showSuggestions: true,

        init() {
            this.loadHistory();
            this.showWelcomeMessage();
        },

        get knowledgeBaseLoaded() {
            return this.$store.gemini.knowledgeBaseLoaded;
        },

        get apiKeyExists() {
            return this.$store.gemini.apiKeyExists;
        },

        showWelcomeMessage() {
            if (this.chats.length === 0) {
                this.chats.push({
                    message: 'Selamat datang!',
                    response: 'Halo! Saya adalah asisten AI untuk Sistem RAPOR SDIT Al-Hidayah. Saya dapat membantu Anda dengan:\n\nâ€¢ Panduan login dan navigasi sistem\nâ€¢ Setup awal dan konfigurasi\nâ€¢ Troubleshooting masalah umum\nâ€¢ Workflow penggunaan sehari-hari\nâ€¢ Tips dan best practices\n\nSilakan tanyakan apa yang ingin Anda ketahui tentang sistem ini!',
                    created_at: new Date().toISOString(),
                    is_welcome: true,
                });
            }
        },

        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.loadHistory();
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        async loadHistory() {
            try {
                const response = await fetch('/admin/gemini/history', {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);

                const data = await response.json();

                if (data.success) {
                    const historyChats = data.chats.filter(chat => !chat.is_welcome);
                    if (this.chats.length <= 1) {
                        this.chats = [...this.chats, ...historyChats.reverse()];
                    }
                } else {
                    console.error('History load failed:', data.message);
                    this.error = data.message || 'Gagal memuat riwayat chat';
                }
            } catch (error) {
                console.error('Error loading chat history:', error);
                this.error = `Gagal memuat riwayat chat: ${error.message}`;
            }
        },

        async sendMessage(messageText = null) {
            let finalMessage = messageText;
            if (finalMessage === null || finalMessage === undefined) finalMessage = this.message;
            finalMessage = String(finalMessage || '');

            if (!finalMessage.trim()) return;

            this.message = '';
            this.isLoading = true;
            this.showSuggestions = false;
            this.error = null;

            this.chats.push({
                message: finalMessage,
                response: 'Mengetik...',
                created_at: new Date().toISOString(),
                is_sending: true,
            });

            this.scrollToBottom();

            try {
                const response = await fetch('/admin/gemini/send-message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ message: finalMessage }),
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);

                const data = await response.json();
                const lastChatIndex = this.chats.length - 1;

                if (data.success) {
                    this.chats[lastChatIndex].response = data.response;
                    this.chats[lastChatIndex].id = data.chat?.id;
                    this.chats[lastChatIndex].is_sending = false;
                } else {
                    this.chats[lastChatIndex].response = `Error: ${data.message || 'Terjadi kesalahan saat memproses pesan'}`;
                    this.chats[lastChatIndex].is_error = true;
                    this.chats[lastChatIndex].is_sending = false;
                }
            } catch (error) {
                console.error('Error sending message:', error);
                const lastChatIndex = this.chats.length - 1;
                this.chats[lastChatIndex].response = `Connection Error: ${error.message}. Pastikan koneksi internet stabil dan coba lagi.`;
                this.chats[lastChatIndex].is_error = true;
                this.chats[lastChatIndex].is_sending = false;
                this.error = error.message;
            } finally {
                this.isLoading = false;
                this.scrollToBottom();
            }
        },

        handleFormSubmit(event) {
            event.preventDefault();
            const messageInput = event.target.querySelector('input[type="text"]');
            if (messageInput) this.sendMessage(messageInput.value);
            else this.sendMessage();
        },

        useSuggestion(suggestion) {
            const safeMessage = String(suggestion || '');
            if (safeMessage.trim()) this.sendMessage(safeMessage);
        },

        clearChat() {
            if (confirm('Apakah Anda yakin ingin menghapus riwayat chat?')) {
                this.chats = [];
                this.showWelcomeMessage();
                this.showSuggestions = true;
                this.error = null;
            }
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const chatContainer = this.$refs.chatContainer;
                if (chatContainer) chatContainer.scrollTop = chatContainer.scrollHeight;
            });
        },

        getRelativeTime(dateString) {
            return this.$store.helpers.getRelativeTime(dateString);
        },

        formatTimestamp(dateString) {
            return this.$store.helpers.formatTimestamp(dateString);
        },

        formatResponse(text) {
            return this.$store.helpers.formatResponseText(text);
        },

        copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => this.showToast('Teks berhasil disalin')).catch(() => this.showToast('Gagal menyalin teks'));
        },

        showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed bottom-4 left-4 bg-green-600 text-white px-4 py-2 rounded shadow-lg z-50 transition-opacity';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 2500);
        },
    }));
}
