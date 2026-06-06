@extends('layouts.wali_kelas.app')

@section('title', 'Tambah Data Siswa')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-sm mt-14">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Form Tambah Data Siswa</h2>
    </div>

    <form action="{{ route('wali_kelas.student.store') }}" method="POST" enctype="multipart/form-data" data-turbo="false" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @csrf
        <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

        <!-- Data Diri -->
        <div>
            <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Diri</h3>
            <div class="border p-4 space-y-4 rounded-b">
                <div>
                    <label for="nis" class="block font-semibold">NIS <span class="text-red-500">*</span></label>
                    <input type="text" id="nis" name="nis" class="w-full p-2 border rounded" value="{{ old('nis') }}" required>
                </div>

                <div>
                    <label for="nisn" class="block font-semibold">NISN <span class="text-red-500">*</span></label>
                    <input type="text" id="nisn" name="nisn" class="w-full p-2 border rounded" value="{{ old('nisn') }}" required>
                </div>

                <div>
                    <label for="nama" class="block font-semibold">Nama <span class="text-red-500">*</span></label>
                    <input type="text" id="nama" name="nama" class="w-full p-2 border rounded" value="{{ old('nama') }}" required>
                </div>

                <div>
                    <label for="tanggal_lahir" class="block font-semibold">Tanggal Lahir <span class="text-red-500">*</span></label>
                    <input type="date" id="tanggal_lahir" name="tanggal_lahir" max="{{ now()->subDay()->format('Y-m-d') }}" class="w-full p-2 border rounded" value="{{ old('tanggal_lahir') }}" required>
                </div>

                <div>
                    <label for="jenis_kelamin" class="block font-semibold">Jenis Kelamin <span class="text-red-500">*</span></label>
                    <select id="jenis_kelamin" name="jenis_kelamin" class="w-full p-2 border rounded" required>
                        <option value="">Pilih</option>
                        <option value="Laki-laki" {{ old('jenis_kelamin') == 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                        <option value="Perempuan" {{ old('jenis_kelamin') == 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
                    </select>
                </div>

                <div>
                    <label for="agama" class="block font-semibold">Agama <span class="text-red-500">*</span></label>
                    <select id="agama" name="agama" class="w-full p-2 border rounded" required>
                        <option value="">Pilih Agama</option>
                        <option value="Islam" {{ old('agama') == 'Islam' ? 'selected' : '' }}>Islam</option>
                    </select>
                </div>

                <div>
                    <label for="alamat" class="block font-semibold">Alamat <span class="text-red-500">*</span></label>
                    <textarea id="alamat" name="alamat" class="w-full p-2 border rounded" required>{{ old('alamat') }}</textarea>
                </div>

                <!-- Menampilkan kelas yang sudah fixed -->
                <div class="bg-gray-100 p-3 rounded">
                    <p class="font-medium">Kelas: {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</p>
                    <p class="text-sm text-gray-600">
                        Tahun Ajaran: 
                        @if($kelas->tahunAjaran)
                            {{ $kelas->tahunAjaran->tahun_ajaran }}
                        @else
                            Tidak ada
                        @endif
                    </p>
                </div>

                <input type="hidden" name="kelas_id" value="{{ $kelas->id }}">

                <div>
                    <label for="photo" class="block font-semibold">Photo (ukuran 4×6 atau 2×3)</label>
                    <input type="file" id="photo" name="photo" class="w-full p-2 border rounded">
                </div>
            </div>
        </div>

        <!-- Data Orang Tua -->
        <div>
            <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Orang Tua</h3>
            <div class="border p-4 space-y-4 rounded-b">
                <!-- Form fields untuk data orang tua -->
                <div>
                    <label for="nama_ayah" class="block font-semibold">Nama Ayah <span class="text-red-500">*</span></label>
                    <input type="text" id="nama_ayah" name="nama_ayah" class="w-full p-2 border rounded" value="{{ old('nama_ayah') }}" required>
                </div>

                <div>
                    <label for="nama_ibu" class="block font-semibold">Nama Ibu <span class="text-red-500">*</span></label>
                    <input type="text" id="nama_ibu" name="nama_ibu" class="w-full p-2 border rounded" value="{{ old('nama_ibu') }}" required>
                </div>

                <div>
                    <label for="pekerjaan_ayah" class="block font-semibold">Pekerjaan Ayah</label>
                    <input type="text" id="pekerjaan_ayah" name="pekerjaan_ayah" class="w-full p-2 border rounded" value="{{ old('pekerjaan_ayah') }}">
                </div>

                <div>
                    <label for="pekerjaan_ibu" class="block font-semibold">Pekerjaan Ibu</label>
                    <input type="text" id="pekerjaan_ibu" name="pekerjaan_ibu" class="w-full p-2 border rounded" value="{{ old('pekerjaan_ibu') }}">
                </div>

                <div>
                    <label for="alamat_orangtua" class="block font-semibold">Alamat Orang Tua</label>
                    <textarea id="alamat_orangtua" name="alamat_orangtua" class="w-full p-2 border rounded">{{ old('alamat_orangtua') }}</textarea>
                </div>
            </div>

            <!-- Data Wali -->
            <div class="mt-6">
                <h3 class="bg-green-700 text-white px-4 py-2 rounded-t">Data Wali</h3>
                <div class="border p-4 space-y-4 rounded-b">
                    <div>
                        <label for="wali_siswa" class="block font-semibold">Nama Wali</label>
                        <input type="text" id="wali_siswa" name="wali_siswa" class="w-full p-2 border rounded" value="{{ old('wali_siswa') }}">
                    </div>

                    <div>
                        <label for="pekerjaan_wali" class="block font-semibold">Pekerjaan Wali</label>
                        <input type="text" id="pekerjaan_wali" name="pekerjaan_wali" class="w-full p-2 border rounded" value="{{ old('pekerjaan_wali') }}">
                    </div>
                </div>
            </div>
        </div>

        <!-- Tombol Submit -->
        <div class="col-span-2 flex justify-end space-x-2 mt-4">
            <button type="submit" class="bg-green-700 text-white px-4 py-2 rounded hover:bg-green-800">Simpan</button>
            <a href="{{ route('wali_kelas.student.index') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Kembali</a>
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
