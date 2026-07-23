@extends('layouts.app')

@section('title', 'Data Pelajaran')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Mata Pelajaran</h2>
        </div>

        <div class="flex justify-start mb-4 gap-2">
            <a href="{{ route('subject.create') }}" class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Tambah Data 
            </a>
            
            <a href="{{ route('admin.subject.bobot-nilai') }}" class="flex items-center justify-center text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                </svg>
                Bobot Nilai
            </a>
            
            <a href="{{ route('admin.subject.kkm') }}" class="flex items-center justify-center text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                Kriteria Ketuntasan Minimum
            </a>
        </div>

        <div data-live-list>
            <form action="{{ route('subject.index') }}" method="GET" class="mb-4" data-live-list-form data-turbo="false">
                <div class="flex flex-col gap-3 md:flex-row">
                    <div class="flex flex-1 gap-2">
                        <input type="text" name="search" value="{{ request('search') }}"
                            data-live-search-input
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                            placeholder="Cari nama mata pelajaran">
                        <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                    </div>

                    <details class="relative" data-live-filter-panel>
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
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Guru pengajar</label>
                                    <select name="guru_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua guru</option>
                                        @foreach($guruOptions as $guru)
                                            <option value="{{ $guru->id }}" @selected((string) request('guru_id') === (string) $guru->id)>{{ $guru->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenis</label>
                                    <select name="jenis" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua jenis</option>
                                        <option value="wajib" @selected(request('jenis') === 'wajib')>Wajib</option>
                                        <option value="muatan_lokal" @selected(request('jenis') === 'muatan_lokal')>Muatan Lokal</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Kelengkapan TP</label>
                                    <select name="tp_status" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="lengkap" @selected(request('tp_status') === 'lengkap')>TP lengkap</option>
                                        <option value="belum" @selected(request('tp_status') === 'belum')>Belum lengkap</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                    <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Urutan kelas</option>
                                        <option value="az" @selected(request('sort') === 'az')>A-Z</option>
                                        <option value="za" @selected(request('sort') === 'za')>Z-A</option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-2">
                                    <a href="{{ route('subject.index') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
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
                ['key' => 'guru_id', 'label' => 'Guru', 'values' => $guruOptions->pluck('nama', 'id')->all()],
                ['key' => 'jenis', 'label' => 'Jenis', 'values' => ['wajib' => 'Wajib', 'muatan_lokal' => 'Muatan Lokal']],
                ['key' => 'tp_status', 'label' => 'Kelengkapan TP', 'values' => ['lengkap' => 'TP lengkap', 'belum' => 'Belum lengkap']],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['az' => 'A-Z', 'za' => 'Z-A']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <div data-live-list-results>
                @include('admin.partials.subject-results', ['subjects' => $subjects])
            </div>
        </div>
    </div>
</div>

<!-- Flowbite JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.js"></script>
@endsection
