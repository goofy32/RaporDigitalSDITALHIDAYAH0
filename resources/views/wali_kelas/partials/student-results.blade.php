<div class="relative overflow-x-auto shadow-md sm:rounded-lg">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th class="px-6 py-3">No</th>
                <th class="px-6 py-3">NIS</th>
                <th class="px-6 py-3">NISN</th>
                <th class="px-6 py-3">Nama</th>
                <th class="px-6 py-3">Kelas</th>
                <th class="px-6 py-3">Jenis Kelamin</th>
                <th class="px-6 py-3 text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-6 py-4">{{ ($students->currentPage() - 1) * $students->perPage() + $loop->iteration }}</td>
                <td class="px-6 py-4">{{ str_starts_with($student->nis, 'S2-') ? substr($student->nis, 3) : $student->nis }}</td>
                <td class="px-6 py-4">{{ str_starts_with($student->nisn, 'S2-') ? substr($student->nisn, 3) : $student->nisn }}</td>
                <td class="px-6 py-4">{{ $student->nama }}</td>
                <td class="px-6 py-4">
                    @if($student->kelas)
                        {{ $student->kelas->nomor_kelas }} - {{ $student->kelas->nama_kelas }}
                    @else
                        <span class="text-gray-400">Tidak ada kelas</span>
                    @endif
                </td>
                <td class="px-6 py-4">{{ $student->jenis_kelamin }}</td>
                <td class="px-6 py-4">
                    <div class="flex justify-center gap-2">
                        <a href="{{ route('wali_kelas.student.show', $student->id) }}"
                           class="text-blue-600 hover:text-blue-800"
                           title="Detail Siswa">
                            <img src="{{ asset('images/icons/detail.png') }}" alt="Detail Icon" class="w-5 h-5">
                        </a>

                        <a href="{{ route('wali_kelas.catatan.siswa.show', $student->id) }}"
                           class="text-green-600 hover:text-green-800"
                           title="Buat Catatan Guru Untuk Siswa">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                        </a>

                        <form action="{{ route('wali_kelas.student.destroy', $student->id) }}" method="POST" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="text-red-600 hover:text-red-800"
                                    title="Hapus Siswa"
                                    onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')">
                                <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-4 text-center">Tidak ada data siswa</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $students->withQueryString()->links('vendor.pagination.custom') }}
</div>
