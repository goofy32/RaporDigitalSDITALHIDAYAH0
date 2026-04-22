import Alpine from 'alpinejs';

export function registerKeyboardStore() {
    document.addEventListener('alpine:init', () => {
        Alpine.store('keyboardShortcut', {
            logoutKeyCombination: { key: 'l', ctrlKey: true, altKey: false },

            init() {
                document.addEventListener('keydown', event => {
                    if (
                        event.key === this.logoutKeyCombination.key &&
                        event.ctrlKey === this.logoutKeyCombination.ctrlKey &&
                        event.altKey === this.logoutKeyCombination.altKey
                    ) {
                        event.preventDefault();
                        this.confirmLogout();
                    }
                });
            },

            confirmLogout() {
                const confirmed = confirm('Apakah Anda yakin ingin logout?');
                if (confirmed) this.logout();
            },

            async logout() {
                try {
                    const response = await fetch('/logout', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });

                    if (response.ok) {
                        window.location.href = '/login';
                    } else {
                        console.error('Logout gagal');
                        alert('Gagal logout. Silakan coba lagi.');
                    }
                } catch (error) {
                    console.error('Error logout:', error);
                    alert('Terjadi kesalahan saat logout');
                }
            },
        });

        Alpine.store('keyboardShortcut').init();
    });
}
