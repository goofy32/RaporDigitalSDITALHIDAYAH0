@extends('layouts.app')

@section('title', 'Data Prestasi')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Prestasi</h2>
        </div>

        <!-- Tombol Tambah Data -->
        <div class="flex justify-start mb-4">
            <a href="{{ route('achievement.create') }}" class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Tambah Data
            </a>
        </div>

        <div data-live-list>
            <form action="{{ route('achievement.index') }}" method="GET" class="mb-4" data-live-list-form data-turbo="false">
                <div class="flex flex-col gap-3 md:flex-row">
                    <div class="flex flex-1 gap-2">
                        <input type="text" name="search" value="{{ request('search') }}"
                            data-live-search-input
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                            placeholder="Cari (contoh: kelas 1, nama siswa, NIS, atau NISN)">
                        <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                    </div>

                    <details class="relative">
                        <x-live-list.filter-button />
                        <div class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-4 shadow-lg md:absolute md:right-0 md:z-20 md:w-80">
                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Kelas</label>
                                    <select name="kelas_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua kelas</option>
                                        @foreach($kelasOptions as $kelas)
                                            <option value="{{ $kelas->id }}" @selected((string) request('kelas_id') === (string) $kelas->id)>{{ $kelas->label_kelas }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Siswa</label>
                                    <select name="siswa_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua siswa</option>
                                        @foreach($siswaOptions as $siswa)
                                            <option value="{{ $siswa->id }}" @selected((string) request('siswa_id') === (string) $siswa->id)>{{ $siswa->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenis prestasi</label>
                                    <select name="jenis_prestasi" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua jenis</option>
                                        @foreach($jenisPrestasiOptions as $jenisPrestasi)
                                            <option value="{{ $jenisPrestasi }}" @selected(request('jenis_prestasi') === $jenisPrestasi)>{{ $jenisPrestasi }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                    <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Terbaru</option>
                                        <option value="terlama" @selected(request('sort') === 'terlama')>Terlama</option>
                                        <option value="az" @selected(request('sort') === 'az')>A-Z</option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-2">
                                    <a href="{{ route('achievement.index') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
                                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">Terapkan</button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </form>

            @include('components.live-list.filter-chips', ['filters' => [
                ['key' => 'search', 'label' => 'Pencarian'],
                ['key' => 'kelas_id', 'label' => 'Kelas', 'values' => $kelasOptions->mapWithKeys(fn ($kelas) => [$kelas->id => $kelas->label_kelas])->all()],
                ['key' => 'siswa_id', 'label' => 'Siswa', 'values' => $siswaOptions->pluck('nama', 'id')->all()],
                ['key' => 'jenis_prestasi', 'label' => 'Jenis prestasi'],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['terlama' => 'Terlama', 'az' => 'A-Z']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <x-admin.bulk-delete-toolbar
                :action="route('admin.bulk-delete', 'achievements')"
                record-type="Prestasi"
                form-id="bulk-delete-achievements-form"
            />

            <div data-live-list-results>
                @include('admin.partials.achievement-results', ['prestasis' => $prestasis])
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.js"></script>

@endsection
