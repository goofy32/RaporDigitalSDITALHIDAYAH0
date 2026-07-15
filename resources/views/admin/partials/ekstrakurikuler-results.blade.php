<div class="overflow-x-auto bg-white shadow-md rounded-lg">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="px-6 py-3">NO</th>
                <th scope="col" class="px-6 py-3">Nama Ekstrakulikuler</th>
                <th scope="col" class="px-6 py-3">Pembina</th>
                <th scope="col" class="px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($ekstrakurikulers as $ekstra)
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-6 py-4">{{ ($ekstrakurikulers->currentPage() - 1) * $ekstrakurikulers->perPage() + $loop->iteration }}</td>
                <td class="px-6 py-4">{{ $ekstra->nama_ekstrakurikuler }}</td>
                <td class="px-6 py-4">{{ $ekstra->pembina }}</td>
                <td class="px-6 py-4">
                    <div class="flex space-x-2">
                        <a href="{{ route('ekstra.edit', $ekstra->id) }}" class="text-green-600 hover:text-green-800 transition-colors duration-200" title="Ubah Data">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                        </a>
                        <form action="{{ route('ekstra.destroy', $ekstra->id) }}" method="POST" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-800 transition-colors duration-200"
                                    onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')" title="Hapus Data">
                                    <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr class="bg-white border-b">
                <td colspan="4" class="px-6 py-4 text-center">Tidak ada data ekstrakurikuler</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div>
    {{ $ekstrakurikulers->withQueryString()->links('vendor.pagination.custom') }}
</div>
