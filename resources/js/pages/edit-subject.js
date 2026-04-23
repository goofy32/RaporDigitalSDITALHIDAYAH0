import {
    getSubjectFormConfig,
    initializeSubjectEntry,
    markSubjectFormChanged,
    markSubjectFormSubmitting,
    setSubjectPageReady,
} from '../features/subject-form';

function getPageRoot() {
    return document.querySelector('[data-page="edit-subject"]');
}

function getForm() {
    const pageRoot = getPageRoot();
    return pageRoot?.querySelector('#editSubjectForm') || null;
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]').content;
}

function addLingkupMateri() {
    const container = document.getElementById('lingkupMateriContainer');
    const div = document.createElement('div');
    div.className = 'flex items-center mb-2';
    div.innerHTML = `
        <input type="text" name="lingkup_materi[]" required
            class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </button>`;
    container.appendChild(div);
}

function removeLingkupMateri(button) {
    button.parentElement.remove();
}

function confirmDeleteLingkupMateri(button, id) {
    if (confirm('Apakah Anda yakin ingin menghapus Lingkup Materi ini? Semua tujuan pembelajaran terkait juga akan dihapus.')) {
        deleteLingkupMateri(button, id);
    }
}

async function checkForDependentData(lingkupMateriId) {
    try {
        const response = await fetch(`/admin/subject/lingkup-materi/${lingkupMateriId}/check-dependencies`, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
        });
        if (!response.ok) return false;
        const data = await response.json();
        return data.hasDependents;
    } catch (error) {
        console.error('Error checking dependencies:', error);
        return false;
    }
}

function deleteLingkupMateri(button, id) {
    markSubjectFormChanged();
    markSubjectFormSubmitting(true);

    fetch(`/admin/subject/lingkup-materi/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
    })
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                button.closest('.flex.items-center').remove();
                markSubjectFormChanged();
                alert('Lingkup materi berhasil dihapus');
            } else {
                alert(data.message || 'Gagal menghapus Lingkup Materi');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menghapus Lingkup Materi');
        })
        .finally(() => markSubjectFormSubmitting(false));
}

function handleCheckboxChange(checkbox) {
    const form = getForm();
    const mode = checkbox.classList.contains('muatan-lokal-checkbox')
        ? (checkbox.checked ? 'muatan_lokal' : 'default')
        : (checkbox.checked ? 'guru_mapel' : 'default');

    initializeSubjectEntry(form, {
        mode,
        resetSelection: true,
    });

    markSubjectFormChanged();
}

function updateFormState() {
    const form = getForm();
    if (!form) return;

    initializeSubjectEntry(form, {
        mode: form.dataset.subjectMode || undefined,
        resetSelection: false,
    });
}

function registerEditSubjectGlobals() {
    window.addLingkupMateri = addLingkupMateri;
    window.removeLingkupMateri = removeLingkupMateri;
    window.confirmDeleteLingkupMateri = confirmDeleteLingkupMateri;
    window.checkForDependentData = checkForDependentData;
    window.deleteLingkupMateri = deleteLingkupMateri;
    window.handleCheckboxChange = handleCheckboxChange;
    window.updateFormState = updateFormState;
}

function checkDuplication() {
    const form = getForm();
    const { mapelData } = getSubjectFormConfig(form);
    const mataPelajaranInput = document.getElementById('mata_pelajaran');
    const kelasSelect = document.getElementById('kelas');
    const semesterSelect = document.getElementById('semester');
    const currentId = parseInt(form.getAttribute('data-subject-id'));
    const mataPelajaran = mataPelajaranInput.value.trim();
    const kelasId = parseInt(kelasSelect.value);
    const semester = parseInt(semesterSelect.value);
    if (!mataPelajaran || !kelasId || isNaN(semester)) return true;

    return !mapelData.find(subject =>
        subject.nama_pelajaran.toLowerCase() === mataPelajaran.toLowerCase() &&
        subject.kelas_id === kelasId &&
        subject.semester === semester &&
        subject.id !== currentId
    );
}

function validateMataPelajaran() {
    const mataPelajaranInput = document.getElementById('mata_pelajaran');
    if (!checkDuplication()) {
        mataPelajaranInput.classList.add('border-red-500');
        let errorElement = document.getElementById('mata-pelajaran-error');
        if (!errorElement) {
            errorElement = document.createElement('p');
            errorElement.id = 'mata-pelajaran-error';
            errorElement.className = 'mt-1 text-sm text-red-500';
            errorElement.textContent = 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.';
            mataPelajaranInput.parentNode.appendChild(errorElement);
        }
        return false;
    }

    mataPelajaranInput.classList.remove('border-red-500');
    document.getElementById('mata-pelajaran-error')?.remove();
    return true;
}

function bindChangeListener(element, callback) {
    if (!element) return;
    element.addEventListener('change', () => {
        callback?.();
        markSubjectFormChanged();
    });
}

function bindEditSubjectPage() {
    const pageRoot = getPageRoot();
    if (!pageRoot) return;

    const form = getForm();
    if (!form) return;
    if (form.dataset.subjectFormBound === 'true') {
        setSubjectPageReady(pageRoot, form);
        return;
    }
    registerEditSubjectGlobals();
    form.dataset.subjectFormBound = 'true';

    document.getElementById('mata_pelajaran')?.addEventListener('input', () => {
        validateMataPelajaran();
        markSubjectFormChanged();
    });
    bindChangeListener(document.getElementById('semester'), validateMataPelajaran);
    bindChangeListener(document.getElementById('kelas'), () => { validateMataPelajaran(); updateFormState(); });
    bindChangeListener(document.getElementById('is_muatan_lokal'), () => handleCheckboxChange(document.getElementById('is_muatan_lokal')));
    bindChangeListener(document.getElementById('allow_non_wali'), () => handleCheckboxChange(document.getElementById('allow_non_wali')));

    document.querySelectorAll('#lingkupMateriContainer input[name="lingkup_materi[]"]').forEach(input => {
        const originalValue = input.getAttribute('data-original-value');
        input.addEventListener('change', () => {
            const container = input.closest('[data-lm-id]');
            if (container && container.getAttribute('data-lm-id') !== 'new' && input.value.trim() !== originalValue) {
                markSubjectFormChanged();
            }
        });
    });
    validateMataPelajaran();
    updateFormState();
    setSubjectPageReady(pageRoot, form);
    form.addEventListener('submit', event => {
        if (!checkDuplication()) {
            event.preventDefault();
            event.stopPropagation();
            alert('Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.');
            validateMataPelajaran();
            return false;
        }
        markSubjectFormSubmitting(true);
        return true;
    }, true);
}

export function initEditSubjectPage() {
    bindEditSubjectPage();
}
