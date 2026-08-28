<div class="table-responsive relative shadow-md sm:rounded-lg" role="region" aria-label="Daftar mata pelajaran" tabindex="0">
    <table class="min-w-[64rem] text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="px-6 py-3">No</th>
                <th scope="col" class="px-6 py-3">Mata Pelajaran</th>
                <th scope="col" class="px-6 py-3">Kelas</th>
                <th scope="col" class="px-6 py-3">Semester</th>
                <th scope="col" class="px-6 py-3">Guru Pengampu</th>
                <th scope="col" class="px-6 py-3">Lingkup Materi</th>
                <th scope="col" class="table-action-heading min-w-[100px] w-28 px-6 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($subjects as $subject)
            @php
                $kelas = $subject->kelas;
                $guru = $subject->guru;
                $lingkupMateris = $subject->lingkupMateris ?? collect();
            @endphp
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-6 py-4">{{ ($subjects->currentPage() - 1) * $subjects->perPage() + $loop->iteration }}</td>
                <td class="px-6 py-4">{{ $subject->nama_pelajaran }}</td>
                <td class="px-6 py-4">
                    @if($kelas)
                        {{ $kelas->nomor_kelas }}-{{ $kelas->nama_kelas }}
                    @else
                        <span class="text-amber-700">Kelas tidak tersedia</span>
                    @endif
                </td>
                <td class="px-6 py-4">Semester {{ $subject->semester }}</td>
                <td class="px-6 py-4">
                    @if($guru)
                        {{ $guru->nama }}
                    @elseif($subject->guru_id)
                        <span class="text-amber-700">Guru tidak aktif</span>
                    @else
                        <span class="text-gray-500">Belum ada guru</span>
                    @endif
                </td>
                <td class="px-6 py-4">
                    @if($lingkupMateris->isNotEmpty())
                        <ul class="list-disc list-inside">
                            @foreach($lingkupMateris as $lm)
                                <li>{{ $lm->judul_lingkup_materi }}</li>
                            @endforeach
                        </ul>
                    @else
                        Tidak ada Lingkup Materi
                    @endif
                </td>

                <td class="table-action-cell px-1 py-4">
                    <div class="table-action-group" data-live-list-ignore>
                        <a href="{{ route('tujuan_pembelajaran.create', $subject->id) }}"
                           class="table-action-control text-green-600 hover:bg-green-50 hover:text-green-800"
                           title="Ubah atau Lihat Tujuan Pembelajaran"
                           aria-label="Ubah atau lihat tujuan pembelajaran">
                            <img src="{{ asset('images/icons/edittp.png') }}" alt="TP Icon" class="w-5 h-5 object-contain">
                        </a>

                        <a href="{{ route('subject.edit', $subject->id) }}"
                           data-turbo-action="replace"
                           class="table-action-control text-green-600 hover:bg-green-50 hover:text-green-800"
                           title="Ubah Data"
                           aria-label="Ubah data mata pelajaran">
                            <img src="{{ asset('images/icons/edit.png') }}" alt="Edit Icon" class="w-5 h-5 object-contain">
                        </a>

                        <form action="{{ route('subject.destroy', $subject->id) }}" method="POST" class="inline-flex shrink-0 items-center">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="table-action-control text-red-600 hover:bg-red-50 hover:text-red-800"
                                    onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')"
                                    title="Hapus Data"
                                    aria-label="Hapus data mata pelajaran">
                                <img src="{{ asset('images/icons/delete.png') }}" alt="Delete Icon" class="w-5 h-5 object-contain">
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr class="bg-white border-b">
                <td colspan="7" class="px-6 py-4 text-center">Tidak ada data mata pelajaran</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div>
    {{ $subjects->withQueryString()->links('vendor.pagination.custom') }}
</div>
