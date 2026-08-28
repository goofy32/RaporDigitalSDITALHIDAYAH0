@extends('layouts.app')

@section('title', 'Tutorial Template Rapor')

@section('content')
<div class="p-4 bg-white mt-14">
    <!-- Header -->
    <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <h2 class="text-2xl font-bold text-green-700">Tutorial Template Rapor</h2>
        <a href="{{ route('report.template.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
            Kembali ke Daftar Template
        </a>
    </div>

    <!-- Progress Bar -->
    <div class="w-full bg-gray-200 rounded-full h-2.5 mb-4">
        <div id="progress-bar" class="bg-green-600 h-2.5 rounded-full" style="width: 20%"></div>
    </div>

    <div id="tutorial-container" class="bg-white rounded-lg p-6 shadow-sm">
        <!-- Tutorial steps will be loaded here -->
        <div id="step-1" class="tutorial-step">
            <h3 class="text-xl font-semibold mb-4">Langkah 1: Pengenalan Template Rapor</h3>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="w-full md:w-1/2">
                    <p class="mb-3">Template rapor adalah dokumen Word (.docx) yang berisi placeholder untuk data yang akan diisi saat rapor digenerate.</p>
                    <p class="mb-3">Placeholder ditulis dengan format <code class="px-2 py-1 bg-gray-100 rounded">${nama_placeholder}</code>.</p>
                    <p class="mb-3">Ada 2 jenis template rapor:</p>
                    <ul class="list-disc list-inside mb-4">
                        <li class="mb-2">Template UTS (Ujian Tengah Semester)</li>
                        <li class="mb-2">Template UAS (Ujian Akhir Semester)</li>
                    </ul>
                    <p>Anda bisa menggunakan template contoh atau membuat template sendiri sesuai kebutuhan.</p>
                </div>
                <div class="w-full md:w-1/2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h4 class="font-medium mb-3">Contoh Template:</h4>
                    <img src="{{ asset('images/tutorial/template-example.png') }}" alt="Contoh Template" class="w-full rounded-lg shadow-sm mb-4">
                    <div class="text-sm text-gray-600">
                        <p>Template dapat disesuaikan dengan format rapor sekolah Anda.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 text-center">
                <button onclick="goToStep(2)" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    Lanjut ke Langkah 2
                </button>
            </div>
        </div>

        <div id="step-2" class="tutorial-step hidden">
            <h3 class="text-xl font-semibold mb-4">Langkah 2: Memahami Placeholder</h3>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="w-full md:w-1/2">
                    <p class="mb-3">Placeholder adalah penanda yang akan diganti dengan data siswa saat rapor digenerate.</p>
                    <p class="mb-3">Format placeholder: <code class="px-2 py-1 bg-gray-100 rounded">${nama_placeholder}</code></p>
                    <p class="mb-3">Contoh placeholder:</p>
                    <ul class="list-disc list-inside mb-4">
                        <li class="mb-2"><code class="px-2 py-1 bg-gray-100 rounded">${nama_siswa}</code> - Nama siswa</li>
                        <li class="mb-2"><code class="px-2 py-1 bg-gray-100 rounded">${nisn}</code> - NISN siswa</li>
                        <li class="mb-2"><code class="px-2 py-1 bg-gray-100 rounded">${kelas}</code> - Kelas siswa</li>
                        <li class="mb-2"><code class="px-2 py-1 bg-gray-100 rounded">${nilai_matematika}</code> - Nilai matematika</li>
                    </ul>
                    <p class="mb-3">Placeholder wajib harus ada dalam template, jika tidak maka upload template akan gagal.</p>
                </div>
                <div class="w-full md:w-1/2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h4 class="font-medium mb-3">Tips Menulis Placeholder:</h4>
                    <div class="space-y-2 mb-4">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Tulis placeholder utuh tanpa spasi: <code class="px-1 bg-gray-100 rounded">${nama_siswa}</code></span>
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Gunakan huruf kecil dan underscore</span>
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span>Hindari: <code class="px-1 bg-gray-100 rounded">${ nama_siswa }</code> (spasi di dalam)</span>
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span>Hindari: <code class="px-1 bg-gray-100 rounded">${nama-siswa}</code> (tanda hubung)</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-between">
                <button onclick="goToStep(1)" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    Kembali
                </button>
                <button onclick="goToStep(3)" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    Lanjut ke Langkah 3
                </button>
            </div>
        </div>

        <div id="step-3" class="tutorial-step hidden">
            <h3 class="text-xl font-semibold mb-4">Langkah 3: Membuat Template di Word</h3>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="w-full md:w-1/2">
                    <p class="mb-3">Berikut langkah-langkah membuat template di Microsoft Word:</p>
                    <ol class="list-decimal list-inside mb-4 space-y-3">
                        <li>Buka Microsoft Word dan buat dokumen baru</li>
                        <li>Desain layout rapor sesuai kebutuhan (header, tabel nilai, dll)</li>
                        <li>Tambahkan placeholder di lokasi yang sesuai</li>
                        <li>Simpan file dalam format .docx</li>
                        <li>Upload file ke sistem rapor</li>
                    </ol>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                        <p class="text-yellow-800">Tip: Download template contoh terlebih dahulu untuk referensi.</p>
                    </div>
                </div>
                <div class="w-full md:w-1/2">
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-4">
                        <h4 class="font-medium mb-3">Contoh Tabel Nilai di Word:</h4>
                        <img src="{{ asset('images/tutorial/word-table-example.png') }}" alt="Contoh Tabel di Word" class="w-full rounded-lg shadow-sm">
                    </div>
                    <div class="flex justify-center space-x-2">
                        <a href="{{ route('report.template.sample', ['type' => 'UTS']) }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Download Template UTS
                        </a>
                        <a href="{{ route('report.template.sample', ['type' => 'UAS']) }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Download Template UAS
                        </a>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-between">
                <button onclick="goToStep(2)" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    Kembali
                </button>
                <button onclick="goToStep(4)" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    Lanjut ke Langkah 4
                </button>
            </div>
        </div>

        <div id="step-4" class="tutorial-step hidden">
            <h3 class="text-xl font-semibold mb-4">Langkah 4: Upload Template</h3>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="w-full md:w-1/2">
                    <p class="mb-3">Untuk mengupload template:</p>
                    <ol class="list-decimal list-inside mb-4 space-y-3">
                        <li>Klik tombol "Upload Template" di halaman Template Rapor</li>
                        <li>Pilih jenis rapor (UTS/UAS)</li>
                        <li>Pilih kelas yang akan menggunakan template ini</li>
                        <li>Upload file template (.docx)</li>
                        <li>Klik "Upload"</li>
                    </ol>
                    <p class="mb-3">Jika upload berhasil, template akan muncul di daftar template.</p>
                    <p>Jika gagal, periksa pesan error dan pastikan semua placeholder wajib ada dalam template.</p>
                </div>
                <div class="w-full md:w-1/2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h4 class="font-medium mb-3">Tampilan Form Upload:</h4>
                    <img src="{{ asset('images/tutorial/upload-form.png') }}" alt="Form Upload Template" class="w-full rounded-lg shadow-sm mb-4">
                    <div class="text-sm text-gray-600">
                        <p>Pastikan file template dalam format .docx dan berisi semua placeholder wajib.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-between">
                <button onclick="goToStep(3)" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    Kembali
                </button>
                <button onclick="goToStep(5)" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    Lanjut ke Langkah 5
                </button>
            </div>
        </div>

        <div id="step-5" class="tutorial-step hidden">
            <h3 class="text-xl font-semibold mb-4">Langkah 5: Aktivasi & Penggunaan Template</h3>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="w-full md:w-1/2">
                    <p class="mb-3">Setelah upload, template perlu diaktifkan:</p>
                    <ol class="list-decimal list-inside mb-4 space-y-3">
                        <li>Klik tombol aktivasi (ikon centang) pada template</li>
                        <li>Konfirmasi untuk mengaktifkan template</li>
                    </ol>
                    <p class="mb-3">Hanya ada satu template aktif untuk setiap jenis (UTS/UAS) per kelas.</p>
                    <p class="mb-3">Mengaktifkan template baru akan menonaktifkan template lama dengan jenis dan kelas yang sama.</p>
                    <p>Template aktif akan digunakan saat wali kelas generate rapor siswa.</p>
                </div>
                <div class="w-full md:w-1/2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h4 class="font-medium mb-3">Aktivasi Template:</h4>
                    <img src="{{ asset('images/tutorial/activate-template.png') }}" alt="Aktivasi Template" class="w-full rounded-lg shadow-sm mb-4">
                    <div class="text-sm text-gray-600">
                        <p>Template aktif ditandai dengan status "Aktif" berwarna hijau.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-between">
                <button onclick="goToStep(4)" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    Kembali
                </button>
                <a href="{{ route('report.template.index') }}" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    Selesai
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    var currentStep = 1;
    var totalSteps = 5;
    
    function goToStep(step) {
        // Hide all steps
        document.querySelectorAll('.tutorial-step').forEach(el => {
            el.classList.add('hidden');
        });
        
        // Show requested step
        document.getElementById(`step-${step}`).classList.remove('hidden');
        
        // Update progress bar
        const progress = (step / totalSteps) * 100;
        document.getElementById('progress-bar').style.width = `${progress}%`;
        
        // Update current step
        currentStep = step;
        
        // Scroll to top of container
        document.getElementById('tutorial-container').scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
    
    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight' && currentStep < totalSteps) {
            goToStep(currentStep + 1);
        } else if (e.key === 'ArrowLeft' && currentStep > 1) {
            goToStep(currentStep - 1);
        }
    });
</script>
@endpush
@endsection
