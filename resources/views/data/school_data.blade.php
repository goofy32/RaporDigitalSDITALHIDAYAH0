<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <title>Data Sekolah</title>
</head>

<body>
    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    <div class="p-4 sm:ml-64">
        <div class="p-6 bg-white mt-14">
            <!-- Pesan Sukses atau Peringatan -->
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('warning'))
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                    {{ session('warning') }}
                </div>
            @endif

            <!-- Bagian Header Data Sekolah -->
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-bold text-green-700">Data Sekolah</h2>
                <div class="space-x-2">
                    <a href="{{ route('profile.edit') }}" class="px-4 py-2 bg-green-600 text-white rounded-lg">Edit</a>
                </div>
            </div>

            <!-- Bagian Konten Data Sekolah -->
            <div class="grid grid-cols-3 gap-4">
                <!-- Gambar Profil Kiri -->
                <div class="p-4 bg-gray-100 rounded-lg flex items-center justify-center">
                    <img src="{{ asset('images/logo.png') }}" 
                         alt="Logo Sekolah"
                        class="w-64 h-64 object-cover object-center rounded-lg">
                </div>

                <!-- Tabel Data Sekolah -->
                <div class="col-span-2">
                    <table class="w-full border-collapse border border-gray-300">
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Nama Instansi</td>
                            <td class="border border-gray-300 p-2">{{ $profil->nama_instansi ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Nama Sekolah</td>
                            <td class="border border-gray-300 p-2">{{ $profil->nama_sekolah ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">NPSN</td>
                            <td class="border border-gray-300 p-2">{{ $profil->npsn ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Alamat</td>
                            <td class="border border-gray-300 p-2">{{ $profil->alamat ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Kelurahan/Desa</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kelurahan ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Kecamatan</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kecamatan ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Kabupaten/Kota</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kabupaten ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Provinsi</td>
                            <td class="border border-gray-300 p-2">{{ $profil->provinsi ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Kode Pos</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kode_pos ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Telepon</td>
                            <td class="border border-gray-300 p-2">{{ $profil->telepon ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Email Sekolah</td>
                            <td class="border border-gray-300 p-2">{{ $profil->email_sekolah ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Website</td>
                            <td class="border border-gray-300 p-2">{{ $profil->website ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Tahun Pelajaran</td>
                            <td class="border border-gray-300 p-2">{{ $profil->tahun_pelajaran ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Semester</td>
                            <td class="border border-gray-300 p-2">
                                @if(is_null($profil->getRawOriginal('semester')))
                                    -
                                @else
                                    {{ $profil->semester == 1 ? 'Ganjil' : 'Genap' }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Kepala Sekolah</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kepala_sekolah ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">NUPTK Kepala Sekolah</td>
                            <td class="border border-gray-300 p-2">{{ $profil->nip_kepala_sekolah ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Guru Kelas</td>
                            <td class="border border-gray-300 p-2">{{ $profil->guru_kelas ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Jumlah Kelas</td>
                            <td class="border border-gray-300 p-2">{{ $profil->kelas ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Jumlah Siswa</td>
                            <td class="border border-gray-300 p-2">{{ $profil->jumlah_siswa ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Tempat Terbit Rapor</td>
                            <td class="border border-gray-300 p-2">{{ $profil->tempat_terbit ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="border border-gray-300 p-2 font-semibold">Tanggal Terbit Rapor</td>
                            <td class="border border-gray-300 p-2">{{ $profil->tanggal_terbit ? date('d-m-Y', strtotime($profil->tanggal_terbit)) : '-' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
