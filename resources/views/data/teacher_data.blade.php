<!-- resources/views/data/teacher_data.blade.php -->
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
    <title>Detail Data Pengajar</title>
</head>

<body>
    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    <div class="p-4 xl:ml-64">
        <div class="p-4 bg-white mt-14">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-2xl font-bold text-green-700">Detail Data Pengajar</h2>
                <div class="flex flex-wrap gap-2">
                    <button class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600" onclick="window.history.back()">Kembali</button>
                    <button onclick="window.location.href='{{ route('teacher.edit', $teacher->id) }}'"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        Edit
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-8 lg:flex-row">
                <div class="mx-auto flex h-80 w-full max-w-64 shrink-0 items-start justify-center overflow-hidden rounded-lg bg-gray-200 shadow-md lg:mx-0">
                @if($teacher->photo)
                    <img src="{{ asset('storage/' . $teacher->photo) }}"
                        alt="Foto Pengajar"
                        class="w-full h-full object-cover">
                @else
                    <div class="flex items-center justify-center w-full h-full">
                        <svg class="w-32 h-32 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                        </svg>
                    </div>
                @endif
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <tbody>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">NUPTK</th>
                                <td class="px-4 py-2">{{ $teacher->nuptk ?: 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Nama</th>
                                <td class="px-4 py-2">{{ $teacher->nama ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Jenis Kelamin</th>
                                <td class="px-4 py-2">{{ $teacher->jenis_kelamin ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Tanggal Lahir</th>
                                <td class="px-4 py-2">
                                    @if($teacher->tanggal_lahir instanceof \Carbon\Carbon)
                                        {{ $teacher->tanggal_lahir->format('d-m-Y') }}
                                    @elseif(is_string($teacher->tanggal_lahir) && !empty($teacher->tanggal_lahir))
                                        {{ date('d-m-Y', strtotime($teacher->tanggal_lahir)) }}
                                    @else
                                        Belum Diisi
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">No Handphone</th>
                                <td class="px-4 py-2">{{ $teacher->no_handphone ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Email</th>
                                <td class="px-4 py-2">{{ $teacher->email ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Alamat</th>
                                <td class="px-4 py-2">{{ $teacher->alamat ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Jabatan</th>
                                <td class="px-4 py-2">
                                    @if($teacher->jabatan == 'guru_wali')
                                        Guru dan Wali Kelas
                                    @else
                                        Guru
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Kelas Mengajar</th>
                                <td class="px-4 py-2">
                                    @php
                                        $teachingGroups = $teacher->groupedTeachingResponsibilities();
                                    @endphp

                                    @if($teachingGroups->count() > 0)
                                        <div class="space-y-3">
                                            @foreach($teachingGroups as $classLabel => $subjects)
                                                <div>
                                                    <p class="font-medium text-gray-900">{{ $classLabel }}</p>
                                                    @if($subjects->isNotEmpty())
                                                        <ul class="mt-1 list-disc list-inside text-gray-600">
                                                            @foreach($subjects as $subjectName)
                                                                <li>{{ $subjectName }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @else
                                                        <p class="mt-1 text-gray-500">Belum ada mata pelajaran.</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <span>-</span>
                                    @endif
                                </td>
                            </tr>

                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Wali Kelas</th>
                                <td class="px-4 py-2">
                                    @php
                                        $waliLabels = $teacher->waliClassLabels();
                                    @endphp

                                    @if($waliLabels->isNotEmpty())
                                        {{ $waliLabels->join(', ') }}
                                    @else
                                        <span>Bukan Wali Kelas</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Username</th>
                                <td class="px-4 py-2">{{ $teacher->username ?? 'Belum Diisi' }}</td>
                            </tr>
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium text-gray-900">Password</th>
                                <td class="px-4 py-2">
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-600">Password tidak dapat ditampilkan demi keamanan.</p>
                                        <button type="button"
                                            data-guru-password-reset-open
                                            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100">
                                            Reset password guru
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <x-guru-password-reset-modal :teacher="$teacher" />
</body>
</html>
