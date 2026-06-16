import {
    bindNumericInputs,
    bindTeacherFileValidation,
    createTeacherPasswordStrength,
    ensureTeacherErrorMessage,
    setTeacherSubmitState,
    validateTeacherRequiredFields,
} from '../features/teacher-form';

function getPageRoot() {
    return document.querySelector('[data-page="edit-teacher"]');
}

function enableAllKelasMengajarOptions() {
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    if (!kelasMengajarSelect) return;

    kelasMengajarSelect.querySelectorAll('option').forEach(option => {
        option.disabled = false;
    });
    document.getElementById('kelas_mengajar_info')?.remove();
}

function updateKelasMengajarForWali() {
    var waliKelasId = document.querySelector('[name="wali_kelas_id"]')?.value;
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    if (!waliKelasId || !kelasMengajarSelect) return;

    kelasMengajarSelect.querySelectorAll('option').forEach(option => {
        option.disabled = true;
        option.selected = option.value === waliKelasId;
    });

    var infoText = document.getElementById('kelas_mengajar_info');
    if (!infoText) {
        infoText = document.createElement('p');
        infoText.id = 'kelas_mengajar_info';
        infoText.className = 'mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded-md text-sm text-yellow-800';
        kelasMengajarSelect.parentElement.appendChild(infoText);
    }
    infoText.innerHTML = '<span class="font-medium">Info:</span> Karena Anda terpilih sebagai wali kelas, Anda hanya dapat mengajar di kelas wali yang dipilih.';
}

function handleJabatanChange() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    var jabatan = document.getElementById('jabatan')?.value;
    var waliKelasSection = document.getElementById('wali_kelas_section');
    var kelasMengajarSection = document.getElementById('kelas_mengajar_section');
    var waliKelasSelect = document.querySelector('[name="wali_kelas_id"]');
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    var availableKelasCount = parseInt(pageRoot.dataset.availableKelasCount || '0');
    var kelasListCount = parseInt(pageRoot.dataset.kelasListCount || '0');
    var hasCurrentWaliKelas = pageRoot.dataset.hasCurrentWaliKelas === 'true';

    if (jabatan === 'guru_wali') {
        waliKelasSection.style.display = 'block';
        kelasMengajarSection.style.display = 'none';
        setTeacherSubmitState('editTeacherForm', availableKelasCount === 0 && !hasCurrentWaliKelas, 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk wali kelas');
        if (waliKelasSelect) {
            waliKelasSelect.required = availableKelasCount > 0 || hasCurrentWaliKelas;
            if (waliKelasSelect.dataset.syncBound !== 'true') {
                waliKelasSelect.addEventListener('change', updateKelasMengajarForWali);
                waliKelasSelect.dataset.syncBound = 'true';
            }
        }
        if (kelasMengajarSelect) kelasMengajarSelect.required = false;
        if (waliKelasSelect?.value) updateKelasMengajarForWali();
        return;
    }

    if (jabatan === 'guru') {
        waliKelasSection.style.display = 'none';
        kelasMengajarSection.style.display = 'block';
        enableAllKelasMengajarOptions();
        setTeacherSubmitState('editTeacherForm', kelasListCount === 0, 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk diampu');
        if (waliKelasSelect) {
            waliKelasSelect.required = false;
            waliKelasSelect.value = '';
        }
        if (kelasMengajarSelect) kelasMengajarSelect.required = kelasListCount > 0;
        return;
    }

    waliKelasSection.style.display = 'none';
    kelasMengajarSection.style.display = 'none';
    setTeacherSubmitState('editTeacherForm', false);
    if (waliKelasSelect) waliKelasSelect.required = false;
    if (kelasMengajarSelect) kelasMengajarSelect.required = false;
}

async function validateCurrentPassword() {
    var pageRoot = getPageRoot();
    var currentPassword = document.getElementById('current_password');
    var newPassword = document.getElementById('new_password');
    var currentPasswordError = document.getElementById('current_password_error');

    try {
        var response = await fetch(pageRoot.dataset.verifyPasswordUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                teacher_id: pageRoot.dataset.teacherId,
                current_password: currentPassword.value,
            }),
        });
        var data = await response.json();

        if (!data.valid) {
            currentPassword.classList.add('border-red-500');
            currentPassword.classList.remove('border-green-500');
            currentPasswordError.textContent = 'Password saat ini tidak sesuai';
            currentPasswordError.classList.remove('hidden');
            return false;
        }

        if (newPassword.value) {
            currentPassword.classList.remove('border-red-500');
            currentPassword.classList.add('border-green-500');
            currentPasswordError.classList.add('hidden');
        }
        return true;
    } catch (error) {
        console.error('Error verifying password:', error);
        currentPasswordError.textContent = 'Terjadi kesalahan saat memverifikasi password';
        currentPasswordError.classList.remove('hidden');
        return false;
    }
}

function validatePasswordMatch() {
    var newPassword = document.getElementById('new_password');
    var confirmPassword = document.getElementById('password_confirmation');
    var passwordMatchError = document.getElementById('password_match_error');

    if (newPassword.value && confirmPassword.value && newPassword.value !== confirmPassword.value) {
        confirmPassword.classList.add('border-red-500');
        confirmPassword.classList.remove('border-green-500');
        passwordMatchError.textContent = 'Password tidak cocok';
        passwordMatchError.classList.remove('hidden');
        return false;
    }

    if (confirmPassword.value) {
        confirmPassword.classList.remove('border-red-500');
        confirmPassword.classList.add('border-green-500');
    }
    passwordMatchError.classList.add('hidden');
    return true;
}

