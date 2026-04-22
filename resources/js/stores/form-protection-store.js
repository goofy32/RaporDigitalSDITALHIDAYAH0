import Alpine from 'alpinejs';

export function registerFormProtectionStore() {
    Alpine.store('formProtection', {
        formChanged: false,
        isSubmitting: false,

        init() {
            if (this.isLoginPage()) return;

            if (document.querySelector('form[data-needs-protection]')) {
                this.setupFormChangeListeners();
                this.setupNavigationProtection();
            }
        },

        isLoginPage() {
            return window.location.pathname === '/login' || document.querySelector('form[action*="login"]') !== null;
        },

        setupFormChangeListeners() {
            document
                .querySelectorAll('form[data-needs-protection] input, form[data-needs-protection] select, form[data-needs-protection] textarea')
                .forEach(element => {
                    element.addEventListener('change', () => {
                        this.formChanged = true;
                    });
                    element.addEventListener('keyup', () => {
                        this.formChanged = true;
                    });
                });
        },

        setupNavigationProtection() {
            window.addEventListener('beforeunload', e => {
                if (this.formChanged && !this.isSubmitting) {
                    e.preventDefault();
                    e.returnValue = 'Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
                    return e.returnValue;
                }
            });
        },

        markAsChanged() {
            this.formChanged = true;
        },

        startSubmitting() {
            this.isSubmitting = true;
        },

        reset() {
            this.formChanged = false;
            this.isSubmitting = false;
        },
    });
}
