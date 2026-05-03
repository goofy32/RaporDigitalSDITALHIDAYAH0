function getSubmitButton(formId) {
    return document.querySelector(`button[form="${formId}"][type="submit"]`);
}

export function removeTeacherAlert() {
    document.getElementById('no-kelas-alert')?.remove();
}

export function showTeacherAlert(message) {
    removeTeacherAlert();

    var formHeader = document.querySelector('.flex.justify-between.items-center.mb-6');
    if (!formHeader) return;

    var alertDiv = document.createElement('div');
    alertDiv.id = 'no-kelas-alert';
    alertDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 my-4';
    alertDiv.innerHTML = `
        <div class="flex">
            <div class="ml-3">
                <p class="text-sm"><strong>Error:</strong> ${message}</p>
            </div>
        </div>
    `;

    formHeader.parentNode.insertBefore(alertDiv, formHeader.nextSibling);
}

export function setTeacherSubmitState(formId, disabled, message = '') {
    var submitButton = getSubmitButton(formId);
    if (!submitButton) return;

    submitButton.disabled = disabled;
    submitButton.title = disabled ? message : '';
    submitButton.classList.toggle('opacity-50', disabled);
    submitButton.classList.toggle('cursor-not-allowed', disabled);

    if (disabled && message) {
        showTeacherAlert(message);
    } else {
        removeTeacherAlert();
    }
}

export function ensureTeacherErrorMessage(field, message) {
    var errorDiv = field.parentElement.querySelector('.error-message');
    if (!errorDiv) {
        errorDiv = document.createElement('p');
        errorDiv.className = 'error-message text-red-500 text-xs mt-1';
        field.parentElement.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

export function clearTeacherErrorMessage(field) {
    var errorDiv = field.parentElement.querySelector('.error-message');
    if (errorDiv) {
        errorDiv.textContent = '';
    }
}

export function bindNumericInputs(root) {
    root.querySelectorAll('input[type="number"]').forEach(input => {
        if (input.dataset.numericBound === 'true') return;

        input.addEventListener('keypress', event => {
            if (!/^\d*$/.test(event.key)) {
                event.preventDefault();
            }
        });

        input.addEventListener('paste', event => {
            event.preventDefault();
            var pastedText = (event.clipboardData || window.clipboardData).getData('text');
            input.value = pastedText.replace(/[^\d]/g, '');
        });

        input.dataset.numericBound = 'true';
    });
}

export function bindLengthValidation(field, minLength, maxLength, message) {
    if (!field || field.dataset.lengthBound === 'true') return;

    field.addEventListener('blur', function () {
        var value = this.value.trim();
        if (value && (value.length < minLength || value.length > maxLength)) {
            this.classList.add('border-red-500');
            ensureTeacherErrorMessage(this, message);
            return;
        }

        this.classList.remove('border-red-500');
        clearTeacherErrorMessage(this);
    });

    field.dataset.lengthBound = 'true';
}

export function validateTeacherFile(input) {
    var file = input.files?.[0];
    if (!file) return true;

    var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    var maxSize = 2 * 1024 * 1024;

    if (!allowedTypes.includes(file.type)) {
        alert('Format file harus JPG, JPEG, atau PNG');
        input.value = '';
        return false;
    }

    if (file.size > maxSize) {
        alert('Ukuran file maksimal 2MB');
        input.value = '';
        return false;
    }

    return true;
}

export function bindTeacherFileValidation(root) {
    var photoInput = root.querySelector('input[type="file"]');
    if (!photoInput || photoInput.dataset.fileBound === 'true') return;

    photoInput.addEventListener('change', function () {
        validateTeacherFile(this);
    });

    photoInput.dataset.fileBound = 'true';
}

export function validateTeacherRequiredFields(form, extraValidation) {
    var hasError = false;

    form.querySelectorAll('[required]').forEach(field => {
        var isEmpty = field.multiple
            ? field.selectedOptions.length === 0
            : !field.value.trim();

        if (!isEmpty) return;

        hasError = true;
        field.classList.add('border-red-500');
        ensureTeacherErrorMessage(
            field,
            field.multiple ? 'Pilih minimal satu kelas' : `${field.getAttribute('placeholder') || field.getAttribute('name')} wajib diisi`
        );
    });

    if (typeof extraValidation === 'function') {
        hasError = extraValidation() === false || hasError;
    }

    if (hasError) {
        form.querySelector('.border-red-500')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    return !hasError;
}

export function createTeacherPasswordStrength(password) {
    var score = 0;
    var suggestions = [];

    if (password.length >= 6) score += 20;
    else suggestions.push('Minimal 6 karakter.');
    if (/[A-Z]/.test(password)) score += 20;
    else suggestions.push('Tambahkan huruf besar.');
    if (/[a-z]/.test(password)) score += 20;
    else suggestions.push('Tambahkan huruf kecil.');
    if (/[0-9]/.test(password)) score += 20;
    else suggestions.push('Tambahkan angka.');
    if (/[^A-Za-z0-9]/.test(password)) score += 20;
    else suggestions.push('Tambahkan karakter khusus.');

    return {
        score,
        suggestion: suggestions.length ? `Saran: ${suggestions.join(' ')}` : '',
    };
}
