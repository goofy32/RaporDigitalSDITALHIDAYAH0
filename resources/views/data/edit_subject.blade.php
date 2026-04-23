@extends('layouts.app')

@section('title', 'Edit Data Mata Pelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Mata Pelajaran</h2>
            <div>
                <button onclick="window.history.back()" class="px-4 py-2 mr-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="editSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
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
        <form id="editSubjectForm" action="{{ route('subject.update', $subject->id) }}" method="POST" class="space-y-6" data-subject-id="{{ $subject->id }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Mata Pelajaran -->
            <div>
                <label for="mata_pelajaran" class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                <input type="text" id="mata_pelajaran" name="mata_pelajaran" value="{{ old('mata_pelajaran', $subject->nama_pelajaran) }}" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 @error('mata_pelajaran') border-red-500 @enderror">
                @error('mata_pelajaran')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Muatan Lokal Checkbox -->
            <div class="mb-4 muatan-lokal-options">
                <div class="flex items-center">
                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}>
                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                        <span class="font-medium">Pelajaran Muatan Lokal</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-gray-500">Pelajaran ini hanya dapat diajar oleh guru mapel (bukan wali kelas)</p>
            </div>

            <!-- Opsi Non-muatan lokal dengan guru bukan wali kelas -->
            <div class="mb-4 non-muatan-lokal-options" style="{{ $subject->is_muatan_lokal ? 'display: none;' : '' }}">
                <div class="flex items-center">
                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}>
                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                        <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-gray-500">Pelajaran wajib ini akan diajar oleh guru mapel, bukan wali kelas</p>
            </div>

            <!-- Kelas Dropdown -->
            <div>
                <label for="kelas" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                <select id="kelas" name="kelas" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select @error('kelas') border-red-500 @enderror">
                    <option value="">Pilih Kelas</option>
                    @foreach($classes as $class)
                    <option value="{{ $class->id }}" 
                        data-has-wali="{{ $class->hasWaliKelas() ? 'true' : 'false' }}" 
                        data-wali-id="{{ $class->getWaliKelasId() }}"
                        {{ old('kelas', $subject->kelas_id) == $class->id ? 'selected' : '' }}>
                        {{ $class->nomor_kelas }} - {{ $class->nama_kelas }}
                        {{ $class->hasWaliKelas() ? '(Ada Wali Kelas)' : '(Belum Ada Wali Kelas)' }}
                    </option>
                    @endforeach
                </select>
                @error('kelas')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Semester -->
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                <div class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg text-gray-700">
                    {{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}
                </div>
                <input type="hidden" id="semester" name="semester" value="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}">
                @error('semester')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Guru Pengampu -->
            <div>
                <label for="guru_pengampu" class="block mb-2 text-sm font-medium text-gray-900">Guru Pengampu</label>
                <select id="guru_pengampu" name="guru_pengampu" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 guru-select @error('guru_pengampu') border-red-500 @enderror">
                    <option value="">Pilih Guru</option>
                    @foreach($teachers as $teacher)
                    <option value="{{ $teacher->id }}" 
                        data-jabatan="{{ $teacher->jabatan }}" 
                        {{ old('guru_pengampu', $subject->guru_id) == $teacher->id ? 'selected' : '' }}>
                        {{ $teacher->nama }} ({{ $teacher->jabatan == 'guru_wali' ? 'Wali Kelas' : 'Guru' }})
                    </option>
                    @endforeach
                </select>
                @error('guru_pengampu')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
                
                <!-- Tempat untuk pesan info -->
                <div class="info-container mt-2"></div>
            </div>

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
        </form>
    </div>
</div>

@push('scripts')
<script>
// Variable to track lingkup materi items that need to be updated
let lingkupMateriChanges = [];
let waliKelasMap = {!! $waliKelasMap !!};

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
}

function removeLingkupMateri(button) {
    button.parentElement.remove();
}

function confirmDeleteLingkupMateri(button, id) {
    if (confirm('Apakah Anda yakin ingin menghapus Lingkup Materi ini? Semua tujuan pembelajaran terkait juga akan dihapus.')) {
        deleteLingkupMateri(button, id);
    }
}

