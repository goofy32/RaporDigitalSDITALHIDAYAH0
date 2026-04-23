<div x-data="adminSettings" x-cloak class="fixed z-50 inset-0 overflow-y-auto" 
     x-show="isOpen" 
     @open-settings.window="open()"
     aria-labelledby="modal-title"
     role="dialog"
     aria-modal="true">
    
    <!-- Backdrop -->
    <div x-show="isOpen" class="fixed inset-0 bg-gray-900 bg-opacity-50 transition-opacity" @click="close"></div>
    
    <!-- Modal -->
    <div class="flex items-center justify-center min-h-screen p-4">
        <div x-show="isOpen"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform scale-95"
             x-transition:enter-end="opacity-100 transform scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 transform scale-100"
             x-transition:leave-end="opacity-0 transform scale-95"
             class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-auto z-10 overflow-hidden">
            
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    Pengaturan Rapor
                </h3>
                <button @click="close" type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-600 dark:hover:text-white">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Content -->
            <div class="p-4 space-y-4 overflow-y-auto max-h-[calc(100vh-200px)]">
                <!-- Tab Controls -->
                <div class="border-b mb-4">
                    <ul class="flex flex-wrap -mb-px text-sm font-medium text-center">
                        <li class="mr-2" @click="activeTab = 'kkm'">
                            <button :class="{'text-green-600 border-green-600 border-b-2 rounded-t-lg active': activeTab === 'kkm', 'text-gray-500 hover:text-gray-600 border-b-2 border-transparent hover:border-gray-300': activeTab !== 'kkm'}" 
                                    class="inline-block p-4">
                                KKM (Kriteria Ketuntasan Minimal)
                            </button>
                        </li>
                        <li class="mr-2" @click="activeTab = 'bobot'">
                            <button :class="{'text-green-600 border-green-600 border-b-2 rounded-t-lg active': activeTab === 'bobot', 'text-gray-500 hover:text-gray-600 border-b-2 border-transparent hover:border-gray-300': activeTab !== 'bobot'}" 
                                    class="inline-block p-4">
                                Bobot Nilai
                            </button>
                        </li>
                    </ul>
                </div>
                
                <!-- Tab KKM -->
                <div x-show="activeTab === 'kkm'" class="space-y-4">
                    <!-- Alert Info -->
                    <div class="p-4 text-sm text-yellow-800 border-l-4 border-yellow-300 bg-yellow-50">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p>KKM adalah nilai minimum yang harus dicapai siswa untuk dinyatakan tuntas dalam suatu mata pelajaran.</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
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
                            @click="saveKkmNotificationSettings" 
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        >
                            Simpan Pengaturan Notifikasi
                        </button>
                    </div>

                    
                    <!-- Pengaturan KKM Massal -->
                    <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                        <h4 class="text-lg font-medium text-green-800 mb-2">Pengaturan KKM Massal</h4>
                        <div class="mb-4">
                            <label for="global_kkm_value" class="block mb-2 text-sm font-medium text-gray-900">
                                Nilai KKM untuk Semua Mata Pelajaran
                            </label>
                            <div class="sm:flex gap-4">
                                <input 
                                    type="number" 
                                    id="global_kkm_value" 
                                    x-model="globalKkmData.nilai" 
                                    min="0" 
                                    max="100" 
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5 mb-2 sm:mb-0"
                                    placeholder="Contoh: 70"
                                >
                                <button 
                                    @click="applyGlobalKkm" 
                                    class="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                                >
                                    Terapkan
                                </button>
                            </div>
                            <div class="flex mt-2">
                                <label class="inline-flex items-center">
                                    <input 
                                        type="checkbox" 
                                        class="form-checkbox h-5 w-5 text-green-600" 
                                        x-model="globalKkmData.overwriteExisting"
                                    >
                                    <span class="ml-2 text-sm text-gray-700">Timpa nilai KKM yang sudah ada</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Nilai ini akan diterapkan ke semua mata pelajaran. Jika opsi "Timpa nilai KKM yang sudah ada" dicentang, maka nilai KKM yang sudah diatur sebelumnya akan diperbarui.
                            </p>
                        </div>
                    </div>
                    
                    <!-- KKM per Mata Pelajaran -->
                    <div class="mb-4">
                        <h4 class="text-lg font-medium text-gray-900 mb-2">KKM per Mata Pelajaran</h4>
                        <label for="mata_pelajaran_id" class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                        <select id="mata_pelajaran_id" x-model="kkmData.mata_pelajaran_id" 
                                @change="handleMapelChange()"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                            <option value="">Pilih Mata Pelajaran</option>
                            <template x-for="kelas in kelasData" :key="kelas.id">
                                <optgroup :label="'Kelas ' + kelas.nomor_kelas + ' - ' + kelas.nama_kelas">
                                    <template x-for="mapel in kelas.mata_pelajarans" :key="mapel.id">
                                        <option :value="mapel.id" x-text="mapel.nama_pelajaran"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label for="nilai_kkm" class="block mb-2 text-sm font-medium text-gray-900">Nilai KKM</label>
                        <input type="number" id="nilai_kkm" x-model="kkmData.nilai" min="0" max="100" 
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                        <p class="mt-1 text-sm text-gray-500">Nilai dari 0-100</p>
                    </div>
                    
                    <!-- KKM List Table -->
                    <div class="mt-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-2">Daftar KKM</h4>
                        
                        <div class="mb-4">
                            <label class="inline-flex items-center">
                                <input type="checkbox" class="form-checkbox h-5 w-5 text-green-600" x-model="showAllKkm">
                                <span class="ml-2 text-sm text-gray-700">Tampilkan semua data KKM</span>
                            </label>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left text-gray-500">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3">Kelas</th>
                                        <th scope="col" class="px-6 py-3">Mata Pelajaran</th>
                                        <th scope="col" class="px-6 py-3">KKM</th>
                                        <th scope="col" class="px-6 py-3">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Show either all KKM entries or filtered ones -->
                                    <template x-for="kkm in showAllKkm ? kkmList : getFilteredKkmList()" :key="kkm.id">
                                        <tr class="bg-white border-b hover:bg-gray-50">
                                            <td class="px-6 py-4" x-text="kkm.mata_pelajaran && kkm.mata_pelajaran.kelas ? 'Kelas ' + kkm.mata_pelajaran.kelas.nomor_kelas + ' - ' + kkm.mata_pelajaran.kelas.nama_kelas : '-'"></td>
                                            <td class="px-6 py-4" x-text="kkm.mata_pelajaran ? kkm.mata_pelajaran.nama_pelajaran : '-'"></td>
                                            <td class="px-6 py-4" x-text="kkm.nilai"></td>
                                            <td class="px-6 py-4">
                                                <button @click="deleteKkm(kkm.id)" class="text-red-600 hover:underline">
                                                    Hapus
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                    
                                    <!-- Message when no KKM data is available -->
                                    <tr x-show="(showAllKkm && kkmList.length === 0) || (!showAllKkm && kkmData.mata_pelajaran_id && getFilteredKkmList().length === 0)">
                                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                            <span x-text="showAllKkm ? 'Belum ada data KKM' : 'Nilai KKM belum diatur untuk mata pelajaran ini'"></span>
                                        </td>
                                    </tr>
                                    
                                    <!-- Message when no subject is selected and not showing all -->
                                    <tr x-show="!showAllKkm && !kkmData.mata_pelajaran_id">
                                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                            Pilih mata pelajaran untuk melihat nilai KKM atau centang "Tampilkan semua data KKM"
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex justify-end">
                        <button @click="saveKkm" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            Simpan KKM
                        </button>
                    </div>
                </div>
                
                <!-- Tab Bobot Nilai -->
                <div x-show="activeTab === 'bobot'" class="space-y-4">
                    <div class="p-4 text-sm text-green-800 border-l-4 border-green-300 bg-green-50">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p>Bobot nilai menentukan persentase masing-masing komponen dalam perhitungan nilai akhir rapor. Total bobot harus 100% (1.0).</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg mb-4">
                        <h4 class="text-md font-medium text-yellow-800 mb-2">Penting Diperhatikan:</h4>
                        <ul class="text-sm text-yellow-700 list-disc list-inside space-y-1">
                            <li>Perubahan bobot nilai akan mempengaruhi perhitungan nilai akhir rapor untuk <strong>semua siswa</strong>.</li>
                            <li>Nilai yang sudah diinput sebelumnya akan otomatis dihitung ulang sesuai bobot baru.</li>
                            <li>Pastikan semua guru/pengajar mengetahui perubahan bobot ini untuk menghindari kesalahpahaman.</li>
                            <li>Disarankan untuk melakukan perubahan bobot di awal semester atau sebelum proses penilaian dimulai.</li>
                        </ul>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-900">Bobot Sumatif Tujuan Pembelajaran (S.TP)</label>
                        <div class="flex items-center">
                            <input type="number" x-model="bobotData.bobot_tp" step="0.01" min="0" max="1" 
                                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                            <span class="ml-2 text-gray-700">x 100% = <span x-text="Math.round(bobotData.bobot_tp * 100) + '%'"></span></span>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-900">Bobot Sumatif Lingkup Materi (S.LM)</label>
                        <div class="flex items-center">
                            <input type="number" x-model="bobotData.bobot_lm" step="0.01" min="0" max="1" 
                                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                            <span class="ml-2 text-gray-700">x 100% = <span x-text="Math.round(bobotData.bobot_lm * 100) + '%'"></span></span>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-900">Bobot Sumatif Akhir Semester (S.AS)</label>
                        <div class="flex items-center">
                            <input type="number" x-model="bobotData.bobot_as" step="0.01" min="0" max="1" 
                                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                            <span class="ml-2 text-gray-700">x 100% = <span x-text="Math.round(bobotData.bobot_as * 100) + '%'"></span></span>
                        </div>
                    </div>
                    
                    <div class="mt-2 mb-4">
                        <div class="flex items-center">
                            <span class="font-medium text-gray-700">Total Bobot: </span>
                            <span class="ml-2" :class="isTotalValid ? 'text-green-600' : 'text-red-600'" 
                                  x-text="Math.round((parseFloat(bobotData.bobot_tp) + parseFloat(bobotData.bobot_lm) + parseFloat(bobotData.bobot_as)) * 100) + '%'"></span>
                        </div>
                        <p x-show="!isTotalValid" class="mt-1 text-sm text-red-600">
                            Total bobot harus 100%. Mohon sesuaikan nilai di atas.
                        </p>
                    </div>
                    
                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 mb-4">
                        <h4 class="text-md font-medium text-gray-900 mb-2">Rumus Perhitungan Nilai Akhir:</h4>
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">NA RAPOR</span> = 
                            (<span x-text="bobotData.bobot_tp"></span> * S.TP + 
                            <span x-text="bobotData.bobot_lm"></span> * S.LM + 
                            <span x-text="bobotData.bobot_as"></span> * S.AS)
                        </p>
                    </div>
                    
                    <div class="mt-4 flex justify-end">
                        <button @click="saveBobot" :disabled="!isTotalValid" 
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed">
                            Simpan Bobot Nilai
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

