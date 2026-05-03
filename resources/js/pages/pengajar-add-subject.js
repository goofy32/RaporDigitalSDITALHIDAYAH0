import {
    findPengajarSubjectDuplicate,
    getPengajarSubjectConfig,
    markPengajarSubjectChanged,
    syncPengajarCheckboxes,
} from '../features/pengajar-subject-form';

var subjectCount = 1;

function getPageRoot() {
    return document.querySelector('[data-page="pengajar-add-subject"]');
}

function updateKelasSelection(subjectEntry) {
    if (!subjectEntry) return;

    var pageRoot = getPageRoot();
    var config = getPengajarSubjectConfig(pageRoot);
    var kelasSelect = subjectEntry.querySelector('.kelas-select');
    if (!kelasSelect || !kelasSelect.value) return;

    var selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
    var isWaliKelas = selectedOption.getAttribute('data-is-wali-kelas') === 'true';
    var allowNonWaliInput = subjectEntry.querySelector('.allow-non-wali-input');

    if (config.isGuruWali && allowNonWaliInput) {
        allowNonWaliInput.value = isWaliKelas ? '0' : '1';
    }

    markPengajarSubjectChanged();
}

function fixSubjectNumbering() {
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        entry.querySelector('h4').textContent = `Mata Pelajaran ${index + 1}`;
    });
}

function updateEntryStyles() {
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        entry.classList.remove('bg-gray-50', 'bg-blue-50', 'bg-green-50', 'border-l-4', 'border-blue-300', 'border-green-300');
        entry.classList.add(index % 2 === 0 ? 'bg-green-50' : 'bg-blue-50', 'border-l-4', index % 2 === 0 ? 'border-green-300' : 'border-blue-300', 'shadow-md');
    });
}

function addLingkupMateri(button) {
    var container = button.closest('.lingkup-materi-container');
    var entryIndex = button.closest('.subject-entry').querySelector('input[type="text"]').name.match(/subjects\[(\d+)\]/)[1];
    var div = document.createElement('div');
    div.className = 'flex items-center mb-2';
    div.innerHTML = `
        <input type="text" name="subjects[${entryIndex}][lingkup_materi][]" required class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
    `;
    container.appendChild(div);
}

function removeLingkupMateri(button) {
    button.parentElement.remove();
}

function addSubjectEntry() {
    subjectCount += 1;
    var container = document.getElementById('subjectEntriesContainer');
    var template = container.querySelector('.subject-entry').cloneNode(true);

    template.querySelectorAll('input, select').forEach(input => {
        var name = input.getAttribute('name');
        var id = input.getAttribute('id');

        if (name) input.setAttribute('name', name.replace(/subjects\[0\]/, `subjects[${subjectCount - 1}]`));
        if (id) input.setAttribute('id', id.replace(/_0$/, `_${subjectCount - 1}`));

        if (input.tagName === 'INPUT' && input.type !== 'checkbox' && !input.hasAttribute('disabled') && !input.hasAttribute('hidden')) {
            input.value = '';
        } else if (input.tagName === 'SELECT' && !input.hasAttribute('disabled') && input.options.length > 0) {
            input.selectedIndex = 0;
        } else if (input.type === 'checkbox' && !input.hasAttribute('disabled')) {
            input.checked = false;
        }
    });

    template.querySelectorAll('label').forEach(label => {
        var forAttr = label.getAttribute('for');
        if (forAttr) label.setAttribute('for', forAttr.replace(/_0$/, `_${subjectCount - 1}`));
    });

    template.querySelector('h4').textContent = `Mata Pelajaran ${document.querySelectorAll('.subject-entry').length + 1}`;
    template.querySelector('.remove-btn')?.classList.remove('hidden');
    var lingkupContainer = template.querySelector('.lingkup-materi-container');
    var firstLingkupEntry = lingkupContainer.querySelector('.flex.items-center').cloneNode(true);
    lingkupContainer.innerHTML = '';
    lingkupContainer.appendChild(firstLingkupEntry);
    firstLingkupEntry.querySelector('input').value = '';
    container.appendChild(template);

    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        var removeBtn = entry.querySelector('.remove-btn');
        if (removeBtn) removeBtn.classList.toggle('hidden', index === 0);
    });

    updateEntryStyles();
    updateKelasSelection(template);
}

function removeSubjectEntry(button) {
    if (document.querySelectorAll('.subject-entry').length <= 1) return;

    button.closest('.subject-entry').remove();
    subjectCount = document.querySelectorAll('.subject-entry').length;
    fixSubjectNumbering();
    updateEntryStyles();
    if (document.querySelectorAll('.subject-entry').length === 1) {
        document.querySelector('.subject-entry .remove-btn')?.classList.add('hidden');
    }
}

function validateForm() {
    var pageRoot = getPageRoot();
    var config = getPengajarSubjectConfig(pageRoot);
    var formValid = true;

    document.querySelectorAll('.mata-pelajaran-error').forEach(el => el.remove());
    document.querySelectorAll('input.border-red-500').forEach(el => el.classList.remove('border-red-500'));

    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        var mataPelajaranInput = entry.querySelector(`input[name="subjects[${index}][mata_pelajaran]"]`);
        var kelasSelect = entry.querySelector(`select[name="subjects[${index}][kelas]"]`);
        var semesterSelect = entry.querySelector(`select[name="subjects[${index}][semester]"]`);
        var mataPelajaran = mataPelajaranInput?.value.trim();
        var kelasId = parseInt(kelasSelect?.value);
        var semester = parseInt(semesterSelect?.value);

        if (!mataPelajaran || !kelasId || isNaN(semester)) return;

        if (findPengajarSubjectDuplicate(config.mapelData, mataPelajaran, kelasId, semester)) {
            mataPelajaranInput.classList.add('border-red-500');
            var errorElement = document.createElement('p');
            errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
            errorElement.textContent = `"${mataPelajaran}" sudah ada di kelas ini untuk semester ${semester}`;
            mataPelajaranInput.parentNode.appendChild(errorElement);
            formValid = false;
        }
    });

    if (!formValid) alert('Terdapat duplikasi mata pelajaran. Silakan periksa kembali form.');
    return formValid;
}

export function initPengajarAddSubjectPage() {
    var pageRoot = getPageRoot();
    if (!pageRoot) return;

    window.syncCheckboxes = checkbox => {
        syncPengajarCheckboxes(checkbox.closest('.subject-entry'), checkbox);
        markPengajarSubjectChanged();
    };
    window.updateKelasSelection = subjectEntry => updateKelasSelection(subjectEntry);
    window.addSubjectEntry = addSubjectEntry;
    window.removeSubjectEntry = removeSubjectEntry;
    window.addLingkupMateri = addLingkupMateri;
    window.removeLingkupMateri = removeLingkupMateri;
    window.validateForm = validateForm;

    document.querySelectorAll('.subject-entry').forEach(entry => updateKelasSelection(entry));
    document.querySelector('.subject-entry .remove-btn')?.classList.add('hidden');
    updateEntryStyles();
    subjectCount = document.querySelectorAll('.subject-entry').length;

    if (pageRoot.dataset.sessionError && pageRoot.dataset.sessionErrorShown !== 'true') {
        pageRoot.dataset.sessionErrorShown = 'true';
        alert(pageRoot.dataset.sessionError);
    }
}
