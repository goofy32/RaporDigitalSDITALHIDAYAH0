@extends('layouts.pengajar.app')

@section('title', 'Tambah Data Mata Pelajaran')

@section('content')
<div>
    <div class="p-6 bg-white mt-14">
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

        <!-- Status messages -->
        <div id="statusMessage" class="mb-4 hidden">
            <div id="successMessage" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative hidden" role="alert">
                <span class="block sm:inline" id="successText"></span>
            </div>
            <div id="errorMessage" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative hidden" role="alert">
                <span class="block sm:inline" id="errorText"></span>
            </div>
        </div>

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

        @if ($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="ml-3">
                    <h3 class="text-sm font-medium">Terdapat beberapa kesalahan:</h3>
                    <ul class="mt-2 list-disc list-inside text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
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
        <form id="addSubjectForm" action="{{ route('pengajar.subject.store') }}" method="POST" @submit="handleSubmit" x-data="formProtection" class="space-y-6" data-needs-protection>
            @csrf

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Multiple Subject Entry Form -->
            <div id="subjectEntriesContainer">
                <!-- Template for a subject entry -->
                <div class="subject-entry bg-gray-50 p-4 rounded-lg mb-6">
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

                    @php
                        $isGuruWali = auth()->guard('guru')->user()->jabatan == 'guru_wali';
                        $kelasWaliId = $isGuruWali ? auth()->guard('guru')->user()->getWaliKelasId() : null;
                    @endphp

                    <!-- Opsi Muatan Lokal -->
                    <div class="mb-4">
                        @if($isGuruWali)
                            <!-- Untuk guru wali: tidak bisa mengajar muatan lokal -->
                            <div class="guru-wali-options">
                                <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                    <p class="text-sm text-blue-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai wali kelas, Anda hanya dapat mengajar mata pelajaran wajib (non-muatan lokal).
                                    </p>
                                </div>
                            </div>
                            <!-- Hidden input dengan nilai 0 (false) -->
                            <input type="hidden" name="subjects[0][is_muatan_lokal]" value="0" class="is-muatan-lokal-input">
                        @else
                            <!-- Untuk guru biasa (bukan wali): Bisa pilih muatan lokal atau mata pelajaran wajib -->
                            <div class="mb-4">
                                <div class="info-container mb-3">
                                    <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                        <p class="text-sm text-blue-800">
                                            <span class="font-medium">Info:</span> 
                                            Sebagai guru biasa, Anda dapat mengajar mata pelajaran muatan lokal atau mata pelajaran wajib.
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="muatan-lokal-container">
                                    <div class="flex items-center">
                                        <input id="is_muatan_lokal_0" name="subjects[0][is_muatan_lokal]" type="checkbox" 
                                            class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                            onchange="syncCheckboxes(this)">
                                        <label for="is_muatan_lokal_0" class="ml-2 block text-sm text-gray-900">
                                            <span class="font-medium">Pelajaran Muatan Lokal</span>
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Pelajaran khusus yang diajar oleh guru mapel</p>
                                </div>
                                
                                <div class="non-muatan-lokal-options mt-2">
                                    <div class="flex items-center">
                                        <input id="allow_non_wali_0" name="subjects[0][allow_non_wali]" type="checkbox" 
                                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                            onchange="syncCheckboxes(this)">
                                        <label for="allow_non_wali_0" class="ml-2 block text-sm text-gray-900">
                                            <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Pelajaran wajib yang diajar oleh guru mapel</p>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Kelas Dropdown -->
                    <div class="mb-4">
                        <label for="kelas_0" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                        <select id="kelas_0" name="subjects[0][kelas]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select"
                            onchange="updateKelasSelection(this.closest('.subject-entry'))">
                            <option value="">Pilih Kelas</option>
                            @if($classes->isEmpty())
                                <option value="" disabled>Tidak ada kelas yang ditugaskan</option>
                            @else
                                @foreach($classes as $class)
                                    @if($isGuruWali && $kelasWaliId == $class->id)
                                        <option value="{{ $class->id }}" data-is-wali-kelas="true">
                                            Kelas {{ $class->nomor_kelas }} {{ $class->nama_kelas }} (Wali Kelas)
                                        </option>
                                    @else
                                        <option value="{{ $class->id }}">
                                            Kelas {{ $class->nomor_kelas }} {{ $class->nama_kelas }}
                                        </option>
                                    @endif
                                @endforeach
                            @endif
                        </select>
                    </div>

                    <!-- Semester Dropdown -->
                    <div class="mb-4">
                        <label for="semester_0" class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                        <select id="semester_0" name="subjects[0][semester]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                            <option value="">Pilih Semester</option>
                            <option value="1" {{ old('semester') == 1 ? 'selected' : '' }}>Semester 1</option>
                            <option value="2" {{ old('semester') == 2 ? 'selected' : '' }}>Semester 2</option>
                        </select>
                    </div>

                    <!-- Hidden input untuk guru_id -->
                    <input type="hidden" name="subjects[0][guru_pengampu]" value="{{ auth()->guard('guru')->id() }}">

                    <!-- Hidden input untuk allow_non_wali (hanya digunakan untuk guru wali) -->
                    @if($isGuruWali)
                    <input type="hidden" name="subjects[0][allow_non_wali]" value="0" class="allow-non-wali-input">
                    @endif

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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all subject entries
    document.querySelectorAll('.subject-entry').forEach(entry => {
        // Initialize kelas selection
        updateKelasSelection(entry.querySelector('.kelas-select'));
        
        // Pastikan bahwa hanya satu checkbox yang bisa dicentang
        const isMuatanLokalCheckbox = entry.querySelector('.muatan-lokal-checkbox');
        const allowNonWaliCheckbox = entry.querySelector('.allow-non-wali-checkbox');
        
        if (isMuatanLokalCheckbox && allowNonWaliCheckbox) {
            // Secara default, tidak ada yang dicentang
            isMuatanLokalCheckbox.checked = false;
            allowNonWaliCheckbox.checked = false;
        }
    });
    const firstEntry = document.querySelector('.subject-entry');
    if (firstEntry) {
        firstEntry.classList.remove('bg-gray-50');
        firstEntry.classList.add('bg-green-50', 'border-l-4', 'border-green-300', 'shadow-md');
        
        // Pastikan tombol hapus di entry pertama tersembunyi
        const removeBtn = firstEntry.querySelector('.remove-btn');
        if (removeBtn) {
            removeBtn.classList.add('hidden');
        }
    }
    
    // Pastikan subjectCount diinisialisasi dengan benar
    subjectCount = document.querySelectorAll('.subject-entry').length;
});

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