function updatePasswordStrength() {
    var newPassword = document.getElementById('new_password');
    var strengthMeter = document.getElementById('password_strength_meter');
    var strengthBar = document.getElementById('password_strength_bar');
    var strengthText = document.getElementById('password_strength_text');
    var strength = createTeacherPasswordStrength(newPassword.value);

    strengthMeter.style.display = newPassword.value.length > 0 ? 'block' : 'none';
    if (!newPassword.value.length) return;

    strengthBar.style.width = `${strength.score}%`;
    if (strength.score < 40) {
        strengthBar.className = 'h-2 absolute left-0 top-0 bg-red-500';
        strengthText.className = 'text-xs text-red-500 mt-1';
        strengthText.textContent = 'Lemah';
    } else if (strength.score < 70) {
        strengthBar.className = 'h-2 absolute left-0 top-0 bg-yellow-500';
        strengthText.className = 'text-xs text-yellow-600 mt-1';
        strengthText.textContent = 'Sedang';
    } else {
        strengthBar.className = 'h-2 absolute left-0 top-0 bg-green-500';
        strengthText.className = 'text-xs text-green-600 mt-1';
        strengthText.textContent = 'Kuat';
    }
    if (strength.suggestion) {
        strengthText.textContent += ` - ${strength.suggestion}`;
    }
}

function bindEditTeacherValidation(form) {
    if (form.dataset.validationBound === 'true') return;

    form.addEventListener('submit', async function (event) {
        var isValid = validateTeacherRequiredFields(form, function () {
            var hasError = false;
            var jabatan = document.getElementById('jabatan')?.value;
            var waliKelasId = document.getElementById('wali_kelas_id');

            if (jabatan === 'guru_wali' && waliKelasId && !waliKelasId.value) {
                hasError = true;
                waliKelasId.classList.add('border-red-500');
                ensureTeacherErrorMessage(waliKelasId, 'Wali kelas harus dipilih untuk guru dengan jabatan Guru dan Wali Kelas');
            }
            return !hasError;
        });

        if (!isValid) {
            event.preventDefault();
            return;
        }

        var newPassword = document.getElementById('new_password');
        var currentPassword = document.getElementById('current_password');
        if (!newPassword.value) return;

        event.preventDefault();
        if (!currentPassword.value) {
            currentPassword.classList.add('border-red-500');
            document.getElementById('current_password_error').textContent = 'Password saat ini harus diisi untuk mengubah password';
            document.getElementById('current_password_error').classList.remove('hidden');
            currentPassword.focus();
            currentPassword.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if ((await validateCurrentPassword()) && validatePasswordMatch()) {
            form.submit();
        }
    });

    form.dataset.validationBound = 'true';
}

let signatureUploadResetBound = false;

function resetSignatureUploadForm() {
    document.querySelectorAll('[data-signature-upload-form]').forEach(form => {
        const label = form.querySelector('[data-signature-upload-label]');

        form.dataset.submitting = 'false';

        if (label?.dataset.originalText) {
            label.textContent = label.dataset.originalText;
            label.classList.remove('opacity-75', 'pointer-events-none');
        }
    });
}

function bindSignatureUploadForm() {
    const form = document.querySelector('[data-signature-upload-form]');
    const input = form?.querySelector('[data-signature-upload-input]');
    const label = form?.querySelector('[data-signature-upload-label]');

    if (!form || !input || form.dataset.signatureUploadBound === 'true') {
        return;
    }

    if (label && !label.dataset.originalText) {
        label.dataset.originalText = label.textContent.trim();
    }

    input.addEventListener('change', () => {
        if (!input.files?.length || form.dataset.submitting === 'true') {
            return;
        }

        form.dataset.submitting = 'true';

        if (label) {
            label.textContent = 'Mengunggah...';
            label.classList.add('opacity-75', 'pointer-events-none');
        }

        form.submit();
    });

    form.dataset.signatureUploadBound = 'true';

    if (!signatureUploadResetBound) {
        document.addEventListener('turbo:before-cache', resetSignatureUploadForm);
        signatureUploadResetBound = true;
    }
}

export function initEditTeacherPage() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    var form = document.getElementById('editTeacherForm');
    if (!form) return;

    window.handleJabatanChange = handleJabatanChange;
    bindNumericInputs(form);
    bindTeacherFileValidation(form);
    bindEditTeacherValidation(form);
    bindSignatureUploadForm();

    document.getElementById('current_password')?.addEventListener('blur', function () {
        if (this.value && document.getElementById('new_password')?.value) {
            validateCurrentPassword();
        }
    });
    document.getElementById('new_password')?.addEventListener('blur', function () {
        if (this.value && document.getElementById('current_password')?.value) validateCurrentPassword();
        updatePasswordStrength();
    });
    document.getElementById('new_password')?.addEventListener('input', function () {
        updatePasswordStrength();
        if (document.getElementById('password_confirmation')?.value) validatePasswordMatch();
    });
    document.getElementById('password_confirmation')?.addEventListener('input', validatePasswordMatch);

    handleJabatanChange();
}
