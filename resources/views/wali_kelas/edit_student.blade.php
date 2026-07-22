@extends('layouts.wali_kelas.app')

@section('title', 'Edit Data Siswa')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-sm mt-14">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-bold text-green-700">Form Edit Data Siswa</h2>
        <div class="flex flex-wrap gap-2 sm:justify-end">
            <button type="submit" form="wali-student-edit-form" class="bg-green-700 text-white px-4 py-2 rounded hover:bg-green-800">Update</button>
            <a href="{{ route('wali_kelas.student.index') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Kembali</a>
        </div>
    </div>

    <form id="wali-student-edit-form" action="{{ route('wali_kelas.student.update', $student->id) }}" method="POST" enctype="multipart/form-data" x-data="formProtection" @submit="handleSubmit" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @csrf
        @method('PUT')

        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

        <!-- Data Diri -->
        <div>
            <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Diri</h3>
            <div class="border p-4 space-y-4 rounded-b">
                <div>
                    <label for="nis" class="block font-semibold">NIS</label>
                    <input type="text" id="nis" name="nis" maxlength="20" class="w-full p-2 border rounded @error('nis') border-red-500 @enderror"
                           value="{{ old('nis', $student->nis) }}" required>
                    @error('nis')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="nisn" class="block font-semibold">NISN</label>
                    <input type="text" id="nisn" name="nisn" maxlength="20" class="w-full p-2 border rounded @error('nisn') border-red-500 @enderror"
                           value="{{ old('nisn', $student->nisn) }}" required>
                    @error('nisn')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="nama" class="block font-semibold">Nama</label>
                    <input type="text" id="nama" name="nama" maxlength="255" class="w-full p-2 border rounded @error('nama') border-red-500 @enderror"
                           value="{{ old('nama', $student->nama) }}" required>
                    @error('nama')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="tanggal_lahir" class="block font-semibold">Tanggal Lahir</label>
                    <input type="date" id="tanggal_lahir" name="tanggal_lahir" max="{{ now()->subDay()->format('Y-m-d') }}"
                           class="w-full p-2 border rounded @error('tanggal_lahir') border-red-500 @enderror" 
                           value="{{ old('tanggal_lahir', $student->tanggal_lahir) }}" required>
                    @error('tanggal_lahir')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="jenis_kelamin" class="block font-semibold">Jenis Kelamin</label>
                    <select id="jenis_kelamin" name="jenis_kelamin" 
                            class="w-full p-2 border rounded @error('jenis_kelamin') border-red-500 @enderror" required>
                        <option value="">Pilih</option>
                        <option value="Laki-laki" {{ (old('jenis_kelamin', $student->jenis_kelamin) == 'Laki-laki') ? 'selected' : '' }}>Laki-laki</option>
                        <option value="Perempuan" {{ (old('jenis_kelamin', $student->jenis_kelamin) == 'Perempuan') ? 'selected' : '' }}>Perempuan</option>
                    </select>
                    @error('jenis_kelamin')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="agama" class="block font-semibold">Agama</label>
                    <select id="agama" name="agama" class="w-full p-2 border rounded @error('agama') border-red-500 @enderror" required>
                        <option value="">Pilih Agama</option>
                        <option value="Islam" {{ (old('agama', $student->agama) == 'Islam') ? 'selected' : '' }}>Islam</option>
                        <option value="Kristen" {{ (old('agama', $student->agama) == 'Kristen') ? 'selected' : '' }}>Kristen</option>
                        <option value="Katolik" {{ (old('agama', $student->agama) == 'Katolik') ? 'selected' : '' }}>Katolik</option>
                        <option value="Hindu" {{ (old('agama', $student->agama) == 'Hindu') ? 'selected' : '' }}>Hindu</option>
                        <option value="Buddha" {{ (old('agama', $student->agama) == 'Buddha') ? 'selected' : '' }}>Buddha</option>
                        <option value="Konghucu" {{ (old('agama', $student->agama) == 'Konghucu') ? 'selected' : '' }}>Konghucu</option>
                    </select>
                    @error('agama')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="alamat" class="block font-semibold">Alamat</label>
                    <textarea id="alamat" name="alamat" maxlength="500" class="w-full p-2 border rounded @error('alamat') border-red-500 @enderror"
                              required>{{ old('alamat', $student->alamat) }}</textarea>
                    @error('alamat')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Menampilkan kelas yang sudah fixed -->
                <div class="bg-gray-100 p-3 rounded">
                    <p class="font-medium">Kelas: {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</p>
                </div>

                <input type="hidden" name="kelas_id" value="{{ $kelas->id }}">

                <div>
                    <label for="photo" class="block font-semibold mb-2">Photo (ukuran 4×6 atau 2×3)</label>
                    @if($student->photo)
                        <div class="mb-2">
                            <img src="{{ asset('storage/' . $student->photo) }}" 
                                 alt="Foto {{ $student->nama }}" 
                                 class="w-32 h-32 object-cover rounded">
                        </div>
                    @endif
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"
                           class="w-full p-2 border rounded @error('photo') border-red-500 @enderror">
                    <p class="text-xs text-gray-500 mt-1">Format JPG, JPEG, PNG, atau WEBP. Ukuran maksimal 2 MB.</p>
                    @error('photo')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Data Orang Tua -->
        <div>
            <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Orang Tua</h3>
            <div class="border p-4 space-y-4 rounded-b">
                <div>
                    <label for="nama_ayah" class="block font-semibold">Nama Ayah</label>
                    <input type="text" id="nama_ayah" name="nama_ayah" maxlength="255"
                           class="w-full p-2 border rounded @error('nama_ayah') border-red-500 @enderror"
                           value="{{ old('nama_ayah', $student->nama_ayah) }}" required>
                    @error('nama_ayah')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="nama_ibu" class="block font-semibold">Nama Ibu</label>
                    <input type="text" id="nama_ibu" name="nama_ibu" maxlength="255"
                           class="w-full p-2 border rounded @error('nama_ibu') border-red-500 @enderror"
                           value="{{ old('nama_ibu', $student->nama_ibu) }}" required>
                    @error('nama_ibu')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="pekerjaan_ayah" class="block font-semibold">Pekerjaan Ayah</label>
                    <input type="text" id="pekerjaan_ayah" name="pekerjaan_ayah" maxlength="100"
                           class="w-full p-2 border rounded @error('pekerjaan_ayah') border-red-500 @enderror"
                           value="{{ old('pekerjaan_ayah', $student->pekerjaan_ayah) }}">
                    @error('pekerjaan_ayah')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="pekerjaan_ibu" class="block font-semibold">Pekerjaan Ibu</label>
                    <input type="text" id="pekerjaan_ibu" name="pekerjaan_ibu" maxlength="100"
                           class="w-full p-2 border rounded @error('pekerjaan_ibu') border-red-500 @enderror"
                           value="{{ old('pekerjaan_ibu', $student->pekerjaan_ibu) }}">
                    @error('pekerjaan_ibu')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="alamat_orangtua" class="block font-semibold">Alamat Orang Tua</label>
                    <textarea id="alamat_orangtua" name="alamat_orangtua" maxlength="500"
                              class="w-full p-2 border rounded @error('alamat_orangtua') border-red-500 @enderror">{{ old('alamat_orangtua', $student->alamat_orangtua) }}</textarea>
                    @error('alamat_orangtua')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Data Wali -->
            <div class="mt-6">
                <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Wali</h3>
                <div class="border p-4 space-y-4 rounded-b">
                    <div>
                        <label for="wali_siswa" class="block font-semibold">Nama Wali</label>
                        <input type="text" id="wali_siswa" name="wali_siswa" maxlength="255"
                               class="w-full p-2 border rounded @error('wali_siswa') border-red-500 @enderror"
                               value="{{ old('wali_siswa', $student->wali_siswa) }}">
                        @error('wali_siswa')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="pekerjaan_wali" class="block font-semibold">Pekerjaan Wali</label>
                        <input type="text" id="pekerjaan_wali" name="pekerjaan_wali" maxlength="100"
                               class="w-full p-2 border rounded @error('pekerjaan_wali') border-red-500 @enderror"
                               value="{{ old('pekerjaan_wali', $student->pekerjaan_wali) }}">
                        @error('pekerjaan_wali')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check for SweetAlert validation error in session
        @if(session('swal_validation_error'))
            Swal.fire({
                icon: 'error',
                title: 'Validasi Error',
                text: @json(session('swal_validation_error')),
                confirmButtonText: 'Oke'
            });
        @endif

        // Disable Turbo for this form
        const form = document.querySelector('form');
        if (form) {
            form.setAttribute('data-turbo', 'false');
        }
    });
</script>
@endpush

@endsection
