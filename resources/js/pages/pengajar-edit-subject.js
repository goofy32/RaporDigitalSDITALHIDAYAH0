import {
    findPengajarSubjectDuplicate,
    getPengajarSubjectConfig,
    markPengajarSubjectChanged,
    setPengajarDeleteButtonState,
    syncPengajarCheckboxes,
} from '../features/pengajar-subject-form';
import { refreshLearningCopyOption } from '../features/subject-form';

var pendingDeleteIds = [];

function getPageRoot() {
    return document.querySelector('[data-page="pengajar-edit-subject"]');
}

function getForm() {
    return document.getElementById('editSubjectForm');
}

function syncPendingDeleteInputs() {
    var form = getForm();
    if (!form) return;

    form.querySelectorAll('input[name="delete_ids[]"]').forEach(input => input.remove());
    pendingDeleteIds.forEach(id => {
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'delete_ids[]';
        hiddenInput.value = id;
        form.appendChild(hiddenInput);
    });
}

function addLingkupMateri() {
    var container = document.getElementById('lingkupMateriContainer');
    var div = document.createElement('div');
    div.className = 'flex items-center mb-2';
    div.setAttribute('data-lm-id', 'new');
    div.innerHTML = `
        <input type="text" name="lingkup_materi[]" required class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
    `;
    container.appendChild(div);
    refreshLearningCopyOption(getForm());
    markPengajarSubjectChanged();
}

function removeLingkupMateri(button) {
    button.closest('.flex.items-center')?.remove();
    refreshLearningCopyOption(getForm());
    markPengajarSubjectChanged();
}

function setPendingDeleteState(row, button, id, isPending) {
    var input = row.querySelector('input[name="lingkup_materi[]"]');
    row.classList.toggle('opacity-40', isPending);
    row.classList.toggle('line-through', isPending);
    row.dataset.pendingDelete = isPending ? 'true' : 'false';
    if (input) {
        input.disabled = isPending;
        input.required = !isPending;
    }
    if (isPending && !pendingDeleteIds.includes(id)) pendingDeleteIds.push(id);
    if (!isPending) pendingDeleteIds = pendingDeleteIds.filter(deleteId => deleteId !== id);
    setPengajarDeleteButtonState(button, isPending);
    syncPendingDeleteInputs();
    refreshLearningCopyOption(getForm());
    markPengajarSubjectChanged();
}

function confirmDeleteLingkupMateri(button, id) {
    var row = button.closest('.flex.items-center');
    if (!row) return;
    setPendingDeleteState(row, button, id, row.dataset.pendingDelete !== 'true');
}

function deleteLingkupMateri(button, id) {
    confirmDeleteLingkupMateri(button, id);
}

function updateKelasSelection() {
    var pageRoot = getPageRoot();
    var config = getPengajarSubjectConfig(pageRoot);
    var kelasSelect = document.getElementById('kelas');
    var selectedOption = kelasSelect?.options?.[kelasSelect.selectedIndex];
    var isMuatanLokalElement = document.getElementById('is_muatan_lokal');
    var allowNonWaliElement = document.getElementById('allow_non_wali');
    var waliInfo = document.querySelector('.wali-info');
    var muatanLokalContainer = document.querySelector('.muatan-lokal-container');
    var nonMuatanOptions = document.querySelector('.non-muatan-lokal-options');
    var isWaliKelas = selectedOption?.getAttribute('data-is-wali-kelas') === 'true';
    var isMuatanLokal = isMuatanLokalElement ? isMuatanLokalElement.checked : false;

    if (!config.isGuruWali || !waliInfo || !muatanLokalContainer) {
        refreshLearningCopyOption(getForm());
        return;
    }

    if (isWaliKelas) {
        waliInfo.style.display = 'block';
        muatanLokalContainer.style.display = 'none';
        if (isMuatanLokalElement) {
            isMuatanLokalElement.checked = false;
            isMuatanLokalElement.disabled = true;
        }
        if (allowNonWaliElement) {
            allowNonWaliElement.checked = false;
            allowNonWaliElement.disabled = true;
        }
        if (nonMuatanOptions) nonMuatanOptions.style.display = 'none';
        refreshLearningCopyOption(getForm());
        return;
    }

    waliInfo.style.display = 'none';
    muatanLokalContainer.style.display = 'block';
    if (isMuatanLokalElement) isMuatanLokalElement.disabled = false;
    if (allowNonWaliElement) allowNonWaliElement.disabled = false;
    if (nonMuatanOptions) nonMuatanOptions.style.display = isMuatanLokal ? 'none' : 'block';
    if (!isMuatanLokal && allowNonWaliElement) allowNonWaliElement.checked = true;
    refreshLearningCopyOption(getForm());
}

