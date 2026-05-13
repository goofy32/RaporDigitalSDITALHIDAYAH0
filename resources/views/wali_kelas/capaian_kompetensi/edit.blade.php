@extends('layouts.wali_kelas.app')

@section('content')
<div class="p-4 bg-white mt-14">
    @php
        $initialCustomizedCount = $existingCapaian
            ->filter(fn ($item) => filled($item->custom_capaian_tertinggi) || filled($item->custom_capaian_terendah))
            ->count();
    @endphp

    <form action="{{ route('wali_kelas.capaian_kompetensi.update', $mataPelajaran->id) }}"
          method="POST"
          id="capaianKompetensiForm"
          x-data="capaianKompetensiForm()"
          x-on:submit.prevent="submitForm">
        @csrf
        @method('PUT')

        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-green-700">Kelola Capaian Kompetensi</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $mataPelajaran->nama_pelajaran }} - Kelas {{ $mataPelajaran->kelas->nomor_kelas }}{{ $mataPelajaran->kelas->nama_kelas }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('wali_kelas.capaian_kompetensi.index') }}"
                   class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </a>
                <button type="submit"
                        x-bind:disabled="isSubmitting"
                        x-bind:class="isSubmitting ? 'opacity-50 cursor-not-allowed' : ''"
                        class="inline-flex items-center rounded-lg bg-green-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-green-700">
                    <span x-show="!isSubmitting" class="inline-flex items-center">
                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Simpan Semua Perubahan
                    </span>
                    <span x-show="isSubmitting" class="inline-flex items-center">
                        <svg class="mr-2 h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Menyimpan...
                    </span>
                </button>
            </div>
        </div>

        <div class="mb-6 border-l-4 border-green-500 bg-green-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div class="ml-3 text-sm text-green-800">
                    <p>
                        <strong>Capaian Tertinggi</strong> menampilkan lingkup materi yang paling dikuasai siswa.
                        <strong>Capaian Terendah</strong> menampilkan lingkup materi yang masih perlu diperkuat.
                        Kosongkan textarea jika ingin tetap memakai kalimat otomatis dari sistem.
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-3 rounded-lg bg-gray-50 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                <div><strong>Total Siswa:</strong> {{ $siswaList->count() }}</div>
                <div><strong>Dikustomisasi:</strong> <span x-text="customizedCount">{{ $initialCustomizedCount }}</span></div>
            </div>

            <div class="relative w-full max-w-xs">
                <input type="text"
                       x-model="searchTerm"
                       placeholder="Cari nama siswa..."
                       class="block w-full rounded-lg border border-gray-300 bg-white py-2 pl-10 pr-4 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="mb-4 text-sm text-gray-600">
            <span x-show="hasChanges" x-transition class="font-medium text-orange-600">
                Ada perubahan yang belum disimpan.
            </span>
            <span x-show="!hasChanges">
                Perbarui capaian tertinggi dan terendah siswa sesuai kebutuhan, lalu simpan perubahan.
            </span>
        </div>

        <div class="relative overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                    <tr>
                        <th class="px-4 py-3">No</th>
                        <th class="px-4 py-3">Nama Siswa</th>
                        <th class="px-4 py-3">Nilai</th>
                        <th class="px-4 py-3">Capaian Tertinggi</th>
                        <th class="px-4 py-3">Capaian Terendah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($siswaList as $index => $siswa)
                        @php
                            $existingRow = $existingCapaian->get($siswa->id);
                            $existingCapaianTertinggi = $existingRow?->custom_capaian_tertinggi ?? '';
                            $existingCapaianTerendah = $existingRow?->custom_capaian_terendah ?? '';
                            $nilai = $siswa->nilais()
                                ->where('mata_pelajaran_id', $mataPelajaran->id)
                                ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
                                ->first();
                            $nilaiAkhir = $nilai ? $nilai->nilai_akhir_rapor : null;
                            $autoCapaian = \App\Http\Controllers\CapaianKompetensiController::generateAutoCapaianTertinggiTerendah(
                                $siswa->id,
                                $mataPelajaran->id,
                                session('tahun_ajaran_id')
                            );
                        @endphp
                        <tr class="border-b bg-white align-top hover:bg-gray-50"
                            x-show="searchTerm === '' || '{{ strtolower($siswa->nama) }}'.includes(searchTerm.toLowerCase())"
                            x-transition>
                            <td class="px-4 py-4 font-medium text-gray-900">{{ $index + 1 }}</td>
                            <td class="px-4 py-4">
                                <div class="font-medium text-gray-900">{{ $siswa->nama }}</div>
                                <div class="mt-1 text-xs text-gray-500">NIS: {{ $siswa->nis }}</div>
                            </td>
                            <td class="px-4 py-4">
                                @if(!is_null($nilaiAkhir))
                                    <div class="font-semibold text-gray-900">{{ number_format($nilaiAkhir, 0) }}</div>
                                    <div class="mt-1 text-xs text-gray-500">Nilai akhir rapor</div>
                                @else
                                    <span class="text-xs text-gray-400">Belum tersedia</span>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <p class="mb-2 text-xs italic text-gray-500">{{ $autoCapaian['tertinggi'] }}</p>
                                <textarea name="capaian_tertinggi[{{ $siswa->id }}]"
                                          rows="2"
                                          class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                          placeholder="Kosongkan untuk pakai teks otomatis"
                                          x-on:input="updateCustomizedCount(); checkForChanges()">{{ $existingCapaianTertinggi }}</textarea>
                            </td>
                            <td class="px-4 py-4">
                                <p class="mb-2 text-xs italic text-gray-500">{{ $autoCapaian['terendah'] }}</p>
                                <textarea name="capaian_terendah[{{ $siswa->id }}]"
                                          rows="2"
                                          class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                          placeholder="Kosongkan untuk pakai teks otomatis"
                                          x-on:input="updateCustomizedCount(); checkForChanges()">{{ $existingCapaianTerendah }}</textarea>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>
