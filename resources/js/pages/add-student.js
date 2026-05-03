import {
    bindStudentPhotoPreview,
    bindStudentRequiredIndicators,
    bindStudentSanitizers,
    bindStudentSubmitValidation,
} from '../features/student-form';

export function initAddStudentPage() {
    var pageRoot = document.querySelector('[data-page="add-student"]');
    if (!pageRoot) return;

    var form = document.getElementById('studentForm');
    if (!form || form.dataset.studentPageBound === 'true') return;

    form.dataset.studentPageBound = 'true';

    bindStudentRequiredIndicators(form);
    bindStudentSanitizers(form);
    bindStudentPhotoPreview(form);
    bindStudentSubmitValidation(form, { requireAllFields: true });
}
