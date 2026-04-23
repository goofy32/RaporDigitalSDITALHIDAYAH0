import Alpine from 'alpinejs';

export function registerAnalisisNilaiStore() {
    Alpine.store('analisisNilai', {
        toggleAllDetails(value) {
            return value;
        }
    });
}
