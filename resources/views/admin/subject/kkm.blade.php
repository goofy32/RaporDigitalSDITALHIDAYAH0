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

        <div x-data="kkmForm" data-subject-index-url="{{ route('subject.index') }}" data-subject-url-base="{{ url('/admin/subject') }}">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-green-700">Kriteria Ketuntasan Minimal</h2>
                <div class="flex gap-2">
                    <a href="{{ route('subject.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium">
                        Kembali
                    </a>
                    <!-- <button @click="saveKkm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                        Simpan
                    </button> -->
                </div>
            </div>
            <!-- Pengaturan Notifikasi KKM -->
            <div class="p-4 bg-green-50 border border-green-200 rounded-lg mb-6">
                <h4 class="text-lg font-medium text-green-800 mb-2">Pengaturan Notifikasi KKM</h4>
                <div class="mb-4">
                    <div class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="notification_complete_scores_only" 
                            x-model="kkmNotificationSettings.completeScoresOnly" 
                            class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500"
                        >
                        <label for="notification_complete_scores_only" class="ml-2 text-sm font-medium text-gray-900">
                            Hanya tampilkan notifikasi KKM rendah untuk nilai yang sudah lengkap
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-gray-600">
                        Jika diaktifkan, notifikasi nilai dibawah KKM hanya akan muncul ketika semua komponen nilai (TP, LM, Tes, Non-Tes) sudah diisi lengkap.
                    </p>
                </div>
                <button 
                    type="button" @click.prevent="saveKkmNotificationSettings()"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium"
                >
                    Simpan Pengaturan Notifikasi
                </button>
            </div>

            <!-- Form Input KKM - Layout seperti screenshot -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Pilih Kelas -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Pilih kelas</label>
                    <select x-model="selectedKelasId" 
                            @change="loadMataPelajaranByKelas()"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        <option value="">Pilih kelas</option>
                        <template x-for="kelas in kelasData" :key="kelas.id">
                            <option :value="kelas.id" x-text="'Kelas ' + kelas.nomor_kelas + ' - ' + kelas.nama_kelas"></option>
                        </template>
                    </select>
                </div>

                <!-- Pilih Mata Pelajaran -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Pilih Mata Pelajaran</label>
                    <select x-model="kkmData.mata_pelajaran_id" 
                            @change="handleMapelChange()"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        <option value="">Pilih mata pelajaran</option>
                        <template x-for="mapel in filteredMataPelajaran" :key="mapel.id">
                            <option :value="mapel.id" x-text="mapel.nama_pelajaran"></option>
                        </template>
                    </select>
                </div>

                <!-- Nilai KKM -->
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-900">Nilai KKM</label>
                    <input type="number" x-model="kkmData.nilai" min="0" max="100" 
                           class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                </div>
            </div>

            <!-- Tombol Tambah ke Tabel -->
            <div class="mb-6">
                <button type="button" @click.prevent="addToTable()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                    Tambah ke Tabel
                </button>
            </div>

            <!-- Daftar KKM dengan filter kelas seperti screenshot -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Daftar KKM</h3>
                    <div class="flex items-center gap-4">
                        <select x-model="filterKelasId" 
                                @change="filterKkmList()"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 p-2.5">
                            <option value="">Pilih kelas</option>
                            <template x-for="kelas in kelasData" :key="kelas.id">
                                <option :value="kelas.id" x-text="'Kelas ' + kelas.nomor_kelas + ' - ' + kelas.nama_kelas"></option>
                            </template>
                        </select>
                    </div>
                </div>
                
                <div class="overflow-x-auto border border-gray-200 rounded-lg">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 border-r">Kelas</th>
                                <th scope="col" class="px-6 py-3 border-r">Mata Pelajaran</th>
                                <th scope="col" class="px-6 py-3 border-r">Nilai KKM</th>
                                <th scope="col" class="px-6 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(kkm, index) in filteredKkmList" :key="kkm.id">
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4 border-r" x-text="kkm.mata_pelajaran && kkm.mata_pelajaran.kelas ? 'Kelas ' + kkm.mata_pelajaran.kelas.nomor_kelas + ' - ' + kkm.mata_pelajaran.kelas.nama_kelas : '-'"></td>
                                    <td class="px-6 py-4 border-r" x-text="kkm.mata_pelajaran ? kkm.mata_pelajaran.nama_pelajaran : '-'"></td>
                                    <td class="px-6 py-4 border-r text-center" x-text="kkm.nilai"></td>
                                    <td class="px-6 py-4">
                                        <button @click="deleteKkm(kkm.id)" class="text-red-600 hover:underline">
                                            Hapus
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            
                            <tr x-show="filteredKkmList.length === 0">
                                <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                    <span x-text="filterKelasId ? 'Belum ada data KKM untuk kelas ini' : 'Belum ada data KKM'"></span>
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
