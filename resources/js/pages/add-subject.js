import {
    getSubjectFormConfig,
    initializeSubjectEntry,
    markSubjectFormChanged,
    setSubjectPageReady,
} from '../features/subject-form';

let subjectCount = 1;

function getPageRoot() {
    return document.querySelector('[data-page="add-subject"]');
}

function getForm() {
    const pageRoot = getPageRoot();
    return pageRoot?.querySelector('#addSubjectForm') || null;
}

function getConfig() {
    return getSubjectFormConfig(getForm());
}

function showErrorAfter(input, message) {
    input.classList.add('border-red-500');
    const errorElement = document.createElement('p');
    errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
    errorElement.textContent = message;
    input.parentNode.appendChild(errorElement);
}

function showContainerError(container, message) {
    const errorElement = document.createElement('p');
    errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
    errorElement.textContent = message;
    container.appendChild(errorElement);
}

function updateEntryStyles() {
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        entry.classList.remove('bg-gray-50', 'bg-blue-50', 'bg-green-50', 'border-l-4', 'border-blue-300', 'border-green-300');
        entry.classList.add(index % 2 === 0 ? 'bg-green-50' : 'bg-blue-50', 'border-l-4', index % 2 === 0 ? 'border-green-300' : 'border-blue-300', 'shadow-md');
    });
}

function fixSubjectNumbering() {
    const entries = document.querySelectorAll('.subject-entry');
    subjectCount = entries.length;
    entries.forEach((entry, index) => {
        entry.querySelector('h4').textContent = `Mata Pelajaran ${index + 1}`;
    });
}

function resetEntryFields(entry, index) {
    entry.querySelectorAll('input, select').forEach(input => {
        const name = input.getAttribute('name');
        if (name) input.setAttribute('name', name.replace(/subjects\[\d+\]/, `subjects[${index}]`));

        const id = input.getAttribute('id');
        if (id) input.setAttribute('id', id.replace(/_\d+$/, `_${index}`));

        if (input.tagName === 'INPUT' && input.type !== 'checkbox') input.value = '';
        if (input.tagName === 'SELECT') input.selectedIndex = 0;
        if (input.type === 'checkbox') input.checked = false;
    });

    entry.querySelectorAll('label').forEach(label => {
        const forAttr = label.getAttribute('for');
        if (forAttr) label.setAttribute('for', forAttr.replace(/_\d+$/, `_${index}`));
    });
}

function resetLingkupMateri(entry) {
    const lingkupContainer = entry.querySelector('.lingkup-materi-container');
    const firstLingkupEntry = lingkupContainer.querySelector('.flex.items-center').cloneNode(true);
    lingkupContainer.innerHTML = '';
    lingkupContainer.appendChild(firstLingkupEntry);
    firstLingkupEntry.querySelector('input').value = '';
}

function addSubjectEntry() {
    subjectCount++;
    const container = document.getElementById('subjectEntriesContainer');
    const template = container.querySelector('.subject-entry').cloneNode(true);
    const index = subjectCount - 1;

    resetEntryFields(template, index);
    resetLingkupMateri(template);

    template.querySelector('h4').textContent = `Mata Pelajaran ${subjectCount}`;
    template.querySelector('.remove-btn').classList.remove('hidden');
    template.querySelector('.info-container').innerHTML = '';
    template.querySelector(`input[name="subjects[${index}][semester]"]`).value = getConfig().currentSemester;

    const divider = document.createElement('div');
    divider.className = 'border-t-2 border-dashed border-gray-300 my-8';
    container.appendChild(divider);
    container.appendChild(template);

    document.querySelectorAll('.subject-entry').forEach((entry, entryIndex) => {
        entry.querySelector('.remove-btn').classList.toggle('hidden', entryIndex === 0);
    });

    initializeSubjectEntry(template, { mode: 'default', resetSelection: true });
    updateEntryStyles();
    markSubjectFormChanged();
}

function removeSubjectEntry(button) {
    const entry = button.closest('.subject-entry');
    if (document.querySelectorAll('.subject-entry').length <= 1) return;

    const nextElement = entry.nextElementSibling;
    const prevElement = entry.previousElementSibling;

    if (nextElement?.classList.contains('border-t-2')) nextElement.remove();
    else if (prevElement?.classList.contains('border-t-2')) prevElement.remove();

    entry.remove();
    fixSubjectNumbering();

    if (document.querySelectorAll('.subject-entry').length === 1) {
        document.querySelector('.subject-entry .remove-btn').classList.add('hidden');
    }

    updateEntryStyles();
    markSubjectFormChanged();
}

