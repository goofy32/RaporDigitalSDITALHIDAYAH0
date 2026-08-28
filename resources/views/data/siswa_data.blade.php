<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <title>Detail Data Siswa</title>
</head>

<body>

    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    <div class="p-4 xl:ml-64">
        <div class="p-4 bg-white mt-14">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-2xl font-bold text-green-700">Detail Data Siswa</h2>
                <div class="flex flex-wrap gap-2">
                    <button class="bg-gray-600 text-white font-medium py-2 px-4 rounded hover:bg-gray-700" onclick="window.history.back()">Kembali</button>
                    <a href="{{ route('student.edit', $student->id) }}" 
                    class="bg-green-600 text-white font-medium py-2 px-4 rounded hover:bg-green-700">
                    Edit
                    </a>
                </div>
            </div>

            <div class="flex flex-col gap-8 lg:flex-row">
                <div class="mx-auto w-full max-w-xs shrink-0 lg:mx-0 lg:w-1/4">
                    <div class="bg-gray-200 rounded-lg shadow-md p-4">
                        @if($student->photo)
                            <img src="{{ asset('storage/' . $student->photo) }}" alt="{{ $student->nama }}" class="w-full h-auto rounded-lg">
                        @else
                            <div class="flex items-center justify-center w-full h-48 bg-gray-300 rounded-lg">
                                <svg class="w-16 h-16 text-gray-500" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zM8 10a4 4 0 118 0 4 4 0 01-8 0zm12 9a8 8 0 00-16 0h16z" />
                                </svg>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <tbody>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">NISN</th>
                                <td class="px-4 py-2">{{ str_starts_with($student['nisn'], 'S2-') ? substr($student['nisn'], 3) : $student['nisn'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Nama</th>
                                <td class="px-4 py-2">{{ $student['nama'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Kelas</th>
                                <td class="px-4 py-2">{{ $student->kelas->nomor_kelas }} - {{ $student->kelas->nama_kelas }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Tanggal Lahir</th>
                                <td class="px-4 py-2">{{ $student['tanggal_lahir'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Jenis Kelamin</th>
                                <td class="px-4 py-2">{{ $student['jenis_kelamin'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Agama</th>
                                <td class="px-4 py-2">{{ $student['agama'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Alamat</th>
                                <td class="px-4 py-2">{{ $student['alamat'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Nama Ayah</th>
                                <td class="px-4 py-2">{{ $student->nama_ayah }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Pekerjaan Ayah</th>
                                <td class="px-4 py-2">{{ $student->pekerjaan_ayah }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Nama Ibu</th>
                                <td class="px-4 py-2">{{ $student->nama_ibu }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Pekerjaan Ibu</th>
                                <td class="px-4 py-2">{{ $student->pekerjaan_ibu }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Alamat Orang Tua</th>
                                <td class="px-4 py-2">{{ $student->alamat_orangtua }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
