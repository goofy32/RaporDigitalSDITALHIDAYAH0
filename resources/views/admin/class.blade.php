@extends('layouts.app')

@section('title', 'Data Kelas')

@section('content')
<div >
    <div class="p-4 bg-white mt-14">
        <!-- Header Data Kelas -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Kelas</h2>
        </div>

        @if(session('info'))
        <div class="mb-4 bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4">
            <p>{{ session('info') }}</p>
        </div>
        @endif

        @if(session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4">
            <p>{{ session('success') }}</p>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <p>{{ session('error') }}</p>
        </div>
        @endif

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <a href="{{ route('kelas.create') }}" 
            class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
             <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                 <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
             </svg>
             Tambah Data
            </a>
        </div>
        
        <div data-live-list>
            <form action="{{ route('kelas.index') }}" method="GET" class="mb-4" data-live-list-form data-turbo="false">
                <div class="flex flex-col gap-3 md:flex-row">
                    <div class="flex flex-1 gap-2">
                        <input type="text" name="search" value="{{ request('search') }}"
                            data-live-search-input
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                            placeholder="Cari (contoh: kelas 1)">
                        <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                    </div>

                    <details class="relative" data-live-filter-panel>
                        <x-live-list.filter-button />
                        <div class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-4 shadow-lg md:absolute md:right-0 md:z-20 md:w-80">
                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Tingkat kelas</label>
                                    <select name="tingkat" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua tingkat</option>
                                        @foreach($classLevels as $level)
                                            <option value="{{ $level }}" @selected((string) request('tingkat') === (string) $level)>Kelas {{ $level }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Wali kelas</label>
                                    <select name="wali_kelas_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua wali kelas</option>
                                        @foreach($waliKelasOptions as $wali)
                                            <option value="{{ $wali->id }}" @selected((string) request('wali_kelas_id') === (string) $wali->id)>{{ $wali->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Status wali</label>
                                    <select name="wali_status" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua status</option>
                                        <option value="ada" @selected(request('wali_status') === 'ada')>Sudah ada wali kelas</option>
                                        <option value="belum" @selected(request('wali_status') === 'belum')>Belum ada wali kelas</option>
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
                                    <a href="{{ route('kelas.index') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
                                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">Terapkan</button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </form>

            @include('components.live-list.filter-chips', ['filters' => [
                ['key' => 'search', 'label' => 'Pencarian'],
                ['key' => 'tingkat', 'label' => 'Tingkat'],
                ['key' => 'wali_kelas_id', 'label' => 'Wali kelas', 'values' => $waliKelasOptions->pluck('nama', 'id')->all()],
                ['key' => 'wali_status', 'label' => 'Status wali', 'values' => ['ada' => 'Sudah ada wali kelas', 'belum' => 'Belum ada wali kelas']],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['za' => 'Z-A']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <div data-live-list-results>
                @include('admin.partials.class-results', ['kelasList' => $kelasList])
            </div>
        </div>
    </div>
</div>
@endsection
