@extends('layouts.pengajar.app')

@section('title', 'Tambah Data Mata Pelajaran')

@section('content')
@php
    $pengajarMapelData = \App\Models\MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')->get();
@endphp
<div
    data-page="pengajar-add-subject"
    data-is-guru-wali="{{ auth()->guard('guru')->user()->jabatan == 'guru_wali' ? 'true' : 'false' }}"
    data-mapel-data='@json($pengajarMapelData)'
    data-session-error="{{ e(session('error', '')) }}"
>
    <div class="p-6 bg-white mt-14">
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

        <!-- Status messages -->
        <div id="statusMessage" class="mb-4 hidden">
            <div id="successMessage" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative hidden" role="alert">
                <span class="block sm:inline" id="successText"></span>
            </div>
            <div id="errorMessage" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative hidden" role="alert">
                <span class="block sm:inline" id="errorText"></span>
            </div>
        </div>

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

        @if ($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="ml-3">
                    <h3 class="text-sm font-medium">Terdapat beberapa kesalahan:</h3>
                    <ul class="mt-2 list-disc list-inside text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        @endif

        <!-- Form -->
        <form id="addSubjectForm" action="{{ route('pengajar.subject.store') }}" method="POST" data-turbo="false" @submit="handleSubmit" x-data="formProtection" class="space-y-6" data-needs-protection>
            @csrf

            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">

            <!-- Multiple Subject Entry Form -->
            <div id="subjectEntriesContainer">
                <!-- Template for a subject entry -->
                <div class="subject-entry bg-gray-50 p-4 rounded-lg mb-6">
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

                    @php
                        $isGuruWali = auth()->guard('guru')->user()->jabatan == 'guru_wali';
                        $kelasWaliId = $isGuruWali ? auth()->guard('guru')->user()->getWaliKelasId() : null;
                    @endphp

                    <!-- Opsi Muatan Lokal -->
                    <div class="mb-4">
                        @if($isGuruWali)
                            <!-- Untuk guru wali: tidak bisa mengajar muatan lokal -->
                            <div class="guru-wali-options">
                                <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                    <p class="text-sm text-blue-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai wali kelas, Anda hanya dapat mengajar mata pelajaran wajib (non-muatan lokal).
                                    </p>
                                </div>
                            </div>
                            <!-- Hidden input dengan nilai 0 (false) -->
                            <input type="hidden" name="subjects[0][is_muatan_lokal]" value="0" class="is-muatan-lokal-input">
                        @else
                            <!-- Untuk guru biasa (bukan wali): Bisa pilih muatan lokal atau mata pelajaran wajib -->
                            <div class="mb-4">
                                <div class="info-container mb-3">
                                    <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                        <p class="text-sm text-blue-800">
                                            <span class="font-medium">Info:</span> 
                                            Sebagai guru biasa, Anda dapat mengajar mata pelajaran muatan lokal atau mata pelajaran wajib.
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="muatan-lokal-container">
                                    <div class="flex items-center">
                                        <input id="is_muatan_lokal_0" name="subjects[0][is_muatan_lokal]" type="checkbox" 
                                            class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                            onchange="syncCheckboxes(this)">
                                        <label for="is_muatan_lokal_0" class="ml-2 block text-sm text-gray-900">
                                            <span class="font-medium">Pelajaran Muatan Lokal</span>
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Pelajaran khusus yang diajar oleh guru mapel</p>
                                </div>
                                
                                <div class="non-muatan-lokal-options mt-2">
                                    <div class="flex items-center">
                                        <input id="allow_non_wali_0" name="subjects[0][allow_non_wali]" type="checkbox" 
                                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                            onchange="syncCheckboxes(this)">
                                        <label for="allow_non_wali_0" class="ml-2 block text-sm text-gray-900">
                                            <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Pelajaran wajib yang diajar oleh guru mapel</p>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Kelas Dropdown -->
                    <div class="mb-4">
                        <label for="kelas_0" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                        <select id="kelas_0" name="subjects[0][kelas]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 kelas-select"
                            onchange="updateKelasSelection(this.closest('.subject-entry'))">
                            <option value="">Pilih Kelas</option>
                            @if($classes->isEmpty())
                                <option value="" disabled>Tidak ada kelas yang ditugaskan</option>
                            @else
                                @foreach($classes as $class)
                                    @if($isGuruWali && $kelasWaliId == $class->id)
                                        <option value="{{ $class->id }}" data-is-wali-kelas="true">
                                            Kelas {{ $class->nomor_kelas }} {{ $class->nama_kelas }} (Wali Kelas)
                                        </option>
                                    @else
                                        <option value="{{ $class->id }}">
                                            Kelas {{ $class->nomor_kelas }} {{ $class->nama_kelas }}
                                        </option>
                                    @endif
                                @endforeach
                            @endif
                        </select>
                    </div>

                    <!-- Semester Dropdown -->
                    <div class="mb-4">
                        <label for="semester_0" class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                        <select id="semester_0" name="subjects[0][semester]" required
                            class="block w-full p-2.5 bg-white border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                            <option value="">Pilih Semester</option>
                            <option value="1" {{ old('semester') == 1 ? 'selected' : '' }}>Semester 1</option>
                            <option value="2" {{ old('semester') == 2 ? 'selected' : '' }}>Semester 2</option>
                        </select>
                    </div>

                    <!-- Hidden input untuk guru_id -->
                    <input type="hidden" name="subjects[0][guru_pengampu]" value="{{ auth()->guard('guru')->id() }}">

                    <!-- Hidden input untuk allow_non_wali (hanya digunakan untuk guru wali) -->
                    @if($isGuruWali)
                    <input type="hidden" name="subjects[0][allow_non_wali]" value="0" class="allow-non-wali-input">
                    @endif

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

@endsection
