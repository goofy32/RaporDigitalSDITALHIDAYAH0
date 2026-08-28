<div class="table-responsive" role="region" aria-label="Daftar siswa" tabindex="0">
    <table class="min-w-[56rem] text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th class="px-6 py-3">No</th>
                <th class="px-6 py-3">NIS</th>
                <th class="px-6 py-3">NISN</th>
                <th class="px-6 py-3">Nama</th>
                <th class="px-6 py-3">Kelas</th>
                <th class="px-6 py-3">Jenis Kelamin</th>
                <th class="table-action-heading px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-6 py-4">{{ $loop->iteration + ($students->currentPage() - 1) * $students->perPage() }}</td>
                <td class="px-6 py-4">{{ str_starts_with($student->nis, 'S2-') ? substr($student->nis, 3) : $student->nis }}</td>
                <td class="px-6 py-4">{{ str_starts_with($student->nisn, 'S2-') ? substr($student->nisn, 3) : $student->nisn }}</td>
                <td class="px-6 py-4">{{ $student->nama }}</td>
                <td class="px-6 py-4">{{ $student->admin_kelas_label ?? optional($student->kelas)->full_kelas ?? '-' }}</td>
                <td class="px-6 py-4">{{ $student->jenis_kelamin }}</td>
                <td class="table-action-cell px-1 py-4" data-live-list-ignore>
                    <div class="table-action-group">
                    <a href="{{ route('student.show', $student->id) }}" class="table-action-control text-blue-600 hover:bg-blue-50 hover:text-blue-800" title="Lihat Lengkap" aria-label="Lihat lengkap siswa">
                       <img src="{{ asset('images/icons/detail.png') }}" alt="Detail Icon" class="w-5 h-5">
                    </a>
                    <a href="{{ route('student.edit', $student->id) }}" class="table-action-control text-yellow-600 hover:bg-yellow-50 hover:text-yellow-800" title="Ubah Data" aria-label="Ubah data siswa">
                        <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5">
                    </a>
                    <form action="{{ route('student.destroy', $student->id) }}" method="POST" class="inline-flex shrink-0" onsubmit="if (!confirm('Apakah Anda yakin ingin menghapus data ini?')) { return false; } const button = this.querySelector('[data-student-delete-submit]'); if (button) { button.disabled = true; button.setAttribute('aria-disabled', 'true'); button.classList.add('opacity-50', 'cursor-wait'); button.title = 'Menghapus...'; const icon = button.querySelector('img'); if (icon) { icon.alt = 'Menghapus...'; } } return true;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="table-action-control text-red-600 hover:bg-red-50 hover:text-red-800 disabled:pointer-events-none" title="Hapus Data" aria-label="Hapus data siswa" data-student-delete-submit>
                            <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5">
                        </button>
                    </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-4 text-center">Tidak ada data siswa.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $students->withQueryString()->links('vendor.pagination.custom') }}
</div>
