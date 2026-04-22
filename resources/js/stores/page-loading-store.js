import Alpine from 'alpinejs';

export function registerPageLoadingStore() {
    Alpine.store('pageTransition', {
        isLoading: false,
        startLoading() {
            this.isLoading = true;
        },
        stopLoading() {
            this.isLoading = false;
        },
    });

    Alpine.store('pageLoading', {
        isLoading: false,
        elementStates: {},
        startLoading() {
            this.isLoading = true;
        },
        stopLoading() {
            this.isLoading = false;
        },
        markComponentLoaded(id) {
            this.elementStates[id] = true;
        },
        isComponentLoaded(id) {
            return this.elementStates[id] || false;
        },
    });
}
