import Alpine from 'alpinejs';

export function registerGeminiChatDebug() {
    Alpine.data('geminiChatDebug', () => ({
        isOpen: false,
        message: '',
        chats: [],
        isLoading: false,
        error: '',
        showSuggestions: true,
        suggestions: [],
        showHistoryMenu: false,
        loadingCounter: 0,
        loadingMessage: 'AI sedang menganalisis nilai...',
        loadingInterval: null,
        loadingMessages: [
            'AI sedang menganalisis nilai...',
            'Memproses data akademik...',
            'Menghitung statistik...',
            'Menganalisis performa siswa...',
            'Menyiapkan rekomendasi...',
            'Mengkompilasi laporan...',
            'Memvalidasi hasil analisis...',
            'Finalisasi respons...'
        ],

        init() {
            console.log('Alpine chat component initialized');
            this.suggestions = this.getUserRoleBasedSuggestions();
            this.loadHistory();
            this.$watch('message', (value) => {
                this.showSuggestions = value.length === 0;
            });
        },

        startLoadingCounter() {
            this.loadingCounter = 1;
            this.loadingMessage = this.loadingMessages[0];
            let messageIndex = 0;

            this.loadingInterval = setInterval(() => {
                this.loadingCounter++;

                if (this.loadingCounter % 3 === 0) {
                    messageIndex = (messageIndex + 1) % this.loadingMessages.length;
                    this.loadingMessage = this.loadingMessages[messageIndex];
                }

                if (this.loadingCounter > 15) {
                    this.loadingMessage = 'Memproses analisis kompleks...';
                }
                if (this.loadingCounter > 25) {
                    this.loadingMessage = 'AI sedang berpikir keras...';
                }
                if (this.loadingCounter > 35) {
                    this.loadingMessage = 'Hampir selesai...';
                }
            }, 1000);
        },

        stopLoadingCounter() {
            if (this.loadingInterval) {
                clearInterval(this.loadingInterval);
                this.loadingInterval = null;
            }
            this.loadingCounter = 0;
        },

        getUserRoleBasedSuggestions() {
            const currentPath = window.location.pathname;

            if (currentPath.startsWith('/admin')) {
                return [
                    'Berikan overview lengkap sistem akademik',
                    'Siswa mana yang belum diisi nilainya?',
                    'Mata pelajaran apa yang paling sulit bagi siswa?',
                    'Guru mana yang belum menyelesaikan input nilai?',
                    'Berapa rata-rata nilai akademik keseluruhan?',
                    'Bagaimana progress kesiapan rapor seluruh sekolah?',
                    'Kelas mana yang performanya paling baik?',
                    'Analisis nilai tertinggi dan terendah',
                    'Guru mana yang sudah selesai input nilai?',
                    'Berapa persen kelengkapan data akademik?'
                ];
            } else if (currentPath.startsWith('/pengajar')) {
                return [
                    'Analisis mata pelajaran yang saya ajar',
                    'Siswa mana yang nilainya masih di bawah KKM?',
                    'Progress input nilai saya berapa persen?',
                    'Siswa mana yang belum saya isi nilainya?',
                    'Bagaimana performa kelas yang saya ajar?',
                    'Mata pelajaran mana yang paling sulit untuk siswa?',
                    'Siapa siswa terbaik di mata pelajaran saya?',
                    'Berapa rata-rata nilai mata pelajaran saya?',
                    'Trend perkembangan nilai siswa',
                    'Rekomendasi untuk meningkatkan hasil belajar'
                ];
            } else if (currentPath.startsWith('/wali-kelas')) {
                return [
                    'Overview lengkap performa kelas saya',
                    'Siswa mana yang perlu bimbingan khusus?',
                    'Progress kelengkapan nilai di kelas saya',
                    'Guru mana yang belum input nilai di kelas saya?',
                    'Berapa siswa yang sudah siap rapor?',
                    'Mata pelajaran apa yang perlu fokus tambahan?',
                    'Perbandingan kelas saya dengan kelas lain',
                    'Siswa mana yang paling berprestasi di kelas?',
                    'Analisis kesiapan data rapor kelas saya',
                    'Rekomendasi untuk meningkatkan performa kelas'
                ];
            }

            return [
                'Bagaimana cara menggunakan sistem ini?',
                'Apa yang bisa saya tanyakan tentang nilai?',
                'Panduan lengkap sistem rapor'
            ];
        },

        getApiEndpoint() {
            const currentPath = window.location.pathname;

            if (currentPath.startsWith('/admin')) {
                return '/admin/gemini/send-message';
            } else if (currentPath.startsWith('/pengajar')) {
                return '/pengajar/gemini/send-message';
            } else if (currentPath.startsWith('/wali-kelas')) {
                return '/wali-kelas/gemini/send-message';
            }

            return '/admin/gemini/send-message';
        },

        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.$nextTick(() => {
                    this.scrollToBottom();
                });
            }
        },

        handleClearHistory() {
            console.log('Clear history called');
            this.clearAllHistory();
        },

        handleDeleteChat(index) {
            console.log('Delete chat called for index:', index);
            this.deleteSpecificChat(index);
        },

        handleResetConversation() {
            console.log('Reset conversation called');
            this.resetConversation();
        },

        async clearAllHistory() {
            console.log('clearAllHistory method called');

            if (!confirm('Apakah Anda yakin ingin menghapus semua riwayat chat? Tindakan ini tidak dapat dibatalkan.')) {
                return;
            }

            try {
                const apiEndpoint = this.getApiEndpoint().replace('/send-message', '/clear-history');

                const response = await fetch(apiEndpoint, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    this.chats = [];
                    this.showHistoryMenu = false;
                    this.showSuggestions = true;
                    console.log('History cleared successfully');
                } else {
                    alert('Gagal menghapus riwayat: ' + data.message);
                }
            } catch (error) {
                console.error('Error clearing history:', error);
                alert('Terjadi kesalahan saat menghapus riwayat');
            }
        },

        async deleteSpecificChat(chatIndex) {
            console.log('deleteSpecificChat method called for index:', chatIndex);

            if (!confirm('Hapus chat ini?')) {
                return;
            }

            try {
                const chat = this.chats[chatIndex];
                if (!chat.id) {
                    this.chats.splice(chatIndex, 1);
                    return;
                }

                const apiEndpoint = this.getApiEndpoint().replace('/send-message', '/chat/' + chat.id);

                const response = await fetch(apiEndpoint, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    this.chats.splice(chatIndex, 1);
                    console.log('Chat deleted successfully');
                } else {
                    alert('Gagal menghapus chat: ' + data.message);
                }
            } catch (error) {
                console.error('Error deleting chat:', error);
                alert('Terjadi kesalahan saat menghapus chat');
            }
        },

        async sendMessage() {
            if (!this.message.trim() || this.isLoading) return;

            const userMessage = this.message.trim();
            this.message = '';
            this.error = '';
            this.isLoading = true;
            this.showSuggestions = false;
            this.startLoadingCounter();

            this.chats.push({
                message: userMessage,
                response: 'AI sedang memproses... Mohon tunggu sebentar.',
                created_at: new Date().toISOString(),
                is_sending: true
            });

            this.scrollToBottom();

            try {
                const apiEndpoint = this.getApiEndpoint();

                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        message: userMessage
                    })
                });

                const data = await response.json();

                if (data.success) {
                    this.chats[this.chats.length - 1].response = data.response;
                    this.chats[this.chats.length - 1].is_sending = false;
                    this.chats[this.chats.length - 1].id = data.chat?.id;

                    if (data.fallback) {
                        this.chats[this.chats.length - 1].response += '\n\n*Catatan: Response dari sistem fallback*';
                    }
                } else {
                    this.chats[this.chats.length - 1].response = data.message || 'Terjadi kesalahan saat memproses permintaan';
                    this.chats[this.chats.length - 1].is_error = true;
                    this.chats[this.chats.length - 1].is_sending = false;
                }
            } catch (error) {
                console.error('Chat error:', error);
                this.chats[this.chats.length - 1].response = 'Koneksi gagal. Silakan coba lagi dalam beberapa menit.';
                this.chats[this.chats.length - 1].is_error = true;
                this.chats[this.chats.length - 1].is_sending = false;
            } finally {
                this.isLoading = false;
                this.stopLoadingCounter();
                this.scrollToBottom();
            }
        },

        useSuggestion(suggestion) {
            this.message = suggestion;
            this.showSuggestions = false;
            this.sendMessage();
        },

        async loadHistory() {
            try {
                const apiEndpoint = this.getApiEndpoint().replace('/send-message', '/history');
                const response = await fetch(apiEndpoint);
                const data = await response.json();

                if (data.success) {
                    this.chats = data.chats.reverse();
                }
            } catch (error) {
                console.error('Failed to load chat history:', error);
            }
        },

        formatResponse(response) {
            if (!response) return '';

            let formatted = response.replace(/\n/g, '<br>');
            formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            formatted = formatted.replace(/\*(.*?)\*/g, '<em>$1</em>');

            return formatted;
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const container = this.$refs.chatContainer;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },

        toggleHistoryMenu() {
            this.showHistoryMenu = !this.showHistoryMenu;
        },

        async resetConversation() {
            if (!confirm('Reset seluruh konteks percakapan? AI akan kehilangan memori tentang percakapan sebelumnya dan memulai dari awal.')) {
                return;
            }

            try {
                const apiEndpoint = this.getApiEndpoint().replace('/send-message', '/clear-history');

                const response = await fetch(apiEndpoint, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    this.chats = [];
                    this.showSuggestions = true;
                    this.showHistoryMenu = false;

                    setTimeout(() => {
                        this.chats.push({
                            message: 'Reset konteks percakapan',
                            response: 'Konteks percakapan telah direset. Saya siap memulai percakapan baru dengan konteks yang segar. Silakan tanyakan apa yang Anda butuhkan!',
                            created_at: new Date().toISOString(),
                            is_system: true,
                            id: 'system_' + Date.now()
                        });
                        this.scrollToBottom();
                    }, 300);

                    console.log('Conversation context reset successfully');
                } else {
                    alert('Gagal mereset konteks: ' + data.message);
                }
            } catch (error) {
                console.error('Error resetting conversation:', error);
                alert('Terjadi kesalahan saat mereset konteks percakapan');
            }
        },

        getQuickSuggestions() {
            const currentPath = window.location.pathname;

            if (this.chats.length > 0) {
                const lastChat = this.chats[this.chats.length - 1];

                if (lastChat.message.toLowerCase().includes('nilai')) {
                    return [
                        'Lanjutkan analisis yang lebih detail',
                        'Bagaimana cara meningkatkan nilai tersebut?',
                        'Bandingkan dengan periode sebelumnya'
                    ];
                } else if (lastChat.message.toLowerCase().includes('siswa')) {
                    return [
                        'Analisis siswa lainnya yang serupa',
                        'Rekomendasi tindak lanjut untuk siswa ini',
                        'Perbandingan dengan siswa terbaik'
                    ];
                } else if (lastChat.message.toLowerCase().includes('guru')) {
                    return [
                        'Progress guru lainnya',
                        'Analisis efektivitas mengajar',
                        'Rekomendasi improvement untuk guru'
                    ];
                }
            }

            return this.getUserRoleBasedSuggestions();
        }
    }));
}
