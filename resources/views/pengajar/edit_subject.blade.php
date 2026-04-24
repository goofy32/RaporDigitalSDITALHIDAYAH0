@extends('layouts.pengajar.app')

@section('title', 'Edit Data Mata Pelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Mata Pelajaran</h2>
            <div class="flex space-x-2">
                <button onclick="window.history.back()" class="px-4 py-2 mr-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="editSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
                </button>
            </div>
        </div>

        <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
            <p class="text-sm text-blue-700">
                <strong>Info:</strong> Anda sedang mengedit mata pelajaran dari tahun ajaran 
                <strong>{{ $subject->tahunAjaran->tahun_ajaran }}</strong> 
                ({{ $subject->tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }}).
            </p>
            @if($subject->tahun_ajaran_id != session('tahun_ajaran_id'))
            <p class="text-sm text-red-700 mt-1">
                <strong>Perhatian:</strong> Tahun ajaran ini berbeda dengan tahun ajaran aktif saat ini.
            </p>
            @endif
        </div>

        <!-- Form -->
        <form id="editSubjectForm" 
            action="{{ route('pengajar.subject.update', $subject->id) }}" 
            method="POST" 
            class="space-y-6"
            data-subject-id="{{ $subject->id }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="tahun_ajaran_id" value="{{ $subject->tahun_ajaran_id }}">

            <!-- Layout dengan satu kolom (tanpa grid) -->
            <div class="space-y-6">
                <!-- Mata Pelajaran -->
                <div>
                    <label for="mata_pelajaran" class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                    <input type="text" id="mata_pelajaran" name="mata_pelajaran" value="{{ old('mata_pelajaran', $subject->nama_pelajaran) }}" required
                        class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 @error('mata_pelajaran') border-red-500 @enderror">
                    @error('mata_pelajaran')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                @php
                    $isGuruWali = auth()->guard('guru')->user()->jabatan == 'guru_wali';
                    $kelasWaliId = $isGuruWali ? auth()->guard('guru')->user()->getWaliKelasId() : null;
                @endphp

                <!-- Opsi Muatan Lokal -->
                <div>
                    @if($isGuruWali)
                        <!-- Untuk guru wali: logika options berbeda tergantung apakah kelas yang dipilih adalah kelas wali -->
                        <div class="wali-kelas-options">
                            <!-- Kondisional display berdasarkan kelas -->
                            @if(auth()->guard('guru')->user()->getWaliKelasId() == $subject->kelas_id)
                            <!-- Jika kelas yang dipilih adalah kelas wali -->
                            <div class="wali-info">
                                <div class="p-2 bg-green-50 border border-green-200 rounded-md">
                                    <p class="text-sm text-green-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai wali kelas, Anda mengajar mata pelajaran wajib (non-muatan lokal) di kelas yang Anda walikan.
                                    </p>
                                </div>
                                <!-- Hidden inputs -->
                                <input type="hidden" name="is_muatan_lokal" value="0">
                                <input type="hidden" name="allow_non_wali" value="0">
                            </div>
                            @else
                            <!-- Jika kelas yang dipilih bukan kelas wali -->
                            <div class="muatan-lokal-container">
                                <div class="flex items-center">
                                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                                        <span class="font-medium">Pelajaran Muatan Lokal</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Pelajaran khusus yang diajar oleh guru mapel</p>
                            </div>
                            
                            <!-- Opsi allow_non_wali untuk mata pelajaran wajib di kelas non-wali -->
                            <div class="non-muatan-lokal-options mt-2">
                                <div class="flex items-center">
                                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                                        <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Pelajaran wajib yang diajar oleh guru mapel</p>
                            </div>
                            @endif
                        </div>
                    @else
                        <!-- Untuk guru biasa: Bisa pilih muatan lokal atau mata pelajaran wajib -->
                        <div>
                            <div class="info-container mb-3">
                                <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                    <p class="text-sm text-blue-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai guru biasa, Anda dapat mengajar mata pelajaran muatan lokal atau mata pelajaran wajib.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Checkbox Muatan Lokal -->
                            <div class="muatan-lokal-container">
                                <div class="flex items-center">
                                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                                        Mata Pelajaran Muatan Lokal
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Checkbox Mata Pelajaran Wajib -->
                            <div class="non-muatan-lokal-options mt-2">
                                <div class="flex items-center">
                                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                                        Mata Pelajaran Wajib yang diajar guru biasa
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Kelas Dropdown -->
                <div>
                    <label for="kelas" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                    
                    @if(isset($disableKelasDropdown) && $disableKelasDropdown)
                        <!-- Jika wali kelas dan mengajar di kelas wali, tampilkan sebagai readonly -->
                        <div class="relative">
                            <input type="text" 
                                value="Kelas {{ $subject->kelas->nomor_kelas }} {{ $subject->kelas->nama_kelas }} (Kelas Wali)"
                                class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed"
                                readonly>
                            <input type="hidden" name="kelas" value="{{ $subject->kelas_id }}">
                            <p class="mt-1 text-xs text-gray-500">Kelas tidak dapat diubah untuk mata pelajaran wali kelas</p>
                        </div>
                    @else
                        <!-- Dropdown kelas yang bisa diedit -->
                        <div class="relative">
                            <select id="kelas" name="kelas" required
                                class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 @error('kelas') border-red-500 @enderror">
                                <option value="">Pilih Kelas</option>
                                @foreach($classes as $class)
                                    <option value="{{ $class->id }}" 
                                        {{ old('kelas', $subject->kelas_id) == $class->id ? 'selected' : '' }}
                                        data-is-wali-kelas="{{ auth()->guard('guru')->user()->getWaliKelasId() == $class->id ? 'true' : 'false' }}">
                                        Kelas {{ $class->nomor_kelas }} {{ $class->nama_kelas }}
                                        {{ auth()->guard('guru')->user()->getWaliKelasId() == $class->id ? '(Wali Kelas)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    
                    @error('kelas')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Semester Dropdown -->
                <div>
                    <label for="semester" class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                    <div class="flex">
                        <input type="text" id="semester_display" 
                            value="{{ $subject->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}" 
                            class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed" 
                            readonly>
                        <input type="hidden" name="semester" value="{{ $subject->semester }}">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Semester tidak dapat diubah untuk mata pelajaran yang sudah ada</p>
                </div>

                <!-- Hidden input untuk guru_id -->
                <input type="hidden" name="guru_pengampu" value="{{ auth()->guard('guru')->id() }}">

                <!-- Lingkup Materi -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                    <div id="lingkupMateriContainer">
                    @foreach($subject->lingkupMateris as $index => $lm)
                    <div class="flex items-center mb-2" data-lm-id="{{ $lm->id }}">
                        <input type="text" name="lingkup_materi[]" value="{{ old('lingkup_materi.'.$index, $lm->judul_lingkup_materi) }}" required
                            class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                            data-original-value="{{ $lm->judul_lingkup_materi }}">
                        @if($index == 0)
                            <button type="button" onclick="addLingkupMateri()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        @else
                        <button type="button" onclick="confirmDeleteLingkupMateri(this, {{ $lm->id }})" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        @endif
                    </div>
                    @endforeach
                    </div>
                    @error('lingkup_materi')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Fix Layout Script -->
<script>
    // Script untuk memastikan sidebar dan layout benar saat halaman dimuat
    document.addEventListener('DOMContentLoaded', function() {
        // Fix sidebar visibility
        const sidebar = document.getElementById('logo-sidebar');
        if (sidebar) {
            sidebar.style.transform = 'translateX(0)';
            sidebar.style.display = 'block';
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('sm:translate-x-0');
            console.log('Fixed sidebar visibility');
        }
        
        // Fix content margin
        const content = document.querySelector('.p-4.sm\\:ml-64');
        if (content) {
            content.style.marginLeft = '16rem';
            console.log('Fixed content margin');
        }
        
        // TAMBAHKAN KODE BARU DI SINI
        // Force set selected value pada dropdown kelas
        const kelasDropdown = document.getElementById('kelas');
        const selectedKelasId = {{ $subject->kelas_id }};
        
        if (kelasDropdown) {
            const selectedKelasId = {{ $subject->kelas_id }};
            console.log('Setting kelas dropdown value to:', selectedKelasId);
            kelasDropdown.value = selectedKelasId;
            
            // Trigger change event
            const event = new Event('change');
            kelasDropdown.dispatchEvent(event);
            
            // Log untuk debug
            console.log('After setting value:', kelasDropdown.value);
            console.log('Selected index:', kelasDropdown.selectedIndex);
            console.log('Selected option text:', kelasDropdown.options[kelasDropdown.selectedIndex]?.text || 'None');
        }
        
        // Inisialisasi Flowbite setelah mengatur nilai dropdown
        setTimeout(() => {
            if (typeof initFlowbite === 'function') {
                console.log('Initializing Flowbite...');
                initFlowbite();
            } else {
                console.warn('Flowbite initialization function not found');
            }
        }, 100);
    });


    setTimeout(() => {
        const selects = document.querySelectorAll('select');
        selects.forEach(select => {
            if (typeof window.Flowbite !== 'undefined' && typeof window.Flowbite.initSelects !== 'undefined') {
                window.Flowbite.initSelects();
            } else if (typeof initFlowbite === 'function') {
                initFlowbite();
            }
        });
    }, 100);
</script>

@push('scripts')
<script>
    // Variable to track lingkup materi items that need to be updated
    var lingkupMateriChanges = [];
    
    // Definisikan array data mata pelajaran yang sudah ada
    window.mapelData = [
        @foreach(App\Models\MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')->get() as $mapel)
        {
            id: {{ $mapel->id }},
            nama: "{{ $mapel->nama_pelajaran }}",
            kelas_id: {{ $mapel->kelas_id }},
            semester: {{ $mapel->semester }}
        },
        @endforeach
    ];
    
    function addLingkupMateri() {
        const container = document.getElementById('lingkupMateriContainer');
        const div = document.createElement('div');
        div.className = 'flex items-center mb-2';
        div.setAttribute('data-lm-id', 'new');
        
        div.innerHTML = `
            <input type="text" name="lingkup_materi[]" required
                class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        `;
        
        container.appendChild(div);
        
        // Mark form as changed for protection
        window.formChanged = true;
    }
    
    function removeLingkupMateri(button) {
        // For new items that haven't been saved to DB
        button.closest('.flex.items-center').remove();
        window.formChanged = true;
    }
    
    function confirmDeleteLingkupMateri(button, id) {
        if (confirm('Apakah Anda yakin ingin menghapus Lingkup Materi ini? Semua tujuan pembelajaran terkait juga akan dihapus.')) {
            deleteLingkupMateri(button, id);
        }
    }

    function deleteLingkupMateri(button, id) {
        fetch(`/pengajar/subject/lingkup-materi/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                button.closest('.flex.items-center').remove();
                showMessage('Lingkup materi berhasil dihapus', 'success');
                window.formChanged = true;
            } else {
                showMessage(data.message || 'Gagal menghapus Lingkup Materi', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('Terjadi kesalahan saat menghapus Lingkup Materi', 'error');
        });
    }
    
    // Fungsi untuk menghandle checkbox yang saling mengunci (untuk guru biasa)
    function syncCheckboxes(checkbox) {
        const isMuatanLokalCheckbox = document.getElementById('is_muatan_lokal');
        const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
        
        if (!isMuatanLokalCheckbox || !allowNonWaliCheckbox) return;
        
        // Jika yang diklik adalah checkbox muatan lokal
        if (checkbox.id === 'is_muatan_lokal') {
            // Jika muatan lokal dicentang, maka mata pelajaran wajib tidak boleh dicentang
            if (checkbox.checked) {
                allowNonWaliCheckbox.checked = false;
            }
        }
        
        // Jika yang diklik adalah checkbox mata pelajaran wajib
        if (checkbox.id === 'allow_non_wali') {
            // Jika mata pelajaran wajib dicentang, maka muatan lokal tidak boleh dicentang
            if (checkbox.checked) {
                isMuatanLokalCheckbox.checked = false;
            }
        }
        
        // Mark form as changed
        window.formChanged = true;
    }
    
    function updateNonWaliOptions() {
        const isMuatanLokalElement = document.getElementById('is_muatan_lokal');
        const nonMuatanOptions = document.querySelector('.non-muatan-lokal-options');
        const allowNonWaliElement = document.getElementById('allow_non_wali');
        
        // Gunakan optional chaining untuk mencegah error
        const isMuatanLokal = isMuatanLokalElement ? isMuatanLokalElement.checked : false;
        
        // Tampilkan/sembunyikan opsi non-muatan lokal jika elemen ada
        if (nonMuatanOptions) {
            nonMuatanOptions.style.display = isMuatanLokal ? 'none' : 'block';
        }
        
        // Perbarui pesan info jika perlu
        const infoElement = document.getElementById('non-wali-info');
        if (infoElement && !isMuatanLokal && allowNonWaliElement && allowNonWaliElement.checked) {
            infoElement.textContent = 'Anda memilih untuk mengajar mata pelajaran non-muatan lokal di kelas selain kelas yang Anda walikan.';
        }
        
        // Update kelas selection
        updateKelasSelection();
        
        // Mark form as changed
        window.formChanged = true;
    }
    
    function updateKelasSelection() {
        // Ambil elemen penting
        const kelasSelect = document.getElementById('kelas');
        const isMuatanLokalElement = document.getElementById('is_muatan_lokal');
        const allowNonWaliElement = document.getElementById('allow_non_wali');
        
        if (!kelasSelect || !kelasSelect.options[kelasSelect.selectedIndex]) return;
        
        const selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
        const isWaliKelas = selectedOption.getAttribute('data-is-wali-kelas') === 'true';
        
        // Dapatkan status muatan lokal dan allow non wali
        const isMuatanLokal = isMuatanLokalElement ? isMuatanLokalElement.checked : false;
        const allowNonWali = allowNonWaliElement ? allowNonWaliElement.checked : false;
        
        @if(auth()->guard('guru')->user()->isWaliKelas())
        // Tampilkan info wali kelas jika kelas yang dipilih adalah kelas wali
        const waliInfo = document.querySelector('.wali-info');
        const muatanLokalContainer = document.querySelector('.muatan-lokal-container');
        const nonMuatanOptions = document.querySelector('.non-muatan-lokal-options');
        
        if (waliInfo && muatanLokalContainer) {
            if (isWaliKelas) {
                // Mengajar di kelas wali: selalu non-muatan lokal
                waliInfo.style.display = 'block';
                muatanLokalContainer.style.display = 'none';
                
                // Reset dan disable checkboxes
                if (isMuatanLokalElement) {
                    isMuatanLokalElement.checked = false;
                    isMuatanLokalElement.disabled = true;
                }
                if (allowNonWaliElement) {
                    allowNonWaliElement.checked = false;
                    allowNonWaliElement.disabled = true;
                }
                
                // Sembunyikan opsi non-muatan lokal
                if (nonMuatanOptions) nonMuatanOptions.style.display = 'none';
            } else {
                // Mengajar di kelas selain kelas wali
                waliInfo.style.display = 'none';
                muatanLokalContainer.style.display = 'block';
                
                // Enable checkboxes
                if (isMuatanLokalElement) isMuatanLokalElement.disabled = false;
                
                // Jika bukan muatan lokal, tampilkan dan auto-check allow_non_wali
                if (!isMuatanLokal) {
                    if (nonMuatanOptions) nonMuatanOptions.style.display = 'block';
                    
                    if (allowNonWaliElement) {
                        allowNonWaliElement.checked = true;
                        const nonWaliInfo = document.getElementById('non-wali-info');
                        if (nonWaliInfo) {
                            nonWaliInfo.textContent = 'Otomatis dicentang karena kelas yang dipilih bukan kelas wali Anda dan mata pelajaran non-muatan lokal.';
                        }
                    }
                } else {
                    // Jika muatan lokal, sembunyikan opsi allow_non_wali
                    if (nonMuatanOptions) nonMuatanOptions.style.display = 'none';
                }
            }
        }
        @endif
        
        // Mark form as changed
        window.formChanged = true;
    }
    
    function showMessage(message, type) {
        if (type === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: message,
                showConfirmButton: false,
                timer: 1500
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: message,
                confirmButtonText: 'Ok'
            });
        }
    }
    
    // Add event listeners
    document.addEventListener('DOMContentLoaded', function() {
        const mataPelajaranInput = document.getElementById('mata_pelajaran');
        const kelasSelect = document.getElementById('kelas');
        const semesterSelect = document.getElementById('semester');
        const isMuatanLokalCheckbox = document.getElementById('is_muatan_lokal');
        const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
        const currentId = parseInt(document.getElementById('editSubjectForm').getAttribute('data-subject-id'));
        
        // Inisialisasi untuk guru biasa: pastikan hanya satu checkbox yang bisa dicentang
        if (isMuatanLokalCheckbox && allowNonWaliCheckbox) {
            // Jika keduanya dicentang, prioritaskan muatan lokal
            if (isMuatanLokalCheckbox.checked && allowNonWaliCheckbox.checked) {
                allowNonWaliCheckbox.checked = false;
            }
            
            // Tambahkan event listener
            if (!isMuatanLokalCheckbox.hasAttribute('data-has-listener')) {
                isMuatanLokalCheckbox.addEventListener('change', function() {
                    if (this.id === 'is_muatan_lokal') {
                        syncCheckboxes(this);
                    } else {
                        updateNonWaliOptions();
                    }
                    window.formChanged = true;
                });
                isMuatanLokalCheckbox.setAttribute('data-has-listener', 'true');
            }
            
            if (!allowNonWaliCheckbox.hasAttribute('data-has-listener')) {
                allowNonWaliCheckbox.addEventListener('change', function() {
                    if (this.id === 'allow_non_wali') {
                        syncCheckboxes(this);
                    } else {
                        updateNonWaliOptions();
                    }
                    window.formChanged = true;
                });
                allowNonWaliCheckbox.setAttribute('data-has-listener', 'true');
            }
        }
        
        // Fungsi untuk memeriksa duplikasi
        function checkDuplication() {
            const mataPelajaran = mataPelajaranInput.value.trim();
            const kelasId = parseInt(kelasSelect.value);
            const semester = parseInt(document.querySelector('input[name="semester"]').value);
            
            // Jika salah satu field kosong, lewati validasi
            if (!mataPelajaran || !kelasId || isNaN(semester)) return true;
            
            // Periksa duplikasi, kecuali untuk mata pelajaran yang sedang diedit
            const duplicate = window.mapelData.find(subject => 
                subject.nama.toLowerCase() === mataPelajaran.toLowerCase() && 
                subject.kelas_id === kelasId && 
                subject.semester === semester && 
                subject.id !== currentId
            );
            
            return !duplicate;
        }
        
        // Real-time validation
        function validateMataPelajaran() {
            if (!checkDuplication()) {
                mataPelajaranInput.classList.add('border-red-500');
                
                // Buat pesan error di bawah input jika belum ada
                let errorElement = document.getElementById('mata-pelajaran-error');
                if (!errorElement) {
                    errorElement = document.createElement('p');
                    errorElement.id = 'mata-pelajaran-error';
                    errorElement.className = 'mt-1 text-sm text-red-500';
                    errorElement.textContent = 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.';
                    mataPelajaranInput.parentNode.appendChild(errorElement);
                }
                
                return false;
            } else {
                // Hapus class error dan pesan error jika validasi berhasil
                mataPelajaranInput.classList.remove('border-red-500');
                const errorElement = document.getElementById('mata-pelajaran-error');
                if (errorElement) {
                    errorElement.remove();
                }
                
                return true;
            }
        }
        
        // Add event listeners
        if (mataPelajaranInput) {
            mataPelajaranInput.addEventListener('input', function() {
                validateMataPelajaran();
                window.formChanged = true;
            });
        }
        
        if (kelasSelect) {
            kelasSelect.addEventListener('change', function() {
                validateMataPelajaran();
                updateKelasSelection();
                window.formChanged = true;
            });
        }
        
        if (isMuatanLokalCheckbox) {
            isMuatanLokalCheckbox.addEventListener('change', function() {
                if (this.id === 'is_muatan_lokal') {
                    syncCheckboxes(this);
                } else {
                    updateNonWaliOptions();
                }
                window.formChanged = true;
            });
        }
        
        if (allowNonWaliCheckbox) {
            allowNonWaliCheckbox.addEventListener('change', function() {
                if (this.id === 'allow_non_wali') {
                    syncCheckboxes(this);
                } else {
                    updateNonWaliOptions();
                }
                window.formChanged = true;
            });
        }
        
        // Initial update functions
        if (isMuatanLokalCheckbox && isMuatanLokalCheckbox.id === 'is_muatan_lokal') {
            syncCheckboxes(isMuatanLokalCheckbox);
        } else {
            updateNonWaliOptions();
        }
        updateKelasSelection();
        validateMataPelajaran();
        
        // Reset form changed flag since this is just initialization
        setTimeout(() => {
            window.formChanged = false;
        }, 100);
        
        // Intercept form submission
        document.getElementById('editSubjectForm').addEventListener('submit', function(event) {
            if (!checkDuplication()) {
                event.preventDefault();
                event.stopPropagation();
                
                // Tampilkan pesan error
                Swal.fire({
                    icon: 'error',
                    title: 'Validasi Gagal',
                    text: 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.'
                });
                
                // Validasi visual
                validateMataPelajaran();
                
                return false;
            }
            
            // Jika validasi lolos, reset flag dan biarkan form submit normal
            window.formChanged = false;
            return true;
        });
    });

    // Fix layout pada berbagai event Turbo
    document.addEventListener('turbo:before-render', function() {
        // Fix sidebar visibility before rendering
        const sidebar = document.getElementById('logo-sidebar');
        if (sidebar) {
            sidebar.style.transform = 'translateX(0)';
            sidebar.style.display = 'block';
        }
    });

    document.addEventListener('turbo:render', function() {
        // Fix sidebar visibility after rendering
        const sidebar = document.getElementById('logo-sidebar');
        if (sidebar) {
            sidebar.style.transform = 'translateX(0)';
            sidebar.style.display = 'block';
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('sm:translate-x-0');
        }
        
        // Fix content margin
        const content = document.querySelector('.p-4.sm\\:ml-64');
        if (content) {
            content.style.marginLeft = '16rem';
        }
    });
</script>

@if(session('error'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: "{{ session('error') }}",
            confirmButtonText: 'Ok'
        });
    });
</script>
@endif
@endpush
@endsection