function syncCheckboxes(checkbox) {
    const subjectEntry = checkbox.closest('.subject-entry');
    const isMuatanLokalCheckbox = subjectEntry.querySelector('[name*="is_muatan_lokal"]');
    const allowNonWaliCheckbox = subjectEntry.querySelector('[name*="allow_non_wali"]');
    
    // Jika yang diklik adalah checkbox muatan lokal
    if (checkbox.name.includes('is_muatan_lokal')) {
        // Jika muatan lokal dicentang, maka mata pelajaran wajib tidak boleh dicentang
        if (checkbox.checked && allowNonWaliCheckbox) {
            allowNonWaliCheckbox.checked = false;
        }
    }
    
    // Jika yang diklik adalah checkbox mata pelajaran wajib
    if (checkbox.name.includes('allow_non_wali')) {
        // Jika mata pelajaran wajib dicentang, maka muatan lokal tidak boleh dicentang
        if (checkbox.checked && isMuatanLokalCheckbox) {
            isMuatanLokalCheckbox.checked = false;
        }
    }
    
    // Mark form as changed
    window.formChanged = true;
}

// Hapus function toggleMuatanLokal karena sudah tidak digunakan

function updateKelasSelection(selectElement) {
    if (!selectElement || !selectElement.value) return;
    
    const subjectEntry = selectElement.closest('.subject-entry');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const isWaliKelas = selectedOption.getAttribute('data-is-wali-kelas') === 'true';
    const allowNonWaliInput = subjectEntry.querySelector('.allow-non-wali-input');
    
    // Check if it's guru wali
    const isGuruWali = {{ $isGuruWali ? 'true' : 'false' }};
    
    if (isGuruWali) {
        // For guru wali:
        // - is_muatan_lokal is always false (handled by hidden input)
        // - allow_non_wali depends on if it's their own class
        if (isWaliKelas) {
            // In their own class, allow_non_wali is false
            if (allowNonWaliInput) {
                allowNonWaliInput.value = "0";
            }
        } else {
            // In other classes, they teach as a regular teacher
            // So allow_non_wali needs to be true
            if (allowNonWaliInput) {
                allowNonWaliInput.value = "1";
            }
        }
    }
    
    // Mark form as changed for the protection system
    window.formChanged = true;
}

