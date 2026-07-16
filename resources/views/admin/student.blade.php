@extends('layouts.app')

@section('title', 'Data Siswa')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header Data Siswa -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Data Siswa</h2>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('student.create') }}" 
                    class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                    <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
                    </svg>
                    Tambah Data
                </a>
                <button id="uploadButton" data-turbo-permanent  class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                    Upload Excel
                </button>
                <a href="{{ route('student.template') }}" class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                    Download Template
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if (session('import_errors'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <p class="mb-2 font-semibold">Kesalahan import:</p>
                <ul class="list-inside list-disc space-y-1">
                    @foreach (session('import_errors') as $importError)
                        <li>{{ $importError }}</li>
                    @endforeach
                </ul>
            </div>
        @endif


        <div data-live-list>
            <form action="{{ route('student') }}" method="GET" class="mb-4" data-live-list-form>
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
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenis kelamin</label>
                                    <select name="jenis_kelamin" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="Laki-laki" @selected(request('jenis_kelamin') === 'Laki-laki')>Laki-laki</option>
                                        <option value="Perempuan" @selected(request('jenis_kelamin') === 'Perempuan')>Perempuan</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Foto siswa</label>
                                    <select name="foto" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Semua</option>
                                        <option value="ada" @selected(request('foto') === 'ada')>Ada foto</option>
                                        <option value="belum" @selected(request('foto') === 'belum')>Belum ada foto</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                    <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                        <option value="">Nama A-Z</option>
                                        <option value="nama_za" @selected(request('sort') === 'nama_za')>Nama Z-A</option>
                                        <option value="nis" @selected(request('sort') === 'nis')>NIS</option>
                                        <option value="nisn" @selected(request('sort') === 'nisn')>NISN</option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-2">
                                    <a href="{{ route('student') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
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
                ['key' => 'jenis_kelamin', 'label' => 'Jenis kelamin'],
                ['key' => 'foto', 'label' => 'Foto', 'values' => ['ada' => 'Ada foto', 'belum' => 'Belum ada foto']],
                ['key' => 'sort', 'label' => 'Urutan', 'values' => ['nama_za' => 'Nama Z-A', 'nis' => 'NIS', 'nisn' => 'NISN']],
            ]])

            <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

            <x-admin.bulk-delete-toolbar
                :action="route('admin.bulk-delete', 'students')"
                record-type="Siswa"
                form-id="bulk-delete-students-form"
            />

            <div data-live-list-results>
                @include('admin.partials.student-results', ['students' => $students])
            </div>
        </div>

        <div id="uploadModal" 
            class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50"
            aria-labelledby="modal-title" 
            role="dialog" 
            aria-modal="true">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex justify-between items-center pb-3">
                        <h3 class="text-lg font-medium text-gray-900" id="modal-title">
                            Upload Data Siswa
                        </h3>
                        <button id="closeModal" class="text-gray-400 hover:text-gray-500">
                            <span class="sr-only">Close</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <form action="{{ route('student.import') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div class="mt-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Pilih File Excel
                            </label>
                            <input type="file" 
                                name="file" 
                                accept=".xlsx,.xls" 
                                class="block w-full text-sm text-gray-500
                                        file:mr-4 file:py-2 file:px-4
                                        file:rounded-md file:border-0
                                        file:text-sm file:font-medium
                                        file:bg-green-50 file:text-green-700
                                        hover:file:bg-green-100
                                        border border-gray-300 rounded-lg cursor-pointer
                                        focus:outline-none">
                            <p class="mt-1 text-sm text-gray-500">File Excel (.xlsx, .xls)</p>
                        </div>

                        @error('file')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        <div class="flex justify-end space-x-3 mt-4">
                            <button type="button" 
                                    id="closeModalBtn"
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-300">
                                Batal
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Upload
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.js"></script>
<script>
document.addEventListener('turbo:load', function () {
    const modal = document.getElementById('uploadModal');
    const uploadButton = document.getElementById('uploadButton');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const closeModalX = document.getElementById('closeModal');

    function openModal() {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Prevent scrolling
    }

    function closeModal() {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto'; // Enable scrolling
    }

    uploadButton?.addEventListener('click', openModal);
    closeModalBtn?.addEventListener('click', closeModal);
    closeModalX?.addEventListener('click', closeModal);

    // Close when clicking outside
    modal?.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
    });
 
</script>

@endsection
