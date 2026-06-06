@extends('layouts.pengajar.app')

@section('title', 'Edit Data Mata Pelajaran')

@push('meta')
<meta name="turbo-visit-control" content="reload">
@endpush

@section('content')
@php
    $pengajarMapelData = \App\Models\MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')->get();
@endphp
<div
    data-page="pengajar-edit-subject"
    data-is-guru-wali="{{ auth()->guard('guru')->user()->jabatan == 'guru_wali' ? 'true' : 'false' }}"
    data-subject-id="{{ $subject->id }}"
    data-mapel-data='@json($pengajarMapelData)'
    data-session-error="{{ e(session('error', '')) }}"
>
    <div class="p-4 bg-white mt-14">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Mata Pelajaran</h2>
            <div class="flex space-x-2">
                <button onclick="window.history.back()" class="px-4 py-2 mr-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button type="submit" form="editSubjectForm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
                </button>
            </div>
        </div>

        <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
            <p class="text-sm text-blue-700">
                <strong>Info:</strong> Anda sedang mengedit mata pelajaran dari tahun ajaran 
                <strong>{{ $subject->tahunAjaran->tahun_ajaran }}</strong> 
                ({{ $subject->tahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }}).
            </p>
            @if($subject->tahun_ajaran_id != session('tahun_ajaran_id'))
            <p class="text-sm text-red-700 mt-1">
                <strong>Perhatian:</strong> Tahun ajaran ini berbeda dengan tahun ajaran aktif saat ini.
            </p>
            @endif
        </div>

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
            action="{{ route('pengajar.subject.update', $subject->id) }}" 
            method="POST" 
            data-turbo="false"
            x-data="formProtection"
            @submit="handleSubmit"
            data-needs-protection
            class="space-y-6"
            data-subject-id="{{ $subject->id }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="tahun_ajaran_id" value="{{ $subject->tahun_ajaran_id }}">

            <!-- Layout dengan satu kolom (tanpa grid) -->
            <div class="space-y-6">
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
                    $isGuruWali = auth()->guard('guru')->user()->jabatan == 'guru_wali';
                    $kelasWaliId = $isGuruWali ? auth()->guard('guru')->user()->getWaliKelasId() : null;
                @endphp

                <!-- Opsi Muatan Lokal -->
                <div>
                    @if($isGuruWali)
                        <!-- Untuk guru wali: logika options berbeda tergantung apakah kelas yang dipilih adalah kelas wali -->
                        <div class="wali-kelas-options">
                            <!-- Kondisional display berdasarkan kelas -->
                            @if(auth()->guard('guru')->user()->getWaliKelasId() == $subject->kelas_id)
                            <!-- Jika kelas yang dipilih adalah kelas wali -->
                            <div class="wali-info">
                                <div class="p-2 bg-green-50 border border-green-200 rounded-md">
                                    <p class="text-sm text-green-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai wali kelas, Anda mengajar mata pelajaran wajib (non-muatan lokal) di kelas yang Anda walikan.
                                    </p>
                                </div>
                                <!-- Hidden inputs -->
                                <input type="hidden" name="is_muatan_lokal" value="0">
                                <input type="hidden" name="allow_non_wali" value="0">
                            </div>
                            @else
                            <!-- Jika kelas yang dipilih bukan kelas wali -->
                            <div class="muatan-lokal-container">
                                <div class="flex items-center">
                                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                                        <span class="font-medium">Pelajaran Muatan Lokal</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Pelajaran khusus yang diajar oleh guru mapel</p>
                            </div>
                            
                            <!-- Opsi allow_non_wali untuk mata pelajaran wajib di kelas non-wali -->
                            <div class="non-muatan-lokal-options mt-2">
                                <div class="flex items-center">
                                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                                        <span class="font-medium">Pelajaran Wajib - Guru Mapel</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Pelajaran wajib yang diajar oleh guru mapel</p>
                            </div>
                            @endif
                        </div>
                    @else
                        <!-- Untuk guru biasa: Bisa pilih muatan lokal atau mata pelajaran wajib -->
                        <div>
                            <div class="info-container mb-3">
                                <div class="p-2 bg-blue-50 border border-blue-200 rounded-md">
                                    <p class="text-sm text-blue-800">
                                        <span class="font-medium">Info:</span> 
                                        Sebagai guru biasa, Anda dapat mengajar mata pelajaran muatan lokal atau mata pelajaran wajib.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Checkbox Muatan Lokal -->
                            <div class="muatan-lokal-container">
                                <div class="flex items-center">
                                    <input id="is_muatan_lokal" name="is_muatan_lokal" type="checkbox" 
                                        class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded muatan-lokal-checkbox"
                                        {{ old('is_muatan_lokal', $subject->is_muatan_lokal) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="is_muatan_lokal" class="ml-2 block text-sm text-gray-900">
                                        Mata Pelajaran Muatan Lokal
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Checkbox Mata Pelajaran Wajib -->
                            <div class="non-muatan-lokal-options mt-2">
                                <div class="flex items-center">
                                    <input id="allow_non_wali" name="allow_non_wali" type="checkbox" 
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded allow-non-wali-checkbox"
                                        {{ old('allow_non_wali', $subject->allow_non_wali) ? 'checked' : '' }}
                                        onchange="syncCheckboxes(this)">
                                    <label for="allow_non_wali" class="ml-2 block text-sm text-gray-900">
                                        Mata Pelajaran Wajib yang diajar guru biasa
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Kelas Dropdown -->
                <div>
                    <label for="kelas" class="block mb-2 text-sm font-medium text-gray-900">Kelas</label>
                    
                    @if(isset($disableKelasDropdown) && $disableKelasDropdown)
                        <!-- Jika wali kelas dan mengajar di kelas wali, tampilkan sebagai readonly -->
                        <div class="relative">
                            <input type="text" 
                                value="{{ $subject->kelas->label_kelas }} (Kelas Wali)"
                                class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed"
                                readonly>
                            <input type="hidden" name="kelas" value="{{ $subject->kelas_id }}">
                            <p class="mt-1 text-xs text-gray-500">Kelas tidak dapat diubah untuk mata pelajaran wali kelas</p>
                        </div>
                    @else
                        <!-- Dropdown kelas yang bisa diedit -->
                        <div class="relative">
                            <select id="kelas" name="kelas" required
                                class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 @error('kelas') border-red-500 @enderror">
                                <option value="">Pilih Kelas</option>
                                @foreach($classes as $class)
                                    <option value="{{ $class->id }}" 
                                        {{ old('kelas', $subject->kelas_id) == $class->id ? 'selected' : '' }}
                                        data-is-wali-kelas="{{ auth()->guard('guru')->user()->getWaliKelasId() == $class->id ? 'true' : 'false' }}">
                                        {{ $class->label_kelas }}
                                        {{ auth()->guard('guru')->user()->getWaliKelasId() == $class->id ? '(Wali Kelas)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    
                    @error('kelas')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Semester Dropdown -->
                <div>
                    <label for="semester" class="block mb-2 text-sm font-medium text-gray-900">Semester</label>
                    <div class="flex">
                        <input type="text" id="semester_display" 
                            value="{{ $subject->semester == 1 ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)' }}" 
                            class="block w-full p-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed" 
                            readonly>
                        <input type="hidden" name="semester" value="{{ $subject->semester }}">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Semester tidak dapat diubah untuk mata pelajaran yang sudah ada</p>
                </div>

                <!-- Hidden input untuk guru_id -->
                <input type="hidden" name="guru_pengampu" value="{{ auth()->guard('guru')->id() }}">

                <!-- Lingkup Materi -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                    <div id="lingkupMateriContainer">
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
            </div>
        </form>
    </div>
</div>

@endsection
