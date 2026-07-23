function updateSemester(selectElement) {
    var target = selectElement || document.getElementById('tahun_pelajaran');
    var semesterInput = document.getElementById('semester');
    if (!target || !semesterInput) return;

    if (!target.value) {
        semesterInput.value = '';
        return;
    }

    var selectedOption = target.options[target.selectedIndex];
    var semester = selectedOption?.getAttribute('data-semester');
    if (semester) {
        semesterInput.value = semester;
    }
}

function syncAcademicYearSelection(pageRoot) {
    var tahunSelect = document.getElementById('tahun_pelajaran');
    var semesterInput = document.getElementById('semester');
    var currentTahunAjaran = pageRoot.dataset.currentTahunPelajaran || '';
    var currentSemester = pageRoot.dataset.currentSemester || '';
    var matchFound = false;

    if (!tahunSelect || !semesterInput) return;

    Array.from(tahunSelect.options).forEach(option => {
        if (option.value !== currentTahunAjaran) return;

        if (option.getAttribute('data-semester') === currentSemester) {
            option.selected = true;
            matchFound = true;
        }
    });

    if (!matchFound && currentTahunAjaran && currentSemester) {
        var selectedOption = tahunSelect.options[tahunSelect.selectedIndex];
        if (selectedOption && selectedOption.value === currentTahunAjaran) {
            selectedOption.textContent = `${currentTahunAjaran} - ${currentSemester === '1' ? 'Ganjil' : 'Genap'}`;
            selectedOption.setAttribute('data-semester', currentSemester);
        }
    }

    updateSemester(tahunSelect);
    tahunSelect.addEventListener('change', function () {
        updateSemester(this);
    });
}

export function initAdminProfilePage() {
    var pageRoot = document.querySelector('[data-page="admin-profile"]');
    if (!pageRoot || pageRoot.dataset.profileBound === 'true') return;

    pageRoot.dataset.profileBound = 'true';
    syncAcademicYearSelection(pageRoot);
}