async function checkForDependentData(lingkupMateriId) {
    try {
        const response = await fetch(`/subject/lingkup-materi/${lingkupMateriId}/check-dependencies`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        
        if (!response.ok) {
            console.error("Server returned error:", response.status);
            return false;
        }
        
        try {
            const data = await response.json();
            return data.hasDependents;
        } catch (jsonError) {
            console.error("JSON parsing error:", jsonError);
            return false;
        }
    } catch (error) {
        console.error("Error checking dependencies:", error);
        return false;
    }
}

function deleteLingkupMateri(button, id) {
    let alpineComponent = document.querySelector('[x-data="formProtection"]');
    if (window.Alpine && alpineComponent && alpineComponent.__x) {
        try {
            alpineComponent.__x.$data.formChanged = true;
            if ('isSubmitting' in alpineComponent.__x.$data) {
                alpineComponent.__x.$data.isSubmitting = true;
            }
        } catch (error) {
            console.error("Alpine data error:", error);
        }
    }
    
    fetch(`/subject/lingkup-materi/${id}`, {
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
            
            if (window.Alpine && alpineComponent && alpineComponent.__x) {
                try {
                    alpineComponent.__x.$data.formChanged = true;
                } catch (error) {
                    console.error("Alpine data update error:", error);
                }
            }
            
            showMessage('Lingkup materi berhasil dihapus', 'success');
        } else {
            showMessage(data.message || 'Gagal menghapus Lingkup Materi', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Terjadi kesalahan saat menghapus Lingkup Materi', 'error');
    })
    .finally(() => {
        if (window.Alpine && alpineComponent && alpineComponent.__x) {
            try {
                if ('isSubmitting' in alpineComponent.__x.$data) {
                    alpineComponent.__x.$data.isSubmitting = false;
                }
            } catch (error) {
                console.error("Alpine data reset error:", error);
            }
        }
    });
}

function showInfo(container, type, message, isImportant = false) {
    let className, icon;
    
    switch(type) {
        case 'info':
            className = 'bg-blue-50 border border-blue-200 text-blue-800';
            icon = `<svg class="h-5 w-5 text-blue-500 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
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

function showMessage(message, type) {
    alert(message);
}


function handleCheckboxChange(checkbox) {
    const form = document.getElementById('editSubjectForm');
    const isMuatanLokalCheckbox = document.getElementById('is_muatan_lokal');
    const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
    const guruSelect = document.getElementById('guru_pengampu');
    
    // Jika checkbox muatan lokal yang diubah
    if (checkbox === isMuatanLokalCheckbox && checkbox.checked) {
        // Jika dicentang, nonaktifkan checkbox allow_non_wali
        if (allowNonWaliCheckbox) {
            allowNonWaliCheckbox.checked = false;
        }
    }
    
    // Jika checkbox allow_non_wali yang diubah
    if (checkbox === allowNonWaliCheckbox && checkbox.checked) {
        // Jika dicentang, nonaktifkan checkbox muatan lokal
        if (isMuatanLokalCheckbox) {
            isMuatanLokalCheckbox.checked = false;
        }
    }

    if (guruSelect) {
        guruSelect.selectedIndex = 0;
        form.setAttribute('data-skip-auto-select', 'true');
    }
    
    // Update guru options setelah mengubah status checkbox
    updateFormState();
    
    // Tandai form sebagai berubah untuk proteksi navigasi
    if (window.Alpine) {
        const protectionData = document.querySelector('[x-data="formProtection"]');
        if (protectionData && protectionData.__x) {
            protectionData.__x.$data.formChanged = true;
        }
    }
}
// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Get the necessary elements
    const kelasSelect = document.getElementById('kelas');
    const guruSelect = document.getElementById('guru_pengampu');
    const muatanLokalCheckbox = document.getElementById('is_muatan_lokal');
    const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
    const nonMuatanOptions = document.querySelector('.non-muatan-lokal-options');
    const infoContainer = document.querySelector('.info-container');
    
    // Make sure we have the required elements before proceeding
    if (!kelasSelect || !guruSelect || !muatanLokalCheckbox) {
        console.error('Required form elements not found');
        return;
    }

    // Store form initial state
    const form = document.getElementById('editSubjectForm');
    form.setAttribute('data-previous-muatan-lokal', muatanLokalCheckbox.checked);
    
    // Store all teachers' roles
    const guruRoles = {};
    Array.from(guruSelect.options).forEach(option => {
        if (option.value) {
            const isWaliKelas = option.getAttribute('data-jabatan') === 'guru_wali';
            guruRoles[option.value] = isWaliKelas ? 'wali_kelas' : 'guru';
        }
    });
    
    const guruWaliList = Object.entries(guruRoles)
        .filter(([_, role]) => role === 'wali_kelas')
        .map(([id, _]) => parseInt(id));
    
    const guruBiasaList = Object.entries(guruRoles)
        .filter(([_, role]) => role === 'guru')
        .map(([id, _]) => parseInt(id));
    
    // Main function to update form state based on selections
    window.updateFormState = function updateFormState() {
        // Get the necessary elements
        const kelasSelect = document.getElementById('kelas');
        const guruSelect = document.getElementById('guru_pengampu');
        const muatanLokalCheckbox = document.getElementById('is_muatan_lokal');
        const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
        const muatanOptions = document.querySelector('.muatan-lokal-options');
        const nonMuatanOptions = document.querySelector('.non-muatan-lokal-options');
        const infoContainer = document.querySelector('.info-container');
        
        // Clear previous info/alerts
        if (infoContainer) {
            infoContainer.innerHTML = '';
        }
        
        // Make sure we have the required elements before proceeding
        if (!kelasSelect || !guruSelect || !muatanLokalCheckbox) {
            console.error('Required form elements not found');
            return;
        }

        const isMuatanLokal = muatanLokalCheckbox.checked;
        const allowNonWali = allowNonWaliCheckbox ? allowNonWaliCheckbox.checked : false;
        
        // Toggle visibility of non-muatan options
        if (muatanOptions) {
            muatanOptions.style.display = allowNonWali ? 'none' : 'block';
        }

        if (nonMuatanOptions) {
            nonMuatanOptions.style.display = isMuatanLokal ? 'none' : 'block';
            
            // Reset checkbox when toggling muatan lokal
            if (isMuatanLokal && allowNonWaliCheckbox) {
                allowNonWaliCheckbox.checked = false;
            }
        }
        
        // Reset all options to enabled first
        Array.from(guruSelect.options).forEach(option => {
            option.disabled = false;
        });
        
        const selectedKelasId = parseInt(kelasSelect.value);
        if (!selectedKelasId) return; // No kelas selected
        
        const selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
        if (!selectedOption) return;
        
        const hasWaliKelas = selectedOption.getAttribute('data-has-wali') === 'true';
        const waliKelasIdAttr = selectedOption.getAttribute('data-wali-id');
        const waliKelasId = waliKelasIdAttr ? parseInt(waliKelasIdAttr) : null;
        
        // Remove any existing "no guru" option from select
        const noGuruOption = Array.from(guruSelect.options).find(option => 
            option.value === "" && option.getAttribute('data-no-guru') === 'true');
        if (noGuruOption) {
            noGuruOption.remove();
        }
        
        // Apply rules based on selection
        let hasAvailableGuru = false;
        
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
                showInfo(infoContainer, 'warning', 'Kelas ini belum memiliki wali kelas. Harap tambahkan wali kelas terlebih dahulu, atau centang opsi "Pelajaran non-muatan lokal dengan guru bukan wali kelas".');
                
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
                    
                    showInfo(infoContainer, 'warning', 'Belum ada guru wali kelas untuk kelas ini. Harap tambahkan wali kelas atau centang "Pelajaran non-muatan lokal dengan guru bukan wali kelas".', true);
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
                    const shouldAutoSelect = document.getElementById('editSubjectForm').getAttribute('data-skip-auto-select') !== 'true';
                    if (shouldAutoSelect && guruSelect.value !== waliKelasId.toString() && waliKelasFound) {
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
        
        // Highlight this field if no valid option is selected
        if (guruSelect.value === "" || guruSelect.selectedIndex === 0) {
            guruSelect.classList.add('border-yellow-500');
        } else {
            guruSelect.classList.remove('border-yellow-500');
        }

        document.getElementById('editSubjectForm').removeAttribute('data-skip-auto-select');
    };

    const mataPelajaranInput = document.getElementById('mata_pelajaran');
    const semesterSelect = document.getElementById('semester');
    const submitButton = document.querySelector('button[type="submit"]');
    const currentId = parseInt(document.getElementById('editSubjectForm').getAttribute('data-subject-id'));
    
    // Fungsi untuk memeriksa duplikasi
    function checkDuplication() {
        const mataPelajaran = mataPelajaranInput.value.trim();
        const kelasId = parseInt(kelasSelect.value);
        const semester = parseInt(semesterSelect.value);
        
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
    
    // Event listeners
    if (mataPelajaranInput) {
        mataPelajaranInput.addEventListener('input', function() {
            validateMataPelajaran();
            if (window.Alpine) {
                const protectionData = document.querySelector('[x-data="formProtection"]');
                if (protectionData && protectionData.__x) {
                    protectionData.__x.$data.formChanged = true;
                }
            }
        });
    }
    
    if (semesterSelect) {
        semesterSelect.addEventListener('change', function() {
            validateMataPelajaran();
            if (window.Alpine) {
                const protectionData = document.querySelector('[x-data="formProtection"]');
                if (protectionData && protectionData.__x) {
                    protectionData.__x.$data.formChanged = true;
                }
            }
        });
    }
    
    if (kelasSelect) {
        kelasSelect.addEventListener('change', function() {
            validateMataPelajaran();
            updateFormState();
            if (window.Alpine) {
                const protectionData = document.querySelector('[x-data="formProtection"]');
                if (protectionData && protectionData.__x) {
                    protectionData.__x.$data.formChanged = true;
                }
            }
        });
    }
    
    if (muatanLokalCheckbox) {
        muatanLokalCheckbox.addEventListener('change', function() {
            updateFormState();
            if (window.Alpine) {
                const protectionData = document.querySelector('[x-data="formProtection"]');
                if (protectionData && protectionData.__x) {
                    protectionData.__x.$data.formChanged = true;
                }
            }
        });
    }
    
    if (allowNonWaliCheckbox) {
        allowNonWaliCheckbox.addEventListener('change', function() {
            updateFormState();
            if (window.Alpine) {
                const protectionData = document.querySelector('[x-data="formProtection"]');
                if (protectionData && protectionData.__x) {
                    protectionData.__x.$data.formChanged = true;
                }
            }
        });
    }
    
    // Add listeners for existing Lingkup Materi inputs
    const lingkupMateriInputs = document.querySelectorAll('#lingkupMateriContainer input[name="lingkup_materi[]"]');
    
    lingkupMateriInputs.forEach(input => {
        const originalValue = input.getAttribute('data-original-value');
        
        input.addEventListener('change', () => {
            const container = input.closest('[data-lm-id]');
            if (container) {
                const lmId = container.getAttribute('data-lm-id');
                
                // Only track changes for existing items (with an ID)
                if (lmId !== 'new') {
                    const currentValue = input.value.trim();
                    
                    // Check if the value has changed
                    if (currentValue !== originalValue) {
                        // Flag this for update
                        if (window.Alpine) {
                            const protectionData = document.querySelector('[x-data="formProtection"]');
                            if (protectionData && protectionData.__x) {
                                protectionData.__x.$data.formChanged = true;
                            }
                        }
                    }
                }
            }
        });
    });
    
    // Validasi setelah halaman dimuat
    validateMataPelajaran();
    
    // Inisialisasi opsi guru berdasarkan pilihan muatan lokal dan kelas
    updateFormState();
    
    // Intercept form submission
    document.getElementById('editSubjectForm').addEventListener('submit', function(event) {
        if (!checkDuplication()) {
            event.preventDefault();
            event.stopPropagation();
            
            // Tampilkan pesan error
            alert('Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.');
            
            // Validasi visual
            validateMataPelajaran();
            
            return false;
        }
        
        // Jika validasi lolos, biarkan form submit normal
        if (window.Alpine) {
            const protectionData = document.querySelector('[x-data="formProtection"]');
            if (protectionData && protectionData.__x) {
                protectionData.__x.$data.isSubmitting = true;
            }
        }
        return true;
    }, true); // true untuk capture phase (akan dijalankan sebelum event handler lain)
});


document.addEventListener('DOMContentLoaded', function() {
    // Tambahkan event listener untuk checkbox muatan lokal
    const muatanLokalCheckbox = document.getElementById('is_muatan_lokal');
    if (muatanLokalCheckbox) {
        muatanLokalCheckbox.addEventListener('change', function() {
            handleCheckboxChange(this);
        });
    }
    
    // Tambahkan event listener untuk checkbox allow non wali
    const allowNonWaliCheckbox = document.getElementById('allow_non_wali');
    if (allowNonWaliCheckbox) {
        allowNonWaliCheckbox.addEventListener('change', function() {
            handleCheckboxChange(this);
        });
    }
    
    // Jalankan updateFormState untuk setup awal
    updateFormState();
});
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
