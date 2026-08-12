@extends('layouts.app')

@section('title', 'Data Pengajar')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header Data Pengajar -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Pengajar</h2>
        </div>

        <!-- Tombol Tambah Data -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <a href="{{ route('teacher.create') }}" 
                class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Tambah Data
            </a>
        </div>

        <div data-live-list>
            <form action="{{ route('teacher') }}" method="GET" class="mb-4" data-live-list-form>
                <div class="flex flex-col gap-3 md:flex-row">
                    <div class="flex flex-1 gap-2">
                        <input type="text" name="search" value="{{ request('search') }}"
                            data-live-search-input
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                            placeholder="Cari pengajar berdasarkan NUPTK, Nama, Username, Email">
                        <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                    </div>

                    <details class="relative" data-live-filter-panel>
                        <x-live-list.filter-button />
                        <div class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-4 shadow-lg md:absolute md:right-0 md:z-20 md:w-80">
                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Tanggung jawab</label>
                                    <select name="jabatan" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="guru" @selected(request('jabatan') === 'guru')>Pengajar</option>
                                        <option value="guru_wali" @selected(request('jabatan') === 'guru_wali')>Guru dan Wali Kelas</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Status wali kelas</label>
                                    <select name="wali_status" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="wali" @selected(request('wali_status') === 'wali')>Menjadi wali kelas</option>
                                        <option value="bukan_wali" @selected(request('wali_status') === 'bukan_wali')>Bukan wali kelas</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Kelas yang diajar</label>
                                    <select name="kelas_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua kelas</option>
                                        @foreach($kelasOptions as $kelas)
                                            <option value="{{ $kelas->id }}" @selected((string) request('kelas_id') === (string) $kelas->id)>{{ $kelas->label_kelas }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Mata pelajaran</label>
                                    <select name="mata_pelajaran_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua mata pelajaran</option>
                                        @foreach($mataPelajaranOptions as $mapel)
                                            <option value="{{ $mapel->id }}" @selected((string) request('mata_pelajaran_id') === (string) $mapel->id)>{{ $mapel->nama_pelajaran }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                    <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">A-Z</option>
                                        <option value="za" @selected(request('sort') === 'za')>Z-A</option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-2">
                                    <a href="{{ route('teacher') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
                                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">Terapkan</button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </form>

            @include('components.live-list.filter-chips', ['filters' => [
                ['key' => 'search', 'label' => 'Pencarian'],
                ['key' => 'jabatan', 'label' => 'Tanggung jawab', 'values' => ['guru' => 'Pengajar', 'guru_wali' => 'Guru dan Wali Kelas']],
                ['key' => 'wali_status', 'label' => 'Status wali', 'values' => ['wali' => 'Menjadi wali kelas', 'bukan_wali' => 'Bukan wali kelas']],
                ['key' => 'kelas_id', 'label' => 'Kelas', 'values' => $kelasOptions->mapWithKeys(fn ($kelas) => [$kelas->id => $kelas->label_kelas])->all()],
                ['key' => 'mata_pelajaran_id', 'label' => 'Mata pelajaran', 'values' => $mataPelajaranOptions->pluck('nama_pelajaran', 'id')->all()],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['za' => 'Z-A']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <div data-live-list-results>
                @include('admin.partials.teacher-results', ['teachers' => $teachers])
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.confirmGuruVerification = async function(event, form) {
        event.preventDefault();

        const email = form.dataset.email || '';
        let confirmed = false;

        if (window.Swal) {
            const result = await window.Swal.fire({
                icon: 'warning',
                title: `Kirim email verifikasi ke ${email}?`,
                text: 'Guru harus membuka tautan verifikasi dan masuk menggunakan akun Guru yang terkait.',
                showCancelButton: true,
                confirmButtonText: 'Kirim Verifikasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#15803d',
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = window.confirm(`Kirim email verifikasi ke ${email}?`);
        }

        if (confirmed) {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            form.submit();
        }

        return false;
    };
</script>
@endpush
