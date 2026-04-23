@extends('layouts.app')

@section('title', 'Tambah Data Mata Pelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-2xl font-bold text-green-700 break-words max-w-full sm:max-w-lg">Form Tambah Data Mata Pelajaran</h2>
            <div class="flex flex-wrap gap-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="addSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan Semua
                </button>
            </div>
        </div>

        <!-- Flash Message untuk Error/Success -->
        @if(session('error'))
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <p>{{ session('error') }}</p>
        </div>
        @endif

        @if(session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4">
            <p>{{ session('success') }}</p>
        </div>
        @endif

        @if(session('errors') && count(session('errors')) > 0)
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <h4 class="font-medium">Terjadi beberapa kesalahan:</h4>
            <ul class="ml-4 mt-2 list-disc">
                @foreach(session('errors') as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Form -->
        <form id="addSubjectForm" action="{{ route('subject.store') }}" method="POST" @submit="handleSubmit" x-data="formProtection" class="space-y-6" data-needs-protection>
            @csrf

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Multiple Subject Entry Form -->
            <div id="subjectEntriesContainer">
                <!-- Template for a subject entry -->
                <div class="subject-entry bg-gray-50 p-4 rounded-lg mb-6" data-previous-muatan-lokal="false">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-md font-medium text-gray-800">Mata Pelajaran 1</h4>
                        <button type="button" onclick="removeSubjectEntry(this)" class="text-red-600 hover:text-red-800 hidden remove-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    <!-- Mata Pelajaran -->
                    <div class="mb-4">
                        <label for="mata_pelajaran_0" class="block mb-2 text-sm font-medium text-gray-900">Nama Mata Pelajaran</label>
                        <input type="text" id="mata_pelajaran_0" name="subjects[0][mata_pelajaran]" value="{{ old('mata_pelajaran') }}" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>

                    <!-- Muatan Lokal Checkbox -->
                    <div class="mb-4">
                        <div class="flex items-center">
                            <input id="is_muatan_lokal_0" name="subjects[0][is_muatan_lokal]" type="checkbox" 
                                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                onchange="updateGuruOptions(this.closest('.subject-entry'))">
                            <label for="is_muatan_lokal_0" class="ml-2 block text-sm text-gray-900">
                                <span class="font-medium">Pelajaran Muatan Lokal</span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Pelajaran ini hanya dapat diajar oleh guru mapel (bukan wali kelas)</p>
                    </div>

                    <!-- Opsi Non-muatan lokal dengan guru bukan wali kelas -->
                    <div class="mb-4 non-muatan-lokal-options" style="display: none;">
                        <div class="flex items-center">
                            <input id="allow_non_wali_0" name="subjects[0][allow_non_wali]" type="checkbox" 
                                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded allow-non-wali-checkbox"
                                onchange="updateGuruOptions(this.closest('.subject-entry'))">
                            <label for="allow_non_wali_0" class="ml-2 block text-sm text-gray-900">
                                <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Pelajaran wajib ini akan diajar oleh guru mapel, bukan wali kelas</p>
                    </div>

                    <!-- Kelas Dropdown (Single Select) -->
                    <div class="mb-4">
                        <label for="kelas_0" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                        <select id="kelas_0" name="subjects[0][kelas]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select"
                            onchange="updateGuruOptions(this.closest('.subject-entry'))">
                            <option value="">Pilih Kelas</option>
                            @foreach($classes as $class)
                            <option value="{{ $class->id }}" data-has-wali="{{ $class->hasWaliKelas() ? 'true' : 'false' }}" data-wali-id="{{ $class->getWaliKelasId() }}">
                                {{ $class->nomor_kelas }} - {{ $class->nama_kelas }}
                                {{ $class->hasWaliKelas() ? '(Ada Wali Kelas)' : '(Belum Ada Wali Kelas)' }}
                            </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Semester -->
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                        <div class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
                            {{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}
                        </div>
                        <input type="hidden" id="semester_0" name="subjects[0][semester]" value="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}">
                    </div>

                    <!-- Guru Pengampu -->
                    <div class="mb-4">
                        <label for="guru_pengampu_0" class="block mb-2 text-sm font-medium text-gray-900">Guru Pengampu</label>
                        <select id="guru_pengampu_0" name="subjects[0][guru_pengampu]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 guru-select">
                            <option value="">Pilih Guru</option>
                            @foreach($teachers as $teacher)
                            <option value="{{ $teacher->id }}" data-jabatan="{{ $teacher->jabatan }}" {{ old('guru_pengampu') == $teacher->id ? 'selected' : '' }}>
                                {{ $teacher->nama }} ({{ $teacher->jabatan == 'guru_wali' ? 'Wali Kelas' : 'Guru' }})
                            </option>
                            @endforeach
                        </select>
                        <!-- Tempat untuk pesan info -->
                        <div class="info-container mt-2"></div>
                    </div>

                    <!-- Lingkup Materi -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                        <div class="lingkup-materi-container">
                            <div class="flex items-center mb-2">
                                <input type="text" name="subjects[0][lingkup_materi][]" required
                                    class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                                <button type="button" onclick="addLingkupMateri(this)" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end mt-6 mb-2">
                <button type="button" onclick="addSubjectEntry()" class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    Tambah Mata Pelajaran
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
let subjectCount = 1;
let waliKelasMap = {!! $waliKelasMap !!};

function addSubjectEntry() {
    subjectCount++;
    const container = document.getElementById('subjectEntriesContainer');
    const template = container.querySelector('.subject-entry').cloneNode(true);
    
    
    // Update IDs and names
    template.querySelectorAll('input, select').forEach(input => {
        const name = input.getAttribute('name');
        if (name) {
            input.setAttribute('name', name.replace(/subjects\[0\]/, `subjects[${subjectCount-1}]`));
        }
        
        const id = input.getAttribute('id');
        if (id) {
            const newId = id.replace(/_0$/, `_${subjectCount-1}`);
            input.setAttribute('id', newId);
        }
        
        // Clear values
        if (input.tagName === 'INPUT' && input.type !== 'checkbox') {
            input.value = '';
        } else if (input.tagName === 'SELECT') {
            input.selectedIndex = 0;
        } else if (input.type === 'checkbox') {
            input.checked = false;
        }

        // Re-attach event handlers for checkboxes
        if (input.classList.contains('muatan-lokal-checkbox') || input.classList.contains('allow-non-wali-checkbox')) {
            input.setAttribute('onchange', "handleCheckboxChange(this)");
        }
        
        // Re-attach event handlers for select boxes
        if (input.classList.contains('kelas-select')) {
            input.setAttribute('onchange', "updateGuruOptions(this.closest('.subject-entry'))");
        }
    });
    
    // Update labels
    template.querySelectorAll('label').forEach(label => {
        const forAttr = label.getAttribute('for');
        if (forAttr) {
            label.setAttribute('for', forAttr.replace(/_0$/, `_${subjectCount-1}`));
        }
    });

    // Update heading
    template.querySelector('h4').textContent = `Mata Pelajaran ${subjectCount}`;
    
    if (subjectCount % 2 === 0) {
        template.classList.remove('bg-gray-50');
        template.classList.add('bg-blue-50', 'border-l-4', 'border-blue-300');
    } else {
        template.classList.remove('bg-gray-50');
        template.classList.add('bg-green-50', 'border-l-4', 'border-green-300');
    }
    
    // Tambahkan bayangan dan jarak
    template.classList.add('shadow-md', 'my-6');
    
    // Show the remove button for this entry (entry baru)
    template.querySelector('.remove-btn').classList.remove('hidden');
    
    // Reset lingkup materi container - keep only one entry
    const lingkupContainer = template.querySelector('.lingkup-materi-container');
    const firstLingkupEntry = lingkupContainer.querySelector('.flex.items-center').cloneNode(true);
    lingkupContainer.innerHTML = '';
    lingkupContainer.appendChild(firstLingkupEntry);
    firstLingkupEntry.querySelector('input').value = '';

    // Hide non-muatan lokal options initially
    template.querySelector('.non-muatan-lokal-options').style.display = 'none';
    
    // Clear any info messages
    template.querySelector('.info-container').innerHTML = '';
    
    const divider = document.createElement('div');
    divider.className = 'border-t-2 border-dashed border-gray-300 my-8';
    container.appendChild(divider);

    const currentSemester = {{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }};
template.querySelector(`input[name="subjects[${subjectCount-1}][semester]"]`).value = currentSemester;

    // Add the new entry to the container
    container.appendChild(template);
    
    const allEntries = document.querySelectorAll('.subject-entry');
    if (allEntries.length > 1) {
        // Tampilkan tombol hapus di SEMUA entry kecuali yang pertama
        allEntries.forEach((entry, index) => {
            const removeBtn = entry.querySelector('.remove-btn');
            if (index === 0) {
                // Entry pertama: sembunyikan tombol hapus
                removeBtn.classList.add('hidden');
            } else {
                // Entry lainnya: tampilkan tombol hapus
                removeBtn.classList.remove('hidden');
            }
        });
    }
}

function removeSubjectEntry(button) {
    const entry = button.closest('.subject-entry');
    
    // Only allow removal if there's more than one entry
    const allEntries = document.querySelectorAll('.subject-entry');
    if (allEntries.length > 1) {
        // Hapus divider sebelum atau setelah entry jika ada
        const nextElement = entry.nextElementSibling;
        if (nextElement && nextElement.classList.contains('border-t-2')) {
            nextElement.remove();
        } else {
            const prevElement = entry.previousElementSibling;
            if (prevElement && prevElement.classList.contains('border-t-2')) {
                prevElement.remove();
            }
        }
        
        // Hapus entry
        entry.remove();
        fixSubjectNumbering();
        // Update subject numbers in headings
        document.querySelectorAll('.subject-entry h4').forEach((heading, index) => {
            heading.textContent = `Mata Pelajaran ${index + 1}`;
        });
        
        // If there's only one entry left, hide its remove button
        if (document.querySelectorAll('.subject-entry').length === 1) {
            document.querySelector('.subject-entry .remove-btn').classList.add('hidden');
        }
    }
    updateEntryStyles();
}

function addLingkupMateri(button) {
    const container = button.closest('.lingkup-materi-container');
    const entryIndex = button.closest('.subject-entry').querySelector('input[type="text"]').name.match(/subjects\[(\d+)\]/)[1];
    
    const div = document.createElement('div');
    div.className = 'flex items-center mb-2';
    
    div.innerHTML = `
        <input type="text" name="subjects[${entryIndex}][lingkup_materi][]" required
            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <button type="button" onclick="removeLingkupMateri(this)" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </button>
    `;
    
    container.appendChild(div);
}


function fixSubjectNumbering() {
    const entries = document.querySelectorAll('.subject-entry');
    
    // Update subjectCount agar sesuai dengan jumlah entri yang ada
    subjectCount = entries.length;
    
    // Update semua heading mata pelajaran
    entries.forEach((entry, index) => {
        // Perbarui teks heading
        entry.querySelector('h4').textContent = `Mata Pelajaran ${index + 1}`;
    });
}


function removeLingkupMateri(button) {
    button.parentElement.remove();
}

// New function to handle checkbox changes
function handleCheckboxChange(checkbox) {
    const subjectEntry = checkbox.closest('.subject-entry');
    const isMuatanLokalCheckbox = subjectEntry.querySelector('input[name*="[is_muatan_lokal]"]');
    const allowNonWaliCheckbox = subjectEntry.querySelector('input[name*="[allow_non_wali]"]');
    
    // If the muatan lokal checkbox was changed
    if (checkbox === isMuatanLokalCheckbox && checkbox.checked) {
        // If checked, uncheck the allow_non_wali checkbox
        if (allowNonWaliCheckbox) {
            allowNonWaliCheckbox.checked = false;
        }
    }
    
    // If the allow_non_wali checkbox was changed
    if (checkbox === allowNonWaliCheckbox && checkbox.checked) {
        // If checked, uncheck the muatan lokal checkbox
        if (isMuatanLokalCheckbox) {
            isMuatanLokalCheckbox.checked = false;
        }
    }
    
    // Update the guru options after changing checkbox state
    updateGuruOptions(subjectEntry);
}

function updateGuruOptions(subjectEntry) {
    // Ambil elemen dari entry yang aktif
    const isMuatanLokalElement = subjectEntry.querySelector('input[name*="[is_muatan_lokal]"]');
    const nonMuatanOptions = subjectEntry.querySelector('.non-muatan-lokal-options');
    const allowNonWaliElement = subjectEntry.querySelector('input[name*="[allow_non_wali]"]');
    const kelasSelect = subjectEntry.querySelector('select[name*="[kelas]"]');
    const guruSelect = subjectEntry.querySelector('select[name*="[guru_pengampu]"]');
    const infoContainer = subjectEntry.querySelector('.info-container');
    
    // Clear previous info/alerts
    if (infoContainer) {
        infoContainer.innerHTML = '';
    }
    
    // Periksa apakah elemen ada sebelum mengakses propertinya
    const isMuatanLokal = isMuatanLokalElement ? isMuatanLokalElement.checked : false;
    const allowNonWali = allowNonWaliElement ? allowNonWaliElement.checked : false;
    
    // Pastikan infoContainer ada
    if (!infoContainer) return;
    
    // Pastikan guruSelect ada
    if (!guruSelect) return;
    
    // Reset all options to enabled
    Array.from(guruSelect.options).forEach(option => {
        option.disabled = false;
    });

    // Store previous state for comparison
    const previousMuatanLokal = subjectEntry.getAttribute('data-previous-muatan-lokal') === 'true';
    
    // If the muatan lokal state changed, reset the guru selection
    if (isMuatanLokal !== previousMuatanLokal) {
        guruSelect.selectedIndex = 0; // Reset selection to the default option
        subjectEntry.setAttribute('data-previous-muatan-lokal', isMuatanLokal);
    }
    
    // Toggle display of non-muatan lokal options jika elemen ada
    if (nonMuatanOptions) {
        nonMuatanOptions.style.display = isMuatanLokal ? 'none' : 'block';
        
        // Reset the allowNonWali checkbox when toggling muatan lokal
        if (isMuatanLokal && allowNonWaliElement) {
            allowNonWaliElement.checked = false;
        }
    }
    
    // Pastikan kelasSelect ada
    if (!kelasSelect) return;
    
    // Get selected kelas info
    const selectedKelasId = parseInt(kelasSelect.value);
    if (!selectedKelasId) return; // No kelas selected
    
    const selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
    if (!selectedOption) return;
    
    const hasWaliKelas = selectedOption.getAttribute('data-has-wali') === 'true';
    const waliKelasIdAttr = selectedOption.getAttribute('data-wali-id');
    const waliKelasId = waliKelasIdAttr ? parseInt(waliKelasIdAttr) : null;

    // Remove any existing "no guru" message
    const existingNoGuruMessage = infoContainer.querySelector('.no-guru-message');
    if (existingNoGuruMessage) {
        existingNoGuruMessage.remove();
    }
    
    // Disable options based on rules
    let hasAvailableGuru = false;
    
    // Apply rules
    if (isMuatanLokal) {
        // For muatan lokal: only regular teachers, not wali kelas
        Array.from(guruSelect.options).forEach(option => {
            if (option.value && option.getAttribute('data-jabatan') === 'guru_wali') {
                option.disabled = true;
            } else if (option.value && option.getAttribute('data-jabatan') !== 'guru_wali') {
                hasAvailableGuru = true;
            }
        });
        
        if (!hasAvailableGuru) {
            showInfo(infoContainer, 'warning', 'Tidak ada guru biasa tersedia. Harap tambahkan guru dengan jabatan guru terlebih dahulu.', true);
        } else {
            showInfo(infoContainer, 'info', 'Mata pelajaran muatan lokal hanya dapat diajar oleh guru dengan jabatan guru biasa (bukan wali kelas).');
        }
    } else {
        // For non-muatan lokal
        if (!hasWaliKelas) {
            // Class doesn't have wali kelas
            showInfo(infoContainer, 'warning', 'Kelas ini belum memiliki wali kelas. Harap tambahkan wali kelas terlebih dahulu, atau centang opsi "Pelajaran wajib dengan guru bukan wali kelas".');
            
            if (allowNonWali) {
                // Allow only regular teachers, NOT wali kelas
                Array.from(guruSelect.options).forEach(option => {
                    if (option.value && option.getAttribute('data-jabatan') === 'guru_wali') {
                        option.disabled = true;
                    } else if (option.value && option.getAttribute('data-jabatan') !== 'guru_wali') {
                        hasAvailableGuru = true;
                    }
                });
                
                if (!hasAvailableGuru) {
                    showInfo(infoContainer, 'warning', 'Tidak ada guru biasa tersedia. Harap tambahkan guru dengan jabatan guru terlebih dahulu.', true);
                } else {
                    showInfo(infoContainer, 'info', 'Anda memilih mata pelajaran non-muatan lokal yang diajar oleh guru biasa.');
                }
            } else {
                // No valid options
                Array.from(guruSelect.options).forEach(option => {
                    if (option.value) {
                        option.disabled = true;
                    }
                });
                
                showInfo(infoContainer, 'warning', 'Belum ada guru wali kelas untuk kelas ini. Harap tambahkan wali kelas atau centang "Pelajaran wajib dengan guru bukan wali kelas".', true);
            }
        } else if (waliKelasId) { // Pastikan waliKelasId tidak null
            // Class has wali kelas
            if (allowNonWali) {
                // Allow only regular teachers, NOT wali kelas
                Array.from(guruSelect.options).forEach(option => {
                    if (option.value && option.getAttribute('data-jabatan') === 'guru_wali') {
                        option.disabled = true;
                    } else if (option.value && option.getAttribute('data-jabatan') !== 'guru_wali') {
                        hasAvailableGuru = true;
                    }
                });
                
                if (!hasAvailableGuru) {
                    showInfo(infoContainer, 'warning', 'Tidak ada guru biasa tersedia. Harap tambahkan guru dengan jabatan guru terlebih dahulu.', true);
                } else {
                    showInfo(infoContainer, 'info', 'Anda memilih mata pelajaran non-muatan lokal yang diajar oleh guru biasa, bukan wali kelas.');
                }
            } else {
                // Allow only the wali kelas of this class
                let waliKelasFound = false;
                Array.from(guruSelect.options).forEach(option => {
                    if (option.value && parseInt(option.value) !== waliKelasId) {
                        option.disabled = true;
                    } else if (option.value && parseInt(option.value) === waliKelasId) {
                        waliKelasFound = true;
                    }
                });
                
                // Auto-select wali kelas if not already selected
                if (guruSelect.value !== waliKelasId.toString() && waliKelasFound) {
                    guruSelect.value = waliKelasId.toString();
                }
                
                if (!waliKelasFound) {
                    showInfo(infoContainer, 'warning', 'Wali kelas tidak ditemukan dalam daftar guru. Harap periksa data guru.', true);
                } else {
                    showInfo(infoContainer, 'info', 'Untuk mata pelajaran wajib (bukan muatan lokal), guru pengampu harus wali kelas dari kelas ini.');
                }
            }
        }
    }
    
    // Auto-select an appropriate guru based on the rules
    autoSelectGuru(guruSelect, selectedKelasId, isMuatanLokal, allowNonWali, waliKelasId);
    
    // Highlight this field if no valid option is selected
    if (guruSelect.value === "" || guruSelect.selectedIndex === 0) {
        guruSelect.classList.add('border-yellow-500');
    } else {
        guruSelect.classList.remove('border-yellow-500');
    }
}

function updateEntryStyles() {
    const entries = document.querySelectorAll('.subject-entry');
    entries.forEach((entry, index) => {
        // Reset all classes first
        entry.classList.remove('bg-gray-50', 'bg-blue-50', 'bg-green-50', 'border-l-4', 'border-blue-300', 'border-green-300');
        
        // Apply correct styling based on index
        if (index % 2 === 0) {
            entry.classList.add('bg-green-50', 'border-l-4', 'border-green-300', 'shadow-md');
        } else {
            entry.classList.add('bg-blue-50', 'border-l-4', 'border-blue-300', 'shadow-md');
        }
    });
}

function showInfo(container, type, message, isImportant = false) {
    let className, icon;
    
    switch(type) {
        case 'info':
            className = 'bg-yellow-50 border border-yellow-200 text-yellow-800';
            icon = `<svg class="h-5 w-5 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9z" clip-rule="evenodd" />
            </svg>`;
            break;
        case 'warning':
            className = 'bg-yellow-50 border border-yellow-200 text-yellow-800';
            icon = `<svg class="h-5 w-5 text-yellow-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>`;
            break;
        case 'error':
            className = 'bg-red-50 border border-red-200 text-red-800';
            icon = `<svg class="h-5 w-5 text-red-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>`;
            break;
    }
    
    // For important messages, add additional highlight styling
    if (isImportant) {
        className += ' font-medium';
    }
    
    // Create a unique ID for this message if it's an important warning
    const messageId = isImportant ? `msg-${Date.now()}` : '';
    
    const messageHTML = `
        <div id="${messageId}" class="p-2 ${className} rounded-md flex items-start mb-2">
            ${icon}
            <p class="text-sm">${message}</p>
        </div>
    `;
    
    // Append to container (don't replace existing messages)
    container.innerHTML += messageHTML;
    
    // If it's an important message, add a subtle highlight effect
    if (isImportant && messageId) {
        setTimeout(() => {
            const msgElement = document.getElementById(messageId);
            if (msgElement) {
                msgElement.classList.add('animate-pulse');
                setTimeout(() => {
                    msgElement.classList.remove('animate-pulse');
                }, 1000);
            }
        }, 100);
    }
}

function autoSelectGuru(guruSelect, kelasId, isMuatanLokal, allowNonWali, waliKelasId) {
    // If no option is selected or the default option is selected
    if (guruSelect.selectedIndex <= 0 || guruSelect.value === "") {
        // For muatan lokal, select the first available non-wali guru
        if (isMuatanLokal) {
            for (let i = 0; i < guruSelect.options.length; i++) {
                const option = guruSelect.options[i];
                if (option.value && option.getAttribute('data-jabatan') !== 'guru_wali' && !option.disabled) {
                    guruSelect.selectedIndex = i;
                    break;
                }
            }
        } 
        // For non-muatan lokal without allow non-wali
        else if (!allowNonWali && waliKelasId) {
            guruSelect.value = waliKelasId.toString();
        }
        // For non-muatan lokal with allow non-wali
        else if (allowNonWali) {
            for (let i = 0; i < guruSelect.options.length; i++) {
                const option = guruSelect.options[i];
                if (option.value && option.getAttribute('data-jabatan') !== 'guru_wali' && !option.disabled) {
                    guruSelect.selectedIndex = i;
                    break;
                }
            }
        }
    }
}

// Validasi Duplikasi
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

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.getElementById('addSubjectForm');
    if (form) {
        form.addEventListener('submit', function(event) {
            if (!validateForm()) {
                event.preventDefault();
                return false;
            }
            return true;
        });
    }
    
    // Add event listeners to all checkboxes
    document.querySelectorAll('.muatan-lokal-checkbox, .allow-non-wali-checkbox').forEach(checkbox => {
        checkbox.setAttribute('onchange', 'handleCheckboxChange(this)');
    });
    
    // Initialize all entries
    document.querySelectorAll('.subject-entry').forEach(entry => {
        updateGuruOptions(entry);
    });

    const firstEntry = document.querySelector('.subject-entry');
    if (firstEntry) {
        firstEntry.classList.remove('bg-gray-50');
        firstEntry.classList.add('bg-green-50', 'border-l-4', 'border-green-300', 'shadow-md');
    }
});

function validateForm() {
    // Clear all previous errors
    document.querySelectorAll('.mata-pelajaran-error').forEach(el => el.remove());
    document.querySelectorAll('input.border-red-500, select.border-red-500').forEach(el => el.classList.remove('border-red-500'));
    
    let formValid = true;
    
    // Validate each subject entry
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        const mataPelajaranInput = entry.querySelector(`input[name="subjects[${index}][mata_pelajaran]"]`);
        const kelasSelect = entry.querySelector(`select[name="subjects[${index}][kelas]"]`);
        const semesterSelect = entry.querySelector(`select[name="subjects[${index}][semester]"]`);
        const guruSelect = entry.querySelector(`select[name="subjects[${index}][guru_pengampu]"]`);
        
        // Check if required fields are filled
        if (!mataPelajaranInput.value.trim()) {
            mataPelajaranInput.classList.add('border-red-500');
            
            const errorElement = document.createElement('p');
            errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
            errorElement.textContent = 'Nama mata pelajaran harus diisi';
            mataPelajaranInput.parentNode.appendChild(errorElement);
            
            formValid = false;
        }
        
        if (!kelasSelect.value) {
            kelasSelect.classList.add('border-red-500');
            
            const errorElement = document.createElement('p');
            errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
            errorElement.textContent = 'Kelas harus dipilih';
            kelasSelect.parentNode.appendChild(errorElement);
            
            formValid = false;
        }
        
        if (!semesterSelect.value) {
            semesterSelect.classList.add('border-red-500');
            
            const errorElement = document.createElement('p');
            errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
            errorElement.textContent = 'Semester harus dipilih';
            semesterSelect.parentNode.appendChild(errorElement);
            
            formValid = false;
        }
        
        if (!guruSelect.value) {
            guruSelect.classList.add('border-red-500');
            
            const errorElement = document.createElement('p');
            errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
            errorElement.textContent = 'Guru pengampu harus dipilih';
            guruSelect.parentNode.appendChild(errorElement);
            
            formValid = false;
        }
        
        // Check for duplicates
        if (mataPelajaranInput.value.trim() && kelasSelect.value && semesterSelect.value) {
            const mataPelajaran = mataPelajaranInput.value.trim();
            const kelasId = parseInt(kelasSelect.value);
            const semester = parseInt(semesterSelect.value);
            
            // Skip validation for incomplete entries
            if (!mataPelajaran || !kelasId || isNaN(semester)) {
                return;
            }
            
            // Check for duplicate subjects
            const duplicate = window.mapelData.find(subject => 
                subject.nama.toLowerCase() === mataPelajaran.toLowerCase() && 
                subject.kelas_id === kelasId && 
                subject.semester === semester
            );
            
            if (duplicate) {
                // Show error
                mataPelajaranInput.classList.add('border-red-500');
                
                const errorElement = document.createElement('p');
                errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
                errorElement.textContent = `"${mataPelajaran}" sudah ada di kelas ini untuk semester ${semester}`;
                mataPelajaranInput.parentNode.appendChild(errorElement);
                
                formValid = false;
            }
        }
        
        // Validate lingkup materi
        const lingkupMateriInputs = entry.querySelectorAll('input[name^="subjects[' + index + '][lingkup_materi]"]');
        let hasEmptyLingkupMateri = false;
        
        lingkupMateriInputs.forEach(input => {
            if (!input.value.trim()) {
                input.classList.add('border-red-500');
                hasEmptyLingkupMateri = true;
                formValid = false;
            }
        });
        
        if (hasEmptyLingkupMateri) {
            const lingkupMateriContainer = entry.querySelector('.lingkup-materi-container');
            if (lingkupMateriContainer) {
                const errorElement = document.createElement('p');
                errorElement.className = 'mata-pelajaran-error mt-1 text-sm text-red-500';
                errorElement.textContent = 'Semua lingkup materi harus diisi';
                lingkupMateriContainer.appendChild(errorElement);
            }
        }
    });
    
    if (!formValid) {
        // Use SweetAlert2 if available, otherwise use regular alert
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Data Belum Lengkap',
                html: 'Mohon lengkapi semua kolom yang bertanda <span class="text-red-500">merah</span> sebelum menyimpan.',
                icon: 'warning',
                confirmButtonText: 'Mengerti'
            });
        } else {
            alert('Mohon lengkapi semua kolom yang wajib diisi sebelum menyimpan.');
        }
    }
    
    return formValid;
}
</script>

@if(session('error'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert("{{ session('error') }}");
    });
</script>
@endif
@endpush
@endsection
