function parseJsonDataset(value, fallback) {
    if (!value) return fallback;

    try {
        return JSON.parse(value);
    } catch (error) {
        console.error('Failed to parse kenaikan kelas dataset:', error);
        return fallback;
    }
}

function applyDropdownStyles(dropdown, baseBorderColor, focusBorderColor) {
    if (!dropdown) return;

    dropdown.style.borderColor = baseBorderColor;

    dropdown.addEventListener('focus', function () {
        this.style.borderColor = focusBorderColor;
        this.style.boxShadow = `0 0 0 1px ${focusBorderColor}`;
    });

    dropdown.addEventListener('blur', function () {
        this.style.borderColor = baseBorderColor;
        this.style.boxShadow = 'none';
    });
}

export function initKenaikanKelasShowPage() {
    var pageEl = document.querySelector('[data-page="kenaikan-kelas-show"]');
    if (!pageEl) return;

    var selectedStudents = [];
    var raporStatus = parseJsonDataset(pageEl.dataset.raporStatus, {});
    var sessionDetails = parseJsonDataset(pageEl.dataset.sessionDetails, []);
    var sessionAction = pageEl.dataset.sessionAction || '';
    var sessionStatus = pageEl.dataset.sessionStatus || '';
    var selectAllCheckbox = document.getElementById('select-all');
    var studentCheckboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');

    function updateHiddenInputs(containerId, selectedIds) {
        var container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = '';
        selectedIds.forEach(id => {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'siswa_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    function updateSelectedStudents() {
        var selectedCount = document.getElementById('selectedCount');
        var actionForms = document.getElementById('actionForms');

        selectedStudents = Array.from(document.querySelectorAll('.student-checkbox:not(:disabled):checked')).map(checkbox => checkbox.value);

        if (selectedCount) {
            selectedCount.textContent = selectedStudents.length;
        }

        if (actionForms) {
            actionForms.style.display = selectedStudents.length > 0 ? 'block' : 'none';
        }

        updateHiddenInputs('selectedKelulusanIds', selectedStudents);
        updateHiddenInputs('selectedNaikIds', selectedStudents);
        updateHiddenInputs('selectedTinggalIds', selectedStudents);
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedStudents();
        });
    }

    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            updateSelectedStudents();

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = studentCheckboxes.length > 0
                    && document.querySelectorAll('.student-checkbox:not(:disabled):checked').length === studentCheckboxes.length;
            }
        });
    });

    applyDropdownStyles(document.getElementById('kelas_tujuan_id'), 'rgb(134, 239, 172)', 'rgb(34, 197, 94)');
    applyDropdownStyles(document.getElementById('kelas_tinggal_id'), 'rgb(252, 165, 165)', 'rgb(239, 68, 68)');
    window.setTimeout(function () {
        applyDropdownStyles(document.getElementById('kelas_tujuan_id'), 'rgb(134, 239, 172)', 'rgb(34, 197, 94)');
        applyDropdownStyles(document.getElementById('kelas_tinggal_id'), 'rgb(252, 165, 165)', 'rgb(239, 68, 68)');
    }, 100);

    document.querySelectorAll('.check-rapor-btn').forEach(button => {
        button.addEventListener('click', function (event) {
            var form = this.closest('form');
            var actionType = this.getAttribute('data-action');
            var noRaporStudents = [];

            event.preventDefault();

            selectedStudents.forEach(id => {
                if (!raporStatus[id]) {
                    var studentRow = document.querySelector(`tr[data-siswa-id='${id}']`);
                    var studentName = studentRow?.querySelector('td:nth-child(3)')?.textContent;
                    if (studentName) {
                        noRaporStudents.push(studentName);
                    }
                }
            });

            if (!noRaporStudents.length) {
                form.submit();
                return;
            }

            var warningHtml = '<p>Siswa berikut belum memiliki rapor:</p><ul class="text-left mt-2">';
            noRaporStudents.forEach(name => {
                warningHtml += `<li>- ${name}</li>`;
            });
            warningHtml += `</ul><p class="mt-3">Apakah Anda tetap ingin melanjutkan ${actionType}?</p>`;

            window.Swal.fire({
                title: 'Perhatian!',
                html: warningHtml,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3F7858',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Lanjutkan',
                cancelButtonText: 'Batal',
            }).then(result => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    if (sessionDetails.length) {
        var icon = sessionStatus === 'lulus' ? '🎓' : (sessionStatus === 'pindah' ? '🔄' : '⛔');
        var detailHtml = '<div class="max-h-60 overflow-y-auto py-2"><ul class="text-left">';

        sessionDetails.forEach(detail => {
            var arrow = sessionAction === 'kenaikan' ? '↗' : (sessionAction === 'tinggal' ? '↔' : icon);
            var targetText = sessionAction === 'kelulusan' ? sessionStatus : detail.kelas_tujuan;
            detailHtml += `<li class="mb-2 flex items-start"><span class="mr-1">${arrow}</span><div><strong>${detail.nama}</strong><br>${detail.kelas_asal} → ${targetText}</div></li>`;
        });

        detailHtml += '</ul></div>';

        window.Swal.fire({
            title: 'Berhasil!',
            html: detailHtml,
            icon: 'success',
            confirmButtonColor: '#10b981',
            confirmButtonText: 'OK',
        });
    }

    updateSelectedStudents();
}
