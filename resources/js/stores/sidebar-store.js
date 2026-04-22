import Alpine from 'alpinejs';

export function registerSidebarStore() {
    document.addEventListener('alpine:init', () => {
        Alpine.store('sidebar', {
            dropdownState: {},

            toggleDropdown(name) {
                this.dropdownState[name] = !this.dropdownState[name];
                localStorage.setItem(`dropdown_${name}`, this.dropdownState[name]);
            },

            initDropdown(name) {
                const savedState = localStorage.getItem(`dropdown_${name}`);
                if (savedState !== null) {
                    this.dropdownState[name] = savedState === 'true';
                }
            },
        });
    });
}
