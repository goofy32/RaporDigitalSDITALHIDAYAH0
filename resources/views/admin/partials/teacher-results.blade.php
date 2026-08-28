<div class="table-responsive mt-4" role="region" aria-label="Daftar pengajar" tabindex="0">
    <table class="min-w-[92rem] text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th class="px-6 py-3">No</th>
                <th class="px-6 py-3">NUPTK</th>
                <th class="px-6 py-3">Nama</th>
                <th class="px-6 py-3">Username</th>
                <th class="px-6 py-3">Jenis Kelamin</th>
                <th class="px-6 py-3">Email</th>
                <th class="px-6 py-3">No Handphone</th>
                <th class="px-6 py-3">Alamat</th>
                <th class="px-6 py-3">Jabatan</th>
                <th class="px-6 py-3">Tanggung Jawab</th>
                <th class="table-action-heading px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($teachers as $teacher)
            <tr class="bg-white border-b hover:bg-gray-50 @if(request('search') && stripos($teacher->nama, request('search')) !== false) bg-green-50 @endif">
                <td class="px-6 py-4">{{ $loop->iteration + ($teachers->currentPage() - 1) * $teachers->perPage() }}</td>
                <td class="px-6 py-4">{{ $teacher->nuptk ?: '-' }}</td>
                <td class="px-6 py-4 font-medium @if(request('search') && stripos($teacher->nama, request('search')) !== false) text-green-700 @endif">{{ $teacher->nama }}</td>
                <td class="px-6 py-4">{{ $teacher->username }}</td>
                <td class="px-6 py-4">{{ $teacher->jenis_kelamin }}</td>
                <td class="px-6 py-4">
                    @if($teacher->email)
                        <div class="min-w-48 space-y-1.5">
                            <div class="break-all text-gray-700">{{ $teacher->email }}</div>
                            @if($teacher->email_verified_at)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-800"
                                      data-email-verification-status="verified">
                                    Terverifikasi
                                </span>
                            @else
                                <form method="POST"
                                      action="{{ route('teacher.verification.send', $teacher) }}"
                                      class="inline-block"
                                      data-email-verification-form
                                      data-email="{{ $teacher->email }}"
                                      onsubmit="return window.confirmGuruVerification(event, this);">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-medium text-yellow-800 hover:bg-yellow-200 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-1"
                                            data-email-verification-status="unverified">
                                        Belum diverifikasi
                                    </button>
                                </form>
                            @endif
                        </div>
                    @else
                        <span class="text-gray-400">-</span>
                    @endif
                </td>
                <td class="px-6 py-4">{{ $teacher->no_handphone }}</td>
                <td class="px-6 py-4">{{ $teacher->alamat }}</td>
                <td class="px-6 py-4">
                    @if($teacher->jabatan == 'guru_wali')
                        Guru dan Wali Kelas
                    @else
                        Guru
                    @endif
                </td>
                <td class="px-6 py-4">
                    @php
                        $waliLabels = $teacher->waliClassLabels();
                        $mengajarLabels = $teacher->teachingSummaryLabels();
                    @endphp
                    <div class="min-w-52 space-y-1 text-gray-700">
                        <div>
                            <span class="font-medium text-gray-900">Wali Kelas:</span>
                            <span>{{ $waliLabels->isNotEmpty() ? $waliLabels->join(', ') : '-' }}</span>
                        </div>
                        <div>
                            <span class="font-medium text-gray-900">Mengajar:</span>
                            @if($mengajarLabels->isNotEmpty())
                                <div class="mt-1 space-y-0.5">
                                    @foreach($mengajarLabels as $label)
                                        <div>{{ $label }}</div>
                                    @endforeach
                                </div>
                            @else
                                <span>-</span>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="table-action-cell px-1 py-4">
                    <div class="table-action-group" data-live-list-ignore>
                        <a href="{{ route('teacher.show', $teacher->id) }}" class="table-action-control text-blue-600 hover:bg-blue-50 hover:text-blue-800" title="Lihat Detail" aria-label="Lihat detail pengajar">
                            <img src="{{ asset('images/icons/detail.png') }}" alt="Detail Icon" class="w-5 h-5">
                        </a>
                        <a href="{{ route('teacher.edit', $teacher->id) }}" class="table-action-control text-yellow-600 hover:bg-yellow-50 hover:text-yellow-800" title="Ubah Data" aria-label="Ubah data pengajar">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                        </a>
                        <form action="{{ route('teacher.destroy', $teacher->id) }}" method="POST"
                            onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini?');"
                            class="inline-flex shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="table-action-control text-red-500 hover:bg-red-50 hover:text-red-700" title="Hapus Data" aria-label="Hapus data pengajar">
                                <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="px-6 py-4 text-center">Tidak ada data pengajar.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $teachers->withQueryString()->links('vendor.pagination.custom') }}
</div>
