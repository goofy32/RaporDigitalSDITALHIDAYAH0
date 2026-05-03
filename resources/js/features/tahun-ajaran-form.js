function removeErrorMessage(errorClass) {
    document.querySelector(`.${errorClass}`)?.remove();
}

function addErrorMessage(input, errorClass, message) {
    removeErrorMessage(errorClass);

    var errorMsg = document.createElement('p');
    errorMsg.classList.add('text-red-500', 'text-sm', 'mt-1', errorClass);
    errorMsg.textContent = message;
    input.parentNode.appendChild(errorMsg);
    input.classList.add('border-red-500');
}

export function validateAcademicYearFormat(input, errorClass = 'tahun-ajaran-error') {
    if (!input) return true;

    var value = input.value.trim();
    var pattern = /^\d{4}\/\d{4}$/;

    if (value && !pattern.test(value)) {
        addErrorMessage(input, errorClass, 'Format tahun ajaran harus YYYY/YYYY, contoh: 2024/2025');
        return false;
    }

    removeErrorMessage(errorClass);
    input.classList.remove('border-red-500');
    return true;
}

export function bindAcademicYearValidation(input, errorClass = 'tahun-ajaran-error') {
    if (!input || input.dataset.academicYearBound === 'true') return;

    input.addEventListener('blur', function () {
        validateAcademicYearFormat(this, errorClass);
    });

    input.dataset.academicYearBound = 'true';
}

export function validateDateRange(startInput, endInput, errorClass = 'tanggal-error') {
    if (!startInput || !endInput || !startInput.value || !endInput.value) return true;

    var mulai = new Date(startInput.value);
    var selesai = new Date(endInput.value);

    if (selesai <= mulai) {
        addErrorMessage(endInput, errorClass, 'Tanggal selesai harus setelah tanggal mulai');
        return false;
    }

    removeErrorMessage(errorClass);
    endInput.classList.remove('border-red-500');
    return true;
}

export function bindDateRangeValidation(startInput, endInput, errorClass = 'tanggal-error') {
    if (!startInput || !endInput || endInput.dataset.dateRangeBound === 'true') return;

    endInput.addEventListener('change', function () {
        validateDateRange(startInput, this, errorClass);
    });

    endInput.dataset.dateRangeBound = 'true';
}
