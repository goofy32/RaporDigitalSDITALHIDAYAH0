<div class="table-responsive mt-4" role="region" aria-label="Daftar kelas" tabindex="0">
    <table class="min-w-[40rem] text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th class="px-6 py-3">Nomor</th>
                <th class="px-6 py-3">Kelas</th>
                <th class="px-6 py-3">Wali Kelas</th>
                <th class="table-action-heading px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($kelasList as $index => $kelas)
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-6 py-4">{{ $index + $kelasList->firstItem() }}</td>
                <td class="px-6 py-4">{{ $kelas->label_kelas }}</td>
                <td class="px-6 py-4">
                    @if($kelas->waliKelas->first())
                        {{ $kelas->waliKelas->first()->nama }}
                    @else
                        <span class="text-gray-400">Belum ada wali kelas</span>
                    @endif
                </td>
                <td class="table-action-cell px-6 py-4">
                    <div class="table-action-group" data-live-list-ignore>
                        <a href="{{ route('kelas.edit', $kelas->id) }}" class="table-action-control text-yellow-600 hover:bg-yellow-50 hover:text-yellow-800" title="Ubah Data" aria-label="Ubah data kelas">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                        </a>
                        <form action="{{ route('kelas.destroy', $kelas->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini?');" class="inline-flex shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="table-action-control text-red-500 hover:bg-red-50 hover:text-red-700" title="Hapus Data" aria-label="Hapus data kelas">
                                <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="px-6 py-4 text-center">Tidak ada data kelas.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div>
    {{ $kelasList->withQueryString()->links('vendor.pagination.custom') }}
</div>
