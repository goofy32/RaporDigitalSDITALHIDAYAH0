import Alpine from 'alpinejs';

export function registerNavigationStore() {
    Alpine.store('navigation', {
        imagesLoaded: {},
        markImageLoaded(id) {
            this.imagesLoaded[id] = true;
        },
        isImageLoaded(id) {
            return this.imagesLoaded[id] || false;
        },
    });
}
