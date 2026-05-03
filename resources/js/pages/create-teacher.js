import {
    bindLengthValidation,
    bindNumericInputs,
    bindTeacherFileValidation,
    ensureTeacherErrorMessage,
    removeTeacherAlert,
    setTeacherSubmitState,
    validateTeacherRequiredFields,
} from '../features/teacher-form';

function getPageRoot() {
    return document.querySelector('[data-page="create-teacher"]');
}

function enableAllKelasMengajarOptions() {
    document.querySelector('[name="kelas_ids[]"]')?.querySelectorAll('option').forEach(option => {
        option.disabled = false;
    });
}

function removeAutoSyncInfo() {
    document.getElementById('kelas_mengajar_auto_info')?.remove();
}

function showAutoSyncInfo() {
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    if (!kelasMengajarSelect) return;

    var infoText = document.getElementById('kelas_mengajar_auto_info');
    if (!infoText) {
        infoText = document.createElement('div');
        infoText.id = 'kelas_mengajar_auto_info';
        infoText.className = 'mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800';
        kelasMengajarSelect.parentElement.appendChild(infoText);
    }

    infoText.innerHTML = `
        <div class="flex items-start">
            <svg class="w-4 h-4 mr-2 mt-0.5 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
            <div><span class="font-medium">Otomatis Disinkronkan:</span> Sebagai wali kelas, Anda hanya dapat mengajar di kelas yang Anda walikan. Kelas mengajar otomatis disesuaikan dengan kelas wali yang dipilih.</div>
        </div>
    `;
}

function syncKelasWaliToKelasAjar() {
    var waliKelasId = document.querySelector('[name="wali_kelas_id"]')?.value;
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    if (!kelasMengajarSelect) return;

    if (waliKelasId) {
        kelasMengajarSelect.querySelectorAll('option').forEach(option => {
            option.disabled = true;
            option.selected = option.value === waliKelasId;
        });
        showAutoSyncInfo();
        return;
    }

    enableAllKelasMengajarOptions();
    removeAutoSyncInfo();
}

function handleJabatanChange() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    var jabatan = document.getElementById('jabatan')?.value;
    var waliKelasSection = document.getElementById('wali_kelas_section');
    var kelasMengajarSection = document.getElementById('kelas_mengajar_section');
    var waliKelasSelect = document.querySelector('[name="wali_kelas_id"]');
    var kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
    var kelasForWaliCount = parseInt(pageRoot.dataset.kelasForWaliCount || '0');
    var kelasForMengajarCount = parseInt(pageRoot.dataset.kelasForMengajarCount || '0');

    if (jabatan === 'guru_wali') {
        waliKelasSection.style.display = 'block';
        kelasMengajarSection.style.display = 'block';
        syncKelasWaliToKelasAjar();

        if (waliKelasSelect && waliKelasSelect.dataset.syncBound !== 'true') {
            waliKelasSelect.addEventListener('change', syncKelasWaliToKelasAjar);
            waliKelasSelect.dataset.syncBound = 'true';
        }

        setTeacherSubmitState('createTeacherForm', kelasForWaliCount === 0, 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk wali kelas');
        if (waliKelasSelect) waliKelasSelect.required = kelasForWaliCount > 0;
        if (kelasMengajarSelect) kelasMengajarSelect.required = false;
        return;
    }

    if (jabatan === 'guru') {
        waliKelasSection.style.display = 'none';
        kelasMengajarSection.style.display = 'block';
        enableAllKelasMengajarOptions();
        removeAutoSyncInfo();
        setTeacherSubmitState('createTeacherForm', kelasForMengajarCount === 0, 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk diampu');
        if (waliKelasSelect) waliKelasSelect.required = false;
        if (kelasMengajarSelect) kelasMengajarSelect.required = kelasForMengajarCount > 0;
        return;
    }

    waliKelasSection.style.display = 'none';
    kelasMengajarSection.style.display = 'none';
    removeAutoSyncInfo();
    removeTeacherAlert();
    setTeacherSubmitState('createTeacherForm', false);
    if (waliKelasSelect) waliKelasSelect.required = false;
    if (kelasMengajarSelect) kelasMengajarSelect.required = false;
}

function bindCreateTeacherValidation(form) {
    if (form.dataset.validationBound === 'true') return;

    form.addEventListener('submit', function (event) {
        var isValid = validateTeacherRequiredFields(form, function () {
            var hasError = false;
            var nuptk = document.getElementById('nuptk');
            var phone = document.getElementById('no_handphone');

            if (nuptk?.value.trim() && (nuptk.value.trim().length < 9 || nuptk.value.trim().length > 15)) {
                hasError = true;
                nuptk.classList.add('border-red-500');
                ensureTeacherErrorMessage(nuptk, 'NUPTK harus antara 9-15 digit');
            }

            if (phone?.value.trim() && (phone.value.trim().length < 10 || phone.value.trim().length > 15)) {
                hasError = true;
                phone.classList.add('border-red-500');
                ensureTeacherErrorMessage(phone, 'No. Handphone harus antara 10-15 digit');
            }

            return !hasError;
        });

        if (!isValid) {
            event.preventDefault();
        }
    });

    form.dataset.validationBound = 'true';
}

export function initCreateTeacherPage() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    var form = document.getElementById('createTeacherForm');
    if (!form) return;

    window.handleJabatanChange = handleJabatanChange;
    bindNumericInputs(form);
    bindLengthValidation(document.getElementById('nuptk'), 9, 15, 'NUPTK harus antara 9-15 digit');
    bindLengthValidation(document.getElementById('no_handphone'), 10, 15, 'No. Handphone harus antara 10-15 digit');
    bindTeacherFileValidation(form);
    bindCreateTeacherValidation(form);
    handleJabatanChange();
}
