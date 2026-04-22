import Alpine from 'alpinejs';

export const cleanupHandlers = new Set();
export const sidebarImageCache = new Map();

export function preloadAndCacheSidebarIcons() {
    const sidebar = document.getElementById('logo-sidebar');
    if (!sidebar) return;

    sidebar.querySelectorAll('img').forEach(img => {
        img.style.opacity = '1';
        img.style.visibility = 'visible';

        if (!img.dataset.loaded && !sidebarImageCache.has(img.src)) {
            sidebarImageCache.set(img.src, true);
            img.setAttribute('data-loaded', 'loading');

            const preloader = new Image();
            preloader.onload = () => {
                img.setAttribute('data-loaded', 'true');
                img.style.opacity = '1';
            };
            preloader.onerror = () => {
                const cacheBuster = `${img.src}${img.src.includes('?') ? '&' : '?'}v=${Date.now()}`;
                img.src = cacheBuster;
                img.setAttribute('data-loaded', 'retrying');
            };
            preloader.src = img.src;
        }
    });
}

export function ensureSidebarVisible() {
    const sidebar = document.getElementById('logo-sidebar');
    if (sidebar) {
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('sm:translate-x-0');
    }
}

export function preloadPermanentComponents() {
    const permanentElements = document.querySelectorAll('[data-turbo-permanent]');

    permanentElements.forEach(element => {
        const elementId = element.id;
        if (!elementId) return;

        if (Alpine.store('pageLoading').isComponentLoaded(elementId)) return;

        element.style.opacity = '1';
        Alpine.store('pageLoading').markComponentLoaded(elementId);
    });
}

export function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

export function updateSidebarActiveState() {
    try {
        const currentPath = window.location.pathname;
        const sidebarLinks = document.querySelectorAll('#logo-sidebar a[data-path]');

        if (!sidebarLinks.length) return;

        sidebarLinks.forEach(link => {
            link.classList.remove('bg-green-100', 'bg-gray-100', 'shadow-md', 'active');
            link.removeAttribute('aria-current');
        });

        let mostSpecificLink = null;
        let maxMatchLength = 0;

        sidebarLinks.forEach(link => {
            const path = link.dataset.path;
            if (path && currentPath.includes(path) && path.length > maxMatchLength) {
                maxMatchLength = path.length;
                mostSpecificLink = link;
            }
        });

        if (mostSpecificLink) {
            mostSpecificLink.classList.add('bg-gray-100', 'active');
            mostSpecificLink.setAttribute('aria-current', 'page');
        }
    } catch (error) {
        console.error('Error updating sidebar state:', error);
    }
}

export function exposeSidebarHelpers() {
    window.preloadAndCacheSidebarIcons = preloadAndCacheSidebarIcons;
    window.updateSidebarActiveState = updateSidebarActiveState;
}

export function registerSidebarFeatures() {
    exposeSidebarHelpers();

    Alpine.store('pageTransition');

    document.addEventListener('turbo:before-visit', () => {
        Alpine.store('pageLoading').startLoading();
    });

    const debouncedUpdateSidebar = debounce(updateSidebarActiveState, 100);

    document.addEventListener('DOMContentLoaded', () => {
        updateSidebarActiveState();
        if (typeof initFlowbite === 'function') {
            initFlowbite();
        }
    });

    document.addEventListener('turbo:render', updateSidebarActiveState);
    document.addEventListener('turbo:visit', debouncedUpdateSidebar);
    document.addEventListener('DOMContentLoaded', preloadPermanentComponents);
    document.addEventListener('turbo:load', preloadPermanentComponents);
    document.addEventListener('turbo:load', ensureSidebarVisible);
    document.addEventListener('turbo:render', ensureSidebarVisible);

    document.addEventListener('turbo:before-cache', () => {
        if (window.Alpine) {
            document.querySelectorAll('[x-data]').forEach(el => {
                if (el.__x && el.__x.$data && el.__x.$data.openDropdown !== undefined) {
                    localStorage.setItem('sidebar_dropdown_state', JSON.stringify(el.__x.$data.openDropdown));
                }
            });
        }

        const notificationHandler = document.querySelector('[x-data="notificationHandler"]');
        if (notificationHandler && notificationHandler.__x) {
            notificationHandler.__x.destroy();
        }

        const reportTemplateManager = document.querySelector('[x-data="reportTemplateManager"]');
        if (reportTemplateManager && reportTemplateManager.__x) {
            reportTemplateManager.__x.destroy();
        }

        const sidebarElements = document.querySelectorAll('#logo-sidebar');
        if (sidebarElements.length > 1) {
            for (let i = 1; i < sidebarElements.length; i += 1) {
                sidebarElements[i].remove();
            }
        }

        document.querySelectorAll('#logo-sidebar img').forEach(img => {
            if (img.complete) {
                img.dataset.loaded = 'true';
            }
        });

        document.querySelectorAll('[x-data]').forEach(dropdown => {
            if (dropdown.__x) {
                const state = dropdown.__x.$data.openDropdown;
                if (typeof state !== 'undefined') {
                    localStorage.setItem('formatRaporDropdown', state);
                }
            }
        });
    });
}
