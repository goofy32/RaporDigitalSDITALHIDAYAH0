@extends('layouts.app')

@section('title', 'Buat Tahun Ajaran Berikutnya')

@section('content')
<div class="p-4"
     data-page="tahun-ajaran-copy"
     data-index-url="{{ route('tahun.ajaran.index') }}"
     data-force-delete-url-template="{{ route('tahun.ajaran.force-delete', ['id' => '__ID__']) }}">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-green-700">Buat Tahun Ajaran Berikutnya</h2>
                <p class="text-gray-600">Buat tahun ajaran baru dengan struktur kelas dan pengaturan yang sama dari tahun ajaran saat ini</p>
            </div>

            <div class="flex gap-2">
                <a href="{{ route('tahun.ajaran.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                    Kembali
                </a>
                <button type="submit" form="formCopyTahunAjaran" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    Buat Tahun Ajaran
                </button>
            </div>
        </div>

        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-green-400 text-lg"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">Informasi Pembuatan Tahun Ajaran Berikutnya</h3>
                    <p class="mt-1 text-sm text-green-700">
                        Fitur ini digunakan untuk membuat tahun ajaran baru dengan struktur yang sama. Sistem akan:
                    </p>
                    <ul class="mt-2 text-sm text-green-700 list-disc list-inside">
                        <li>Mempertahankan struktur kelas yang sama (Kelas 1A -> Kelas 1A, dst.)</li>
                        <li>Menyalin pengaturan guru dan wali kelas</li>
                        <li>Menyalin pengaturan mata pelajaran, KKM, dan template rapor</li>
                        <li>Siswa dapat diatur kenaikan kelasnya secara manual nanti</li>
                    </ul>
                </div>
            </div>
        </div>

        <form action="{{ route('tahun.ajaran.process-copy', $sourceTahunAjaran->id) }}" method="POST" id="formCopyTahunAjaran">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-lg font-medium text-gray-700 mb-3">Tahun Ajaran Sumber</h3>
                    <div class="bg-green-50 p-4 rounded-md border border-green-100">
                        <p><strong>Tahun Ajaran:</strong> {{ $sourceTahunAjaran->tahun_ajaran }}</p>
                        <p><strong>Semester:</strong> {{ $sourceTahunAjaran->semester == 1 ? 'Ganjil' : 'Genap' }}</p>
                        <p><strong>Periode:</strong> {{ $sourceTahunAjaran->tanggal_mulai->format('d/m/Y') }} -
                            {{ $sourceTahunAjaran->tanggal_selesai->format('d/m/Y') }}</p>
                        <p><strong>Status:</strong> {{ $sourceTahunAjaran->is_active ? 'Aktif' : 'Tidak Aktif' }}</p>
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-medium text-gray-700 mb-3">Tahun Ajaran Berikutnya</h3>

                    <div class="mb-4">
                        <label for="tahun_ajaran" class="block text-sm font-medium text-gray-700 mb-1">Tahun Ajaran <span class="text-red-500">*</span></label>
                        <input type="text" name="tahun_ajaran" id="tahun_ajaran" value="{{ old('tahun_ajaran', $newTahunAjaran) }}"
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 p-2" required>
                    </div>

                    <div class="mb-4">
                        <label for="semester" class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
                        <div class="w-full p-2 bg-gray-100 border border-gray-300 rounded-md text-gray-700">
                            Ganjil (Semester 1)
                            <p class="mt-1 text-xs text-gray-500">Tahun ajaran baru selalu dimulai dengan semester ganjil</p>
                        </div>
                        <input type="hidden" name="semester" value="1">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div>
                    <label for="tanggal_mulai" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_mulai" id="tanggal_mulai"
                           value="{{ old('tanggal_mulai', now()->addYear()->startOfYear()->format('Y-m-d')) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 p-2" required>
                </div>

                <div>
                    <label for="tanggal_selesai" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Selesai <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_selesai" id="tanggal_selesai"
                           value="{{ old('tanggal_selesai', now()->addYear()->endOfYear()->format('Y-m-d')) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 p-2" required>
                </div>
            </div>

            <div class="mt-6">
                <label for="deskripsi" class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
                <textarea name="deskripsi" id="deskripsi" rows="2"
                          class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 p-2">{{ old('deskripsi', 'Tahun Ajaran ' . $newTahunAjaran) }}</textarea>
            </div>

            {{-- Opsi data copy disembunyikan. Semua data akan selalu disalin. --}}
            <input type="hidden" name="copy_kelas" value="1">
            <input type="hidden" name="copy_mata_pelajaran" value="1">
            <input type="hidden" name="copy_templates" value="1">
            <input type="hidden" name="copy_ekstrakurikuler" value="1">
            <input type="hidden" name="copy_kkm" value="1">
            <input type="hidden" name="copy_bobot_nilai" value="1">
        </form>
    </div>
</div>
@endsection
