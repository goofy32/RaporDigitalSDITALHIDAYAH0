@extends('layouts.app')

@section('title', 'Tambah Data Pengajar')

@section('content')
<div
    data-page="create-teacher"
    data-kelas-for-wali-count="{{ isset($kelasForWali) ? $kelasForWali->count() : 0 }}"
    data-kelas-for-mengajar-count="{{ isset($kelasForMengajar) ? $kelasForMengajar->count() : 0 }}"
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
                        <input type="number" name="nuptk" id="nuptk" value="{{ old('nuptk') }}" min="0" pattern="[0-9]+" inputmode="numeric" placeholder="Kosongkan jika belum ada"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 @error('nuptk') border-red-500 @enderror"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <p class="mt-1 text-sm text-gray-500">Kosongkan jika belum ada. Jika diisi, masukkan hanya angka (9-15 digit)</p>
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
                        <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir') }}" max="{{ now()->subDay()->format('Y-m-d') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, tanggal harus sebelum hari ini.</p>
                        @error('tanggal_lahir')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">No. Handphone</label>
                        <input type="number" name="no_handphone" id="no_handphone" value="{{ old('no_handphone') }}" min="0" pattern="[0-9]+" inputmode="numeric"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                            oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value.length > 15) this.value = this.value.slice(0, 15);">
                        <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, masukkan hanya angka (10-15 digit).</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <p class="mt-1 text-sm text-gray-500">Opsional. Jika diisi, gunakan format email yang valid.</p>
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
                        <label class="block text-sm font-medium text-gray-700">Tanggung Jawab Guru</label>
                        <select name="jabatan" id="jabatan" onchange="handleJabatanChange()" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                            <option value="">Pilih tanggung jawab</option>
                            <option value="guru" {{ old('jabatan') == 'guru' ? 'selected' : '' }}>Pengajar Biasa</option>
                            <option value="guru_wali" {{ old('jabatan') == 'guru_wali' ? 'selected' : '' }}>Wali Kelas</option>
                        </select>
                    </div>

                    <div id="kelas_mengajar_section" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Kelas yang diajar sebagai pengajar khusus/muatan lokal</label>
                        @if(isset($kelasForMengajar) && $kelasForMengajar->count() > 0)
                            <select name="kelas_ids[]" multiple required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 min-h-[120px]">
                                @foreach($kelasForMengajar as $kelas)
                                    <option value="{{ $kelas->id }}" {{ (is_array(old('kelas_ids')) && in_array($kelas->id, old('kelas_ids'))) ? 'selected' : '' }}>
                                        {{ $kelas->label_kelas }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Untuk wali kelas, mata pelajaran wajib reguler otomatis mengikuti kelas wali dan tidak perlu dipilih di sini.</p>
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
                        <label class="block text-sm font-medium text-gray-700">Pilih kelas wali</label>
                        @if(isset($kelasForWali) && $kelasForWali->count() > 0)
                            <select name="wali_kelas_id" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <option value="">Pilih Kelas</option>
                                @foreach($kelasForWali as $kelas)
                                    <option value="{{ $kelas->id }}" {{ old('wali_kelas_id') == $kelas->id ? 'selected' : '' }}>
                                        {{ $kelas->label_kelas }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Wali kelas hanya mengampu mata pelajaran wajib reguler di kelas ini.</p>
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
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                            class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                        <p class="mt-1 text-sm text-gray-500">Format JPG, JPEG, PNG, atau WebP. Maksimal 2 MB.</p>
                        @error('photo')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