function checkDuplication() {
    var pageRoot = getPageRoot();
    var config = getPengajarSubjectConfig(pageRoot);
    var mataPelajaranInput = document.getElementById('mata_pelajaran');
    var kelasSelect = document.getElementById('kelas');
    var semester = parseInt(document.querySelector('input[name="semester"]').value);
    var mataPelajaran = mataPelajaranInput?.value.trim();
    var kelasId = parseInt(kelasSelect?.value);
    if (!mataPelajaran || !kelasId || isNaN(semester)) return true;
    return !findPengajarSubjectDuplicate(config.mapelData, mataPelajaran, kelasId, semester, config.subjectId);
}

function validateMataPelajaran() {
    var mataPelajaranInput = document.getElementById('mata_pelajaran');
    if (checkDuplication()) {
        mataPelajaranInput?.classList.remove('border-red-500');
        document.getElementById('mata-pelajaran-error')?.remove();
        return true;
    }

    mataPelajaranInput.classList.add('border-red-500');
    if (!document.getElementById('mata-pelajaran-error')) {
        var errorElement = document.createElement('p');
        errorElement.id = 'mata-pelajaran-error';
        errorElement.className = 'mt-1 text-sm text-red-500';
        errorElement.textContent = 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.';
        mataPelajaranInput.parentNode.appendChild(errorElement);
    }
    return false;
}

export function initPengajarEditSubjectPage() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    var form = getForm();
    if (!form || form.dataset.pengajarSubjectBound === 'true') return;

    form.dataset.pengajarSubjectBound = 'true';
    window.addLingkupMateri = addLingkupMateri;
    window.removeLingkupMateri = removeLingkupMateri;
    window.confirmDeleteLingkupMateri = confirmDeleteLingkupMateri;
    window.deleteLingkupMateri = deleteLingkupMateri;
    window.syncCheckboxes = checkbox => {
        syncPengajarCheckboxes(getForm(), checkbox);
        updateKelasSelection();
        markPengajarSubjectChanged();
    };
    window.updateKelasSelection = updateKelasSelection;

    document.getElementById('mata_pelajaran')?.addEventListener('input', function () {
        validateMataPelajaran();
        refreshLearningCopyOption(form);
        markPengajarSubjectChanged();
    });
    document.getElementById('kelas')?.addEventListener('change', function () {
        validateMataPelajaran();
        updateKelasSelection();
        markPengajarSubjectChanged();
    });
    document.getElementById('is_muatan_lokal')?.addEventListener('change', function () {
        syncPengajarCheckboxes(getForm(), this);
        updateKelasSelection();
        markPengajarSubjectChanged();
    });
    document.getElementById('allow_non_wali')?.addEventListener('change', function () {
        syncPengajarCheckboxes(getForm(), this);
        updateKelasSelection();
        markPengajarSubjectChanged();
    });

    form.addEventListener('submit', function (event) {
        if (!checkDuplication()) {
            event.preventDefault();
            event.stopPropagation();
            validateMataPelajaran();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Validasi Gagal',
                    text: 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.',
                });
            }
            return false;
        }

        syncPendingDeleteInputs();
        window.formChanged = false;
        return true;
    });

    var sidebar = document.getElementById('logo-sidebar');
    if (sidebar) {
        sidebar.style.transform = 'translateX(0)';
        sidebar.style.display = 'block';
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('sm:translate-x-0');
    }
    document.querySelector('.p-4.sm\\:ml-64')?.style.setProperty('margin-left', '16rem');
    updateKelasSelection();
    validateMataPelajaran();
    refreshLearningCopyOption(form);

    if (pageRoot.dataset.sessionError && pageRoot.dataset.sessionErrorShown !== 'true' && typeof Swal !== 'undefined') {
        pageRoot.dataset.sessionErrorShown = 'true';
        Swal.fire({ icon: 'error', title: 'Gagal!', text: pageRoot.dataset.sessionError, confirmButtonText: 'Ok' });
    }
}
