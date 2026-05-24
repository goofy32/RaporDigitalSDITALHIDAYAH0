import Alpine from 'alpinejs';

function mountGeminiStore() {
    try {
        if (Alpine.store('gemini')) {
            return;
        }
    } catch (error) {
        // Ignore missing store getter errors and continue registering below.
    }

    Alpine.store('gemini', {
        knowledgeBaseLoaded: false,
        apiKeyExists: false,
        connectionTested: false,

        async checkStatus() {
            const chatbot = document.querySelector('[x-data="geminiChatDebug"]');
            if (!chatbot) {
                this.connectionTested = true;
                return;
            }

            try {
                const response = await fetch('/admin/gemini/test-knowledge', {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                this.knowledgeBaseLoaded = data.file_exists || false;
                this.apiKeyExists = data.api_key_exists || false;
                this.connectionTested = true;
            } catch (error) {
                console.error('Failed to check Gemini status:', error);
                this.connectionTested = true;
            }
        },
    });

    Alpine.store('gemini').checkStatus();
}

export function registerGeminiStore() {
    if (window.Alpine && window.alpineInitialized) {
        mountGeminiStore();
        return;
    }

    document.addEventListener('alpine:init', mountGeminiStore, { once: true });
}
