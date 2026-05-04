<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <title>Profil Sekolah</title>
    <meta name="turbo-cache-control" content="no-cache">
    <meta name="turbo-visit-control" content="reload">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
</head>

<body data-page="admin-profile"
      data-current-tahun-pelajaran="{{ old('tahun_pelajaran', $profil->tahun_pelajaran ?? '') }}"
      data-current-semester="{{ old('semester', $profil->semester ?? '') }}">
    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    @if ($errors->any())
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="p-4 sm:ml-64">
        <div class="p-4 bg-white mt-14 relative">
            <div class="absolute top-4 right-4">
                <button type="submit" form="profileForm"
                    class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">
                    Simpan
                </button>
            </div>
            <form id="profileForm" action="{{ route('profile.submit') }}" method="POST">
                @csrf

                <div class="grid gap-6 mb-6 md:grid-cols-2">
                    <div>
                        <label for="nama_instansi" class="block mb-2 text-sm font-medium text-gray-900">Nama Instansi</label>
                        <input type="text" id="nama_instansi" name="nama_instansi" value="{{ old('nama_instansi', $profil->nama_instansi ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('nama_instansi')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="nama_sekolah" class="block mb-2 text-sm font-medium text-gray-900">Nama Sekolah</label>
                        <input type="text" id="nama_sekolah" name="nama_sekolah" value="{{ old('nama_sekolah', $profil->nama_sekolah ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('nama_sekolah')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="npsn" class="block mb-2 text-sm font-medium text-gray-900">NPSN</label>
                        <input type="text" id="npsn" name="npsn" value="{{ old('npsn', $profil->npsn ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('npsn')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="alamat" class="block mb-2 text-sm font-medium text-gray-900">Alamat</label>
                        <input type="text" id="alamat" name="alamat" value="{{ old('alamat', $profil->alamat ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('alamat')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="kelurahan" class="block mb-2 text-sm font-medium text-gray-900">Kelurahan/Desa</label>
                        <input type="text" id="kelurahan" name="kelurahan" value="{{ old('kelurahan', $profil->kelurahan ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('kelurahan')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="kecamatan" class="block mb-2 text-sm font-medium text-gray-900">Kecamatan</label>
                        <input type="text" id="kecamatan" name="kecamatan" value="{{ old('kecamatan', $profil->kecamatan ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('kecamatan')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="kabupaten" class="block mb-2 text-sm font-medium text-gray-900">Kabupaten/Kota</label>
                        <input type="text" id="kabupaten" name="kabupaten" value="{{ old('kabupaten', $profil->kabupaten ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('kabupaten')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="provinsi" class="block mb-2 text-sm font-medium text-gray-900">Provinsi</label>
                        <input type="text" id="provinsi" name="provinsi" value="{{ old('provinsi', $profil->provinsi ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('provinsi')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="kode_pos" class="block mb-2 text-sm font-medium text-gray-900">Kode POS</label>
                        <input type="text" id="kode_pos" name="kode_pos" value="{{ old('kode_pos', $profil->kode_pos ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('kode_pos')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="telepon" class="block mb-2 text-sm font-medium text-gray-900">Telepon</label>
                        <input type="text" id="telepon" name="telepon" value="{{ old('telepon', $profil->telepon ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('telepon')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email_sekolah" class="block mb-2 text-sm font-medium text-gray-900">Email Sekolah</label>
                        <input type="email" id="email_sekolah" name="email_sekolah" value="{{ old('email_sekolah', $profil->email_sekolah ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('email_sekolah')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="website" class="block mb-2 text-sm font-medium text-gray-900">Website (Opsional)</label>
                        <input type="url" id="website" name="website" value="{{ old('website', $profil->website ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('website')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="tahun_pelajaran" class="block mb-2 text-sm font-medium text-gray-900 flex items-center gap-2">
                            Tahun Pelajaran dan Semester

                            <button data-tooltip-target="tooltip-tahun-ajaran" type="button" class="text-gray-500 hover:text-green-600 transition-colors">
                                <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/>
                                </svg>
                            </button>
                            <div id="tooltip-tahun-ajaran" role="tooltip" class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip">
                                Hanya diisi setelah Anda membuat Tahun Ajaran di tab Tahun Ajaran
                                <div class="tooltip-arrow" data-popper-arrow></div>
                            </div>
                        </label>
                        <select id="tahun_pelajaran" name="tahun_pelajaran"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                            <option value="">Pilih Tahun Pelajaran</option>
                            @foreach($tahunAjarans as $ta)
                                <option value="{{ $ta->tahun_ajaran }}"
                                        data-semester="{{ $ta->semester }}"
                                        {{ (old('tahun_pelajaran', $profil->tahun_pelajaran ?? '') == $ta->tahun_ajaran) ? 'selected' : '' }}>
                                    {{ $ta->tahun_ajaran }} - {{ $ta->semester == 1 ? 'Ganjil' : 'Genap' }}
                                </option>
                            @endforeach
                        </select>
                        @error('tahun_pelajaran')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <input type="hidden" id="semester" name="semester" value="{{ old('semester', $profil->semester ?? '') }}">

                    <div>
                        <label for="kepala_sekolah" class="block mb-2 text-sm font-medium text-gray-900">Kepala Sekolah</label>
                        <input type="text" id="kepala_sekolah" name="kepala_sekolah" value="{{ old('kepala_sekolah', $profil->kepala_sekolah ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('kepala_sekolah')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="nip_kepala_sekolah" class="block mb-2 text-sm font-medium text-gray-900">NUPTK Kepala Sekolah</label>
                        <input type="text" id="nip_kepala_sekolah" name="nip_kepala_sekolah" value="{{ old('nip_kepala_sekolah', $profil->nip_kepala_sekolah ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        @error('nip_kepala_sekolah')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <input type="hidden" id="nip_wali_kelas" name="nip_wali_kelas" value="{{ old('nip_wali_kelas', $profil->nip_wali_kelas ?? '') }}">

                    <div>
                        <label for="kelas" class="block mb-2 text-sm font-medium text-gray-900">Jumlah Kelas</label>
                        <input type="number" id="kelas" name="kelas" value="{{ old('kelas', $profil->kelas ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('kelas')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="guru_kelas" class="block mb-2 text-sm font-medium text-gray-900">Jumlah Guru Kelas</label>
                        <input type="number" id="guru_kelas" name="guru_kelas" value="{{ old('guru_kelas', $profil->guru_kelas ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('guru_kelas')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="jumlah_siswa" class="block mb-2 text-sm font-medium text-gray-900">Jumlah Siswa</label>
                        <input type="number" id="jumlah_siswa" name="jumlah_siswa" value="{{ old('jumlah_siswa', $profil->jumlah_siswa ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('jumlah_siswa')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="tempat_terbit" class="block mb-2 text-sm font-medium text-gray-900">Tempat Terbit Rapor</label>
                        <input type="text" id="tempat_terbit" name="tempat_terbit" value="{{ old('tempat_terbit', $profil->tempat_terbit ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('tempat_terbit')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="tanggal_terbit" class="block mb-2 text-sm font-medium text-gray-900">Tanggal Terbit Rapor</label>
                        <input type="date" id="tanggal_terbit" name="tanggal_terbit" value="{{ old('tanggal_terbit', $profil->tanggal_terbit ?? '') }}"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" required>
                        @error('tanggal_terbit')
                            <p class="text-red-500 text-sm">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
