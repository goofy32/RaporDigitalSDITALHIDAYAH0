import Alpine from 'alpinejs';

export const cleanupHandlers = new Set();
export const sidebarImageCache = new Map();

const sidebarAccessibilityObservers = new WeakMap();
const sidebarDesktopMediaQuery = '(min-width: 1280px)';
let sidebarAccessibilitySyncFrame = null;
let sidebarResizeListenerBound = false;

function closeSidebarDrawer(sidebar) {
    const instances = window.FlowbiteInstances;
    const drawer = instances?.instanceExists?.('Drawer', 'logo-sidebar')
        ? instances.getInstance('Drawer', 'logo-sidebar')
        : null;

    if (drawer?.isVisible?.()) {
        drawer.hide();
    }

    sidebar.classList.remove('transform-none', 'translate-x-0');
    sidebar.classList.add('-translate-x-full');
}

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
        const isDesktop = window.matchMedia(sidebarDesktopMediaQuery).matches;

        sidebar.style.display = '';
        sidebar.style.visibility = 'visible';
        sidebar.classList.remove('hidden');
        sidebar.classList.add('xl:translate-x-0');

        closeSidebarDrawer(sidebar);

        if (isDesktop) {
            sidebar.classList.remove('-translate-x-full');
        }

        bindSidebarAccessibilityObserver();
        scheduleSidebarAccessibilitySync();
    }
}

function getSidebarToggle() {
    return document.querySelector('[data-drawer-toggle="logo-sidebar"], [data-drawer-target="logo-sidebar"]');
}

function moveFocusOutOfSidebar(sidebar) {
    if (!sidebar.contains(document.activeElement)) {
        return;
    }

    const toggle = getSidebarToggle();

    if (toggle instanceof HTMLElement && !toggle.closest('[aria-hidden="true"], [inert]')) {
        toggle.focus({ preventScroll: true });
        return;
    }

    if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }
}

export function syncSidebarAccessibility() {
    const sidebar = document.getElementById('logo-sidebar');
    if (!sidebar) return;

    const isDesktop = window.matchMedia(sidebarDesktopMediaQuery).matches;
    const isMobileDrawerOpen = sidebar.classList.contains('transform-none')
        || sidebar.classList.contains('translate-x-0');
    const isHidden = !isDesktop && (!isMobileDrawerOpen || sidebar.classList.contains('hidden'));

    if (isHidden) {
        moveFocusOutOfSidebar(sidebar);
        if (sidebar.getAttribute('aria-hidden') !== 'true') {
            sidebar.setAttribute('aria-hidden', 'true');
        }
        if (!sidebar.hasAttribute('inert')) {
            sidebar.setAttribute('inert', '');
        }
        return;
    }

    if (sidebar.hasAttribute('aria-hidden')) {
        sidebar.removeAttribute('aria-hidden');
    }
    if (sidebar.hasAttribute('inert')) {
        sidebar.removeAttribute('inert');
    }
}

export function scheduleSidebarAccessibilitySync() {
    if (sidebarAccessibilitySyncFrame !== null) {
        return;
    }

    const schedule = window.requestAnimationFrame || (callback => window.setTimeout(callback, 16));

    sidebarAccessibilitySyncFrame = schedule(() => {
        sidebarAccessibilitySyncFrame = null;
        syncSidebarAccessibility();
    });
}

