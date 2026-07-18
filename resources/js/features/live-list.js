const LIVE_LIST_SELECTOR = '[data-live-list]';
const FILTER_PANEL_SELECTOR = '[data-live-filter-panel]';
const DEBOUNCE_MS = 400;

function debounce(callback, delay = DEBOUNCE_MS) {
    let timeoutId = null;

    return (...args) => {
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(() => callback(...args), delay);
    };
}

function setLoading(container, isLoading) {
    const target = container.querySelector('[data-live-list-results]');
    const loading = container.querySelector('[data-live-list-loading]');

    target?.classList.toggle('opacity-50', isLoading);
    target?.classList.toggle('pointer-events-none', isLoading);
    loading?.classList.toggle('hidden', !isLoading);
}

function closeFilterPanels(root = document) {
    root.querySelectorAll(`${FILTER_PANEL_SELECTOR}[open]`).forEach(panel => {
        panel.removeAttribute('open');
    });
}

function formUrl(form) {
    const url = new URL(form.action || window.location.href, window.location.origin);
    const formData = new FormData(form);

    url.search = '';

    for (const [key, value] of formData.entries()) {
        if (key === 'page') {
            continue;
        }

        if (typeof value === 'string' && value.trim() === '') {
            continue;
        }

        url.searchParams.append(key, value);
    }

    return url;
}

async function fetchLiveList(container, url, { replace = false } = {}) {
    const target = container.querySelector('[data-live-list-results]');

    if (!target) {
        window.location.href = url.toString();
        return;
    }

    setLoading(container, true);

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            window.location.href = url.toString();
            return;
        }

        const payload = await response.json();
        target.innerHTML = payload.html ?? '';

        if (replace) {
            window.history.replaceState({}, '', url.toString());
        } else {
            window.history.pushState({}, '', url.toString());
        }
    } catch (error) {
        console.error('Live list update failed:', error);
        window.location.href = url.toString();
    } finally {
        setLoading(container, false);
    }
}

function syncFormFromUrl(container) {
    const form = container.querySelector('[data-live-list-form]');
    if (!form) return;

    const params = new URL(window.location.href).searchParams;

    form.querySelectorAll('input, select, textarea').forEach(field => {
        if (!field.name || field.type === 'hidden') return;

        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = params.getAll(field.name).includes(field.value);
            return;
        }

        field.value = params.get(field.name) ?? '';
    });
}

function bindLiveList(container) {
    if (container.dataset.liveListBound === 'true') {
        return;
    }

    container.dataset.liveListBound = 'true';

    const form = container.querySelector('[data-live-list-form]');
    const runSearch = debounce(() => {
        if (!form) return;
        fetchLiveList(container, formUrl(form));
    });

    form?.addEventListener('submit', event => {
        event.preventDefault();
        closeFilterPanels(container);
        fetchLiveList(container, formUrl(form));
    });

    form?.querySelectorAll('[data-live-search-input]').forEach(input => {
        input.addEventListener('input', runSearch);
    });

    container.addEventListener('click', event => {
        if (event.target.closest('[data-live-list-ignore]')) {
            return;
        }

        const paginationLink = event.target.closest('[data-live-list-results] [data-live-list-pagination] a[href]');
        if (paginationLink) {
            const url = new URL(paginationLink.href, window.location.origin);

            if (url.origin === window.location.origin) {
                event.preventDefault();
                fetchLiveList(container, url);
            }

            return;
        }

        const resetLink = event.target.closest('[data-live-reset]');
        if (resetLink) {
            event.preventDefault();
            closeFilterPanels(container);
            fetchLiveList(container, new URL(resetLink.href, window.location.origin));
        }
    });

    window.addEventListener('popstate', () => {
        if (!document.body.contains(container)) return;
        syncFormFromUrl(container);
        fetchLiveList(container, new URL(window.location.href), { replace: true });
    });
}

function bindFilterPanelInteractions() {
    if (document.documentElement.dataset.liveFilterPanelsBound === 'true') {
        return;
    }

    document.documentElement.dataset.liveFilterPanelsBound = 'true';

    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;

        document.querySelectorAll(`${FILTER_PANEL_SELECTOR}[open]`).forEach(panel => {
            if (!target || !panel.contains(target)) {
                panel.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeFilterPanels();
        }
    });
}

export function registerLiveList() {
    const init = () => {
        bindFilterPanelInteractions();
        document.querySelectorAll(LIVE_LIST_SELECTOR).forEach(bindLiveList);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('turbo:load', init);
}
