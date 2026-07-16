const BULK_DELETE_SELECTOR = '[data-bulk-delete]';

function selectedCheckboxes(container) {
    return Array.from(container.querySelectorAll('[data-bulk-delete-checkbox]:checked'));
}

function allCheckboxes(container) {
    return Array.from(container.querySelectorAll('[data-bulk-delete-checkbox]'));
}

function syncToolbar(container, toolbar) {
    const selected = selectedCheckboxes(container);
    const count = selected.length;
    const countText = toolbar.querySelector('[data-bulk-delete-count]');
    const selectedCount = toolbar.querySelector('[data-bulk-delete-selected-count]');
    const openButton = toolbar.querySelector('[data-bulk-delete-open]');
    const selectAll = container.querySelector('[data-bulk-delete-select-all]');
    const checkboxes = allCheckboxes(container);

    if (countText) {
        countText.textContent = `${count} data dipilih`;
    }

    if (selectedCount) {
        selectedCount.textContent = count.toString();
    }

    if (openButton) {
        openButton.disabled = count === 0;
    }

    if (selectAll) {
        selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
    }
}

function closeModal(toolbar) {
    const modal = toolbar.querySelector('[data-bulk-delete-modal]');
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

function openModal(container, toolbar) {
    const selected = selectedCheckboxes(container);

    if (selected.length === 0) {
        return;
    }

    const hiddenInputs = toolbar.querySelector('[data-bulk-delete-hidden-inputs]');
    if (hiddenInputs) {
        hiddenInputs.innerHTML = '';
        selected.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = checkbox.value;
            hiddenInputs.appendChild(input);
        });
    }

    const modal = toolbar.querySelector('[data-bulk-delete-modal]');
    modal?.classList.remove('hidden');
    modal?.classList.add('flex');
}

function clearSelection(container, toolbar) {
    allCheckboxes(container).forEach(checkbox => {
        checkbox.checked = false;
    });

    syncToolbar(container, toolbar);
    closeModal(toolbar);
}

function bindBulkDelete(toolbar) {
    if (toolbar.dataset.bulkDeleteBound === 'true') {
        return;
    }

    toolbar.dataset.bulkDeleteBound = 'true';

    const liveList = toolbar.closest('[data-live-list]') || document;

    toolbar.querySelector('[data-bulk-delete-open]')?.addEventListener('click', () => openModal(liveList, toolbar));
    toolbar.querySelector('[data-bulk-delete-cancel]')?.addEventListener('click', () => closeModal(toolbar));

    toolbar.querySelector('[data-bulk-delete-modal]')?.addEventListener('click', event => {
        if (event.target === event.currentTarget) {
            closeModal(toolbar);
        }
    });

    liveList.addEventListener('change', event => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (target.matches('[data-bulk-delete-select-all]')) {
            allCheckboxes(liveList).forEach(checkbox => {
                checkbox.checked = target.checked;
            });
        }

        if (target.matches('[data-bulk-delete-checkbox], [data-bulk-delete-select-all]')) {
            syncToolbar(liveList, toolbar);
        }
    });

    liveList.addEventListener('live-list:updated', () => clearSelection(liveList, toolbar));
    syncToolbar(liveList, toolbar);
}

export function registerBulkDelete() {
    const init = () => {
        document.querySelectorAll(BULK_DELETE_SELECTOR).forEach(bindBulkDelete);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('turbo:load', init);
}
