@extends('layouts.app')

@section('title', 'Edit Tahun Ajaran')

@section('content')
<div class="p-4" data-page="tahun-ajaran-edit">
    <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <h2 class="text-2xl font-bold text-green-700">Edit Tahun Ajaran</h2>
        <div class="flex flex-wrap gap-3 md:justify-end">
            <a href="{{ route('tahun.ajaran.index') }}" 
            class="px-4 py-2 bg-gray-600 text-white rounded-md focus:outline-none focus:ring-2 focus:ring-gray-300">
                Batal
            </a>
            <button form="formEditTahunAjaran" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" 
                    @click="showSemesterWarning ? confirmSemesterChange($event) : null"
                    x-data="{
                        confirmSemesterChange(e) {
                            if (!confirm('Anda yakin ingin mengubah semester? Perubahan ini akan mempengaruhi semua data terkait.')) {
                                e.preventDefault();
                            }
                        }
                    }">
                Simpan Perubahan
            </button>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('tahun.ajaran.update', $tahunAjaran->id) }}" method="POST" id="formEditTahunAjaran"
              x-data="{ isActive: {{ $tahunAjaran->is_active ? 'true' : 'false' }}, 
                         oldSemester: {{ $tahunAjaran->semester }}, 
                         semester: {{ $tahunAjaran->semester }},
                         showSemesterWarning: false }"
              x-init="$watch('semester', value => {
                  showSemesterWarning = {{ $tahunAjaran->is_active ? 'true' : 'false' }} && 
                                        oldSemester != value;
              })">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Tahun Ajaran -->
                <div>
                    <label for="tahun_ajaran" class="block text-sm font-medium text-gray-700 mb-1">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <input type="text" name="tahun_ajaran" id="tahun_ajaran" value="{{ old('tahun_ajaran', $tahunAjaran->tahun_ajaran) }}" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50"
                           placeholder="2024/2025" required>
                    @error('tahun_ajaran')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Semester -->
                <div class="mb-4">
                    <label for="semester" class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
                    <div class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md text-gray-700">
                        Ganjil (Semester 1)
                        <p class="mt-1 text-xs text-gray-500">Tahun ajaran baru selalu dimulai dengan semester ganjil</p>
                    </div>
                    <!-- Hidden input untuk memastikan nilai semester tetap dikirim -->
                    <input type="hidden" name="semester" value="1">
                </div>

                <!-- Tanggal Mulai -->
                <div>
                    <label for="tanggal_mulai" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_mulai" id="tanggal_mulai" value="{{ old('tanggal_mulai', $tahunAjaran->tanggal_mulai->format('Y-m-d')) }}" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50"
                           required>
                    @error('tanggal_mulai')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Tanggal Selesai -->
                <div>
                    <label for="tanggal_selesai" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Selesai <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_selesai" id="tanggal_selesai" value="{{ old('tanggal_selesai', $tahunAjaran->tanggal_selesai->format('Y-m-d')) }}" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50"
                           required>
                    @error('tanggal_selesai')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Deskripsi -->
                <div class="md:col-span-2">
                    <label for="deskripsi" class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
                    <textarea name="deskripsi" id="deskripsi" rows="3" 
                              class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50">{{ old('deskripsi', $tahunAjaran->deskripsi) }}</textarea>
                </div>

                <!-- Aktif -->
                <div class="md:col-span-2">
                    <div class="flex items-center">
                        <input type="checkbox" name="is_active" id="is_active" value="1" x-model="isActive"
                               class="rounded border-gray-300 text-green-600 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50"
                               {{ old('is_active', $tahunAjaran->is_active) ? 'checked' : '' }}>
                        <label for="is_active" class="ml-2 block text-sm font-medium text-gray-700">Aktif</label>
                    </div>
                    <p class="text-gray-500 text-sm mt-1">Mengaktifkan tahun ajaran ini akan menonaktifkan tahun ajaran lain</p>
                </div>
            </div>

            <!-- Peringatan perubahan semester -->
            <div x-show="showSemesterWarning" x-cloak
                 class="mt-4 bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>Perhatian!</strong> Mengubah semester untuk tahun ajaran aktif akan memperbarui semua data terkait, termasuk mata pelajaran, nilai, dan absensi.
                        </p>
                    </div>
                </div>
            </div>

            <!-- 
                        <div class="mt-6 flex items-center justify-end gap-4">
                <a href="{{ route('tahun.ajaran.index') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    Kembali
                </a>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" 
                        @click="showSemesterWarning ? confirmSemesterChange($event) : null"
                        x-data="{
                            confirmSemesterChange(e) {
                                if (!confirm('Anda yakin ingin mengubah semester? Perubahan ini akan mempengaruhi semua data terkait.')) {
                                    e.preventDefault();
                                }
                            }
                        }">
                    Simpan Perubahan
                </button>
            </div>
             -->
        </form>
    </div>
</div>

@endsection
