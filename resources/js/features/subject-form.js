import Alpine from 'alpinejs';

function parseJsonDataset(element, key, fallback = []) {
    if (!element?.dataset?.[key]) return fallback;

    try {
        return JSON.parse(element.dataset[key]);
    } catch (error) {
        console.warn(`Invalid subject form dataset: ${key}`, error);
        return fallback;
    }
}

export function getSubjectFormConfig(form) {
    return {
        currentSemester: parseInt(form?.dataset.currentSemester || '1'),
        mapelData: parseJsonDataset(form, 'mapelData'),
        waliKelasMap: parseJsonDataset(form, 'waliKelasMap', {}),
    };
}

export function markSubjectFormChanged() {
    try {
        Alpine.store('formProtection')?.markAsChanged?.();
    } catch (error) {
        console.warn('Could not mark formProtection store as changed', error);
    }
}

export function markSubjectFormSubmitting(isSubmitting = true) {
    try {
        const store = Alpine.store('formProtection');
        if (store && 'isSubmitting' in store) store.isSubmitting = isSubmitting;
    } catch (error) {
        console.warn('Could not update formProtection submitting state', error);
    }
}

export function setSubjectPageReady(pageRoot, form) {
    form?.classList.remove('subject-form-loading');
    pageRoot?.querySelector('[data-page-loader]')?.remove();
}

export function showSubjectInfo(container, type, message) {
    if (!container) return;

    const variants = {
        info: {
            className: 'bg-blue-50 border border-blue-200 text-blue-800',
            icon: '<svg class="h-5 w-5 text-blue-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9z" clip-rule="evenodd" /></svg>',
        },
        warning: {
            className: 'bg-yellow-50 border border-yellow-200 text-yellow-800',
            icon: '<svg class="h-5 w-5 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>',
        },
    };

    const variant = variants[type] || variants.info;
    container.innerHTML = `
        <div class="p-2 ${variant.className} rounded-md flex items-start mb-2">
            ${variant.icon}
            <p class="text-sm">${message}</p>
        </div>
    `;
}

function getTeacherCategory(option) {
    return option.getAttribute('data-jabatan') === 'guru_wali' ? 'wali' : 'guru';
}

function getClassState(form, kelasSelect) {
    const selectedOption = kelasSelect?.options?.[kelasSelect.selectedIndex];
    const selectedKelasId = kelasSelect?.value;
    const config = getSubjectFormConfig(form);

    const waliFromDataset = selectedOption?.dataset?.waliId;
    const waliFromMap = selectedKelasId && config.waliKelasMap?.[selectedKelasId]
        ? config.waliKelasMap[selectedKelasId]
        : null;

    return {
        selectedKelasId,
        hasWaliKelas: selectedOption?.dataset?.hasWali === 'true' || Boolean(waliFromDataset || waliFromMap),
        waliKelasId: waliFromDataset ? parseInt(waliFromDataset) : (waliFromMap ? parseInt(waliFromMap) : null),
    };
}

function getSubjectMode(entry) {
    const isMuatanLokal = entry.querySelector('.muatan-lokal-checkbox')?.checked;
    const isGuruMapel = entry.querySelector('.allow-non-wali-checkbox')?.checked;

    if (isMuatanLokal) return 'muatan_lokal';
    if (isGuruMapel) return 'guru_mapel';
    return 'default';
}

function setEntryVisibility(entry, mode) {
    const muatanOptions = entry.querySelector('.muatan-lokal-options');
    const nonMuatanOptions = entry.querySelector('.non-muatan-lokal-options');

    entry.dataset.showMuatanLokal = mode !== 'guru_mapel' ? 'true' : 'false';
    entry.dataset.showGuruMapel = mode !== 'muatan_lokal' ? 'true' : 'false';
    entry.dataset.showPelajaranWajib = 'true';

    if (muatanOptions) muatanOptions.style.display = entry.dataset.showMuatanLokal === 'true' ? 'block' : 'none';
    if (nonMuatanOptions) nonMuatanOptions.style.display = entry.dataset.showGuruMapel === 'true' ? 'block' : 'none';
}

