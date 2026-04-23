@extends('layouts.app')

@section('title', 'Edit Data Mata Pelajaran')

@section('content')
<div data-page="edit-subject" class="relative">
    <div data-page-loader class="fixed inset-0 z-[9999] flex items-center justify-center bg-white/90 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-3 text-green-700">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-green-200 border-t-green-600"></div>
            <p class="text-sm font-medium">Menyiapkan form mata pelajaran...</p>
        </div>
    </div>
    <div class="p-4 bg-white mt-14">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Mata Pelajaran</h2>
            <div>
                <button onclick="window.history.back()" class="px-4 py-2 mr-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="editSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
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
        <form id="editSubjectForm"
              action="{{ route('subject.update', $subject->id) }}"
              method="POST"
              x-data="formProtection"
              class="space-y-6 subject-form-loading"
              x-cloak
              data-needs-protection
              data-subject-id="{{ $subject->id }}"
              data-current-semester="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}"
              data-wali-kelas-map='{!! e($waliKelasMap) !!}'
              data-mapel-data='{!! e($mataPelajaranList->toJson()) !!}'>
            @csrf
            @method('PUT')

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Mata Pelajaran -->
            <div>
                <label for="mata_pelajaran" class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                <input type="text" id="mata_pelajaran" name="mata_pelajaran" value="{{ old('mata_pelajaran', $subject->nama_pelajaran) }}" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 @error('mata_pelajaran') border-red-500 @enderror">
                @error('mata_pelajaran')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Muatan Lokal Checkbox -->
            <div class="mb-4 muatan-lokal-options">
                <div class="flex items-center">
                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}>
                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                        <span class="font-medium">Pelajaran Muatan Lokal</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-gray-500">Pelajaran ini hanya dapat diajar oleh guru mapel (bukan wali kelas)</p>
            </div>

            <!-- Opsi Non-muatan lokal dengan guru bukan wali kelas -->
            <div class="mb-4 non-muatan-lokal-options" style="{{ $subject->is_muatan_lokal ? 'display: none;' : '' }}">
                <div class="flex items-center">
                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}>
                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                        <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-gray-500">Pelajaran wajib ini akan diajar oleh guru mapel, bukan wali kelas</p>
            </div>

            <!-- Kelas Dropdown -->
            <div>
                <label for="kelas" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                <select id="kelas" name="kelas" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select @error('kelas') border-red-500 @enderror">
                    <option value="">Pilih Kelas</option>
                    @foreach($classes as $class)
                    <option value="{{ $class->id }}" 
                        data-has-wali="{{ $class->hasWaliKelas() ? 'true' : 'false' }}" 
                        data-wali-id="{{ $class->getWaliKelasId() }}"
                        {{ old('kelas', $subject->kelas_id) == $class->id ? 'selected' : '' }}>
                        {{ $class->nomor_kelas }} - {{ $class->nama_kelas }}
                        {{ $class->hasWaliKelas() ? '(Ada Wali Kelas)' : '(Belum Ada Wali Kelas)' }}
                    </option>
                    @endforeach
                </select>
                @error('kelas')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Semester -->
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                <div class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg text-gray-700">
                    {{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}
                </div>
                <input type="hidden" id="semester" name="semester" value="{{ App\Models\TahunAjaran::find(session('tahun_ajaran_id'))->semester }}">
                @error('semester')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <!-- Guru Pengampu -->
            <div>
                <label for="guru_pengampu" class="block mb-2 text-sm font-medium text-gray-900">Guru Pengampu</label>
                <select id="guru_pengampu" name="guru_pengampu" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 guru-select @error('guru_pengampu') border-red-500 @enderror">
                    <option value="">Pilih Guru</option>
                    @foreach($teachers as $teacher)
                    <option value="{{ $teacher->id }}" 
                        data-jabatan="{{ $teacher->jabatan }}" 
                        {{ old('guru_pengampu', $subject->guru_id) == $teacher->id ? 'selected' : '' }}>
                        {{ $teacher->nama }} ({{ $teacher->jabatan == 'guru_wali' ? 'Wali Kelas' : 'Guru' }})
                    </option>
                    @endforeach
                </select>
                @error('guru_pengampu')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
                
                <!-- Tempat untuk pesan info -->
                <div class="info-container mt-2"></div>
            </div>

            <!-- Lingkup Materi -->
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                <div id="lingkupMateriContainer" x-cloak>
                @foreach($subject->lingkupMateris as $index => $lm)
                <div class="flex items-center mb-2" data-lm-id="{{ $lm->id }}">
                    <input type="text" name="lingkup_materi[]" value="{{ old('lingkup_materi.'.$index, $lm->judul_lingkup_materi) }}" required
                        class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        data-original-value="{{ $lm->judul_lingkup_materi }}">
                    @if($index == 0)
                        <button type="button" onclick="addLingkupMateri()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    @else
                    <button type="button" onclick="confirmDeleteLingkupMateri(this, {{ $lm->id }})" class="ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    @endif
                </div>
                @endforeach
                </div>
                @error('lingkup_materi')
                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
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
