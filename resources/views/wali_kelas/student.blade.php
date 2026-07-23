{{-- resources/views/wali_kelas/student.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('title', 'Data Siswa')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Siswa</h2>
        </div>

        <div data-live-list>
            <form action="{{ route('wali_kelas.student.index') }}" method="GET" class="mb-4" data-live-list-form>
                <div class="flex flex-col gap-3 md:flex-row">
                    <div class="flex flex-1 gap-2">
                        <input type="text" name="search" value="{{ request('search') }}"
                            data-live-search-input
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                            placeholder="Cari nama siswa, NIS, atau NISN...">
                        <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                    </div>

                    <details class="relative" data-live-filter-panel>
                        <x-live-list.filter-button />
                        <div class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-4 shadow-lg md:absolute md:right-0 md:z-20 md:w-80">
                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenis kelamin</label>
                                    <select name="jenis_kelamin" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="Laki-laki" @selected(request('jenis_kelamin') === 'Laki-laki')>Laki-laki</option>
                                        <option value="Perempuan" @selected(request('jenis_kelamin') === 'Perempuan')>Perempuan</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Catatan siswa</label>
                                    <select name="catatan" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="ada" @selected(request('catatan') === 'ada')>Ada catatan</option>
                                        <option value="belum" @selected(request('catatan') === 'belum')>Belum ada catatan</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                    <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Nama A-Z</option>
                                        <option value="nama_za" @selected(request('sort') === 'nama_za')>Nama Z-A</option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-2">
                                    <a href="{{ route('wali_kelas.student.index') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
                                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">Terapkan</button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </form>

            @include('components.live-list.filter-chips', ['filters' => [
                ['key' => 'search', 'label' => 'Pencarian'],
                ['key' => 'jenis_kelamin', 'label' => 'Jenis kelamin'],
                ['key' => 'catatan', 'label' => 'Catatan', 'values' => ['ada' => 'Ada catatan', 'belum' => 'Belum ada catatan']],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['nama_za' => 'Nama Z-A']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <div data-live-list-results>
                @include('wali_kelas.partials.student-results', ['students' => $students])
            </div>
        </div>
    </div>
</div>
@endsection
