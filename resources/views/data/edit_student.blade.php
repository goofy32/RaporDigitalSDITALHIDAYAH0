@extends('layouts.app')

@section('title', 'Edit Data Siswa')

@section('content')
<div data-page="edit-student">
    <div class="p-4 bg-white mt-14">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Siswa</h2>
            <!-- Tombol aksi -->
            <div class="flex space-x-2">
                <a href="{{ route('student') }}" class="px-6 py-2.5 bg-gray-500 text-white font-medium rounded-lg hover:bg-gray-600 focus:ring-4 focus:ring-gray-300">
                    Batal
                </a>
                <button type="submit" form="editStudentForm" class="px-6 py-2.5 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 focus:ring-4 focus:ring-green-300">
                    Update Data
                </button>
            </div>
        </div>

        <form id="editStudentForm" action="{{ route('student.update', $student->id) }}" @submit="handleSubmit" x-data="formProtection" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')
            
            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Data Diri Siswa -->
                <div class="bg-white rounded-lg shadow-sm">
                    <h3 class="text-lg font-semibold bg-green-700 text-white px-4 py-2 rounded-t-lg">Data Diri Siswa</h3>
                    <div class="p-6 space-y-4">
                        <!-- NIS -->
                        <div>
                            <label for="nis" class="block text-sm font-medium text-gray-700 mb-1">NIS <span class="text-red-500">*</span></label>
                            <input type="text" id="nis" name="nis" 
                                maxlength="10" 
                                pattern="[0-9]*"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('nis') border-red-500 @enderror" 
                                value="{{ old('nis', $student->nis) }}" required>
                            @error('nis')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- NISN -->
                        <div>
                            <label for="nisn" class="block text-sm font-medium text-gray-700 mb-1">NISN <span class="text-red-500">*</span></label>
                            <input type="text" id="nisn" name="nisn" 
                                maxlength="10" 
                                pattern="[0-9]*"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('nisn') border-red-500 @enderror" 
                                value="{{ old('nisn', $student->nisn) }}" required>
                            @error('nisn')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Nama -->
                        <div>
                            <label for="nama" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                            <input type="text" id="nama" name="nama" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('nama') border-red-500 @enderror" 
                                value="{{ old('nama', $student->nama) }}" required>
                            @error('nama')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Tanggal Lahir -->
                        <div>
                            <label for="tanggal_lahir" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Lahir <span class="text-red-500">*</span></label>
                            <input type="date" id="tanggal_lahir" name="tanggal_lahir" max="{{ now()->subDay()->format('Y-m-d') }}"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('tanggal_lahir') border-red-500 @enderror" 
                                value="{{ old('tanggal_lahir', $student->tanggal_lahir) }}" required>
                            @error('tanggal_lahir')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Jenis Kelamin -->
                        <div>
                            <label for="jenis_kelamin" class="block text-sm font-medium text-gray-700 mb-1">Jenis Kelamin <span class="text-red-500">*</span></label>
                            <select id="jenis_kelamin" name="jenis_kelamin" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('jenis_kelamin') border-red-500 @enderror" 
                                required>
                                <option value="">Pilih Jenis Kelamin</option>
                                <option value="Laki-laki" {{ old('jenis_kelamin', $student->jenis_kelamin) == 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                                <option value="Perempuan" {{ old('jenis_kelamin', $student->jenis_kelamin) == 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
                            </select>
                            @error('jenis_kelamin')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Agama -->
                        <div>
                            <label for="agama" class="block text-sm font-medium text-gray-700 mb-1">Agama <span class="text-red-500">*</span></label>
                            <select id="agama" name="agama" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('agama') border-red-500 @enderror" 
                                required>
                                <option value="">Pilih Agama</option>
                                <option value="Islam" {{ old('agama', $student->agama) == 'Islam' ? 'selected' : '' }}>Islam</option>
                            </select>
                            @error('agama')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Alamat -->
                        <div>
                            <label for="alamat" class="block text-sm font-medium text-gray-700 mb-1">Alamat <span class="text-red-500">*</span></label>
                            <textarea id="alamat" name="alamat" rows="3" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('alamat') border-red-500 @enderror" 
                                required>{{ old('alamat', $student->alamat) }}</textarea>
                            @error('alamat')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Kelas -->
                        <div>
                            <label for="kelas_id" class="block text-sm font-medium text-gray-700 mb-1">Kelas <span class="text-red-500">*</span></label>
                            <select id="kelas_id" name="kelas_id" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('kelas_id') border-red-500 @enderror" 
                                required>
                                <option value="">Pilih Kelas</option>
                                @foreach($kelas as $k)
                                    <option value="{{ $k->id }}" {{ old('kelas_id', $student->kelas_id) == $k->id ? 'selected' : '' }}>
                                        Kelas {{ $k->nomor_kelas }} {{ $k->nama_kelas }}
                                    </option>
                                @endforeach
                            </select>
                            @error('kelas_id')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Foto -->
                        <div>
                            <label for="photo" class="block text-sm font-medium text-gray-700 mb-1">Foto Siswa</label>
                            @if($student->photo)
                                <div class="mb-2">
                                    <img src="{{ asset('storage/' . $student->photo) }}" alt="Current photo" 
                                        class="w-32 h-32 object-cover rounded-lg border shadow-sm">
                                </div>
                            @endif
                            <input type="file" id="photo" name="photo" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('photo') border-red-500 @enderror"
                                accept="image/jpeg,image/png">
                            <div class="mt-1 text-sm text-gray-500">
                                <p>Ketentuan foto:</p>
                                <ul class="list-disc ml-4">
                                    <li>Format: JPG/JPEG/PNG</li>
                                    <li>Ukuran maksimal: 2MB</li>
                                    <li>Dimensi yang disarankan: 4x6 atau 2x3</li>
                                    <li>Background foto bebas dan formal</li>
                                </ul>
                            </div>
                            <!-- Preview foto akan ditampilkan di sini -->
                            <div id="photo-preview" class="mt-2"></div>
                            @error('photo')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Data Orang Tua dan Wali -->
                <div class="space-y-6">
                    <!-- Data Orang Tua -->
                    <div class="bg-white rounded-lg shadow-sm">
                        <h3 class="text-lg font-semibold bg-green-700 text-white px-4 py-2 rounded-t-lg">Data Orang Tua</h3>
                        <div class="p-6 space-y-4">
                            <!-- Nama Ayah -->
                            <div>
                                <label for="nama_ayah" class="block text-sm font-medium text-gray-700 mb-1">Nama Ayah <span class="text-red-500">*</span></label>
                                <input type="text" id="nama_ayah" name="nama_ayah" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('nama_ayah') border-red-500 @enderror" 
                                    value="{{ old('nama_ayah', $student->nama_ayah) }}" required>
                                @error('nama_ayah')
                                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Pekerjaan Ayah -->
                            <div>
                                <label for="pekerjaan_ayah" class="block text-sm font-medium text-gray-700 mb-1">Pekerjaan Ayah</label>
                                <input type="text" id="pekerjaan_ayah" name="pekerjaan_ayah" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200" 
                                    value="{{ old('pekerjaan_ayah', $student->pekerjaan_ayah) }}">
                            </div>

                            <!-- Nama Ibu -->
                            <div>
                                <label for="nama_ibu" class="block text-sm font-medium text-gray-700 mb-1">Nama Ibu <span class="text-red-500">*</span></label>
                                <input type="text" id="nama_ibu" name="nama_ibu" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 @error('nama_ibu') border-red-500 @enderror" 
                                    value="{{ old('nama_ibu', $student->nama_ibu) }}" required>
                                @error('nama_ibu')
                                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Pekerjaan Ibu -->
                            <div>
                                <label for="pekerjaan_ibu" class="block text-sm font-medium text-gray-700 mb-1">Pekerjaan Ibu</label>
                                <input type="text" id="pekerjaan_ibu" name="pekerjaan_ibu" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200" 
                                    value="{{ old('pekerjaan_ibu', $student->pekerjaan_ibu) }}">
                            </div>

                            <!-- Alamat Orang Tua -->
                            <div>
                                <label for="alamat_orangtua" class="block text-sm font-medium text-gray-700 mb-1">Alamat Orang Tua</label>
                                <textarea id="alamat_orangtua" name="alamat_orangtua" rows="3" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200">{{ old('alamat_orangtua', $student->alamat_orangtua) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Data Wali -->
                    <div class="bg-white rounded-lg shadow-sm">
                        <h3 class="text-lg font-semibold bg-green-700 text-white px-4 py-2 rounded-t-lg">Data Wali (Opsional)</h3>
                        <div class="p-6 space-y-4">
                            <!-- Nama Wali -->
                            <div>
                                <label for="wali_siswa" class="block text-sm font-medium text-gray-700 mb-1">Nama Wali</label>
                                <input type="text" id="wali_siswa" name="wali_siswa" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200" 
                                    value="{{ old('wali_siswa', $student->wali_siswa) }}">
                            </div>

                            <!-- Pekerjaan Wali -->
                            <div>
                                <label for="pekerjaan_wali" class="block text-sm font-medium text-gray-700 mb-1">Pekerjaan Wali</label>
                                <input type="text" id="pekerjaan_wali" name="pekerjaan_wali" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200" 
                                    value="{{ old('pekerjaan_wali', $student->pekerjaan_wali) }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
