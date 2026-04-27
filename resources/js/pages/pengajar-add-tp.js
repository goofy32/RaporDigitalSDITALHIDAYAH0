export function initPengajarAddTpPage() {
    var pageEl = document.querySelector('[data-page="pengajar-add-tp"]');
    if (!pageEl) return;

    var csrfToken = pageEl.dataset.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
    var mataPelajaranId = pageEl.dataset.mataPelajaranId || '';
    var listUrl = pageEl.dataset.listUrl || '';
    var storeUrl = pageEl.dataset.storeUrl || '';
    var destroyBaseUrl = pageEl.dataset.destroyBaseUrl || '';
    var tpData = [];
    var existingData = [];
    var activeFilterLingkupMateri = '';

    function renderTable() {
        var tableBody = document.getElementById('tpTableBody');
        var allData = [...existingData, ...tpData];
        var filteredData = activeFilterLingkupMateri
            ? allData.filter(tp => tp.lingkupMateriId == activeFilterLingkupMateri)
            : allData;

        if (!tableBody) return;
        tableBody.innerHTML = '';

        if (!filteredData.length) {
            tableBody.innerHTML = `
                <tr class="bg-white border-b">
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                        ${activeFilterLingkupMateri ? 'Belum ada tujuan pembelajaran untuk lingkup materi yang dipilih' : 'Belum ada tujuan pembelajaran untuk mata pelajaran ini'}
                    </td>
                </tr>
            `;
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
                            <img src="/images/icons/delete.png" alt="Delete" class="w-5 h-5 inline">
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
                <input type="text" name="kode_tp[]" placeholder="Kode TP" required class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
                <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                <button type="button" onclick="addTPRow()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700" title="Tambah baris input">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                    </svg>
                </button>
            </div>
        `;

        if (activeFilterLingkupMateri) {
            document.getElementById('lingkup_materi').value = activeFilterLingkupMateri;
        }
    }

    function validateInputs() {
        var lingkupMateri = document.getElementById('lingkup_materi')?.value;
        var kodeTPs = document.getElementsByName('kode_tp[]');
        var deskripsiTPs = document.getElementsByName('deskripsi_tp[]');
        var i = 0;

        if (!lingkupMateri) {
            alert('Lingkup Materi harus dipilih!');
            return false;
        }

        for (i = 0; i < kodeTPs.length; i += 1) {
            if (!kodeTPs[i].value.trim()) {
                alert(`Kode TP ${i + 1} tidak boleh kosong!`);
                kodeTPs[i].focus();
                return false;
            }
            if (!deskripsiTPs[i].value.trim()) {
                alert(`Deskripsi TP ${i + 1} tidak boleh kosong!`);
                deskripsiTPs[i].focus();
                return false;
            }
        }

        return true;
    }

    async function loadExistingData() {
        try {
            var response = await fetch(listUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });
            var data = await response.json();

            if (!data.success) {
                console.error('Error loading data:', data.message);
                return;
            }

            existingData = data.tujuanPembelajarans.map(tp => ({
                id: tp.id,
                lingkupMateriId: tp.lingkup_materi_id,
                lingkupMateriText: tp.lingkup_materi.judul_lingkup_materi,
                kodeTP: tp.kode_tp,
                deskripsiTP: tp.deskripsi_tp,
                isNew: false,
            }));

            renderTable();
        } catch (error) {
            console.error('Error fetching existing data:', error);
        }
    }

    window.addTPRow = function () {
        var container = document.getElementById('tpContainer');
        var div = document.createElement('div');

        div.className = 'flex items-center mb-2';
        div.innerHTML = `
            <input type="text" name="kode_tp[]" placeholder="Kode TP" required class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
            <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            <button type="button" onclick="removeTPRow(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700" title="Hapus baris input">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        `;

        container.appendChild(div);
        window.Alpine?.store('formProtection')?.markAsChanged();
    };

    window.removeTPRow = function (button) {
        button.parentElement.remove();
        window.Alpine?.store('formProtection')?.markAsChanged();
    };

    window.addRow = function () {
        var lingkupMateriId;
        var lingkupMateriText;
        var kodeTPs;
        var deskripsiTPs;
        var i = 0;

        if (!validateInputs()) return;

        lingkupMateriId = document.getElementById('lingkup_materi').value;
        lingkupMateriText = document.getElementById('lingkup_materi').options[document.getElementById('lingkup_materi').selectedIndex].text;
        kodeTPs = document.getElementsByName('kode_tp[]');
        deskripsiTPs = document.getElementsByName('deskripsi_tp[]');

        for (i = 0; i < kodeTPs.length; i += 1) {
            var kodeTP = kodeTPs[i].value.trim();
            var deskripsiTP = deskripsiTPs[i].value.trim();

            if (tpData.some(item => item.kodeTP === kodeTP) || existingData.some(item => item.kodeTP === kodeTP && item.lingkupMateriId == lingkupMateriId)) {
                alert(`Kode TP "${kodeTP}" sudah ada dalam tabel!`);
                return;
            }

            tpData.push({
                id: null,
                lingkupMateriId,
                lingkupMateriText,
                kodeTP,
                deskripsiTP,
                isNew: true,
            });
        }

        renderTable();
        clearForm();
        window.Alpine?.store('formProtection')?.markAsChanged();
    };

    window.deleteNewRow = function (index) {
        if (!confirm('Apakah Anda yakin ingin menghapus data ini dari tabel?')) return;
        tpData.splice(index, 1);
        renderTable();
        window.Alpine?.store('formProtection')?.markAsChanged();
    };

    window.deleteExistingRow = async function (index, id) {
        if (!id) return;
        if (!confirm('Apakah Anda yakin ingin menghapus data ini? Data akan langsung dihapus dari database.')) return;

        try {
            var response = await fetch(`${destroyBaseUrl}/${id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            var result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Gagal menghapus data');
            }

            existingData.splice(index, 1);
            renderTable();
            window.Alpine?.store('formProtection')?.markAsChanged();
            alert('Data berhasil dihapus dari database!');
        } catch (error) {
            console.error('Error:', error);
            alert(`Terjadi kesalahan saat menghapus data: ${error.message}`);
        }
    };

    window.saveData = async function () {
        try {
            if (!tpData.length) {
                alert('Tidak ada data baru untuk disimpan!');
                return;
            }

            var response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    tpData,
                    mataPelajaranId,
                }),
            });
            var data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Terjadi kesalahan saat menyimpan data.');
            }

            window.Alpine?.store('formProtection')?.reset();
            alert('Data berhasil disimpan!');
            window.location.reload();
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.');
            if (window.Alpine?.store('formProtection')) {
                window.Alpine.store('formProtection').isSubmitting = false;
            }
        }
    };

    activeFilterLingkupMateri = '';
    document.getElementById('table-filter').value = '';
    loadExistingData();

    document.getElementById('table-filter')?.addEventListener('change', function () {
        activeFilterLingkupMateri = this.value;
        renderTable();
        if (this.value) {
            document.getElementById('lingkup_materi').value = this.value;
        }
    });

    document.getElementById('addTPForm')?.addEventListener('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (validateInputs()) {
                window.addRow();
            }
        }
    });

    document.getElementById('addTPForm')?.addEventListener('blur', function (event) {
        if (event.target.hasAttribute('required') && !event.target.value.trim()) {
            event.target.classList.add('border-red-500');
            event.target.setAttribute('title', 'Field ini wajib diisi!');
        } else {
            event.target.classList.remove('border-red-500');
            event.target.removeAttribute('title');
        }
    }, true);

    document.getElementById('lingkup_materi')?.addEventListener('change', function () {
        if (this.value) {
            document.getElementById('table-filter').value = this.value;
            activeFilterLingkupMateri = this.value;
            renderTable();
        }
    });
}
