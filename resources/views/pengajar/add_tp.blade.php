@extends('layouts.pengajar.app')

@section('title', 'Tambah Tujuan Pembelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14 shadow-lg rounded-lg">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-2xl font-bold text-green-700 break-words max-w-full sm:max-w-lg">Tujuan Pembelajaran untuk {{ $mataPelajaran->nama_pelajaran }}</h2>
            <div class="flex flex-wrap gap-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button @click="handleAjaxSubmit" onclick="saveData()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan ke Database
                </button>
            </div>
        </div>
        
        <!-- Informasi Alur Kerja -->
        <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-green-800">
                        <strong>Petunjuk:</strong> Pilih lingkup materi, isi kode dan deskripsi TP, lalu klik tombol "Tambah ke Tabel" untuk menambahkan ke tabel. Klik "Simpan ke Database" untuk menyimpan semua data baru ke database.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Form -->
        <form id="addTPForm" x-data="formProtection" class="space-y-6">
            @csrf
            <!-- Mata Pelajaran -->

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                <p class="text-gray-700 font-semibold">{{ $mataPelajaran->nama_pelajaran }}</p>
                <input type="hidden" id="mata_pelajaran_id" value="{{ $mataPelajaran->id }}">
            </div>

            <!-- Lingkup Materi Dropdown -->
            <div>
                <label for="lingkup_materi" class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                <select id="lingkup_materi" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <option value="">Pilih Lingkup Materi</option>
                    @foreach($mataPelajaran->lingkupMateris as $lm)
                        <option value="{{ $lm->id }}">{{ $lm->judul_lingkup_materi }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Kode TP dan Deskripsi TP Inputs -->
            <div id="tpContainer">
                <div class="flex items-center mb-2">
                    <input type="text" name="kode_tp[]" placeholder="Kode TP (contoh: TP1)" required
                        class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
                    <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required
                        class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <button type="button" onclick="addTPRow()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700" title="Tambah baris input">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>
            </div>
            <!-- Tambah Button dengan label yang lebih jelas -->
            <button type="button" onclick="addRow()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Tambah ke Tabel
            </button>
        </form>

        <!-- Filter untuk tabel -->
        <div class="flex items-center mt-6 mb-3">
            <label class="mr-2 text-sm font-medium text-gray-900">Filter Tabel:</label>
            <select id="table-filter" class="p-2 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                <option value="">Semua Lingkup Materi</option>
                @foreach($mataPelajaran->lingkupMateris as $lm)
                    <option value="{{ $lm->id }}">{{ $lm->judul_lingkup_materi }}</option>
                @endforeach
            </select>
        </div>

        <!-- Tabel dengan caption yang menjelaskan warna latar -->
        <div class="overflow-x-auto bg-white shadow-md rounded-lg mt-3">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">No</th>
                        <th class="px-6 py-3">Lingkup Materi</th>
                        <th class="px-6 py-3">Kode TP</th>
                        <th class="px-6 py-3">Deskripsi TP</th>
                        <th class="px-6 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tpTableBody">
                    <!-- Data will be added dynamically -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JavaScript Section -->
<script>
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    var mataPelajaranId = '{{ $mataPelajaran->id }}';
    var tpData = [];
    var existingData = [];
    var activeFilterLingkupMateri = '';

    function addTPRow() {
        const container = document.getElementById('tpContainer');
        const div = document.createElement('div');
        div.className = 'flex items-center mb-2';
        
        div.innerHTML = `
            <input type="text" name="kode_tp[]" placeholder="Kode TP" required
                class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
            <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required
                class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            <button type="button" onclick="removeTPRow(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700" title="Hapus baris input">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        `;
        
        container.appendChild(div);
        Alpine.store('formProtection').markAsChanged();
    }

    function removeTPRow(button) {
        button.parentElement.remove();
        Alpine.store('formProtection').markAsChanged();
    }

    function addRow() {
        if (!validateInputs()) {
            return;
        }

        const lingkupMateriId = document.getElementById('lingkup_materi').value;
        const kodeTPs = document.getElementsByName('kode_tp[]');
        const deskripsiTPs = document.getElementsByName('deskripsi_tp[]');

        for (let i = 0; i < kodeTPs.length; i++) {
            const kodeTP = kodeTPs[i].value.trim();
            const deskripsiTP = deskripsiTPs[i].value.trim();
            
            // Cek apakah kode TP sudah ada dalam tabel (termasuk yang baru dan yang sudah ada)
            if (tpData.some(item => item.kodeTP === kodeTP) || 
                existingData.some(item => item.kodeTP === kodeTP && item.lingkupMateriId == lingkupMateriId)) {
                alert(`Kode TP "${kodeTP}" sudah ada dalam tabel!`);
                return;
            }

            const newRow = {
                id: null, // Null for new rows that are not saved yet
                lingkupMateriId,
                lingkupMateriText: document.getElementById('lingkup_materi').options[document.getElementById('lingkup_materi').selectedIndex].text,
                kodeTP: kodeTP,
                deskripsiTP: deskripsiTP,
                isNew: true // Flag for new data that hasn't been saved to DB
            };

            tpData.push(newRow);
        }

        renderTable();
        clearForm();
        Alpine.store('formProtection').markAsChanged();
    }

    function validateInputs() {
        const lingkupMateri = document.getElementById('lingkup_materi').value;
        const kodeTPs = document.getElementsByName('kode_tp[]');
        const deskripsiTPs = document.getElementsByName('deskripsi_tp[]');
        
        if (!lingkupMateri) {
            alert('Lingkup Materi harus dipilih!');
            return false;
        }

        for (let i = 0; i < kodeTPs.length; i++) {
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

    function renderTable() {
        const tableBody = document.getElementById('tpTableBody');
        tableBody.innerHTML = '';
        
        // Combine existing and new data for display, keeping them separate in memory
        const allData = [...existingData, ...tpData];
        
        // Filter data berdasarkan lingkup materi yang dipilih (jika ada)
        const filteredData = activeFilterLingkupMateri 
            ? allData.filter(tp => tp.lingkupMateriId == activeFilterLingkupMateri)
            : allData;
        
        // Tampilkan pesan jika tidak ada data
        if (filteredData.length === 0) {
            let message = activeFilterLingkupMateri 
                ? 'Belum ada tujuan pembelajaran untuk lingkup materi yang dipilih'
                : 'Belum ada tujuan pembelajaran untuk mata pelajaran ini';
                
            tableBody.innerHTML = `
                <tr class="bg-white border-b">
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                        ${message}
                    </td>
                </tr>
            `;
            return;
        }
        
        filteredData.forEach((tp, index) => {
            const row = `
                <tr class="bg-white border-b hover:bg-gray-50 ${tp.isNew ? 'bg-green-50' : ''}">
                    <td class="px-6 py-4">${index + 1}</td>
                    <td class="px-6 py-4">${tp.lingkupMateriText}</td>
                    <td class="px-6 py-4">${tp.kodeTP}</td>
                    <td class="px-6 py-4">${tp.deskripsiTP}</td>
                    <td class="px-6 py-4 text-center">
                        <button onclick="${tp.isNew ? 'deleteNewRow' : 'deleteExistingRow'}(${tp.isNew ? tpData.indexOf(tp) : existingData.indexOf(tp)}, ${tp.id || 'null'})" 
                                class="hover:opacity-80 text-red-600" title="${tp.isNew ? 'Hapus dari tabel' : 'Hapus dari database'}">
                            <img src="{{ asset('images/icons/delete.png') }}" alt="Delete" class="w-5 h-5 inline">
                        </button>
                    </td>
                </tr>
            `;
            tableBody.innerHTML += row;
        });
    }

    function deleteNewRow(index) {
        if (confirm('Apakah Anda yakin ingin menghapus data ini dari tabel?')) {
            tpData.splice(index, 1);
            renderTable();
            Alpine.store('formProtection').markAsChanged();
        }
    }

    async function deleteExistingRow(index, id) {
        if (!id) return;
        
        if (confirm('Apakah Anda yakin ingin menghapus data ini? Data akan langsung dihapus dari database.')) {
            try {
                const response = await fetch(`{{ url('/pengajar/tujuan-pembelajaran') }}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    existingData.splice(index, 1);
                    renderTable();
                    Alpine.store('formProtection').markAsChanged();
                    alert('Data berhasil dihapus dari database!');
                } else {
                    throw new Error(result.message || 'Gagal menghapus data');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat menghapus data: ' + error.message);
            }
        }
    }

    function clearForm() {
        const tpContainer = document.getElementById('tpContainer');
        tpContainer.innerHTML = `
            <div class="flex items-center mb-2">
                <input type="text" name="kode_tp[]" placeholder="Kode TP" required
                    class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
                <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required
                    class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                <button type="button" onclick="addTPRow()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700" title="Tambah baris input">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                    </svg>
                </button>
            </div>
        `;
        
        // Set dropdown lingkup materi sesuai filter yang aktif jika ada
        if (activeFilterLingkupMateri) {
            document.getElementById('lingkup_materi').value = activeFilterLingkupMateri;
        }
    }

    // Load existing data
    async function loadExistingData() {
        try {
            const response = await fetch(`{{ url('/pengajar/tujuan-pembelajaran') }}/${mataPelajaranId}/list`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                existingData = data.tujuanPembelajarans.map(tp => ({
                    id: tp.id,
                    lingkupMateriId: tp.lingkup_materi_id,
                    lingkupMateriText: tp.lingkup_materi.judul_lingkup_materi,
                    kodeTP: tp.kode_tp,
                    deskripsiTP: tp.deskripsi_tp,
                    isNew: false
                }));
                
                renderTable();
            } else {
                console.error('Error loading data:', data.message);
            }
        } catch (error) {
            console.error('Error fetching existing data:', error);
        }
    }

    window.saveData = async function() {
        try {
            if (tpData.length === 0) {
                alert('Tidak ada data baru untuk disimpan!');
                return;
            }
            
            // Only send new/modified data to the server
            const response = await fetch('{{ route("pengajar.tujuan_pembelajaran.store") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    tpData: tpData,
                    mataPelajaranId: mataPelajaranId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                Alpine.store('formProtection').reset();
                alert('Data berhasil disimpan!');
                window.location.reload();
            } else {
                throw new Error(data.message || 'Terjadi kesalahan saat menyimpan data.');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.');
            Alpine.store('formProtection').isSubmitting = false;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        activeFilterLingkupMateri = '';
        document.getElementById('table-filter').value = '';
        // Load existing data when page loads
        loadExistingData();
        
        const form = document.getElementById('addTPForm');
        const tableFilter = document.getElementById('table-filter');
        
        // Event listener untuk filter tabel
        tableFilter.addEventListener('change', function() {
            activeFilterLingkupMateri = this.value;
            renderTable();
            
            // Opsional: Set dropdown lingkup materi pada form sesuai dengan filter
            if (this.value) {
                document.getElementById('lingkup_materi').value = this.value;
            }
        });
        
        form.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (validateInputs()) {
                    addRow();
                }
            }
        });

        form.addEventListener('blur', function(e) {
            if (e.target.hasAttribute('required') && !e.target.value.trim()) {
                e.target.classList.add('border-red-500');
                e.target.setAttribute('title', 'Field ini wajib diisi!');
            } else {
                e.target.classList.remove('border-red-500');
                e.target.removeAttribute('title');
            }
        }, true);
        
        // Event listener untuk dropdown lingkup materi pada form
        document.getElementById('lingkup_materi').addEventListener('change', function() {
            // Opsional: Sinkronkan filter dengan dropdown lingkup materi
            if (this.value) {
                tableFilter.value = this.value;
                activeFilterLingkupMateri = this.value;
                renderTable();
            }
        });
    });
</script>
@endsection
