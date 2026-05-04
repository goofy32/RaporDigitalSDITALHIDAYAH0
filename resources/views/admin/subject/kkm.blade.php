@extends('layouts.app')

@section('title', 'Kriteria Ketuntasan Minimal')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header dengan tombol seperti screenshot -->
        <!-- <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Kriteria Ketuntasan Minimal</h2>
            <div class="flex gap-2">
                <a href="{{ route('subject.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium">
                    Kembali
                </a>
                <button type="button" @click.prevent="saveKkm()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                    Simpan
                </button>
            </div>
        </div> -->

        <div x-data="kkmForm"
             data-kelas-data-url="{{ route('kelas.data') }}"
             data-by-kelas-url-template="{{ url('/admin/kkm/by-kelas/__KELAS__') }}"
             data-batch-save-url="{{ route('admin.kkm.batch-save') }}"
             data-global-save-url="{{ route('admin.kkm.global') }}"
             data-tahun-ajaran-id="{{ session('tahun_ajaran_id') ?? \App\Models\TahunAjaran::where('is_active', true)->value('id') }}">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-green-700">Kriteria Ketuntasan Minimal</h2>
                <div class="flex gap-2">
                    <a href="{{ route('subject.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium">
                        Kembali
                    </a>
                    <button type="button"
                            @click.prevent="saveBatchKkm()"
                            :disabled="!selectedKelasId || kkmItems.length === 0 || loadingRows || savingBatch"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <span x-text="savingBatch ? 'Menyimpan...' : 'Simpan'"></span>
                    </button>
                </div>
            </div>

            <!-- Form Input KKM - Berbasis Kelas -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Pilih Kelas -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Pilih kelas</label>
                    <select x-model="selectedKelasId" 
                            @change="loadKkmByKelas()"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        <option value="">Pilih kelas</option>
                        <template x-for="kelas in kelasData" :key="kelas.id">
                            <option :value="kelas.id" x-text="'Kelas ' + kelas.nomor_kelas + ' - ' + kelas.nama_kelas"></option>
                        </template>
                    </select>
                </div>
            </div>

            <!-- Daftar KKM berdasarkan kelas -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Daftar KKM per Kelas</h3>
                </div>
                
                <div class="overflow-x-auto border border-gray-200 rounded-lg">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 border-r">Mata Pelajaran</th>
                                <th scope="col" class="px-6 py-3 border-r w-48">Nilai KKM</th>
                                <th scope="col" class="px-6 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-if="!selectedKelasId">
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-gray-500">
                                        Pilih kelas untuk memuat data KKM.
                                    </td>
                                </tr>
                            </template>

                            <template x-if="selectedKelasId && loadingRows">
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-gray-500">
                                        Memuat data KKM...
                                    </td>
                                </tr>
                            </template>

                            <template x-for="item in kkmItems" :key="item.mata_pelajaran_id">
                                <tr class="border-b"
                                    :class="item.pendingDelete ? 'bg-gray-100 text-gray-400 line-through' : 'bg-white hover:bg-gray-50'">
                                    <td class="px-6 py-4 border-r" x-text="item.nama_pelajaran"></td>
                                    <td class="px-6 py-4 border-r">
                                        <input type="number"
                                               min="0"
                                               max="100"
                                               x-model.number="item.nilai"
                                               :disabled="item.pendingDelete"
                                               class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5 disabled:bg-gray-100 disabled:text-gray-400">
                                    </td>
                                    <td class="px-6 py-4">
                                        <button type="button"
                                                @click="toggleDelete(item)"
                                                :class="item.pendingDelete ? 'text-blue-600 hover:underline' : 'text-red-600 hover:underline'">
                                            <span x-text="item.pendingDelete ? 'Batal' : 'Hapus'"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            
                            <tr x-show="selectedKelasId && !loadingRows && kkmItems.length === 0">
                                <td colspan="3" class="px-6 py-4 text-center text-gray-500">
                                    Belum ada mata pelajaran untuk kelas ini.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pengaturan KKM Massal -->
            <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                <h4 class="text-lg font-medium text-green-800 mb-2">Pengaturan KKM Massal</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-900">
                            Nilai KKM untuk Semua Mata Pelajaran
                        </label>
                        <input type="number" x-model="globalKkmData.nilai" 
                               min="0" max="100" 
                               class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5"
                               placeholder="Contoh: 70">
                    </div>
                    
                    <div class="flex items-end">
                        <div class="flex items-center h-10">
                            <input type="checkbox" id="overwrite" x-model="globalKkmData.overwriteExisting" 
                                   class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500">
                            <label for="overwrite" class="ml-2 text-sm text-gray-700">
                                Timpa nilai KKM yang sudah ada
                            </label>
                        </div>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="button" @click.prevent="applyGlobalKkm()" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                            Terapkan KKM Massal
                        </button>
                    </div>
                </div>
                
                <p class="text-xs text-gray-500">
                    Nilai ini akan diterapkan ke semua mata pelajaran. Jika opsi "Timpa nilai KKM yang sudah ada" dicentang, maka nilai KKM yang sudah diatur sebelumnya akan diperbarui.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection
