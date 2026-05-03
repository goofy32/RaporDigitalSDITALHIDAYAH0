function sanitizeNumbers(input, maxLength) {
    if (!input) return;

    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length > maxLength) {
        input.value = input.value.slice(0, maxLength);
    }
}

function sanitizeLetters(input, maxLength) {
    if (!input) return;

    input.value = input.value.replace(/[^a-zA-Z\s]/g, '');
    if (input.value.length > maxLength) {
        input.value = input.value.slice(0, maxLength);
    }
}

function updateRequiredFieldState(field) {
    if (!field || !field.classList.contains('required-field')) return;

    if (!field.value.trim()) {
        field.classList.add('empty');
        return;
    }

    field.classList.remove('empty');
}

function renderPhotoPreview(preview, file) {
    preview.innerHTML = '';

    if (!file) return true;

    if (file.size > 2 * 1024 * 1024) {
        preview.innerHTML = '<p class="text-red-500 text-sm">Ukuran file terlalu besar. Maksimal 2MB.</p>';
        return false;
    }

    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        preview.innerHTML = '<p class="text-red-500 text-sm">Format file tidak sesuai. Gunakan JPG/JPEG/PNG.</p>';
        return false;
    }

    var previewContainer = document.createElement('div');
    previewContainer.className = 'mt-4 relative';

    var img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    img.className = 'max-w-xs rounded-lg shadow-sm';
    img.style.maxHeight = '200px';

    previewContainer.appendChild(img);
    preview.appendChild(previewContainer);

    img.onload = function () {
        URL.revokeObjectURL(this.src);
    };

    return true;
}

export function bindStudentSanitizers(root) {
    var nisInput = root.querySelector('#nis');
    var nisnInput = root.querySelector('#nisn');
    var namaInput = root.querySelector('#nama');

    if (nisInput && nisInput.dataset.studentBound !== 'true') {
        nisInput.addEventListener('input', function () {
            sanitizeNumbers(this, 10);
            updateRequiredFieldState(this);
        });
        nisInput.addEventListener('blur', function () {
            updateRequiredFieldState(this);
        });
        nisInput.dataset.studentBound = 'true';
    }

    if (nisnInput && nisnInput.dataset.studentBound !== 'true') {
        nisnInput.addEventListener('input', function () {
            sanitizeNumbers(this, 10);
            updateRequiredFieldState(this);
        });
        nisnInput.addEventListener('blur', function () {
            updateRequiredFieldState(this);
        });
        nisnInput.dataset.studentBound = 'true';
    }

    if (namaInput && namaInput.dataset.studentBound !== 'true') {
        namaInput.addEventListener('input', function () {
            sanitizeLetters(this, 255);
            updateRequiredFieldState(this);
        });
        namaInput.addEventListener('blur', function () {
            updateRequiredFieldState(this);
        });
        namaInput.dataset.studentBound = 'true';
    }
}

export function bindStudentRequiredIndicators(root) {
    root.querySelectorAll('.required-field').forEach(field => {
        if (field.dataset.requiredBound === 'true') return;

        updateRequiredFieldState(field);
        field.addEventListener('input', function () {
            updateRequiredFieldState(this);
        });
        field.addEventListener('blur', function () {
            updateRequiredFieldState(this);
        });
        field.dataset.requiredBound = 'true';
    });
}

export function bindStudentPhotoPreview(root) {
    var photoInput = root.querySelector('#photo');
    var preview = root.querySelector('#photo-preview');

    if (!photoInput || !preview || photoInput.dataset.previewBound === 'true') return;

    photoInput.addEventListener('change', function () {
        var file = this.files?.[0];
        if (!renderPhotoPreview(preview, file)) {
            this.value = '';
        }
    });

    photoInput.dataset.previewBound = 'true';
}

export function bindStudentSubmitValidation(form, options = {}) {
    if (!form || form.dataset.validationBound === 'true') return;

    form.addEventListener('submit', function (event) {
        var requireAllFields = options.requireAllFields === true;
        var hasError = false;
        var nis = form.querySelector('#nis')?.value || '';
        var nisn = form.querySelector('#nisn')?.value || '';

        if (requireAllFields) {
            form.querySelectorAll('.required-field').forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('empty');
                    hasError = true;
                }
            });
        }

        if (hasError) {
            event.preventDefault();
            alert('Semua field yang bertanda (*) wajib diisi!');
            return false;
        }

        if (nis.length < 5) {
            event.preventDefault();
            alert('NIS harus minimal 5 digit!');
            return false;
        }

        if (nisn.length < 10) {
            event.preventDefault();
            alert('NISN harus 10 digit!');
            return false;
        }

        return true;
    });

    form.dataset.validationBound = 'true';
}
