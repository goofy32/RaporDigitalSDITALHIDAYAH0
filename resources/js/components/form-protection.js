import Alpine from 'alpinejs';

export function registerFormProtectionComponent() {
    Alpine.data('formProtection', () => ({
        init() {
            if (!this.$el.tagName === 'FORM') return;

            this.$el.querySelectorAll('input, select, textarea').forEach(element => {
                element.addEventListener('change', () => {
                    this.$store.formProtection.markAsChanged();
                });
                element.addEventListener('keyup', () => {
                    this.$store.formProtection.markAsChanged();
                });
            });

            this.$el.addEventListener('submit', () => {
                this.$store.formProtection.startSubmitting();
            });
        },

        handleSubmit(e) {
            if (this.$store.formProtection.formChanged) {
                if (!confirm('Apakah Anda yakin ingin menyimpan perubahan?')) {
                    e.preventDefault();
                    return;
                }
            }
            this.$store.formProtection.startSubmitting();
        },

        confirmClear() {
            if (this.$store.formProtection.formChanged) {
                return confirm('Apakah Anda yakin ingin membersihkan form? Perubahan yang belum disimpan akan hilang.');
            }
            return true;
        },
    }));
}
