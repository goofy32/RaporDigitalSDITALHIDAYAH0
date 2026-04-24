import { renderAsync } from 'docx-preview';

export function initAdminReportPage() {
    const page = document.getElementById('admin-report-page');
    if (!page || page.dataset.initialized === 'true') return;
    page.dataset.initialized = 'true';

    window.renderAsync = renderAsync;

    const filterType = document.getElementById('filter-type');
    const searchInput = document.getElementById('search-input');
    const uploadForm = document.getElementById('uploadForm');
    const officeViewerBtn = document.getElementById('officeViewerBtn');
    let currentPreviewUrl = '';

    const applyFilters = () => {
        const typeFilter = filterType?.value || '';
        const searchFilter = (searchInput?.value || '').toLowerCase();
        document.querySelectorAll('tbody tr').forEach(row => {
            const rowType = row.getAttribute('data-type');
            const rowSearchText = (row.getAttribute('data-search') || '').toLowerCase();
            row.style.display = (!typeFilter || rowType === typeFilter) && (!searchFilter || rowSearchText.includes(searchFilter)) ? '' : 'none';
        });
    };

    filterType?.addEventListener('change', applyFilters);
    searchInput?.addEventListener('input', applyFilters);

    const typeRadios = document.querySelectorAll('input[name="type"]');
    const downloadSampleLink = document.getElementById('download-sample-link');
    typeRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            if (downloadSampleLink) downloadSampleLink.href = `${page.dataset.sampleUrl}?type=${this.value}`;
        });
    });

    uploadForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = document.getElementById('upload-button');
        try {
            const formData = new FormData(this);
            button.disabled = true;
            button.textContent = 'Uploading...';

            for (const pair of [...formData.entries()]) {
                if (pair[0] === 'kelas_ids[]') formData.delete(pair[0]);
            }
            document.querySelectorAll('.kelas-checkbox:checked').forEach(checkbox => {
                formData.append('kelas_ids[]', checkbox.value);
            });

            const response = await fetch(this.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    Accept: 'application/json'
                }
            });
            const result = await response.json();
            button.disabled = false;
            button.textContent = 'Upload';
            result.success ? window.location.reload() : alert(result.message || 'Gagal mengupload template. Pastikan semua placeholder wajib tersedia dalam template.');
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Upload';
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengupload template.');
        }
    });

        window.openUploadModal = function (type = 'UTS') {
            document.querySelectorAll('input[name="type"]').forEach(radio => {
                radio.checked = radio.value === type;
            });
            document.getElementById('uploadModal')?.classList.remove('hidden');
        };

        window.closeUploadModal = function () {
            document.getElementById('uploadModal')?.classList.add('hidden');
        };

        window.openPlaceholderGuide = function () {
            document.getElementById('placeholderGuide')?.classList.remove('hidden');
        };

        window.closePlaceholderGuide = function () {
            document.getElementById('placeholderGuide')?.classList.add('hidden');
        };

        window.previewDocument = async function (url, filename) {
            document.getElementById('docxPreviewModal')?.classList.remove('hidden');
            document.getElementById('previewFileName').textContent = `Preview: ${filename}`;
            document.getElementById('loadingIndicator').style.display = 'flex';
            document.getElementById('errorMessage').classList.add('hidden');
            document.getElementById('docxContent').innerHTML = '';
            currentPreviewUrl = url;
            document.getElementById('downloadDocxBtn').href = url;

            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const arrayBuffer = await response.arrayBuffer();
                document.getElementById('loadingIndicator').style.display = 'none';
                if (typeof window.renderAsync !== 'function') throw new Error('DocX Preview library not found. Use the fallback options instead.');
                await window.renderAsync(arrayBuffer, document.getElementById('docxContent'), null, {
                    className: 'docx-viewer',
                    inWrapper: true,
                    ignoreWidth: false,
                    ignoreHeight: false,
                    breakPages: true,
                    renderHeaders: true,
                    renderFooters: true,
                    useBase64URL: true,
                    useMathMLPolyfill: true,
                    pageWidth: 794,
                    pageHeight: 1123,
                    pageBorderTop: 10,
                    pageBorderRight: 10,
                    pageBorderBottom: 10,
                    pageBorderLeft: 10
                });
            } catch (error) {
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('errorMessage').classList.remove('hidden');
                document.getElementById('errorDetail').textContent = error.message;
                console.error('Error rendering DOCX:', error);
            }
        };

        window.closeDocxPreviewModal = function () {
            document.getElementById('docxPreviewModal')?.classList.add('hidden');
            document.getElementById('docxContent').innerHTML = '';
        };

        window.openDocxInOfficeViewer = function (url) {
            const publicUrl = url.startsWith('http') ? url : window.location.origin + (url.startsWith('/') ? '' : '/') + url;
            const docxContent = document.getElementById('docxContent');
            docxContent.innerHTML = '';
            const iframeContainer = document.createElement('div');
            iframeContainer.className = 'w-full h-full min-h-[70vh]';
            const iframe = document.createElement('iframe');
            iframe.src = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(publicUrl)}`;
            iframe.className = 'w-full h-full min-h-[70vh] border-0';
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allowfullscreen', 'true');
            iframeContainer.appendChild(iframe);
            docxContent.appendChild(iframeContainer);
            document.getElementById('loadingIndicator').style.display = 'none';
        };

    officeViewerBtn && (officeViewerBtn.onclick = () => window.openDocxInOfficeViewer(currentPreviewUrl));

    document.addEventListener('click', event => {
        if (!document.getElementById('admin-report-page')) return;
        if (event.target.classList.contains('fixed')) {
            window.closeUploadModal();
            window.closePlaceholderGuide();
            window.closeDocxPreviewModal();
        }
        const dropdown = document.getElementById('kelas-dropdown');
        const button = document.getElementById('kelas-dropdown-btn');
        if (dropdown && button && !dropdown.contains(event.target) && !button.contains(event.target)) dropdown.classList.add('hidden');
    });

    document.addEventListener('keydown', event => {
        if (!document.getElementById('admin-report-page')) return;
        if (event.key === 'Escape') {
            window.closeUploadModal();
            window.closePlaceholderGuide();
            window.closeDocxPreviewModal();
        }
    });
}

window.handleActivateToggle = async function (event) {
    event.preventDefault();
    const checkbox = event.target.type === 'checkbox' ? event.target : event.target.querySelector('input[type="checkbox"]');
    const form = checkbox.closest('form');
    const isActive = checkbox.checked;
    const actionWord = isActive ? 'mengaktifkah' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionWord} template ini?`)) {
        checkbox.checked = !checkbox.checked;
        return false;
    }
    checkbox.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        const result = await response.json();
        result.success ? window.location.reload() : (alert(result.message || `Gagal ${actionWord} template`), checkbox.disabled = false, checkbox.checked = !checkbox.checked);
    } catch (error) {
        console.error('Error:', error);
        alert(`Terjadi kesalahan saat ${actionWord} template`);
        checkbox.disabled = false;
        checkbox.checked = !checkbox.checked;
    }
    return false;
};

