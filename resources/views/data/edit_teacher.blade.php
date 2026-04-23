@extends('layouts.app')

@section('title', 'Edit Data Pengajar')

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

       @if(session('error'))
       <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
           <div class="flex">
               <div class="flex-shrink-0">
                   <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                       <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                   </svg>
               </div>
               <div class="ml-3">
                   <p class="text-sm">{{ session('error') }}</p>
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
           <h2 class="text-2xl font-bold text-green-700">Form Edit Data Pengajar</h2>
           <div class="flex space-x-2">
               <button onclick="window.history.back()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                   Kembali
               </button>
               <button form="editTeacherForm" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                   Simpan
               </button>
           </div>
       </div>

       <!-- Form -->
       <form id="editTeacherForm" action="{{ route('teacher.update', $teacher->id) }}"  @submit="handleSubmit" method="POST" x-data="formProtection" enctype="multipart/form-data">
           @csrf
           @method('PUT')

           <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

           <div class="w-full px-4">
               <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                   <!-- Kolom Kiri -->
                   <div class="space-y-4">
                       <!-- NUPTK -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">NUPTK</label>
                           <input type="number" name="nuptk" value="{{ old('nuptk', $teacher->nuptk) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 15);">
                          <p class="mt-1 text-sm text-gray-500">Masukkan hanya angka (9-15 digit)</p>
                       </div>

                       <!-- Nama -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Nama</label>
                           <input type="text" name="nama" value="{{ old('nama', $teacher->nama) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                       </div>

                       <!-- Jenis Kelamin -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Jenis Kelamin</label>
                           <select name="jenis_kelamin" required 
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                               <option value="Laki-laki" {{ old('jenis_kelamin', $teacher->jenis_kelamin) === 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                               <option value="Perempuan" {{ old('jenis_kelamin', $teacher->jenis_kelamin) === 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
                           </select>
                       </div>

                       <!-- Tanggal Lahir -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Tanggal Lahir</label>
                           <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $teacher->tanggal_lahir) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                       </div>

                       <!-- No. Handphone -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">No. Handphone</label>
                           <input type="number" name="no_handphone" value="{{ old('no_handphone', $teacher->no_handphone) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 15);">
                           <p class="mt-1 text-sm text-gray-500">Masukkan hanya angka (10-15 digit)</p>
                       </div>

                       <!-- Email -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Email</label>
                           <input type="email" name="email" value="{{ old('email', $teacher->email) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                       </div>

                       <!-- Alamat -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Alamat</label>
                           <textarea name="alamat" rows="3" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">{{ old('alamat', $teacher->alamat) }}</textarea>
                       </div>
                   </div>

                   <!-- Kolom Kanan -->
                   <div class="space-y-4">
                       <!-- Jabatan -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Jabatan</label>
                           <select name="jabatan" id="jabatan" onchange="handleJabatanChange()" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                               <option value="guru" {{ $teacher->jabatan === 'guru' ? 'selected' : '' }}>Guru</option>
                               <option value="guru_wali" {{ $teacher->jabatan === 'guru_wali' ? 'selected' : '' }}>Guru dan Wali Kelas</option>
                           </select>
                       </div>

                       <!-- Kelas Mengajar -->
                       <div id="kelas_mengajar_section">
                            <label class="block text-sm font-medium text-gray-700">Kelas yang Diajar</label>
                            
                            @php
                                // Ambil semua kelas yang diajar (pengajar), kecuali yang sudah diwalikan
                                $kelasAjar = $teacher->kelas()->wherePivot('role', 'pengajar')->pluck('kelas.id')->toArray();
                                
                                // Ambil kelas yang diwalikan
                                $kelasWali = $teacher->kelas()->wherePivot('is_wali_kelas', true)
                                                            ->wherePivot('role', 'wali_kelas')
                                                            ->first();
                            @endphp
                            
                            @if(isset($kelasList) && $kelasList->count() > 0)
                                <select name="kelas_ids[]" multiple required id="kelas_mengajar"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 min-h-[120px]">
                                    @foreach($kelasList as $kelas)
                                        <option value="{{ $kelas->id }}" 
                                            {{ in_array($kelas->id, $kelasAjar) ? 'selected' : '' }}>
                                            Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">Tekan CTRL untuk memilih beberapa kelas yang akan diajar</p>
                            @else
                                <div class="mt-1 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <p class="text-sm text-yellow-800">
                                        <span class="font-medium">Perhatian:</span> Belum ada kelas yang tersedia untuk diampu.
                                        Guru tidak dapat ditambahkan sampai ada kelas yang tersedia.
                                    </p>
                                    <p class="text-sm text-yellow-800 mt-2">
                                        <a href="{{ route('kelas.create') }}" class="text-yellow-600 hover:underline">Klik di sini</a> untuk membuat kelas baru.
                                    </p>
                                </div>
                            @endif
                            
                            @if($kelasWali)
                                <div class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded-md">
                                    <p class="text-sm text-yellow-800">
                                        <span class="font-medium">Catatan:</span> Guru ini menjadi wali kelas untuk Kelas {{ $kelasWali->nomor_kelas }} {{ $kelasWali->nama_kelas }}. 
                                        Kelas wali tidak perlu dipilih di daftar kelas mengajar, karena akan otomatis ditambahkan.
                                    </p>
                                </div>
                            @endif
                        </div>

                       <!-- Wali Kelas -->
                       <div id="wali_kelas_section" style="{{ $teacher->jabatan === 'guru_wali' ? '' : 'display:none;' }}">
                            <label class="block text-sm font-medium text-gray-700">Wali Kelas Untuk</label>
                            
                            @if(isset($availableKelas) && $availableKelas->count() > 0)
                                <select name="wali_kelas_id" id="wali_kelas_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <option value="">Pilih Kelas</option>
                                    @foreach($availableKelas as $kelas)
                                        <option value="{{ $kelas->id }}" 
                                            {{ ($kelasWali && $kelasWali->id === $kelas->id) ? 'selected' : '' }}>
                                            Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($kelasWali)
                                    <p class="mt-1 text-sm text-gray-600">
                                        Saat ini menjadi wali kelas: 
                                        Kelas {{ $kelasWali->nomor_kelas }} {{ $kelasWali->nama_kelas }}
                                    </p>
                                @endif
                            @else
                                <div class="mt-1 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <p class="text-sm text-yellow-800">
                                        <span class="font-medium">Perhatian:</span> Tidak ada kelas yang tersedia untuk ditugaskan sebagai wali kelas.
                                        Semua kelas sudah memiliki wali kelas atau belum ada kelas yang dibuat.
                                    </p>
                                    <p class="text-sm text-yellow-800 mt-2">
                                        <a href="{{ route('kelas.create') }}" class="text-yellow-600 hover:underline">Klik di sini</a> untuk membuat kelas baru.
                                    </p>
                                </div>
                                <input type="hidden" name="wali_kelas_id" value="{{ $kelasWali ? $kelasWali->id : '' }}">
                            @endif
                        </div>

                       <!-- Username -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Username</label>
                           <input type="text" name="username" value="{{ old('username', $teacher->username) }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                       </div>

                       <!-- Photo -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Photo (ukuran 4x6 atau 2x3)</label>
                           <input type="file" name="photo" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                       </div>

                       <!-- Password Section -->
                       <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Ubah Password</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Password Saat Ini</label>
                                    <input type="password" name="current_password" id="current_password"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <p class="mt-1 text-sm text-gray-500">Kosongkan seluruh field password jika tidak ingin mengubah password</p>
                                    <div id="current_password_error" class="hidden mt-1 text-sm text-red-500"></div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Password Baru</label>
                                    <input type="password" name="password" id="new_password"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <div class="mt-1" id="password_strength_meter" style="display: none;">
                                        <div class="h-2 rounded-full bg-gray-200 relative overflow-hidden">
                                            <div id="password_strength_bar" class="h-2 absolute left-0 top-0" style="width: 0%;"></div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1" id="password_strength_text"></p>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                                    <input type="password" name="password_confirmation" id="password_confirmation"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <div id="password_match_error" class="hidden mt-1 text-sm text-red-500"></div>
                                </div>
                            </div>
                        </div>
                   </div>
               </div>
           </div>
       </form>
   </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('password_confirmation');
    const currentPassword = document.getElementById('current_password');
    const strengthMeter = document.getElementById('password_strength_meter');
    const strengthBar = document.getElementById('password_strength_bar');
    const strengthText = document.getElementById('password_strength_text');
    const currentPasswordError = document.getElementById('current_password_error');
    const passwordMatchError = document.getElementById('password_match_error');
    
    // 1. Validasi password saat pengguna selesai mengetik di field password saat ini
    if (currentPassword) {
        currentPassword.addEventListener('blur', async function() {
            // Hanya validasi jika ada input di password baru dan password saat ini
            if (this.value && newPassword.value) {
                validateCurrentPassword();
            }
        });
    }
    
    // 2. Validasi password saat pengguna selesai mengetik di field password baru
    if (newPassword) {
        newPassword.addEventListener('blur', function() {
            // Validasi password saat ini jika sudah diisi
            if (this.value && currentPassword.value) {
                validateCurrentPassword();
            }
            
            // Update strength meter
            if (this.value.length > 0) {
                strengthMeter.style.display = 'block';
                updatePasswordStrength(this.value);
            } else {
                strengthMeter.style.display = 'none';
            }
        });
        
        // Real-time update
        newPassword.addEventListener('input', function() {
            if (this.value.length > 0) {
                strengthMeter.style.display = 'block';
                updatePasswordStrength(this.value);
            } else {
                strengthMeter.style.display = 'none';
            }
            
            // Cek kesesuaian dengan konfirmasi jika sudah diisi
            if (confirmPassword.value) {
                validatePasswordMatch();
            }
        });
    }
    
    // 3. Validasi kecocokan password secara real-time
    if (confirmPassword) {
        confirmPassword.addEventListener('input', validatePasswordMatch);
    }
    
    // Fungsi untuk memvalidasi password saat ini
    async function validateCurrentPassword() {
        try {
            const response = await fetch('/admin/pengajar/verify-password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ 
                    teacher_id: {{ $teacher->id }}, 
                    current_password: currentPassword.value 
                })
            });
            
            const data = await response.json();
            
            if (!data.valid) {
                currentPassword.classList.add('border-red-500');
                currentPassword.classList.remove('border-green-500');
                currentPasswordError.textContent = 'Password saat ini tidak sesuai';
                currentPasswordError.classList.remove('hidden');
                return false;
            } else {
                currentPassword.classList.remove('border-red-500');
                currentPassword.classList.add('border-green-500');
                currentPasswordError.classList.add('hidden');
                return true;
            }
        } catch (error) {
            console.error('Error verifying password:', error);
            currentPasswordError.textContent = 'Terjadi kesalahan saat memverifikasi password';
            currentPasswordError.classList.remove('hidden');
            return false;
        }
    }
    
    // Function untuk validasi kecocokan password
    function validatePasswordMatch() {
        if (newPassword.value && confirmPassword.value) {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.classList.add('border-red-500');
                confirmPassword.classList.remove('border-green-500');
                passwordMatchError.textContent = 'Password tidak cocok';
                passwordMatchError.classList.remove('hidden');
                return false;
            } else {
                confirmPassword.classList.remove('border-red-500');
                confirmPassword.classList.add('border-green-500');
                passwordMatchError.classList.add('hidden');
                return true;
            }
        }
        return true;
    }
    
    // Function untuk update password strength
    function updatePasswordStrength(password) {
        // Rule untuk password strength
        const lengthRule = password.length >= 6;
        const uppercaseRule = /[A-Z]/.test(password);
        const lowercaseRule = /[a-z]/.test(password);
        const numberRule = /[0-9]/.test(password);
        const specialRule = /[^A-Za-z0-9]/.test(password);
        
        // Hitung score (0-100)
        let score = 0;
        if (lengthRule) score += 20;
        if (uppercaseRule) score += 20;
        if (lowercaseRule) score += 20;
        if (numberRule) score += 20;
        if (specialRule) score += 20;
        
        // Update UI
        strengthBar.style.width = `${score}%`;
        
        // Set warna berdasarkan score
        if (score < 40) {
            strengthBar.className = 'h-2 absolute left-0 top-0 bg-red-500';
            strengthText.textContent = 'Lemah';
            strengthText.className = 'text-xs text-red-500 mt-1';
        } else if (score < 70) {
            strengthBar.className = 'h-2 absolute left-0 top-0 bg-yellow-500';
            strengthText.textContent = 'Sedang';
            strengthText.className = 'text-xs text-yellow-600 mt-1';
        } else {
            strengthBar.className = 'h-2 absolute left-0 top-0 bg-green-500';
            strengthText.textContent = 'Kuat';
            strengthText.className = 'text-xs text-green-600 mt-1';
        }
        
        // Tambahkan text untuk saran
        let suggestion = 'Saran: ';
        if (!lengthRule) suggestion += 'Minimal 6 karakter. ';
        if (!uppercaseRule) suggestion += 'Tambahkan huruf besar. ';
        if (!lowercaseRule) suggestion += 'Tambahkan huruf kecil. ';
        if (!numberRule) suggestion += 'Tambahkan angka. ';
        if (!specialRule) suggestion += 'Tambahkan karakter khusus. ';
        
        if (suggestion !== 'Saran: ') {
            strengthText.textContent += ` - ${suggestion}`;
        }
    }
    
    // Validasi form submission untuk password
    const passwordForm = document.getElementById('editTeacherForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            // Jika ada input password tapi tidak ada input password saat ini
            if (newPassword.value && !currentPassword.value) {
                e.preventDefault();
                
                currentPassword.classList.add('border-red-500');
                currentPasswordError.textContent = 'Password saat ini harus diisi untuk mengubah password';
                currentPasswordError.classList.remove('hidden');
                
                currentPassword.focus();
                // Scroll ke elemen
                currentPassword.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            
            // Jika ada input password, validasi password saat ini
            if (newPassword.value && currentPassword.value) {
                // Validasi langsung saat submit
                e.preventDefault(); // Tahan form submission sampai validasi selesai
                
                const passwordValid = await validateCurrentPassword();
                const matchValid = validatePasswordMatch();
                
                // Lanjutkan submit hanya jika semua validasi berhasil
                if (passwordValid && matchValid) {
                    passwordForm.submit();
                } else {
                    // Focus ke elemen yang bermasalah
                    if (!passwordValid) {
                        currentPassword.focus();
                        currentPassword.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else if (!matchValid) {
                        confirmPassword.focus();
                        confirmPassword.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                return false;
            }
            
            // Jika tidak ada validasi password yang perlu dilakukan, form disubmit secara normal
            return true;
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Function untuk handle perubahan jabatan
    window.handleJabatanChange = function() {
        const jabatan = document.getElementById('jabatan').value;
        const waliKelasSection = document.getElementById('wali_kelas_section');
        const kelasMengajarSection = document.getElementById('kelas_mengajar_section');
        const waliKelasSelect = document.querySelector('[name="wali_kelas_id"]');
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        
        // Cek apakah ada kelas yang tersedia
        const availableKelasCount = {{ isset($availableKelas) ? $availableKelas->count() : 0 }};
        const kelasListCount = {{ isset($kelasList) ? $kelasList->count() : 0 }};
        const hasCurrentWaliKelas = {{ $kelasWali ? 'true' : 'false' }};

        if (jabatan === 'guru_wali') {
            waliKelasSection.style.display = 'block';
            kelasMengajarSection.style.display = 'block';
            
            // Jika tidak ada kelas yang tersedia untuk wali kelas dan guru belum menjadi wali kelas, tampilkan pemberitahuan
            if (availableKelasCount === 0 && !hasCurrentWaliKelas) {
                const submitButton = document.querySelector('button[form="editTeacherForm"][type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.title = 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk wali kelas';
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
                
                // Tambahkan peringatan di bagian atas form
                const formHeader = document.querySelector('.flex.justify-between.items-center.mb-6');
                if (formHeader && !document.getElementById('no-kelas-alert')) {
                    const alertDiv = document.createElement('div');
                    alertDiv.id = 'no-kelas-alert';
                    alertDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 my-4';
                    alertDiv.innerHTML = `
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm"><strong>Error:</strong> Tidak dapat mengubah menjadi guru wali kelas karena tidak ada kelas yang tersedia. Harap buat kelas terlebih dahulu.</p>
                            </div>
                        </div>
                    `;
                    formHeader.parentNode.insertBefore(alertDiv, formHeader.nextSibling);
                }
            } else {
                // Hapus peringatan jika ada
                const alertDiv = document.getElementById('no-kelas-alert');
                if (alertDiv) alertDiv.remove();
                
                // Enable tombol submit
                const submitButton = document.querySelector('button[form="editTeacherForm"][type="submit"]');
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.title = '';
                    submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
            
            if(waliKelasSelect) waliKelasSelect.required = availableKelasCount > 0 || hasCurrentWaliKelas;
            if(kelasMengajarSelect) kelasMengajarSelect.required = kelasListCount > 0;
            
            // Tambahkan event listener untuk perubahan pada wali kelas
            if(waliKelasSelect) {
                waliKelasSelect.addEventListener('change', function() {
                    updateKelasMengajarForWali();
                });
            }
        } else if (jabatan === 'guru') {
            waliKelasSection.style.display = 'none';
            kelasMengajarSection.style.display = 'block';
            
            // Jika tidak ada kelas yang tersedia untuk mengajar, tampilkan pemberitahuan
            if (kelasListCount === 0) {
                const submitButton = document.querySelector('button[form="editTeacherForm"][type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.title = 'Tidak dapat menyimpan karena tidak ada kelas yang tersedia untuk diampu';
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
                
                // Tambahkan peringatan di bagian atas form
                const formHeader = document.querySelector('.flex.justify-between.items-center.mb-6');
                if (formHeader && !document.getElementById('no-kelas-alert')) {
                    const alertDiv = document.createElement('div');
                    alertDiv.id = 'no-kelas-alert';
                    alertDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 my-4';
                    alertDiv.innerHTML = `
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm"><strong>Error:</strong> Tidak dapat mengubah guru karena tidak ada kelas yang tersedia untuk diampu. Harap buat kelas terlebih dahulu.</p>
                            </div>
                        </div>
                    `;
                    formHeader.parentNode.insertBefore(alertDiv, formHeader.nextSibling);
                }
            } else {
                // Hapus peringatan jika ada
                const alertDiv = document.getElementById('no-kelas-alert');
                if (alertDiv) alertDiv.remove();
                
                // Enable tombol submit
                const submitButton = document.querySelector('button[form="editTeacherForm"][type="submit"]');
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.title = '';
                    submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
            
            if(waliKelasSelect) waliKelasSelect.required = false;
            if(kelasMengajarSelect) kelasMengajarSelect.required = kelasListCount > 0;
            
            // Enable semua opsi kelas mengajar
            enableAllKelasMengajarOptions();
            
            if(waliKelasSelect) waliKelasSelect.value = '';
        } else {
            waliKelasSection.style.display = 'none';
            kelasMengajarSection.style.display = 'none';
            if(waliKelasSelect) waliKelasSelect.required = false;
            if(kelasMengajarSelect) kelasMengajarSelect.required = false;
        }
        
        // Update kelas mengajar berdasarkan kelas wali jika sudah dipilih
        if(jabatan === 'guru_wali' && waliKelasSelect && waliKelasSelect.value) {
            updateKelasMengajarForWali();
        }
    };
    
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
                infoText.className = 'mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded-md text-sm text-yellow-800';
                kelasMengajarSelect.parentElement.appendChild(infoText);
            }
            infoText.innerHTML = '<span class="font-medium">Info:</span> Karena Anda terpilih sebagai wali kelas, Anda hanya dapat mengajar di kelas wali yang dipilih.';
        }
    }
    
    // Function untuk enable semua opsi kelas mengajar
    function enableAllKelasMengajarOptions() {
        const kelasMengajarSelect = document.querySelector('[name="kelas_ids[]"]');
        if(kelasMengajarSelect) {
            Array.from(kelasMengajarSelect.options).forEach(option => {
                option.disabled = false;
            });
            
            // Hapus pesan informasi jika ada
            const infoText = document.getElementById('kelas_mengajar_info');
            if(infoText) {
                infoText.remove();
            }
        }
    }

    // Form validation
    const validationForm = document.querySelector('form');
    const requiredFields = validationForm.querySelectorAll('[required]');

    validationForm.addEventListener('submit', function(e) {
        let hasError = false;
        requiredFields.forEach(field => {
            if (field.multiple && field.selectedOptions.length === 0) {
                hasError = true;
                field.classList.add('border-red-500');
                addErrorMessage(field, 'Pilih minimal satu kelas');
            } else if (!field.multiple && !field.value.trim()) {
                hasError = true;
                field.classList.add('border-red-500');
                addErrorMessage(field, `${field.getAttribute('placeholder') || field.getAttribute('name')} wajib diisi`);
            }
        });

        // Validasi khusus untuk guru_wali
        const jabatan = document.getElementById('jabatan').value;
        const waliKelasId = document.getElementById('wali_kelas_id');
        
        if (jabatan === 'guru_wali' && (!waliKelasId.value || waliKelasId.value === '')) {
            hasError = true;
            waliKelasId.classList.add('border-red-500');
            addErrorMessage(waliKelasId, 'Wali kelas harus dipilih untuk guru dengan jabatan Guru dan Wali Kelas');
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

    function validateFile(input) {
        const file = input.files[0];
        if (!file) return;
        
        const fileType = file.type;
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        const maxSize = 2 * 1024 * 1024;
        
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

    // Set initial state
    handleJabatanChange();
    
    // Event listener untuk perubahan pada wali kelas di awal load
    const waliKelasSelect = document.querySelector('[name="wali_kelas_id"]');
    const jabatan = document.getElementById('jabatan').value;
    if(jabatan === 'guru_wali' && waliKelasSelect && waliKelasSelect.value) {
        updateKelasMengajarForWali();
    }
});
</script>
@endpush
@endsection
