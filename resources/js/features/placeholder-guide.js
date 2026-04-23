import Alpine from 'alpinejs';

export function registerPlaceholderGuide() {
    Alpine.data('placeholderGuide', () => ({
        placeholderSearch: '',
        activeCategory: 'siswa',
        placeholders: {},

        init() {
            const rawPlaceholders = this.$el.dataset.placeholders || '{}';
            try {
                this.placeholders = JSON.parse(rawPlaceholders);
            } catch (error) {
                console.error('Failed to parse placeholder data:', error);
                this.placeholders = {};
            }
        },

        get filteredPlaceholders() {
            const search = this.placeholderSearch.toLowerCase();
            return Object.entries(this.placeholders).reduce((acc, [category, items]) => {
                const filtered = items.filter(item =>
                    item.key.toLowerCase().includes(search) ||
                    item.description.toLowerCase().includes(search)
                );
                if (filtered.length > 0) {
                    acc[category] = filtered;
                }
                return acc;
            }, {});
        },

        getCategoryLabel(category) {
            const labels = {
                siswa: 'Data Siswa',
                nilai: 'Nilai Akademik',
                ekskul: 'Ekstrakurikuler',
                lainnya: 'Data Lainnya'
            };
            return labels[category] || category;
        },

        async copyPlaceholder(text) {
            try {
                await navigator.clipboard.writeText(text);
                this.$dispatch('show-notification', {
                    type: 'success',
                    message: 'Placeholder berhasil disalin'
                });
            } catch (err) {
                this.$dispatch('show-notification', {
                    type: 'error',
                    message: 'Gagal menyalin placeholder'
                });
            }
        }
    }));
}
