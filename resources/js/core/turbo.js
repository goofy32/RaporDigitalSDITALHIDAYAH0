import Alpine from 'alpinejs';
import { sidebarImageCache } from '../features/sidebar';

export function registerTurboCore() {
    document.addEventListener('turbo:load', () => {
        const searchForms = document.querySelectorAll('form[data-turbo-search]');

        searchForms.forEach(form => {
            const searchInput = form.querySelector('input[name="search"]');
            const submitButton = form.querySelector('button[type="submit"]');

            if (!searchInput || !submitButton) return;

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitButton.innerHTML = `
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    `;
                    form.requestSubmit();
                }
            });

            form.addEventListener('submit', function () {
                const originalContent = submitButton.innerHTML;
                submitButton.innerHTML = `
                    <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                `;

                document.addEventListener('turbo:render', function restoreButton() {
                    submitButton.innerHTML = originalContent;
                    document.removeEventListener('turbo:render', restoreButton);
                });
            });
        });
    });

    document.addEventListener('turbo:render', () => {
        const searchForms = document.querySelectorAll('form[data-turbo-search]');

        searchForms.forEach(form => {
            const searchInput = form.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                setTimeout(() => {
                    searchInput.focus();
                    searchInput.select();
                }, 100);
            }
        });
    });

    document.addEventListener('turbo:before-render', event => {
        const sidebar = document.getElementById('logo-sidebar');
        if (!sidebar) return;

        sidebar.setAttribute('data-turbo-processed', 'true');

        const newSidebar = event.detail.newBody.querySelector('#logo-sidebar');
        if (!newSidebar) return;

        sidebar.querySelectorAll('img').forEach(img => {
            img.style.opacity = '1';
            img.style.visibility = 'visible';
            img.setAttribute('data-loaded', 'true');
            sidebarImageCache.set(img.src, true);
        });

        newSidebar.replaceWith(sidebar);
    });

    document.addEventListener('turbo:before-render', () => {
        Alpine.store('report').showPreview = false;
        Alpine.store('report').previewContent = '';
        Alpine.store('report').closePreview();

        if (window.Alpine) {
            window.Alpine.flushAndStopDeferring();
        }
    });

    document.addEventListener('turbo:frame-render', () => {
        Alpine.store('pageLoading').stopLoading();
    });

    document.addEventListener('turbo:request-timeout', () => {
        Alpine.store('pageLoading').stopLoading();
        console.warn('Turbo request timed out');
    });

    document.addEventListener('turbo:before-fetch-request', event => {
        event.detail.fetchOptions.timeout = 10000;
    });

    document.addEventListener('turbo:load', () => {
        const imgObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;

                const img = entry.target;

                if (img.dataset.loaded === 'true' || sidebarImageCache.has(img.src)) {
                    img.style.opacity = '1';
                    imgObserver.unobserve(img);
                    return;
                }

                img.onload = () => {
                    img.style.opacity = '1';
                    img.dataset.loaded = 'true';
                    sidebarImageCache.set(img.src, true);
                };

                imgObserver.unobserve(img);
            });
        });

        document.querySelectorAll('#logo-sidebar img').forEach(img => {
            if (!img.dataset.loaded && !sidebarImageCache.has(img.src)) {
                img.style.opacity = '0';
                img.style.transition = 'opacity 0.2s';
            }

            imgObserver.observe(img);
        });

        if (typeof initFlowbite === 'function') {
            initFlowbite();
        }

        if (window.Alpine && window.alpineInitialized) {
            window.Alpine.initTree(document.body);
        }

        if (window.Alpine) {
            const savedState = localStorage.getItem('sidebar_dropdown_state');
            if (savedState) {
                try {
                    const state = JSON.parse(savedState);
                    Alpine.store('sidebar').dropdownState = {
                        ...Alpine.store('sidebar').dropdownState,
                        ...state,
                    };
                } catch (e) {
                    console.error('Error restoring sidebar state:', e);
                }
            }
        }

        setTimeout(() => {
            if (Alpine.store('pageLoading')) {
                Alpine.store('pageLoading').stopLoading();
            }
        }, 100);
    });
}
