<div class="table-responsive bg-white shadow-md rounded-lg" role="region" aria-label="Daftar prestasi" tabindex="0">
    <table class="min-w-[56rem] text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="px-6 py-3">NO</th>
                <th scope="col" class="px-6 py-3">Kelas</th>
                <th scope="col" class="px-6 py-3">Nama Siswa</th>
                <th scope="col" class="px-6 py-3">Jenis Prestasi</th>
                <th scope="col" class="px-6 py-3">Keterangan</th>
                <th scope="col" class="table-action-heading px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @if($prestasis->isEmpty())
                <tr class="bg-white border-b">
                    <td colspan="6" class="px-6 py-4 text-center">Tidak ada data prestasi.</td>
                </tr>
            @else
                @foreach ($prestasis as $prestasi)
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">{{ ($prestasis->currentPage() - 1) * $prestasis->perPage() + $loop->iteration }}</td>
                    <td class="px-6 py-4">
                        {{ $prestasi->kelas ? 'Kelas ' . $prestasi->kelas->nomor_kelas . ' - ' . $prestasi->kelas->nama_kelas : '-' }}
                    </td>
                    <td class="px-6 py-4">{{ $prestasi->siswa->nama ?? '-' }}</td>
                    <td class="px-6 py-4">{{ $prestasi->jenis_prestasi }}</td>
                    <td class="px-6 py-4">{{ $prestasi->keterangan }}</td>
                    <td class="table-action-cell px-6 py-4">
                        <div class="table-action-group" data-live-list-ignore>
                            <a href="{{ route('achievement.edit', $prestasi->id) }}" class="table-action-control text-green-600 hover:bg-green-50 hover:text-green-800" title="Ubah Data" aria-label="Ubah data prestasi">
                                <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5" title="Ubah Data">
                            </a>
                            <form action="{{ route('achievement.destroy', $prestasi->id) }}" method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');" class="inline-flex shrink-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="table-action-control text-red-600 hover:bg-red-50 hover:text-red-800" title="Hapus Data" aria-label="Hapus data prestasi">
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
