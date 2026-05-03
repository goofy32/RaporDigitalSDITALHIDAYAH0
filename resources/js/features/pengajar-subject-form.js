function parseJsonDataset(element, key, fallback) {
    if (!element?.dataset?.[key]) return fallback;

    try {
        return JSON.parse(element.dataset[key]);
    } catch (error) {
        console.warn(`Invalid pengajar subject dataset: ${key}`, error);
        return fallback;
    }
}

export function getPengajarSubjectConfig(pageEl) {
    return {
        isGuruWali: pageEl?.dataset?.isGuruWali === 'true',
        mapelData: parseJsonDataset(pageEl, 'mapelData', []),
        subjectId: parseInt(pageEl?.dataset?.subjectId || '0'),
    };
}

export function markPengajarSubjectChanged() {
    window.formChanged = true;
    window.Alpine?.store('formProtection')?.markAsChanged?.();
}

export function syncPengajarCheckboxes(entry, checkbox) {
    var muatanCheckbox = entry.querySelector('.muatan-lokal-checkbox');
    var guruMapelCheckbox = entry.querySelector('.allow-non-wali-checkbox');

    if (!muatanCheckbox || !guruMapelCheckbox) return;

    if (checkbox === muatanCheckbox && muatanCheckbox.checked) {
        guruMapelCheckbox.checked = false;
    }

    if (checkbox === guruMapelCheckbox && guruMapelCheckbox.checked) {
        muatanCheckbox.checked = false;
    }
}

export function findPengajarSubjectDuplicate(mapelData, mataPelajaran, kelasId, semester, currentId) {
    return mapelData.find(subject =>
        subject.nama_pelajaran.toLowerCase() === mataPelajaran.toLowerCase() &&
        parseInt(subject.kelas_id) === parseInt(kelasId) &&
        parseInt(subject.semester) === parseInt(semester) &&
        (!currentId || parseInt(subject.id) !== parseInt(currentId))
    );
}

export function setPengajarDeleteButtonState(button, isPending) {
    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
        button.dataset.originalClass = button.className;
    }

    if (isPending) {
        button.innerHTML = 'Batal';
        button.className = 'delete-btn ml-2 px-3 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600';
        return;
    }

    button.innerHTML = button.dataset.originalHtml;
    button.className = button.dataset.originalClass;
}
