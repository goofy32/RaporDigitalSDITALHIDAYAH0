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
                           <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $teacher->tanggal_lahir) }}" max="{{ now()->subDay()->format('Y-m-d') }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                           <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, tanggal harus sebelum hari ini.</p>
                           @error('tanggal_lahir')
                               <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                           @enderror
                       </div>

                       <!-- No. Handphone -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">No. Handphone</label>
                           <input type="number" name="no_handphone" value="{{ old('no_handphone', $teacher->no_handphone) }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 15);">
                           <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, masukkan hanya angka (10-15 digit).</p>
                       </div>

                       <!-- Email -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Email</label>
                           <input type="email" name="email" value="{{ old('email', $teacher->email) }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                           <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, gunakan format email yang valid.</p>
                       </div>

                       <!-- Alamat -->
                       <div>
                           <label class="block text-sm font-medium text-gray-700">Alamat</label>
                            <textarea
                                name="alamat" rows="5" required
                                class="mt-1 block w-full min-h-[130px] resize-y rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                            >{{ old('alamat', $teacher->alamat) }}</textarea>
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
                           <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                           <p class="mt-1 text-sm text-gray-500">Format JPG, JPEG, PNG, atau WebP. Maksimal 2 MB.</p>
                           @error('photo')
                               <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                           @enderror
                       </div>

                       <!-- Password Section -->
                       <div class="pt-4 border-t border-gray-200">
                            <div class="flex flex-col gap-3 mb-4 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">Keamanan Password</h3>
                                    <p class="mt-1 text-sm text-gray-600">Password tidak dapat ditampilkan demi keamanan.</p>
                                </div>
                                <button type="button"
                                    data-guru-password-reset-open
                                    class="inline-flex items-center justify-center px-3 py-2 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100">
                                    Reset password guru
                                </button>
                            </div>
                            
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

       @php
           $signatureErrors = $errors->getBag('signatureUpload');
       @endphp

       <section class="mt-6 max-w-3xl rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
           <div class="grid gap-5 md:grid-cols-[180px,1fr]">
               <div class="flex min-h-[90px] items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 p-3">
                   @if($teacher->signature_path)
                       <img
                           src="{{ route('teacher.signature.show', $teacher) }}"
                           alt="Preview tanda tangan {{ $teacher->nama }}"
                           class="max-h-[90px] w-full object-contain"
                       >
                   @else
                       <div class="text-center text-sm text-gray-500">
                           Belum ada tanda tangan
                       </div>
                   @endif
               </div>

               <div>
                   <h3 class="text-lg font-semibold text-gray-900">Tanda Tangan Digital</h3>
                   <p class="mt-1 text-sm text-gray-600">
                       Opsional. Digunakan pada rapor ketika guru bertugas sebagai wali kelas.
                   </p>
                   <p class="mt-1 text-sm text-gray-500">
                       Format PNG, JPG, JPEG, atau WebP. Maksimal 1 MB. PNG transparan direkomendasikan.
                   </p>

                   @if(session('signatureUploadSuccess'))
                       <p class="mt-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
                           {{ session('signatureUploadSuccess') }}
                       </p>
                   @endif

                   @if($signatureErrors->has('signature'))
                       <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                           @foreach($signatureErrors->get('signature') as $signatureError)
                               <p>{{ $signatureError }}</p>
                           @endforeach
                       </div>
                   @endif

                   <div
                       data-signature-upload-client-error
                       class="mt-3 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                   ></div>

                   <div class="mt-4 flex flex-wrap items-center gap-3">
                       <form
                           id="signatureUploadForm"
                           data-signature-upload-form
                           data-turbo="false"
                           action="{{ route('teacher.signature.store', $teacher) }}"
                           method="POST"
                           enctype="multipart/form-data"
                       >
                           @csrf
                           <input
                               id="signature"
                               type="file"
                               name="signature"
                               accept="image/png,image/jpeg,image/webp"
                               data-signature-upload-input
                               class="sr-only"
                           >
                           <label
                               for="signature"
                               data-signature-upload-label
                               class="inline-flex cursor-pointer items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                               {{ $teacher->signature_path ? 'Ganti Tanda Tangan' : 'Pilih dan Unggah Tanda Tangan' }}
                           </label>
                       </form>

                       @if($teacher->signature_path)
                           <form action="{{ route('teacher.signature.destroy', $teacher) }}" method="POST"
                               onsubmit="return confirm('Hapus tanda tangan guru ini? Rapor berikutnya akan dibuat tanpa gambar tanda tangan.');">
                               @csrf
                               @method('DELETE')
                               <button type="submit"
                                   class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                                   Hapus
                               </button>
                           </form>
                       @endif
                   </div>

                   <p class="mt-3 text-xs text-gray-500">
                       File disimpan secara privat dan tidak ditampilkan sebagai URL publik.
                   </p>
               </div>
           </div>
       </section>
   </div>
</div>

<x-guru-password-reset-modal :teacher="$teacher" />

@endsection
