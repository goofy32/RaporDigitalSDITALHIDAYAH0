@extends('layouts.app')

@section('title', 'Buat Tahun Ajaran Berikutnya')

@section('content')
<div class="p-4" data-page="tahun-ajaran-copy">
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

            <div class="mt-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Data yang Akan Disalin</h3>

                <div class="bg-green-50 border border-green-100 rounded-md p-4">
                    <div class="mb-4 p-3 bg-green-100 border border-green-300 rounded-md">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-lock text-green-600 mr-2"></i>
                            <span class="text-green-800 font-medium text-sm">Pengaturan Wajib (Tidak Dapat Diubah)</span>
                        </div>

                        <div class="mb-1">
                            <div class="flex items-center">
                                <input type="checkbox" name="copy_kelas_display" id="copy_kelas_display"
                                       value="1" checked disabled
                                       class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500 opacity-75 cursor-not-allowed">
                                <label for="copy_kelas_display" class="ml-2 text-gray-700">
                                    Struktur Kelas dan Guru <span class="text-green-600 font-medium text-xs">(WAJIB)</span>
                                </label>
                            </div>
                            <div class="ml-7 text-gray-500 text-sm">Kelas akan disalin dengan struktur yang sama persis (1A -> 1A, 2B -> 2B, dst.)</div>
                            <input type="hidden" name="copy_kelas" value="1">
                        </div>
                    </div>

                    <h4 class="font-medium text-green-800 mb-3 mt-6">Data Opsional</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 mb-5">
                        <div class="flex items-center">
                            <input type="checkbox" name="copy_mata_pelajaran" id="copy_mata_pelajaran" value="1" checked
                                   class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <label for="copy_mata_pelajaran" class="ml-2 text-gray-700">Mata Pelajaran <span class="text-gray-500">(dan tujuan pembelajaran)</span></label>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" name="copy_templates" id="copy_templates" value="1" checked
                                   class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <label for="copy_templates" class="ml-2 text-gray-700">Template Rapor</label>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" name="copy_ekstrakurikuler" id="copy_ekstrakurikuler" value="1" checked
                                   class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <label for="copy_ekstrakurikuler" class="ml-2 text-gray-700">Ekstrakurikuler</label>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" name="copy_kkm" id="copy_kkm" value="1" checked
                                   class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <label for="copy_kkm" class="ml-2 text-gray-700">Standar KKM</label>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" name="copy_bobot_nilai" id="copy_bobot_nilai" value="1" checked
                                   class="w-5 h-5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <label for="copy_bobot_nilai" class="ml-2 text-gray-700">Bobot Penilaian</label>
                        </div>
                    </div>

                    <div class="bg-white p-3 rounded border border-green-200">
                        <p class="text-gray-700 text-sm"><strong>Catatan Penting:</strong></p>
                        <div class="mt-1 space-y-1">
                            <div class="flex items-start">
                                <i class="fas fa-copy text-green-600 mr-2 mt-0.5 text-xs"></i>
                                <span class="text-gray-700 text-sm">
                                    Struktur kelas akan disalin dengan nama dan guru yang sama persis
                                </span>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-user-friends text-gray-600 mr-2 mt-0.5 text-xs"></i>
                                <span class="text-gray-700 text-sm">
                                    Siswa tidak akan otomatis dipindahkan - dapat diatur manual nanti
                                </span>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-refresh text-gray-600 mr-2 mt-0.5 text-xs"></i>
                                <span class="text-gray-700 text-sm">
                                    Data nilai dan absensi akan dimulai dari kosong untuk tahun ajaran baru
                                </span>
                            </div>
                            <div class="flex items-start">
                                <i class="fas fa-cog text-gray-600 mr-2 mt-0.5 text-xs"></i>
                                <span class="text-gray-700 text-sm">
                                    Kenaikan kelas siswa dapat diatur menggunakan fitur Kenaikan Kelas secara terpisah
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
.fa-lock, .fa-copy, .fa-user-friends, .fa-refresh, .fa-cog {
    width: 12px;
    text-align: center;
}

input[type="checkbox"]:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

input[type="checkbox"]:disabled + label {
    opacity: 0.8;
    cursor: not-allowed;
}

.bg-green-100 {
    background-color: rgba(34, 197, 94, 0.1);
}

.bg-green-50 {
    background-color: rgba(59, 130, 246, 0.05);
}
</style>
@endpush
@endsection