let subjectCount = 1;

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
        if (input.tagName === 'INPUT' && input.type !== 'checkbox' && !input.hasAttribute('disabled') && !input.hasAttribute('hidden')) {
            input.value = '';
        } else if (input.tagName === 'SELECT' && !input.hasAttribute('disabled')) {
            // Keep the first option for single-selects
            if (input.options.length > 0) {
                input.selectedIndex = 0;
            }
        } else if (input.type === 'checkbox' && !input.hasAttribute('disabled')) {
            input.checked = false;
        }
        
        // Re-attach event handlers
        if (input.classList.contains('muatan-lokal-checkbox') || 
            input.classList.contains('allow-non-wali-checkbox')) {
            input.setAttribute('onchange', "syncCheckboxes(this)");
        }

        // Re-attach event handlers for kelas select
        if (input.classList.contains('kelas-select')) {
            input.setAttribute('onchange', "updateKelasSelection(this.closest('.subject-entry'))");
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
    template.querySelector('h4').textContent = `Mata Pelajaran ${document.querySelectorAll('.subject-entry').length + 1}`;
    
    // Styling untuk entry baru
    if (subjectCount % 2 === 0) {
        template.classList.remove('bg-gray-50');
        template.classList.add('bg-blue-50', 'border-l-4', 'border-blue-300');
    } else {
        template.classList.remove('bg-gray-50');
        template.classList.add('bg-green-50', 'border-l-4', 'border-green-300');
    }
    
    // Tambahkan bayangan dan jarak
    template.classList.add('shadow-md', 'my-6');
    
    // Show the remove button for this new entry
    template.querySelector('.remove-btn').classList.remove('hidden');
    
    // Reset lingkup materi container - keep only one entry
    const lingkupContainer = template.querySelector('.lingkup-materi-container');
    const firstLingkupEntry = lingkupContainer.querySelector('.flex.items-center').cloneNode(true);
    lingkupContainer.innerHTML = '';
    lingkupContainer.appendChild(firstLingkupEntry);
    firstLingkupEntry.querySelector('input').value = '';
    
    // Add the new entry to the container
    container.appendChild(template);
    
    // Show all remove buttons if more than one entry, but ONLY for entries after the first one
    const allEntries = document.querySelectorAll('.subject-entry');
    if (allEntries.length > 1) {
        allEntries.forEach((entry, index) => {
            const removeBtn = entry.querySelector('.remove-btn');
            if (index === 0) {
                // Selalu sembunyikan tombol hapus untuk entry pertama
                removeBtn.classList.add('hidden');
            } else {
                // Tampilkan tombol hapus untuk entry lainnya
                removeBtn.classList.remove('hidden');
            }
        });
    }

    // Set initial state for the new entry
    updateKelasSelection(template.querySelector('.kelas-select'));
}

