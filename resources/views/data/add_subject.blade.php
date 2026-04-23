@extends('layouts.app')

@section('title', 'Tambah Data Mata Pelajaran')

@section('content')
<div data-page="add-subject" class="relative">
    <div data-page-loader class="fixed inset-0 z-[9999] flex items-center justify-center bg-white/90 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-3 text-green-700">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-green-200 border-t-green-600"></div>
            <p class="text-sm font-medium">Menyiapkan form mata pelajaran...</p>
        </div>
    </div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-2xl font-bold text-green-700 break-words max-w-full sm:max-w-lg">Form Tambah Data Mata Pelajaran</h2>
            <div class="flex flex-wrap gap-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="addSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan Semua
                </button>
            </div>
        </div>

        <!-- Flash Message untuk Error/Success -->
        @if(session('error'))
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <p>{{ session('error') }}</p>
        </div>
        @endif

        @if(session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4">
            <p>{{ session('success') }}</p>
        </div>
        @endif

        @if(session('errors') && count(session('errors')) > 0)
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <h4 class="font-medium">Terjadi beberapa kesalahan:</h4>
            <ul class="ml-4 mt-2 list-disc">
                @foreach(session('errors') as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Form -->
        <form id="addSubjectForm"
              action="{{ route('subject.store') }}"
              method="POST"
              @submit="handleSubmit"
              x-data="formProtection"
              class="space-y-6 subject-form-loading"
              x-cloak
              data-needs-protection
              data-current-semester="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}"
              data-wali-kelas-map='{!! e($waliKelasMap) !!}'
              data-mapel-data='{!! e($mataPelajaranList->toJson()) !!}'>
            @csrf

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Multiple Subject Entry Form -->
            <div id="subjectEntriesContainer" x-cloak>
                <!-- Template for a subject entry -->
                <div class="subject-entry bg-gray-50 p-4 rounded-lg mb-6" data-subject-mode="default">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-md font-medium text-gray-800">Mata Pelajaran 1</h4>
                        <button type="button" onclick="removeSubjectEntry(this)" class="text-red-600 hover:text-red-800 hidden remove-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    <!-- Mata Pelajaran -->
                    <div class="mb-4">
                        <label for="mata_pelajaran_0" class="block mb-2 text-sm font-medium text-gray-900">Nama Mata Pelajaran</label>
                        <input type="text" id="mata_pelajaran_0" name="subjects[0][mata_pelajaran]" value="{{ old('mata_pelajaran') }}" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>

                    <!-- Muatan Lokal Checkbox -->
                    <div class="mb-4 muatan-lokal-options">
                        <div class="flex items-center">
                            <input id="is_muatan_lokal_0" name="subjects[0][is_muatan_lokal]" type="checkbox" 
                                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                onchange="handleCheckboxChange(this)">
                            <label for="is_muatan_lokal_0" class="ml-2 block text-sm text-gray-900">
                                <span class="font-medium">Pelajaran Muatan Lokal</span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Pelajaran ini hanya dapat diajar oleh guru mapel (bukan wali kelas)</p>
                    </div>

                    <!-- Opsi Non-muatan lokal dengan guru bukan wali kelas -->
                    <div class="mb-4 non-muatan-lokal-options">
                        <div class="flex items-center">
                            <input id="allow_non_wali_0" name="subjects[0][allow_non_wali]" type="checkbox" 
                                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded allow-non-wali-checkbox"
                                onchange="handleCheckboxChange(this)">
                            <label for="allow_non_wali_0" class="ml-2 block text-sm text-gray-900">
                                <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Pelajaran wajib ini akan diajar oleh guru mapel, bukan wali kelas</p>
                    </div>

                    <!-- Kelas Dropdown (Single Select) -->
                    <div class="mb-4">
                        <label for="kelas_0" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                        <select id="kelas_0" name="subjects[0][kelas]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select"
                            onchange="updateGuruOptions(this.closest('.subject-entry'))">
                            <option value="">Pilih Kelas</option>
                            @foreach($classes as $class)
                            <option value="{{ $class->id }}" data-has-wali="{{ $class->hasWaliKelas() ? 'true' : 'false' }}" data-wali-id="{{ $class->getWaliKelasId() }}">
                                {{ $class->nomor_kelas }} - {{ $class->nama_kelas }}
                                {{ $class->hasWaliKelas() ? '(Ada Wali Kelas)' : '(Belum Ada Wali Kelas)' }}
                            </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Semester -->
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                        <div class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
                            {{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}
                        </div>
                        <input type="hidden" id="semester_0" name="subjects[0][semester]" value="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}">
                    </div>

                    <!-- Guru Pengampu -->
                    <div class="mb-4">
                        <label for="guru_pengampu_0" class="block mb-2 text-sm font-medium text-gray-900">Guru Pengampu</label>
                        <select id="guru_pengampu_0" name="subjects[0][guru_pengampu]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 guru-select">
                            <option value="">Pilih Guru</option>
                            @foreach($teachers as $teacher)
                            <option value="{{ $teacher->id }}" data-jabatan="{{ $teacher->jabatan }}" {{ old('guru_pengampu') == $teacher->id ? 'selected' : '' }}>
                                {{ $teacher->nama }} ({{ $teacher->jabatan == 'guru_wali' ? 'Wali Kelas' : 'Guru' }})
                            </option>
                            @endforeach
                        </select>
                        <!-- Tempat untuk pesan info -->
                        <div class="info-container mt-2"></div>
                    </div>

                    <!-- Lingkup Materi -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                        <div class="lingkup-materi-container">
                            <div class="flex items-center mb-2">
                                <input type="text" name="subjects[0][lingkup_materi][]" required
                                    class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                                <button type="button" onclick="addLingkupMateri(this)" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end mt-6 mb-2">
                <button type="button" onclick="addSubjectEntry()" class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    Tambah Mata Pelajaran
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
@if(session('error'))
<script>
    document.addEventListener('turbo:load', function() {
        alert(@json(session('error')));
    });
</script>
@endif
@endpush
@endsection
