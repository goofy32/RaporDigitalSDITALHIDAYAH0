const LIVE_LIST_SELECTOR = '[data-live-list]';
const FILTER_PANEL_SELECTOR = '[data-live-filter-panel]';
const DEBOUNCE_MS = 400;
const liveListInstances = new WeakMap();
const activeLiveListInstances = new Set();
let lifecycleListenersBound = false;

function debounce(callback, delay = DEBOUNCE_MS) {
    let timeoutId = null;

    const debounced = (...args) => {
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(() => callback(...args), delay);
    };

    debounced.cancel = () => {
        window.clearTimeout(timeoutId);
        timeoutId = null;
    };

    return debounced;
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

function pagePathFor(container) {
    const form = container.querySelector('[data-live-list-form]');
    return new URL(form?.action || window.location.href, window.location.origin).pathname;
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

function isInstanceCurrent(instance) {
    return !instance.destroyed
        && liveListInstances.get(instance.container) === instance
        && instance.container.dataset.liveListBound === 'true'
        && instance.container.isConnected
        && document.body.contains(instance.container)
        && window.location.pathname === instance.pagePath;
}

function isLatestRequest(instance, sequence, controller) {
    return isInstanceCurrent(instance)
        && instance.requestSequence === sequence
        && instance.abortController === controller
        && !controller.signal.aborted;
}

function abortActiveRequest(instance) {
    instance.abortController?.abort();
    instance.abortController = null;
}

function stopActiveRequest(instance) {
    abortActiveRequest(instance);
    instance.debouncedSearch?.cancel?.();
    setLoading(instance.container, false);
}

function destroyLiveList(instance) {
    if (instance.destroyed) {
        return;
    }

    stopActiveRequest(instance);
    instance.destroyed = true;
    instance.cleanupCallbacks.forEach(cleanup => cleanup());
    instance.cleanupCallbacks = [];
    setLoading(instance.container, false);
    delete instance.container.dataset.liveListBound;
    liveListInstances.delete(instance.container);
    activeLiveListInstances.delete(instance);
}

function stopAllLiveListRequests() {
    activeLiveListInstances.forEach(stopActiveRequest);
}

function destroyAllLiveLists() {
    Array.from(activeLiveListInstances).forEach(destroyLiveList);
}

function ensureLifecycleListeners() {
    if (lifecycleListenersBound) {
        return;
    }

    lifecycleListenersBound = true;
    document.addEventListener('turbo:before-visit', stopAllLiveListRequests);
    document.addEventListener('turbo:before-cache', destroyAllLiveLists);
}

async function fetchLiveList(instance, url, { replace = false } = {}) {
    const { container } = instance;
    const target = container.querySelector('[data-live-list-results]');

    if (!target) {
        if (isInstanceCurrent(instance)) {
            window.location.href = url.toString();
        }

        return;
    }

    abortActiveRequest(instance);

    const controller = new AbortController();
    const sequence = instance.requestSequence + 1;
    instance.requestSequence = sequence;
    instance.abortController = controller;

    setLoading(container, true);

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!isLatestRequest(instance, sequence, controller)) {
            return;
        }

        if (!response.ok) {
            if (isLatestRequest(instance, sequence, controller)) {
                window.location.href = url.toString();
            }

            return;
        }

        const payload = await response.json();

        if (!isLatestRequest(instance, sequence, controller)) {
            return;
        }

        const currentTarget = container.querySelector('[data-live-list-results]');
        if (!currentTarget) {
            return;
        }

        currentTarget.innerHTML = payload.html ?? '';

        if (replace) {
            window.history.replaceState({}, '', url.toString());
        } else {
            window.history.pushState({}, '', url.toString());
        }
    } catch (error) {
        if (isAbortError(error) || !isLatestRequest(instance, sequence, controller)) {
            return;
        }

        console.error('Live list update failed:', error);
        window.location.href = url.toString();
    } finally {
        if (isLatestRequest(instance, sequence, controller)) {
            instance.abortController = null;
            setLoading(container, false);
        }
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
    const instance = {
        container,
        form,
        pagePath: pagePathFor(container),
        requestSequence: 0,
        abortController: null,
        destroyed: false,
        debouncedSearch: null,
        cleanupCallbacks: [],
    };

    liveListInstances.set(container, instance);
    activeLiveListInstances.add(instance);

    const runSearch = debounce(() => {
        if (!form || !isInstanceCurrent(instance)) return;
        fetchLiveList(instance, formUrl(form));
    });
    instance.debouncedSearch = runSearch;

    const handleSubmit = event => {
        event.preventDefault();
        closeFilterPanels(container);
        fetchLiveList(instance, formUrl(form));
    };

    form?.addEventListener('submit', handleSubmit);
    if (form) {
        instance.cleanupCallbacks.push(() => form.removeEventListener('submit', handleSubmit));
    }

    form?.querySelectorAll('[data-live-search-input]').forEach(input => {
        input.addEventListener('input', runSearch);
        instance.cleanupCallbacks.push(() => input.removeEventListener('input', runSearch));
    });

    const handleClick = event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('[data-live-list-ignore]')) {
            return;
        }

        const paginationLink = target.closest('[data-live-list-results] [data-live-list-pagination] a[href]');
        if (paginationLink) {
            const url = new URL(paginationLink.href, window.location.origin);

            if (url.origin === window.location.origin) {
                event.preventDefault();
                fetchLiveList(instance, url);
            }

            return;
        }

        const resetLink = target.closest('[data-live-reset]');
        if (resetLink) {
            event.preventDefault();
            closeFilterPanels(container);
            fetchLiveList(instance, new URL(resetLink.href, window.location.origin));
        }
    };

    container.addEventListener('click', handleClick);
    instance.cleanupCallbacks.push(() => container.removeEventListener('click', handleClick));

    const handlePopState = () => {
        if (!isInstanceCurrent(instance)) return;
        syncFormFromUrl(container);
        fetchLiveList(instance, new URL(window.location.href), { replace: true });
    };

    window.addEventListener('popstate', handlePopState);
    instance.cleanupCallbacks.push(() => window.removeEventListener('popstate', handlePopState));
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
        ensureLifecycleListeners();
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
