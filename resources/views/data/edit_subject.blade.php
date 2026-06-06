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

        @if($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <h4 class="font-medium">Validasi gagal:</h4>
            <ul class="ml-4 mt-2 list-disc">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Form -->
        <form id="editSubjectForm"
              action="{{ route('subject.update', $subject->id) }}"
              method="POST"
              data-turbo="false"
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

            @php
                $teachingType = old('teaching_type');
                if (! $teachingType) {
                    $teachingType = $subject->is_muatan_lokal ? 'muatan_lokal' : ($subject->allow_non_wali ? 'specialist' : 'regular');
                }
            @endphp

            <div>
                <label for="teaching_type" class="block mb-2 text-sm font-medium text-gray-900">Jenis Pengajaran</label>
                <select id="teaching_type" name="teaching_type"
                    class="subject-type-select block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                    onchange="handleTeachingTypeChange(this)">
                    <option value="regular" {{ $teachingType === 'regular' ? 'selected' : '' }}>Regular Mandatory - diajar wali kelas</option>
                    <option value="muatan_lokal" {{ $teachingType === 'muatan_lokal' ? 'selected' : '' }}>Muatan Lokal - diajar guru non-wali</option>
                    <option value="specialist" {{ $teachingType === 'specialist' ? 'selected' : '' }}>Mandatory Specialist - diajar guru non-wali</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">Guru yang tidak memenuhi aturan akan ditolak oleh sistem saat disimpan.</p>
                <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox"
                    class="hidden muatan-lokal-checkbox"
                    {{ $teachingType === 'muatan_lokal' ? 'checked' : '' }}
                    aria-hidden="true" tabindex="-1">
                <input id="allow_non_wali" name="allow_non_wali" type="checkbox"
                    class="hidden allow-non-wali-checkbox"
                    {{ $teachingType === 'specialist' ? 'checked' : '' }}
                    aria-hidden="true" tabindex="-1">
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
                        {{ $class->label_kelas }}
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
                    @php
                        $activeWaliClassIds = $teacherWaliClassIds[$teacher->id] ?? [];
                        $teachingClassIds = $teacherTeachingClassIds[$teacher->id] ?? [];
                    @endphp
                    <option value="{{ $teacher->id }}"
                        data-jabatan="{{ $teacher->jabatan }}"
                        data-is-active-wali="{{ count($activeWaliClassIds) > 0 ? 'true' : 'false' }}"
                        data-wali-kelas-ids="{{ implode(',', $activeWaliClassIds) }}"
                        data-teaching-class-ids="{{ implode(',', $teachingClassIds) }}"
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
                    <button type="button" onclick="confirmDeleteLingkupMateri(this, {{ $lm->id }})" class="delete-btn ml-2 p-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
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
@endsection