function removeSubjectEntry(button) {
    const entry = button.closest('.subject-entry');
    
    // Only allow removal if there's more than one entry
    const allEntries = document.querySelectorAll('.subject-entry');
    if (allEntries.length > 1) {
        // Remove this entry
        entry.remove();
        
        // Fix subject count
        subjectCount = allEntries.length - 1;
        
        // Update subject numbers in headings correctly (1, 2, 3, ...)
        fixSubjectNumbering();
        
        // If there's only one entry left, hide its remove button
        if (document.querySelectorAll('.subject-entry').length === 1) {
            document.querySelector('.subject-entry .remove-btn').classList.add('hidden');
        }
        
        // Update styling for all entries
        updateEntryStyles();
    }
}


function fixSubjectNumbering() {
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        // Perbarui teks heading
        entry.querySelector('h4').textContent = `Mata Pelajaran ${index + 1}`;
    });
}

// Fungsi baru untuk memperbarui styling setelah penghapusan
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

function removeLingkupMateri(button) {
    button.parentElement.remove();
}

function toggleMuatanLokal(checkbox) {
    const subjectEntry = checkbox.closest('.subject-entry');
    const allowNonWaliContainer = subjectEntry.querySelector('.allow-non-wali-container');
    const allowNonWaliCheckbox = subjectEntry.querySelector('.allow-non-wali-checkbox');
    
    if (checkbox.checked) {
        // Jika muatan lokal dicentang, sembunyikan opsi mata pelajaran wajib
        if (allowNonWaliContainer) {
            allowNonWaliContainer.style.display = 'none';
        }
        // Reset allow_non_wali checkbox
        if (allowNonWaliCheckbox) {
            allowNonWaliCheckbox.checked = false;
        }
    } else {
        // Jika muatan lokal tidak dicentang, tampilkan opsi mata pelajaran wajib
        if (allowNonWaliContainer) {
            allowNonWaliContainer.style.display = 'block';
        }
    }
    
    // Mark form as changed
    window.formChanged = true;
}

function updateKelasSelection(selectElement) {
    if (!selectElement || !selectElement.value) return;
    
    const subjectEntry = selectElement.closest('.subject-entry');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const isWaliKelas = selectedOption.getAttribute('data-is-wali-kelas') === 'true';
    const allowNonWaliInput = subjectEntry.querySelector('.allow-non-wali-input');
    
    // Check if it's guru wali
    const isGuruWali = {{ $isGuruWali ? 'true' : 'false' }};
    
    if (isGuruWali) {
        // For guru wali:
        // - is_muatan_lokal is always false (handled by hidden input)
        // - allow_non_wali depends on if it's their own class
        if (isWaliKelas) {
            // In their own class, allow_non_wali is false
            if (allowNonWaliInput) {
                allowNonWaliInput.value = "0";
            }
        } else {
            // In other classes, they teach as a regular teacher
            // So allow_non_wali needs to be true
            if (allowNonWaliInput) {
                allowNonWaliInput.value = "1";
            }
        }
    } else {
        // For guru biasa:
        // Check the initial state of muatan lokal checkbox
        const isMuatanLokalCheckbox = subjectEntry.querySelector('.is-muatan-lokal-checkbox');
        if (isMuatanLokalCheckbox) {
            toggleMuatanLokal(isMuatanLokalCheckbox);
        }
    }
    
    // Mark form as changed for the protection system
    window.formChanged = true;
}

function validateForm() {
    // Clear all previous errors
    document.querySelectorAll('.mata-pelajaran-error').forEach(el => el.remove());
    document.querySelectorAll('input.border-red-500').forEach(el => el.classList.remove('border-red-500'));
    
    let formValid = true;
    
    // Validate each subject entry
    document.querySelectorAll('.subject-entry').forEach((entry, index) => {
        const mataPelajaranInput = entry.querySelector(`input[name="subjects[${index}][mata_pelajaran]"]`);
        const mataPelajaran = mataPelajaranInput.value.trim();
        const kelasSelect = entry.querySelector(`select[name="subjects[${index}][kelas]"]`);
        const kelasId = parseInt(kelasSelect.value);
        const semesterSelect = entry.querySelector(`select[name="subjects[${index}][semester]"]`);
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
    });
    
    if (!formValid) {
        alert('Terdapat duplikasi mata pelajaran. Silakan periksa kembali form.');
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
@endsection
