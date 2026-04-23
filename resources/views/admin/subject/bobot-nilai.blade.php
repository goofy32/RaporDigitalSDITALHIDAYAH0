@extends('layouts.app')

@section('title', 'Bobot Nilai')

@section('content')
<div>
    <div class="p-4 bg-white mt-14">
        <!-- Header dengan tombol seperti screenshot -->
        <!-- <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Bobot Nilai</h2>
            <div class="flex gap-2">
                <a href="{{ route('subject.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium">
                    Kembali
                </a>
                <button type="button" @click.prevent="saveBobot()" :disabled="!isTotalValid"class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed font-medium">
                    Simpan
                </button>
            </div>
        </div> -->

        <div x-data="bobotNilaiForm" data-subject-index-url="{{ route('subject.index') }}"> 
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-green-700">Bobot Nilai</h2>
                <div class="flex gap-2">
                    <a href="{{ route('subject.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium">
                        Kembali
                    </a>
                    <button @click="saveBobot" :disabled="!isTotalValid" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed font-medium">
                        Simpan
                    </button>
                </div>
            </div>

            <div class="mb-6 p-4 bg-green-50 border-2 border-green-200 rounded-lg">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-green-800">
                        <svg class="inline-block w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Bobot Nilai Saat Ini
                    </h3>
                    <span class="text-sm text-green-600">Tahun Ajaran {{ session('tahun_ajaran_text', 'Aktif') }}</span>
                </div>
                
                <div class="grid grid-cols-3 gap-4 mt-4">
                    <div class="bg-white p-3 rounded-lg border border-green-200">
                        <div class="text-sm text-gray-600">S.TP (Tujuan Pembelajaran)</div>
                        <div class="text-2xl font-bold text-green-700" x-text="Math.round(bobotData.bobot_tp * 100) + '%'"></div>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-green-200">
                        <div class="text-sm text-gray-600">S.LM (Lingkup Materi)</div>
                        <div class="text-2xl font-bold text-green-700" x-text="Math.round(bobotData.bobot_lm * 100) + '%'"></div>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-green-200">
                        <div class="text-sm text-gray-600">S.AS (Akhir Semester)</div>
                        <div class="text-2xl font-bold text-green-700" x-text="Math.round(bobotData.bobot_as * 100) + '%'"></div>
                    </div>
                </div>
            </div>


            <!-- Alert Penting Diperhatikan seperti screenshot -->
            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg mb-6">
                <h4 class="text-md font-medium text-yellow-800 mb-2">Penting Diperhatikan:</h4>
                <ul class="text-sm text-yellow-700 list-disc list-inside space-y-1">
                    <li>Perubahan bobot nilai akan mempengaruhi perhitungan nilai akhir rapor untuk <strong>semua siswa</strong>.</li>
                    <li>Nilai yang sudah diinput sebelumnya akan otomatis dihitung ulang sesuai bobot baru.</li>
                    <li>Pastikan semua guru/pengajar mengetahui perubahan bobot ini untuk menghindari kesalahpahaman.</li>
                    <li>Disarankan untuk melakukan perubahan bobot di awal semester atau sebelum proses penilaian dimulai.</li>
                    <li>Total bobot harus 100% atau (1.0).</li>
                </ul>
            </div>

            <!-- Total Bobot Display seperti screenshot -->
            <div class="mb-6">
                <div class="text-sm font-medium text-gray-700 mb-2">TOTAL BOBOT: 100%</div>
                
                <!-- Rumus Perhitungan Nilai Akhir -->
                <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <h4 class="text-md font-medium text-gray-900 mb-2">Rumus Perhitungan Nilai Akhir:</h4>
                    <div class="text-sm text-gray-700 font-mono">
                        NA RAPOR = ( <span x-text="bobotData.bobot_tp"></span>* S.TP + <span x-text="bobotData.bobot_lm"></span>* S.LM + <span x-text="bobotData.bobot_as"></span>* S.AS)/4
                    </div>
                </div>
            </div>

            <!-- Form Bobot seperti layout screenshot -->
            <form @submit.prevent="saveBobot">
                <!-- Bobot Sumatif Tujuan Pembelajaran (S.TP) -->
                <div class="mb-6">
                    <label class="block mb-2 text-sm font-medium text-gray-900">
                        Bobot Sumatif Tujuan Pembelajaran (S.TP)
                    </label>
                    <div class="flex items-center gap-4">
                        <input type="number" x-model="bobotData.bobot_tp" step="0.01" min="0" max="1" 
                               class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full max-w-md p-2.5">
                        <span class="text-gray-700 whitespace-nowrap">
                            x 100% = <span class="font-medium" x-text="Math.round(bobotData.bobot_tp * 100) + '%'"></span>
                        </span>
                    </div>
                </div>
                
                <!-- Bobot Sumatif Lingkup Materi (S.LM) -->
                <div class="mb-6">
                    <label class="block mb-2 text-sm font-medium text-gray-900">
                        Bobot Sumatif Lingkup Materi (S.LM)
                    </label>
                    <div class="flex items-center gap-4">
                        <input type="number" x-model="bobotData.bobot_lm" step="0.01" min="0" max="1" 
                               class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full max-w-md p-2.5">
                        <span class="text-gray-700 whitespace-nowrap">
                            x 100% = <span class="font-medium" x-text="Math.round(bobotData.bobot_lm * 100) + '%'"></span>
                        </span>
                    </div>
                </div>
                
                <!-- Bobot Sumatif Akhir Semester (S.AS) -->
                <div class="mb-6">
                    <label class="block mb-2 text-sm font-medium text-gray-900">
                        Bobot Sumatif Akhir Semester (S.AS)
                    </label>
                    <div class="flex items-center gap-4">
                        <input type="number" x-model="bobotData.bobot_as" step="0.01" min="0" max="1" 
                               class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full max-w-md p-2.5">
                        <span class="text-gray-700 whitespace-nowrap">
                            x 100% = <span class="font-medium" x-text="Math.round(bobotData.bobot_as * 100) + '%'"></span>
                        </span>
                    </div>
                </div>
                
                <!-- Total Bobot Validation -->
                <div class="mb-6">
                    <div class="flex items-center">
                        <span class="font-medium text-gray-700 text-lg">Total Bobot: </span>
                        <span class="ml-2 text-xl font-bold" :class="isTotalValid ? 'text-green-600' : 'text-red-600'" 
                              x-text="Math.round((parseFloat(bobotData.bobot_tp) + parseFloat(bobotData.bobot_lm) + parseFloat(bobotData.bobot_as)) * 100) + '%'"></span>
                    </div>
                    <p x-show="!isTotalValid" class="mt-1 text-sm text-red-600">
                        Total bobot harus 100%. Mohon sesuaikan nilai di atas.
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