</div>

@push('scripts')
<script>
window.capaianKompetensiHasChanges = false;

function capaianKompetensiForm() {
    return {
        searchTerm: '',
        isSubmitting: false,
        hasChanges: false,
        customizedCount: {{ $initialCustomizedCount }},
        originalValues: {},

        init() {
            this.$nextTick(() => {
                this.$el.querySelectorAll('textarea[name^="capaian_"]').forEach((textarea) => {
                    this.originalValues[textarea.name] = textarea.value;
                });
            });
        },

        updateCustomizedCount() {
            let count = 0;
            this.$el.querySelectorAll('tbody tr').forEach((row) => {
                const tertinggi = row.querySelector('textarea[name^="capaian_tertinggi"]');
                const terendah = row.querySelector('textarea[name^="capaian_terendah"]');

                if ((tertinggi && tertinggi.value.trim() !== '') || (terendah && terendah.value.trim() !== '')) {
                    count++;
                }
            });
            this.customizedCount = count;
        },

        checkForChanges() {
            let hasChanges = false;
            this.$el.querySelectorAll('textarea[name^="capaian_"]').forEach((textarea) => {
                if (textarea.value !== this.originalValues[textarea.name]) {
                    hasChanges = true;
                }
            });

            this.hasChanges = hasChanges;
            window.capaianKompetensiHasChanges = hasChanges;
        },

        submitForm() {
            if (this.isSubmitting) {
                return;
            }

            this.isSubmitting = true;
            window.capaianKompetensiHasChanges = false;
            this.$el.submit();
        }
    };
}

window.addEventListener('beforeunload', function (e) {
    if (window.capaianKompetensiHasChanges) {
        e.preventDefault();
        e.returnValue = 'Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
        return e.returnValue;
    }
});
</script>
@endpush
@endsection