export function filterGuruDropdown(entry, mode, { resetSelection = false } = {}) {
    const form = entry.closest('form');
    const kelasSelect = entry.querySelector('.kelas-select') || document.getElementById('kelas');
    const guruSelect = entry.querySelector('.guru-select') || document.getElementById('guru_pengampu');
    const infoContainer = entry.querySelector('.info-container') || document.querySelector('.info-container');

    if (!guruSelect) return;

    if (resetSelection) guruSelect.value = '';
    infoContainer.innerHTML = '';
    const { selectedKelasId, hasWaliKelas, waliKelasId } = getClassState(form, kelasSelect);

    Array.from(guruSelect.options).forEach(option => {
        option.disabled = false;
        option.hidden = false;
        if (!option.value) return;
    });

    if (!selectedKelasId) {
        guruSelect.classList.toggle('border-yellow-500', guruSelect.value === '' || guruSelect.selectedIndex === 0);
        return;
    }

    if (mode === 'muatan_lokal' || mode === 'guru_mapel') {
        Array.from(guruSelect.options).forEach(option => {
            if (!option.value) return;
            if (getTeacherCategory(option) === 'wali') {
                option.disabled = true;
                option.hidden = true;
            }
        });
        if (mode === 'muatan_lokal') {
            showSubjectInfo(infoContainer, 'warning', 'Ini pelajaran muatan lokal, guru hanya non-wali kelas.');
        } else {
            showSubjectInfo(infoContainer, 'info', 'Mode guru mapel aktif. Guru pengampu hanya guru non-wali kelas.');
        }
    } else if (!hasWaliKelas || !waliKelasId) {
        Array.from(guruSelect.options).forEach(option => {
            if (!option.value) return;
            option.disabled = true;
            option.hidden = true;
        });
        showSubjectInfo(infoContainer, 'warning', 'Pelajaran wajib membutuhkan wali kelas. Kelas ini belum memiliki wali kelas.');
    } else {
        Array.from(guruSelect.options).forEach(option => {
            if (!option.value) return;
            const optionId = parseInt(option.value);
            const isTargetWali = optionId === waliKelasId;
            option.disabled = !isTargetWali;
            option.hidden = !isTargetWali;
        });
        guruSelect.value = waliKelasId.toString();
        showSubjectInfo(infoContainer, 'info', 'Pelajaran wajib otomatis menggunakan guru wali kelas dari kelas ini.');
    }

    guruSelect.classList.toggle('border-yellow-500', guruSelect.value === '' || guruSelect.selectedIndex === 0);
}

export function syncSubjectEntry(entry, options = {}) {
    if (!entry) return;

    const muatanCheckbox = entry.querySelector('.muatan-lokal-checkbox');
    const guruMapelCheckbox = entry.querySelector('.allow-non-wali-checkbox');
    const guruSelect = entry.querySelector('.guru-select');

    const mode = options.mode || getSubjectMode(entry);
    entry.dataset.subjectMode = mode;

    if (mode === 'muatan_lokal' && guruMapelCheckbox) guruMapelCheckbox.checked = false;
    if (mode === 'guru_mapel' && muatanCheckbox) muatanCheckbox.checked = false;
    if (mode === 'default') {
        if (muatanCheckbox) muatanCheckbox.checked = false;
        if (guruMapelCheckbox) guruMapelCheckbox.checked = false;
    }

    setEntryVisibility(entry, mode);
    if (guruSelect && options.resetSelection) guruSelect.value = '';
    filterGuruDropdown(entry, mode, { resetSelection: options.resetSelection });
}

export function initializeSubjectEntry(entry, options = {}) {
    if (!entry) return;

    entry.dataset.showPelajaranWajib = 'true';
    entry.dataset.showGuruMapel = 'true';
    entry.dataset.showMuatanLokal = 'true';

    const initialMode = options.mode || getSubjectMode(entry);
    syncSubjectEntry(entry, {
        mode: initialMode,
        resetSelection: Boolean(options.resetSelection),
    });
}
