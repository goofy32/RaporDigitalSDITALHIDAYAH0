@extends('layouts.app')

@section('title', 'Tambah Data Pengajar')

@section('content')
<div>
    <div class="p-4 bg-white mt-14 rounded-lg shadow">
        <!-- Error Messages -->
        @if ($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                    </svg>
                </div>
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

        <!-- Success Message -->
        @if (session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">{{ session('success') }}</p>
                </div>
            </div>
        </div>
        @endif

        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Tambah Data Pengajar</h2>
            <div class="flex space-x-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    Kembali
                </button>
                <button form="createTeacherForm" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
                </button>
            </div>
        </div>

        <form id="createTeacherForm" action="{{ route('teacher.store') }}" method="POST" @submit="handleSubmit" x-data="formProtection" enctype="multipart/form-data">
            @csrf
            
            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Kolom Kiri -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">NUPTK</label>
                        <input type="number" name="nuptk" id="nuptk" value="{{ old('nuptk') }}" required min="0" pattern="[0-9]+" inputmode="numeric" 
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 @error('nuptk') border-red-500 @enderror"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <p class="mt-1 text-sm text-gray-500">Masukkan hanya angka (9-15 digit)</p>
                        @error('nuptk')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nama</label>
                        <input type="text" name="nama" value="{{ old('nama') }}" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Jenis Kelamin</label>
                        <select name="jenis_kelamin" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            <option value="">Pilih Jenis Kelamin</option>
                            <option value="Laki-laki" {{ old('jenis_kelamin') == 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                            <option value="Perempuan" {{ old('jenis_kelamin') == 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir') }}" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">No. Handphone</label>
                        <input type="number" name="no_handphone" id="no_handphone" value="{{ old('no_handphone') }}" required min="0" pattern="[0-9]+" inputmode="numeric"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                            oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value.length > 15) this.value = this.value.slice(0, 15);">
                        <p class="mt-1 text-sm text-gray-500">Masukkan hanya angka (10-15 digit)</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Alamat</label>
                        <textarea name="alamat" rows="3" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">{{ old('alamat') }}</textarea>
                    </div>
                </div>

                <!-- Kolom Kanan -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Jabatan</label>
                        <select name="jabatan" id="jabatan" onchange="handleJabatanChange()" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            <option value="">Pilih Jabatan</option>
                            <option value="guru" {{ old('jabatan') == 'guru' ? 'selected' : '' }}>Guru</option>
                            <option value="guru_wali" {{ old('jabatan') == 'guru_wali' ? 'selected' : '' }}>Guru & Wali Kelas</option>
                        </select>
                    </div>

                    <div id="kelas_mengajar_section" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Kelas yang Diajar</label>
                        @if(isset($kelasForMengajar) && $kelasForMengajar->count() > 0)
                            <select name="kelas_ids[]" multiple required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 min-h-[120px]">
                                @foreach($kelasForMengajar as $kelas)
                                    <option value="{{ $kelas->id }}" {{ (is_array(old('kelas_ids')) && in_array($kelas->id, old('kelas_ids'))) ? 'selected' : '' }}>
                                        Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Tekan CTRL untuk memilih beberapa kelas yang akan diajar</p>
                        @else
                            <div class="mt-1 p-3 bg-green-50 border border-green-200 rounded-lg">
                                <p class="text-sm text-green-800">
                                    <span class="font-medium">Perhatian:</span> Belum ada kelas yang tersedia untuk diampu.
                                    Guru tidak dapat ditambahkan sampai ada kelas yang tersedia.
                                </p>
                                <p class="text-sm text-green-800 mt-2">
                                    <a href="{{ route('kelas.create') }}" class="text-green-600 hover:underline">Klik di sini</a> untuk membuat kelas baru.
                                </p>
                            </div>
                        @endif
                    </div>
                    <div id="wali_kelas_section" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Wali Kelas Untuk</label>
                        @if(isset($kelasForWali) && $kelasForWali->count() > 0)
                            <select name="wali_kelas_id" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <option value="">Pilih Kelas</option>
                                @foreach($kelasForWali as $kelas)
                                    <option value="{{ $kelas->id }}" {{ old('wali_kelas_id') == $kelas->id ? 'selected' : '' }}>
                                        Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Pilih kelas yang akan diwalikan</p>
                        @else
                            <div class="mt-1 p-3 bg-green-50 border border-green-200 rounded-lg">
                                <p class="text-sm text-green-800">
                                    <span class="font-medium">Perhatian:</span> Tidak ada kelas yang tersedia untuk ditugaskan sebagai wali kelas.
                                    Semua kelas sudah memiliki wali kelas atau belum ada kelas yang dibuat.
                                </p>
                                <p class="text-sm text-green-800 mt-2">
                                    <a href="{{ route('kelas.create') }}" class="text-green-600 hover:underline">Klik di sini</a> untuk membuat kelas baru.
                                </p>
                            </div>
                            <input type="hidden" name="wali_kelas_id" value="">
                        @endif
                    </div>
                    <!-- Kredensial -->
                    <div class="pt-4 border-t border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Informasi Login</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Username</label>
                                <input type="text" name="username" value="{{ old('username') }}" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Password</label>
                                <input type="password" name="password" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Konfirmasi Password</label>
                                <input type="password" name="password_confirmation" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Foto</label>
                        <input type="file" name="photo" accept="image/*"
                            class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                        <p class="mt-1 text-sm text-gray-500">Format: JPG, JPEG, atau PNG (Maks. 2MB)</p>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Perubahan pada bagian script di file create_teacher.blade.php -->
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tambahkan validasi untuk input numerik
    const numericInputs = document.querySelectorAll('input[type="number"]');
    numericInputs.forEach(input => {
        input.addEventListener('keypress', function(e) {
            // Pastikan hanya karakter angka yang bisa dimasukkan
            if (!/^\d*$/.test(e.key)) {
                e.preventDefault();
            }
        });
        
        // Hapus karakter non-angka saat paste
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            this.value = pastedText.replace(/[^\d]/g, '');
        });
    });

    // Validasi NUPTK - harus 9-15 digit angka
    const nuptkInput = document.getElementById('nuptk');
    if (nuptkInput) {
        nuptkInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && (value.length < 9 || value.length > 15)) {
                this.classList.add('border-red-500');
                let errorDiv = this.parentElement.querySelector('.error-message');
                if (!errorDiv) {
                    errorDiv = document.createElement('p');
                    errorDiv.className = 'error-message text-red-500 text-xs mt-1';
                    this.parentElement.appendChild(errorDiv);
                }
                errorDiv.textContent = 'NUPTK harus antara 9-15 digit';
            } else {
                this.classList.remove('border-red-500');
                const errorDiv = this.parentElement.querySelector('.error-message');
                if (errorDiv) {
                    errorDiv.textContent = '';
                }
            }
        });
    }

    // Validasi No. Handphone - harus 10-15 digit angka
    const phoneInput = document.getElementById('no_handphone');
    if (phoneInput) {
        phoneInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && (value.length < 10 || value.length > 15)) {
                this.classList.add('border-red-500');
                let errorDiv = this.parentElement.querySelector('.error-message');
                if (!errorDiv) {
                    errorDiv = document.createElement('p');
                    errorDiv.className = 'error-message text-red-500 text-xs mt-1';
                    this.parentElement.appendChild(errorDiv);
                }
                errorDiv.textContent = 'No. Handphone harus antara 10-15 digit';
            } else {
                this.classList.remove('border-red-500');
                const errorDiv = this.parentElement.querySelector('.error-message');
                if (errorDiv) {
                    errorDiv.textContent = '';
                }
            }
        });
    }

    // Function untuk handle perubahan jabatan
    window.handleJabatanChange = function() {
        const jabatan = document.getElementById('jabatan').value;
        const waliKelasSection = document.getElementById('wali_kelas_section');
        const kelasMengajarSection = document.getElementById('kelas_mengajar_section');
        const waliKelasSelect = document.querySelector('[name="wali_kelas_id"]');
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        
        // Cek apakah ada kelas yang tersedia
        const kelasForWaliCount = {{ isset($kelasForWali) ? $kelasForWali->count() : 0 }};
        const kelasForMengajarCount = {{ isset($kelasForMengajar) ? $kelasForMengajar->count() : 0 }};
        
        if (jabatan === 'guru_wali') {
            // Tampilkan section wali kelas
            waliKelasSection.style.display = 'block';
            kelasMengajarSection.style.display = 'block';
            
            // BARU: Auto-sync kelas mengajar dengan kelas wali
            syncKelasWaliToKelasAjar();
            
            // Tambahkan event listener untuk auto-sync
            if (waliKelasSelect && !waliKelasSelect.hasAttribute('data-listener-added')) {
                waliKelasSelect.addEventListener('change', syncKelasWaliToKelasAjar);
                waliKelasSelect.setAttribute('data-listener-added', 'true');
            }
            
            if (kelasForWaliCount === 0) {
                disableFormSubmission('Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk wali kelas');
            } else {
                enableFormSubmission();
            }
            
            if(waliKelasSelect) waliKelasSelect.required = kelasForWaliCount > 0;
            if(kelasMengajarSelect) {
                kelasMengajarSelect.required = false; // Tidak required karena auto-sync
            }
            
        } else if (jabatan === 'guru') {
            // Sembunyikan wali kelas, tampilkan kelas mengajar
            waliKelasSection.style.display = 'none';
            kelasMengajarSection.style.display = 'block';
            
            // Reset dan enable semua opsi kelas mengajar
            enableAllKelasMengajarOptions();
            removeAutoSyncInfo();
            
            if (kelasForMengajarCount === 0) {
                disableFormSubmission('Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk diampu');
            } else {
                enableFormSubmission();
            }
            
            if(waliKelasSelect) waliKelasSelect.required = false;
            if(kelasMengajarSelect) kelasMengajarSelect.required = kelasForMengajarCount > 0;
            
        } else {
            // Sembunyikan keduanya jika belum pilih jabatan
            waliKelasSection.style.display = 'none';
            kelasMengajarSection.style.display = 'none';
            enableFormSubmission();
            removeAutoSyncInfo();
            
            if(waliKelasSelect) waliKelasSelect.required = false;
            if(kelasMengajarSelect) kelasMengajarSelect.required = false;
        }
    };

    function enableFormSubmission() {
        const submitButton = document.querySelector('button[form="createTeacherForm"][type="submit"]');
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.title = '';
            submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
        }
        
        removeErrorAlert();
    }

    function removeErrorAlert() {
        const existingAlert = document.getElementById('no-kelas-alert');
        if (existingAlert) {
            existingAlert.remove();
        }
    }

    function showErrorAlert(message) {
        removeErrorAlert();

        const formHeader = document.querySelector('.flex.justify-between.items-center.mb-6');
        if (!formHeader) return;

        const alertDiv = document.createElement('div');
        alertDiv.id = 'no-kelas-alert';
        alertDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 my-4';
        alertDiv.innerHTML = `
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm"><strong>Error:</strong> ${message}</p>
                </div>
            </div>
        `;

        formHeader.parentNode.insertBefore(alertDiv, formHeader.nextSibling);
    }

    function disableFormSubmission(message) {
        const submitButton = document.querySelector('button[form="createTeacherForm"][type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.title = message;
            submitButton.classList.add('opacity-50', 'cursor-not-allowed');
        }
        
        showErrorAlert(message);
    }
    
    function syncKelasWaliToKelasAjar() {
        const waliKelasId = document.querySelector('[name="wali_kelas_id"]').value;
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        
        if (!kelasMengajarSelect) return;
        
        if (waliKelasId) {
            // Disable semua opsi dan unselect
            Array.from(kelasMengajarSelect.options).forEach(option => {
                option.disabled = true;
                option.selected = false;
            });
            
            // Enable dan select hanya kelas wali
            Array.from(kelasMengajarSelect.options).forEach(option => {
                if (option.value === waliKelasId) {
                    option.disabled = false;
                    option.selected = true;
                }
            });
            
            // Tambahkan info message
            showAutoSyncInfo();
        } else {
            // Jika tidak ada kelas wali dipilih, enable semua opsi
            enableAllKelasMengajarOptions();
            removeAutoSyncInfo();
        }
    }

    function removeAutoSyncInfo() {
        const infoText = document.getElementById('kelas_mengajar_auto_info');
        if (infoText) {
            infoText.remove();
        }
    }

    function showAutoSyncInfo() {
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        let infoText = document.getElementById('kelas_mengajar_auto_info');
        
        if (!infoText) {
            infoText = document.createElement('div');
            infoText.id = 'kelas_mengajar_auto_info';
            infoText.className = 'mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800';
            kelasMengajarSelect.parentElement.appendChild(infoText);
        }
        
        infoText.innerHTML = `
            <div class="flex items-start">
                <svg class="w-4 h-4 mr-2 mt-0.5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <span class="font-medium">Otomatis Disinkronkan:</span> 
                    Sebagai wali kelas, Anda hanya dapat mengajar di kelas yang Anda walikan. 
                    Kelas mengajar otomatis disesuaikan dengan kelas wali yang dipilih.
                </div>
            </div>
        `;
    }
    // Function untuk update kelas mengajar berdasarkan kelas wali
    function updateKelasMengajarForWali() {
        const waliKelasId = document.querySelector('[name="wali_kelas_id"]').value;
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        
        if(waliKelasId && kelasMengajarSelect) {
            // Disable semua opsi terlebih dahulu
            Array.from(kelasMengajarSelect.options).forEach(option => {
                option.disabled = true;
                option.selected = false;
            });
            
            // Enable dan select hanya kelas yang menjadi wali kelas
            Array.from(kelasMengajarSelect.options).forEach(option => {
                if(option.value === waliKelasId) {
                    option.disabled = false;
                    option.selected = true;
                }
            });
            
            // Tambahkan pesan informasi
            let infoText = document.getElementById('kelas_mengajar_info');
            if(!infoText) {
                infoText = document.createElement('p');
                infoText.id = 'kelas_mengajar_info';
                infoText.className = 'mt-2 p-2 bg-green-50 border border-green-200 rounded-md text-sm text-green-800';
                kelasMengajarSelect.parentElement.appendChild(infoText);
            }
            infoText.innerHTML = '<span class="font-medium">Info:</span> Karena Anda terpilih sebagai wali kelas, Anda hanya dapat mengajar di kelas wali yang dipilih.';
        }
    }
    
    // Function untuk enable semua opsi kelas mengajar
    function enableAllKelasMengajarOptions() {
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        if (kelasMengajarSelect) {
            Array.from(kelasMengajarSelect.options).forEach(option => {
                option.disabled = false;
            });
        }
    }

    // Validasi saat submit
    const form = document.querySelector('form');
    const requiredFields = form.querySelectorAll('[required]');
    
    form.addEventListener('submit', function(e) {
        let hasError = false;
        requiredFields.forEach(field => {
            if (field.multiple) {
                if (field.selectedOptions.length === 0) {
                    hasError = true;
                    field.classList.add('border-red-500');
                    addErrorMessage(field, 'Pilih minimal satu kelas');
                }
            } else if (!field.value.trim()) {
                hasError = true;
                field.classList.add('border-red-500');
                addErrorMessage(field, `${field.getAttribute('placeholder') || field.getAttribute('name')} wajib diisi`);
            }
        });
        
        // Validasi panjang NUPTK
        const nuptk = document.getElementById('nuptk');
        if (nuptk && nuptk.value.trim()) {
            const nuptkValue = nuptk.value.trim();
            if (nuptkValue.length < 9 || nuptkValue.length > 15) {
                hasError = true;
                nuptk.classList.add('border-red-500');
                addErrorMessage(nuptk, 'NUPTK harus antara 9-15 digit');
            }
        }
        
        // Validasi panjang No. Handphone
        const phone = document.getElementById('no_handphone');
        if (phone && phone.value.trim()) {
            const phoneValue = phone.value.trim();
            if (phoneValue.length < 10 || phoneValue.length > 15) {
                hasError = true;
                phone.classList.add('border-red-500');
                addErrorMessage(phone, 'No. Handphone harus antara 10-15 digit');
            }
        }

        if (hasError) {
            e.preventDefault();
            const firstError = form.querySelector('.border-red-500');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });

    function addErrorMessage(field, message) {
        let errorDiv = field.parentElement.querySelector('.error-message');
        if (!errorDiv) {
            errorDiv = document.createElement('p');
            errorDiv.className = 'error-message text-red-500 text-xs mt-1';
            field.parentElement.appendChild(errorDiv);
        }
        errorDiv.textContent = message;
    }

    // File validation
    const photoInput = document.querySelector('input[type="file"]');
    if (photoInput) {
        photoInput.addEventListener('change', function() {
            validateFile(this);
        });
    }

    // Set initial state
    handleJabatanChange();
});

function validateFile(input) {
    const file = input.files[0];
    if (!file) return;
    
    const fileType = file.type;
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    const maxSize = 2 * 1024 * 1024; // 2MB
    
    if (!allowedTypes.includes(fileType)) {
        alert('Format file harus JPG, JPEG, atau PNG');
        input.value = '';
        return;
    }
    
    if (file.size > maxSize) {
        alert('Ukuran file maksimal 2MB');
        input.value = '';
        return;
    }
}
</script>
@endpush
@endsection
