export function initEditClassPage() {
    var pageRoot = document.querySelector('[data-page="edit-class"]');
    if (!pageRoot) return;

    var form = document.querySelector('form');
    var nomorKelasInput = document.getElementById('nomor_kelas');
    var changeWaliKelasCheckbox = document.getElementById('change_wali_kelas');
    var newWaliKelasContainer = document.getElementById('new_wali_kelas_container');

    if (!form || form.dataset.editClassBound === 'true') return;
    form.dataset.editClassBound = 'true';

    form.addEventListener('submit', function (event) {
        var hasError = false;

        form.querySelectorAll('[required]').forEach(field => {
            if (field.value.trim()) {
                field.classList.remove('border-red-500');
                field.parentElement.querySelector('.error-message')?.remove();
                return;
            }

            hasError = true;
            field.classList.add('border-red-500');
            var errorDiv = field.parentElement.querySelector('.error-message') || document.createElement('p');
            errorDiv.className = 'error-message text-red-500 text-xs mt-1';
            errorDiv.textContent = `${field.getAttribute('placeholder') || field.getAttribute('name')} wajib diisi`;
            if (!errorDiv.parentElement) field.parentElement.appendChild(errorDiv);
        });

        var waliKelasIdSelect = document.getElementById('wali_kelas_id');
        var currentWaliKelasInput = document.getElementById('current_wali_kelas_id');
        if (changeWaliKelasCheckbox?.checked && waliKelasIdSelect && !waliKelasIdSelect.value) {
            hasError = true;
            waliKelasIdSelect.classList.add('border-red-500');
            var waliError = waliKelasIdSelect.parentElement.querySelector('.error-message') || document.createElement('p');
            waliError.className = 'error-message text-red-500 text-xs mt-1';
            waliError.textContent = 'Pilih wali kelas baru';
            if (!waliError.parentElement) waliKelasIdSelect.parentElement.appendChild(waliError);
        }

        if (
            !hasError &&
            changeWaliKelasCheckbox?.checked &&
            waliKelasIdSelect &&
            currentWaliKelasInput &&
            waliKelasIdSelect.value &&
            waliKelasIdSelect.value === currentWaliKelasInput.value
        ) {
            event.preventDefault();
            Swal.fire({
                icon: 'info',
                title: 'Tidak Ada Perubahan',
                text: 'Wali kelas yang dipilih sama dengan sebelumnya. Silakan pilih guru yang berbeda atau simpan tanpa mengubah wali kelas.',
                confirmButtonText: 'OK',
            });
            return;
        }

        if (hasError) {
            event.preventDefault();
            form.querySelector('.border-red-500')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    nomorKelasInput?.addEventListener('input', function () {
        if (this.value.length > 0) this.value = parseInt(this.value.replace(/^0+/, ''), 10) || '';
        if (this.value === '0') this.value = '';
        if (parseInt(this.value, 10) > 99) this.value = '99';
    });

    if (changeWaliKelasCheckbox && newWaliKelasContainer) {
        changeWaliKelasCheckbox.addEventListener('change', function () {
            if (this.checked) {
                newWaliKelasContainer.classList.remove('hidden');
                return;
            }

            newWaliKelasContainer.classList.add('hidden');
            var waliKelasSelect = document.getElementById('wali_kelas_id');
            if (waliKelasSelect) {
                waliKelasSelect.value = '';
            }
        });
    }
}
