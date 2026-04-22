import Alpine from 'alpinejs';

export function registerGeminiStore() {
    document.addEventListener('alpine:init', () => {
        Alpine.store('gemini', {
            knowledgeBaseLoaded: false,
            apiKeyExists: false,
            connectionTested: false,

            async checkStatus() {
                try {
                    const response = await fetch('/admin/gemini/test-knowledge', {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.ok) {
                        const data = await response.json();
                        this.knowledgeBaseLoaded = data.file_exists || false;
                        this.apiKeyExists = data.api_key_exists || false;
                        this.connectionTested = true;
                    }
                } catch (error) {
                    console.error('Failed to check Gemini status:', error);
                    this.connectionTested = true;
                }
            },
        });

        Alpine.store('gemini').checkStatus();
    });
}
