import Alpine from 'alpinejs';

export function registerContentLoadingStore() {
    Alpine.store('contentLoading', {
        isLoading: false,

        startLoading() {
            this.isLoading = true;
            document.getElementById('content-loading-overlay')?.classList.add('active');
        },

        stopLoading() {
            this.isLoading = false;
            document.getElementById('content-loading-overlay')?.classList.remove('active');
        }
    });
}
