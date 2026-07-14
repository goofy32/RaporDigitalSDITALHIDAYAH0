@php
    $targetClassLabel = $target->kelas
        ? 'Kelas '.$target->kelas->nomor_kelas.' '.$target->kelas->nama_kelas
        : 'Kelas tidak tersedia';
    $selectedSourceId = $selectedSource?->id ?? request('source_id');
@endphp

<div class="p-4 bg-white mt-14 shadow-lg rounded-lg">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Salin LM/TP</h2>
            <p class="mt-1 text-sm text-gray-600">
                Salin Lingkup Materi dan Tujuan Pembelajaran dari kelas paralel untuk mata pelajaran yang sama.
            </p>
        </div>
        <a href="{{ $backRoute }}" class="inline-flex items-center justify-center rounded-lg bg-gray-500 px-4 py-2 text-sm font-medium text-white hover:bg-gray-600">
            Kembali
        </a>
    </div>

    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-6 rounded-lg border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-800">
        <p class="font-semibold">Tujuan salin</p>
        <p>{{ $targetClassLabel }} - {{ $target->nama_pelajaran }} - Semester {{ $target->semester }}</p>
        <p class="mt-1 text-green-700">Data yang disalin hanya Lingkup Materi dan Tujuan Pembelajaran. Nilai, rapor, absensi, dan catatan siswa tidak ikut disalin.</p>
    </div>

    <form action="{{ $previewRoute }}" method="GET" data-turbo="false" class="mb-6 rounded-lg border border-gray-200 p-4">
        <label for="source_id" class="mb-2 block text-sm font-medium text-gray-900">Pilih sumber pembelajaran</label>
        <div class="flex flex-col gap-2 sm:flex-row">
            <select id="source_id"
                    name="source_id"
                    required
                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500">
                <option value="">Pilih kelas sumber</option>
                @foreach($sources as $source)
                    <option value="{{ $source->id }}" @selected((int) $selectedSourceId === (int) $source->id)>
                        Kelas {{ $source->kelas?->nomor_kelas }} {{ $source->kelas?->nama_kelas }} - {{ $source->nama_pelajaran }} - {{ $source->lingkupMateris->count() }} LM
                    </option>
                @endforeach
            </select>
            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">
                Preview
            </button>
        </div>
        @if($sources->isEmpty())
            <p class="mt-2 text-sm text-yellow-700">Belum ada pembelajaran sumber yang sesuai. LM/TP hanya dapat disalin dari mata pelajaran yang sama pada tahun ajaran, semester, dan tingkat kelas yang sama.</p>
        @endif
    </form>

    @if($preview)
        <div class="rounded-lg border border-gray-200">
            <div class="border-b border-gray-200 p-4">
                <h3 class="text-lg font-semibold text-gray-900">Preview Salin LM/TP</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Dari Kelas {{ $selectedSource->kelas?->nomor_kelas }} {{ $selectedSource->kelas?->nama_kelas }} ke {{ $targetClassLabel }}.
                    Preview ini belum mengubah data.
                </p>
            </div>

            <div class="grid gap-3 p-4 sm:grid-cols-4">
                <div class="rounded-lg bg-green-50 p-3 text-green-800">
                    <p class="text-xs uppercase tracking-wide">LM disalin</p>
                    <p class="text-2xl font-bold">{{ $preview['summary']['lm_to_copy'] }}</p>
                </div>
                <div class="rounded-lg bg-green-50 p-3 text-green-800">
                    <p class="text-xs uppercase tracking-wide">TP disalin</p>
                    <p class="text-2xl font-bold">{{ $preview['summary']['tp_to_copy'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 text-gray-700">
                    <p class="text-xs uppercase tracking-wide">LM dilewati</p>
                    <p class="text-2xl font-bold">{{ $preview['summary']['lm_skipped'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 text-gray-700">
                    <p class="text-xs uppercase tracking-wide">TP dilewati</p>
                    <p class="text-2xl font-bold">{{ $preview['summary']['tp_skipped'] }}</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                        <tr>
                            <th class="px-4 py-3">Lingkup Materi</th>
                            <th class="px-4 py-3">Status LM</th>
                            <th class="px-4 py-3">Tujuan Pembelajaran</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preview['items'] as $item)
                            <tr class="border-t align-top">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $item['judul_lingkup_materi'] }}</td>
                                <td class="px-4 py-3">
                                    @if($item['will_copy_lm'])
                                        <span class="rounded bg-green-100 px-2 py-1 text-xs font-medium text-green-700">Akan disalin</span>
                                    @else
                                        <span class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">Sudah ada, tidak dibuat ulang</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <ul class="space-y-1">
                                        @forelse($item['tujuan_pembelajarans'] as $tp)
                                            <li>
                                                <span class="font-medium">{{ $tp['kode_tp'] }}</span>
                                                {{ $tp['deskripsi_tp'] }}
                                                @if($tp['will_copy'])
                                                    <span class="ml-2 text-xs text-green-700">akan disalin</span>
                                                @else
                                                    <span class="ml-2 text-xs text-gray-500">dilewati karena sudah ada</span>
                                                @endif
                                            </li>
                                        @empty
                                            <li class="text-gray-500">Tidak ada TP pada lingkup materi ini.</li>
                                        @endforelse
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-600">
                    Konfirmasi diperlukan sebelum data disalin. Data yang sudah ada di kelas tujuan tidak akan ditimpa.
                </p>
                <form action="{{ $applyRoute }}" method="POST" data-turbo="false">
                    @csrf
                    <input type="hidden" name="source_id" value="{{ $selectedSource->id }}">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">
                        Konfirmasi Salin LM/TP
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
