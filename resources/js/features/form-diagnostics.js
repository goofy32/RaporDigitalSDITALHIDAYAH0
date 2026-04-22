import Alpine from 'alpinejs';
import { sidebarImageCache, preloadAndCacheSidebarIcons, exposeSidebarHelpers } from './sidebar';

export function registerFormDiagnostics() {
    exposeSidebarHelpers();

    document.addEventListener('turbo:submit-start', event => {
        const form = event.target;
        if (form.hasAttribute('data-needs-protection')) {
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            document.querySelectorAll('.border-red-500').forEach(el => el.classList.remove('border-red-500'));
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const studentForm = document.querySelector('form[action*="student.store"]');

        if (studentForm) {
            studentForm.addEventListener('submit', function () {
                const formData = new FormData(this);

                for (const [key, value] of formData.entries()) {
                    void key;
                    void value;
                }

                if (!formData.get('tahun_ajaran_id')) {
                    console.warn('tahun_ajaran_id is missing!');
                    const tahunAjaranId = document.querySelector('meta[name="tahun-ajaran-id"]')?.content;

                    if (tahunAjaranId && !document.querySelector('input[name="tahun_ajaran_id"]')) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'tahun_ajaran_id';
                        input.value = tahunAjaranId;
                        this.appendChild(input);
                    }
                }
            });
        }

        const formProtectionEl = document.querySelector('[x-data="formProtection"]');
        if (formProtectionEl) {
            void formProtectionEl;
        }

        document.addEventListener('turbo:before-visit', () => {});
        document.addEventListener('turbo:before-cache', () => {});
        document.addEventListener('turbo:submit-start', event => {
            void event.detail.formSubmission;
        });
        document.addEventListener('turbo:submit-end', event => {
            if (!event.detail.success) {
                console.error('Form submission failed');
            }
        });
    });

    document.addEventListener('turbo:submit-end', event => {
        if (!event.detail.success) {
            console.error('Form submission failed');

            const responseText = event.detail.fetchResponse.responseText;
            if (responseText && responseText.includes('bg-red-100')) {
                const parser = new DOMParser();
                const htmlDoc = parser.parseFromString(responseText, 'text/html');
                const errorElement = htmlDoc.querySelector('.bg-red-100.border-l-4.border-red-500');

                if (errorElement) {
                    const formElement = event.target;
                    formElement.insertAdjacentElement('beforebegin', errorElement);
                    errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }
    });

    document.addEventListener('turbo:before-fetch-response', event => {
        const response = event.detail.fetchResponse;
        if (!response.succeeded) {
            Alpine.store('pageLoading').stopLoading();
            console.error('Turbo fetch failed:', response.statusCode);
        }
    });

    document.addEventListener('turbo:render', () => {
        document.querySelectorAll('#logo-sidebar img').forEach(img => {
            if (sidebarImageCache.has(img.src) || img.getAttribute('data-loaded') === 'true') {
                img.style.opacity = '1';
                img.style.visibility = 'visible';
                img.setAttribute('data-loaded', 'true');
            } else {
                img.style.opacity = '0';
                img.onload = () => {
                    img.style.opacity = '1';
                    img.style.visibility = 'visible';
                    img.setAttribute('data-loaded', 'true');
                    sidebarImageCache.set(img.src, true);
                };
            }
        });
    });

    document.addEventListener('DOMContentLoaded', preloadAndCacheSidebarIcons);
    document.addEventListener('turbo:load', preloadAndCacheSidebarIcons);
    document.addEventListener('turbo:render', preloadAndCacheSidebarIcons);
}
