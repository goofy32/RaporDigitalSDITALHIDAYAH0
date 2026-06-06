@extends('layouts.app')

@section('title', 'Edit Data Pengajar')

@section('content')
<div
    data-page="edit-teacher"
    data-available-kelas-count="{{ isset($availableKelas) ? $availableKelas->count() : 0 }}"
    data-kelas-list-count="{{ isset($kelasList) ? $kelasList->count() : 0 }}"
    data-has-current-wali-kelas="{{ $currentWaliKelas ? 'true' : 'false' }}"
    data-teacher-id="{{ $teacher->id }}"
    data-verify-password-url="{{ route('teacher.verify-password') }}"
>
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
                           <input type="number" name="nuptk" value="{{ old('nuptk', $teacher->nuptk) }}" placeholder="Kosongkan jika belum ada"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 15);">
                          <p class="mt-1 text-sm text-gray-500">Kosongkan jika belum ada. Jika diisi, masukkan hanya angka (9-15 digit)</p>
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
                           <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $teacher->tanggal_lahir) }}" max="{{ now()->subDay()->format('Y-m-d') }}" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                           @error('tanggal_lahir')
                               <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                           @enderror
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
                           <label class="block text-sm font-medium text-gray-700">Tanggung Jawab Guru</label>
                           <select name="jabatan" id="jabatan" onchange="handleJabatanChange()" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                               <option value="guru" {{ $teacher->jabatan === 'guru' ? 'selected' : '' }}>Pengajar Biasa</option>
                               <option value="guru_wali" {{ $teacher->jabatan === 'guru_wali' ? 'selected' : '' }}>Wali Kelas</option>
                           </select>
                       </div>

                       <!-- Kelas Mengajar -->
                       <div id="kelas_mengajar_section">
                            <label class="block text-sm font-medium text-gray-700">Kelas yang diajar sebagai pengajar khusus/muatan lokal</label>
                            
                            @php
                                $kelasAjar = $teacher->kelas
                                    ->filter(fn ($kelas) => $kelas->pivot->role === 'pengajar' && ! $kelas->pivot->is_wali_kelas)
                                    ->pluck('id')
                                    ->toArray();
                                
                                $kelasWali = $currentWaliKelas;
                            @endphp
                            
                            @if(isset($kelasList) && $kelasList->count() > 0)
                                <select name="kelas_ids[]" multiple required id="kelas_mengajar"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 min-h-[120px]">
                                    @foreach($kelasList as $kelas)
                                        <option value="{{ $kelas->id }}" 
                                            {{ in_array($kelas->id, $kelasAjar) ? 'selected' : '' }}>
                                            {{ $kelas->label_kelas }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">Untuk wali kelas, mata pelajaran wajib reguler otomatis mengikuti kelas wali dan tidak perlu dipilih di sini.</p>
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
                                        <span class="font-medium">Catatan:</span> Guru ini menjadi wali kelas untuk {{ $kelasWali->label_kelas }}.
                                        Kelas wali tidak perlu dipilih di daftar kelas mengajar, karena akan otomatis ditambahkan.
                                    </p>
                                </div>
                            @endif
                        </div>

                       <!-- Wali Kelas -->
                       <div id="wali_kelas_section" style="{{ $teacher->jabatan === 'guru_wali' ? '' : 'display:none;' }}">
                            <label class="block text-sm font-medium text-gray-700">Pilih kelas wali</label>
                            
                            @if(isset($availableKelas) && $availableKelas->count() > 0)
                                <select name="wali_kelas_id" id="wali_kelas_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <option value="">Pilih Kelas</option>
                                    @foreach($availableKelas as $kelas)
                                        <option value="{{ $kelas->id }}" 
                                            {{ ($kelasWali && $kelasWali->id === $kelas->id) ? 'selected' : '' }}>
                                            {{ $kelas->label_kelas }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($kelasWali)
                                    <p class="mt-1 text-sm text-gray-600">
                                        Saat ini menjadi wali kelas: 
                                        {{ $kelasWali->label_kelas }}
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

@endsection
