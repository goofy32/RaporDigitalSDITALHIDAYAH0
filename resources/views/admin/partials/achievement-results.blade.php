<div class="overflow-x-auto bg-white shadow-md rounded-lg">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="px-4 py-3 text-center">
                    <input type="checkbox" data-bulk-delete-select-all class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500" aria-label="Pilih semua prestasi di halaman ini">
                </th>
                <th scope="col" class="px-6 py-3">NO</th>
                <th scope="col" class="px-6 py-3">Kelas</th>
                <th scope="col" class="px-6 py-3">Nama Siswa</th>
                <th scope="col" class="px-6 py-3">Jenis Prestasi</th>
                <th scope="col" class="px-6 py-3">Keterangan</th>
                <th scope="col" class="px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @if($prestasis->isEmpty())
                <tr class="bg-white border-b">
                    <td colspan="7" class="px-6 py-4 text-center">Tidak ada data prestasi.</td>
                </tr>
            @else
                @foreach ($prestasis as $prestasi)
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-4 py-4 text-center">
                        <input type="checkbox" value="{{ $prestasi->id }}" data-bulk-delete-checkbox class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500" aria-label="Pilih prestasi {{ $prestasi->jenis_prestasi }}">
                    </td>
                    <td class="px-6 py-4">{{ ($prestasis->currentPage() - 1) * $prestasis->perPage() + $loop->iteration }}</td>
                    <td class="px-6 py-4">
                        {{ $prestasi->kelas ? 'Kelas ' . $prestasi->kelas->nomor_kelas . ' - ' . $prestasi->kelas->nama_kelas : '-' }}
                    </td>
                    <td class="px-6 py-4">{{ $prestasi->siswa->nama ?? '-' }}</td>
                    <td class="px-6 py-4">{{ $prestasi->jenis_prestasi }}</td>
                    <td class="px-6 py-4">{{ $prestasi->keterangan }}</td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex space-x-2" data-live-list-ignore>
                            <a href="{{ route('achievement.edit', $prestasi->id) }}" class="text-green-600 hover:text-green-800 transition-colors duration-200">
                                <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5" title="Ubah Data">
                            </a>
                            <form action="{{ route('achievement.destroy', $prestasi->id) }}" method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 transition-colors duration-200" title="Hapus Data">
                                    <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            @endif
        </tbody>
    </table>
</div>

<div>
    {{ $prestasis->withQueryString()->links('vendor.pagination.custom') }}
</div>
