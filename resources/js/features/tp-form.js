function markTpFormChanged() {
    window.Alpine?.store('formProtection')?.markAsChanged?.();
}

function getDeleteIconUrl(pageEl) {
    return pageEl.dataset.deleteIconUrl || '/images/icons/delete.png';
}

export function initTpFormPage(pageSelector, options = {}) {
    var pageEl = document.querySelector(`[data-page="${pageSelector}"]`);
    if (!pageEl || pageEl.dataset.tpFormBound === 'true') return;

    pageEl.dataset.tpFormBound = 'true';

    var settings = {
        enableUnsavedWarning: false,
        ...options,
    };
    var csrfToken = pageEl.dataset.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
    var mataPelajaranId = pageEl.dataset.mataPelajaranId || '';
    var listUrl = pageEl.dataset.listUrl || '';
    var storeUrl = pageEl.dataset.storeUrl || '';
    var destroyBaseUrl = pageEl.dataset.destroyBaseUrl || '';
    var dependencyCheckBaseUrl = pageEl.dataset.dependencyCheckBaseUrl || '';
    var tpData = [];
    var existingData = [];
    var activeFilterLingkupMateri = '';
    var tpTableHasUnsavedData = false;
    var unsavedWarningListenersAttached = false;

    function sanitizeKodeTpValue(value) {
        return String(value ?? '').replace(/[^0-9]/g, '');
    }

    function bindKodeTpNumericOnly(root = document) {
        root.querySelectorAll('input[name="kode_tp[]"]').forEach((input) => {
            if (input.dataset.kodeTpBound === 'true') {
                return;
            }

            input.dataset.kodeTpBound = 'true';
            input.setAttribute('inputmode', 'numeric');
            input.addEventListener('input', function () {
                this.value = sanitizeKodeTpValue(this.value);
            });
        });
    }

    function setTpTableUnsavedState(hasUnsaved) {
        tpTableHasUnsavedData = hasUnsaved;
    }

    function syncTpTableUnsavedState() {
        if (!settings.enableUnsavedWarning) return;
        setTpTableUnsavedState(tpData.length > 0);
    }

    function handleTpBeforeUnload(event) {
        if (!tpTableHasUnsavedData) return;
        event.preventDefault();
        event.returnValue = '';
        return event.returnValue;
    }

    function handleTpTurboBeforeVisit(event) {
        if (!tpTableHasUnsavedData) return;
        if (!confirm('Ada data TP yang belum disimpan. Yakin ingin keluar?')) {
            event.preventDefault();
            return;
        }

        setTpTableUnsavedState(false);
    }

    function cleanupTpUnsavedWarning() {
        setTpTableUnsavedState(false);
        window.removeEventListener('beforeunload', handleTpBeforeUnload);
        document.removeEventListener('turbo:before-visit', handleTpTurboBeforeVisit);
        document.removeEventListener('turbo:before-cache', cleanupTpUnsavedWarning);
        unsavedWarningListenersAttached = false;
    }

    function setupTpUnsavedWarning() {
        if (!settings.enableUnsavedWarning || unsavedWarningListenersAttached) return;
        window.addEventListener('beforeunload', handleTpBeforeUnload);
        document.addEventListener('turbo:before-visit', handleTpTurboBeforeVisit);
        document.addEventListener('turbo:before-cache', cleanupTpUnsavedWarning);
        unsavedWarningListenersAttached = true;
    }

    function renderTable() {
        var tableBody = document.getElementById('tpTableBody');
        var allData = [...existingData, ...tpData];
        var filteredData = activeFilterLingkupMateri ? allData.filter(tp => tp.lingkupMateriId == activeFilterLingkupMateri) : allData;
        var deleteIconUrl = getDeleteIconUrl(pageEl);

        if (!tableBody) return;
        tableBody.innerHTML = '';

        if (!filteredData.length) {
            tableBody.innerHTML = `<tr class="bg-white border-b"><td colspan="5" class="px-6 py-4 text-center text-gray-500">${activeFilterLingkupMateri ? 'Belum ada tujuan pembelajaran untuk lingkup materi yang dipilih' : 'Belum ada tujuan pembelajaran untuk mata pelajaran ini'}</td></tr>`;
            return;
        }

        filteredData.forEach((tp, index) => {
            tableBody.innerHTML += `
                <tr class="bg-white border-b hover:bg-gray-50 ${tp.isNew ? 'bg-green-50' : ''}">
                    <td class="px-6 py-4">${index + 1}</td>
                    <td class="px-6 py-4">${tp.lingkupMateriText}</td>
                    <td class="px-6 py-4">${tp.kodeTP}</td>
                    <td class="px-6 py-4">${tp.deskripsiTP}</td>
                    <td class="px-6 py-4 text-center">
                        <button onclick="${tp.isNew ? 'deleteNewRow' : 'deleteExistingRow'}(${tp.isNew ? tpData.indexOf(tp) : existingData.indexOf(tp)}, ${tp.id || 'null'})" class="hover:opacity-80 text-red-600" title="${tp.isNew ? 'Hapus dari tabel' : 'Hapus dari database'}">
                            <img src="${deleteIconUrl}" alt="Delete" class="w-5 h-5 inline">
                        </button>
                    </td>
                </tr>
            `;
        });
    }

    function clearForm() {
        var tpContainer = document.getElementById('tpContainer');
        if (!tpContainer) return;

        tpContainer.innerHTML = `
            <div class="flex items-center mb-2">
                <input type="text" name="kode_tp[]" placeholder="Kode TP (contoh: 1)" inputmode="numeric" required class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
                <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                <button type="button" onclick="addTPRow()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700" title="Tambah baris input"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/></svg></button>
            </div>
        `;
        if (activeFilterLingkupMateri) document.getElementById('lingkup_materi').value = activeFilterLingkupMateri;
        bindKodeTpNumericOnly(tpContainer);
    }

    function validateInputs() {
        var lingkupMateri = document.getElementById('lingkup_materi')?.value;
        var kodeTPs = document.getElementsByName('kode_tp[]');
        var deskripsiTPs = document.getElementsByName('deskripsi_tp[]');
        var i = 0;

        if (!lingkupMateri) return alert('Lingkup Materi harus dipilih!'), false;
        for (i = 0; i < kodeTPs.length; i += 1) {
            var kodeTpValue = kodeTPs[i].value.trim();
            var sanitizedKodeTp = sanitizeKodeTpValue(kodeTpValue);
            if (!kodeTpValue) return alert(`Kode TP ${i + 1} tidak boleh kosong!`), kodeTPs[i].focus(), false;
            if (kodeTpValue !== sanitizedKodeTp || !sanitizedKodeTp) {
                alert(`Kode TP ${i + 1} harus berupa angka.`);
                kodeTPs[i].focus();
                return false;
            }
            kodeTPs[i].value = sanitizedKodeTp;
            if (!deskripsiTPs[i].value.trim()) return alert(`Deskripsi TP ${i + 1} tidak boleh kosong!`), deskripsiTPs[i].focus(), false;
        }
        return true;
    }

    async function loadExistingData() {
        try {
            var response = await fetch(listUrl, { method: 'GET', headers: { 'Content-Type': 'application/json', Accept: 'application/json' } });
            var data = await response.json();
            if (!data.success) return console.error('Error loading data:', data.message);
            existingData = data.tujuanPembelajarans.map(tp => ({ id: tp.id, lingkupMateriId: tp.lingkup_materi_id, lingkupMateriText: tp.lingkup_materi.judul_lingkup_materi, kodeTP: tp.kode_tp, deskripsiTP: tp.deskripsi_tp, isNew: false }));
            renderTable();
            syncTpTableUnsavedState();
        } catch (error) {
            console.error('Error fetching existing data:', error);
        }
    }

    window.addTPRow = function () {
        var container = document.getElementById('tpContainer');
        var div = document.createElement('div');
        div.className = 'flex items-center mb-2';
        div.innerHTML = `
            <input type="text" name="kode_tp[]" placeholder="Kode TP (contoh: 1)" inputmode="numeric" required class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
            <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            <button type="button" onclick="removeTPRow(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700" title="Hapus baris input"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        `;
        container.appendChild(div);
        bindKodeTpNumericOnly(div);
        markTpFormChanged();
    };

    window.removeTPRow = function (button) { button.parentElement.remove(); markTpFormChanged(); };
    window.addRow = function () {
        if (!validateInputs()) return;

        var lingkupMateriId = document.getElementById('lingkup_materi').value;
        var lingkupMateriText = document.getElementById('lingkup_materi').options[document.getElementById('lingkup_materi').selectedIndex].text;
        var kodeTPs = Array.from(document.getElementsByName('kode_tp[]'));
        var deskripsiTPs = document.getElementsByName('deskripsi_tp[]');
        var newRows = [];

        for (var index = 0; index < kodeTPs.length; index += 1) {
            var kodeTP = sanitizeKodeTpValue(kodeTPs[index].value.trim());
            var deskripsiTP = deskripsiTPs[index].value.trim();
            kodeTPs[index].value = kodeTP;
            if (tpData.some(item => item.kodeTP === kodeTP) || existingData.some(item => item.kodeTP === kodeTP && item.lingkupMateriId == lingkupMateriId) || newRows.some(item => item.kodeTP === kodeTP)) {
                alert(`Kode TP "${kodeTP}" sudah ada dalam tabel!`);
                return;
            }
            newRows.push({ id: null, lingkupMateriId, lingkupMateriText, kodeTP, deskripsiTP, isNew: true });
        }

        tpData.push(...newRows);
        renderTable();
        clearForm();
        markTpFormChanged();
        syncTpTableUnsavedState();
    };

    window.deleteNewRow = function (index) {
        if (!confirm('Apakah Anda yakin ingin menghapus data ini dari tabel?')) return;
        tpData.splice(index, 1);
        renderTable();
        markTpFormChanged();
        syncTpTableUnsavedState();
    };

    window.deleteExistingRow = async function (index, id) {
        if (!id) return;
        try {
            if (dependencyCheckBaseUrl) {
                var dependencyResponse = await fetch(`${dependencyCheckBaseUrl}/${id}/check-dependencies`, { method: 'GET', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } });
                var dependencyResult = await dependencyResponse.json();
                var confirmMessage = dependencyResult.hasDependents
                    ? 'PERHATIAN: Tujuan pembelajaran ini memiliki data penilaian terkait. Menghapus tujuan pembelajaran akan menghapus SEMUA data penilaian yang terkait. Apakah Anda tetap ingin melanjutkan?'
                    : 'Apakah Anda yakin ingin menghapus tujuan pembelajaran ini?';
                if (!confirm(confirmMessage)) return;
            } else if (!confirm('Apakah Anda yakin ingin menghapus data ini? Data akan langsung dihapus dari database.')) {
                return;
            }

            var response = await fetch(`${destroyBaseUrl}/${id}`, { method: 'DELETE', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } });
            if (response.status === 400) {
                var warningData = await response.json();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tidak Dapat Dihapus',
                        text: warningData.message || 'TP ini sudah memiliki data nilai. Hapus nilai terlebih dahulu sebelum menghapus TP ini.',
                        confirmButtonText: 'Mengerti',
                    });
                } else {
                    alert(warningData.message || 'TP ini sudah memiliki data nilai. Hapus nilai terlebih dahulu sebelum menghapus TP ini.');
                }
                return;
            }

            if (!response.ok) {
                var errorData = {};
                try {
                    errorData = await response.json();
                } catch (parseError) {
                    errorData = {};
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menghapus',
                        text: errorData.message || 'Terjadi kesalahan. Silakan coba lagi.',
                    });
                } else {
                    alert(errorData.message || 'Terjadi kesalahan. Silakan coba lagi.');
                }
                return;
            }

            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Gagal menghapus data');
            existingData.splice(index, 1);
            renderTable();
            markTpFormChanged();
            alert('Data berhasil dihapus dari database!');
        } catch (error) {
            console.error('Error:', error);
            alert(`Terjadi kesalahan saat menghapus data: ${error.message}`);
        }
    };

    window.saveData = async function () {
        try {
            if (!tpData.length) return alert('Tidak ada data baru untuk disimpan!');
            var response = await fetch(storeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ tpData, mataPelajaranId }) });
            var data = await response.json();
            if (!data.success) throw new Error(data.message || 'Terjadi kesalahan saat menyimpan data.');
            tpData = [];
            setTpTableUnsavedState(false);
            window.Alpine?.store('formProtection')?.reset?.();
            alert('Data berhasil disimpan!');
            window.location.reload();
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.');
            if (window.Alpine?.store('formProtection')) window.Alpine.store('formProtection').isSubmitting = false;
        }
    };

    activeFilterLingkupMateri = '';
    document.getElementById('table-filter').value = '';
    setupTpUnsavedWarning();
    bindKodeTpNumericOnly(pageEl);
    loadExistingData();
    document.getElementById('table-filter')?.addEventListener('change', function () { activeFilterLingkupMateri = this.value; renderTable(); if (this.value) document.getElementById('lingkup_materi').value = this.value; });
    document.getElementById('addTPForm')?.addEventListener('keypress', function (event) { if (event.key === 'Enter') { event.preventDefault(); if (validateInputs()) window.addRow(); } });
    document.getElementById('addTPForm')?.addEventListener('blur', function (event) { if (event.target.hasAttribute('required') && !event.target.value.trim()) { event.target.classList.add('border-red-500'); event.target.setAttribute('title', 'Field ini wajib diisi!'); } else { event.target.classList.remove('border-red-500'); event.target.removeAttribute('title'); } }, true);
    document.getElementById('lingkup_materi')?.addEventListener('change', function () { if (this.value) { document.getElementById('table-filter').value = this.value; activeFilterLingkupMateri = this.value; renderTable(); } });
}
