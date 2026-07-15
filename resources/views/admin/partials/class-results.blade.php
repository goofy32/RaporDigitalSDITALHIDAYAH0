<div class="overflow-x-auto mt-4">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th class="px-6 py-3">Nomor</th>
                <th class="px-6 py-3">Kelas</th>
                <th class="px-6 py-3">Wali Kelas</th>
                <th class="px-6 py-3">Aksi</th>
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
                <td class="px-6 py-4 text-center">
                    <div class="flex space-x-2">
                        <a href="{{ route('kelas.edit', $kelas->id) }}" class="text-yellow-600 hover:text-yellow-800" title="Ubah Data">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                        </a>
                        <form action="{{ route('kelas.destroy', $kelas->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini?');" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline" title="Hapus Data">
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