window.handleActivate = async function (event) {
    event.preventDefault();
    if (!confirm('Apakah Anda yakin ingin mengaktifkan template ini?')) return false;
    const form = event.target;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        const result = await response.json();
        result.success ? window.location.reload() : (alert(result.message || 'Gagal mengaktifkan template'), button.disabled = false);
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat mengaktifkan template');
        button.disabled = false;
    }
    return false;
};

window.handleDelete = async function (event) {
    event.preventDefault();
    if (!confirm('Apakah Anda yakin ingin menghapus template ini?')) return false;
    const form = event.target;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        const result = await response.json();
        result.success ? window.location.reload() : (alert(result.message || 'Gagal menghapus template'), button.disabled = false);
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menghapus template');
        button.disabled = false;
    }
    return false;
};

window.showTemplateClasses = function (classes) {
    const classesList = classes.map(kelas => `<li class="py-1">${kelas}</li>`).join('');
    Swal.fire({
        title: 'Kelas yang Menggunakan Template',
        html: `<ul class="text-left list-disc pl-5">${classesList}</ul>`,
        confirmButtonText: 'Tutup'
    });
};

window.toggleKelasDropdown = function () {
    document.getElementById('kelas-dropdown')?.classList.toggle('hidden');
};

window.toggleAllKelas = function (checkbox) {
    document.querySelectorAll('.kelas-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    window.updateSelectedKelasText();
};

window.updateSelectedKelasText = function () {
    const checkboxes = document.querySelectorAll('.kelas-checkbox:checked');
    const selectAllCheckbox = document.getElementById('select-all-kelas');
    const selectedKelasElement = document.getElementById('selected-kelas');
    if (!selectedKelasElement || !selectAllCheckbox) return;

    if (checkboxes.length === 0) {
        selectedKelasElement.textContent = 'Pilih Kelas';
        selectAllCheckbox.checked = false;
    } else if (checkboxes.length === document.querySelectorAll('.kelas-checkbox').length) {
        selectedKelasElement.textContent = 'Semua Kelas';
        selectAllCheckbox.checked = true;
    } else if (checkboxes.length <= 2) {
        selectedKelasElement.textContent = Array.from(checkboxes).map(cb => cb.parentElement.querySelector('span').textContent.trim()).join(', ');
        selectAllCheckbox.checked = false;
    } else {
        selectedKelasElement.textContent = `${checkboxes.length} kelas dipilih`;
        selectAllCheckbox.checked = false;
    }
};