function bindSidebarAccessibilityObserver() {
    const sidebar = document.getElementById('logo-sidebar');
    if (!sidebar || sidebarAccessibilityObservers.has(sidebar)) return;

    sidebar.dataset.accessibilityObserver = 'true';

    const observer = new MutationObserver(() => scheduleSidebarAccessibilitySync());
    observer.observe(sidebar, {
        attributes: true,
        attributeFilter: ['class'],
    });

    sidebarAccessibilityObservers.set(sidebar, observer);

    if (!sidebarResizeListenerBound) {
        const desktopMediaQuery = window.matchMedia(sidebarDesktopMediaQuery);
        const handleBreakpointChange = () => {
            ensureSidebarVisible();
            scheduleSidebarAccessibilitySync();
        };

        if (typeof desktopMediaQuery.addEventListener === 'function') {
            desktopMediaQuery.addEventListener('change', handleBreakpointChange);
        } else {
            desktopMediaQuery.addListener(handleBreakpointChange);
        }

        sidebarResizeListenerBound = true;
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
        const currentPath = (window.location.pathname.replace(/\/+$/, '') || '/').toLowerCase();
        const sidebarLinks = document.querySelectorAll('#logo-sidebar a[data-path]');

        if (!sidebarLinks.length) return;

        sidebarLinks.forEach(link => {
            link.classList.remove('bg-green-100', 'bg-gray-100', 'shadow-md', 'active', 'text-gray-900', 'font-semibold');
            link.classList.add('text-gray-700');
            link.removeAttribute('aria-current');
        });

        let mostSpecificLink = null;
        let maxMatchLength = 0;

        sidebarLinks.forEach(link => {
            const path = (link.dataset.path || '').replace(/\/+$/, '').toLowerCase();
            const isMatch = path && (currentPath === path || currentPath.startsWith(`${path}/`));

            if (isMatch && path.length > maxMatchLength) {
                maxMatchLength = path.length;
                mostSpecificLink = link;
            }
        });

        if (mostSpecificLink) {
            mostSpecificLink.classList.remove('text-gray-700');
            mostSpecificLink.classList.add('bg-gray-100', 'text-gray-900', 'font-semibold', 'active');
            mostSpecificLink.setAttribute('aria-current', 'page');
        }
    } catch (error) {
        console.error('Error updating sidebar state:', error);
    }
}

export function safeInitFlowbite() {
    window.clearTimeout(window.__flowbiteInitTimer);

    window.__flowbiteInitTimer = window.setTimeout(() => {
        const initFlowbiteFn = window.initFlowbite;
        if (typeof initFlowbiteFn !== 'function') {
            window.ensureFlowbiteLoaded?.();
            return;
        }

        const drawerTriggers = document.querySelectorAll('[data-drawer-target], [data-drawer-toggle]');
        const hasMissingDrawerTarget = Array.from(drawerTriggers).some(trigger => {
            const targetId = trigger.getAttribute('data-drawer-target') || trigger.getAttribute('data-drawer-toggle');
            return targetId && !document.getElementById(targetId);
        });

        if (hasMissingDrawerTarget) return;

        initFlowbiteFn();
    }, 50);
}

export function exposeSidebarHelpers() {
    window.preloadAndCacheSidebarIcons = preloadAndCacheSidebarIcons;
    window.updateSidebarActiveState = updateSidebarActiveState;
    window.safeInitFlowbite = safeInitFlowbite;
}

export function registerSidebarFeatures() {
    exposeSidebarHelpers();

    Alpine.store('pageTransition');

    bindSidebarAccessibilityObserver();
    scheduleSidebarAccessibilitySync();

    document.addEventListener('turbo:before-visit', () => {
        Alpine.store('pageLoading').startLoading();
    });

    const debouncedUpdateSidebar = debounce(updateSidebarActiveState, 100);

    document.addEventListener('DOMContentLoaded', () => {
        updateSidebarActiveState();
        safeInitFlowbite();
    });

    document.addEventListener('turbo:render', updateSidebarActiveState);
    document.addEventListener('turbo:visit', debouncedUpdateSidebar);
    document.addEventListener('DOMContentLoaded', preloadPermanentComponents);
    document.addEventListener('turbo:load', preloadPermanentComponents);
    document.addEventListener('turbo:load', ensureSidebarVisible);
    document.addEventListener('turbo:load', scheduleSidebarAccessibilitySync);
    document.addEventListener('turbo:render', ensureSidebarVisible);
    document.addEventListener('turbo:render', scheduleSidebarAccessibilitySync);

    document.addEventListener('turbo:before-cache', () => {
        ensureSidebarVisible();
        scheduleSidebarAccessibilitySync();

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