function addLingkupMateri(button) {
    const container = button.closest('.lingkup-materi-container');
    const entryIndex = button.closest('.subject-entry').querySelector('input[type="text"]').name.match(/subjects\[(\d+)\]/)[1];
    const div = document.createElement('div');
    div.className = 'flex items-center mb-2';
    div.innerHTML = `
        <input type="text" name="subjects[${entryIndex}][lingkup_materi][]" required
            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </button>`;
    container.appendChild(div);
    markSubjectFormChanged();
}

function removeLingkupMateri(button) {
    button.parentElement.remove();
    markSubjectFormChanged();
}

function handleCheckboxChange(checkbox) {
    const entry = checkbox.closest('.subject-entry');
    const mode = checkbox.classList.contains('muatan-lokal-checkbox')
        ? (checkbox.checked ? 'muatan_lokal' : 'default')
        : (checkbox.checked ? 'guru_mapel' : 'default');

    initializeSubjectEntry(entry, {
        mode,
        resetSelection: true,
    });

    markSubjectFormChanged();
}

function updateGuruOptions(subjectEntry) {
    initializeSubjectEntry(subjectEntry, {
        mode: subjectEntry.dataset.subjectMode || 'default',
        resetSelection: false,
    });
}

function registerAddSubjectGlobals() {
    window.addSubjectEntry = addSubjectEntry;
    window.removeSubjectEntry = removeSubjectEntry;
    window.addLingkupMateri = addLingkupMateri;
    window.removeLingkupMateri = removeLingkupMateri;
    window.handleCheckboxChange = handleCheckboxChange;
    window.updateGuruOptions = updateGuruOptions;
}

function validateForm() {
    document.querySelectorAll('.mata-pelajaran-error').forEach(el => el.remove());
    document.querySelectorAll('input.border-red-500, select.border-red-500').forEach(el => el.classList.remove('border-red-500'));

    let formValid = true;
    const { mapelData } = getConfig();

    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        const mataPelajaranInput = entry.querySelector(`input[name="subjects[${index}][mata_pelajaran]"]`);
        const kelasSelect = entry.querySelector(`select[name="subjects[${index}][kelas]"]`);
        const semesterSelect = entry.querySelector(`select[name="subjects[${index}][semester]"]`);
        const guruSelect = entry.querySelector(`select[name="subjects[${index}][guru_pengampu]"]`);

        if (!mataPelajaranInput.value.trim()) {
            showErrorAfter(mataPelajaranInput, 'Nama mata pelajaran harus diisi');
            formValid = false;
        }

        if (!kelasSelect.value) {
            showErrorAfter(kelasSelect, 'Kelas harus dipilih');
            formValid = false;
        }

        if (!semesterSelect.value) {
            showErrorAfter(semesterSelect, 'Semester harus dipilih');
            formValid = false;
        }

        if (!guruSelect.value) {
            showErrorAfter(guruSelect, 'Guru pengampu harus dipilih');
            formValid = false;
        }

        const mataPelajaran = mataPelajaranInput.value.trim();
        const kelasId = parseInt(kelasSelect.value);
        const semester = parseInt(semesterSelect.value);
        const duplicate = mapelData.find(subject =>
            subject.nama_pelajaran.toLowerCase() === mataPelajaran.toLowerCase() &&
            subject.kelas_id === kelasId &&
            subject.semester === semester
        );

        if (mataPelajaran && kelasId && !isNaN(semester) && duplicate) {
            showErrorAfter(mataPelajaranInput, `"${mataPelajaran}" sudah ada di kelas ini untuk semester ${semester}`);
            formValid = false;
        }

        let hasEmptyLingkupMateri = false;
        entry.querySelectorAll(`input[name^="subjects[${index}][lingkup_materi]"]`).forEach(input => {
            if (!input.value.trim()) {
                input.classList.add('border-red-500');
                hasEmptyLingkupMateri = true;
                formValid = false;
            }
        });

        if (hasEmptyLingkupMateri) {
            showContainerError(entry.querySelector('.lingkup-materi-container'), 'Semua lingkup materi harus diisi');
        }
    });

    if (!formValid && typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Data Belum Lengkap',
            html: 'Mohon lengkapi semua kolom yang bertanda <span class="text-red-500">merah</span> sebelum menyimpan.',
            icon: 'warning',
            confirmButtonText: 'Mengerti'
        });
    }

    return formValid;
}

export function initAddSubjectPage() {
    const pageRoot = getPageRoot();
    if (!pageRoot) return;

    const form = getForm();
    if (!form) return;
    if (form.dataset.subjectFormBound === 'true') {
        setSubjectPageReady(pageRoot, form);
        return;
    }

    registerAddSubjectGlobals();
    form.dataset.subjectFormBound = 'true';
    form.addEventListener('submit', event => {
        if (!validateForm()) event.preventDefault();
    });

    document.querySelectorAll('.subject-entry').forEach(entry => initializeSubjectEntry(entry, { resetSelection: false }));
    updateEntryStyles();
    setSubjectPageReady(pageRoot, form);
}
