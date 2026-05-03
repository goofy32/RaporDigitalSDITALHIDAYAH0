import {
    bindStudentPhotoPreview,
    bindStudentSanitizers,
    bindStudentSubmitValidation,
} from '../features/student-form';

export function initEditStudentPage() {
    var pageRoot = document.querySelector('[data-page="edit-student"]');
    if (!pageRoot) return;

    var form = document.getElementById('editStudentForm');
    if (!form || form.dataset.studentPageBound === 'true') return;

    form.dataset.studentPageBound = 'true';

    bindStudentSanitizers(form);
    bindStudentPhotoPreview(form);
    bindStudentSubmitValidation(form);
}
