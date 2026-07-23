<div class="p-6 max-h-[80vh] overflow-y-auto">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-green-700">Panduan Lengkap Placeholder Template Rapor</h3>
        <button onclick="closePlaceholderGuide()" class="text-gray-400 hover:text-gray-600">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Introduction -->
    <div class="mb-6">
        <p class="mb-2">Template rapor harus menggunakan format Word (.docx) dengan placeholder yang sesuai untuk data yang akan diisi.</p>
        <p class="mb-2">Format placeholder: <code class="px-2 py-1 bg-gray-100 rounded">${nama_placeholder}</code></p>
        
        <div class="mt-4 p-3 bg-blue-50 border-l-4 border-blue-500 text-blue-700">
            <h5 class="font-medium mb-2">Panduan Penulisan Placeholder di Word:</h5>
            <ul class="list-disc list-inside space-y-1">
                <li>Ketik placeholder dalam satu formatting yang sama (hindari styling sebagian placeholder)</li>
                <li>Pastikan menulis placeholder utuh: <code class="bg-gray-100 px-1 rounded">${placeholder_key}</code> tanpa spasi di dalamnya</li>
                <li>Jangan tambahkan koma, tanda kurung atau karakter lain di dalam placeholder</li>
                <li>Satu sel tabel sebaiknya berisi maksimal satu placeholder</li>
                <li>Untuk gambar tanda tangan, gunakan <code class="bg-gray-100 px-1 rounded">${ttd_wali_kelas}</code> dalam satu paragraf atau sel biasa, bukan text box/header/footer</li>
                <li>Sisakan area sekitar 120 x 60 px untuk tanda tangan; PNG transparan direkomendasikan</li>
                <li>Jika perlu mengganti template, gunakan fungsi "Download Contoh" untuk referensi</li>
            </ul>
        </div>

        <div class="mt-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700">
            <h5 class="font-medium mb-2">Contoh Kesalahan Umum:</h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <p class="font-medium">❌ Salah:</p>
                    <ul class="list-disc list-inside space-y-1 text-sm">
                        <li><code class="px-1 bg-gray-100 rounded">${nama_matapelajaran5, nilai_matapelajaran5)}</code></li>
                        <li><code class="px-1 bg-gray-100 rounded">${ nama_siswa }</code> (ada spasi)</li>
                        <li><code class="px-1 bg-gray-100 rounded">${nama-siswa}</code> (pakai dash)</li>
                        <li><code class="px-1 bg-gray-100 rounded">$nama_siswa</code> (tanpa kurung kurawal)</li>
                    </ul>
                </div>
                <div>
                    <p class="font-medium">✅ Benar:</p>
                    <ul class="list-disc list-inside space-y-1 text-sm">
                        <li><code class="px-1 bg-gray-100 rounded">${nama_matapelajaran5}</code></li>
                        <li><code class="px-1 bg-gray-100 rounded">${nilai_matapelajaran5}</code></li>
                        <li><code class="px-1 bg-gray-100 rounded">${nama_siswa}</code></li>
                        <li><code class="px-1 bg-gray-100 rounded">${tahun_ajaran}</code></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Type Selection Tabs -->
    <div class="flex mb-4 border-b mt-6">
        <button id="btn-all" onclick="showPlaceholderType('all')" 
                class="px-4 py-2 border-b-2 border-green-500 text-green-700 font-medium">
            Semua Placeholder
        </button>
        <button id="btn-uts" onclick="showPlaceholderType('uts')" 
                class="px-4 py-2 border-b-2 border-transparent hover:text-green-700 hover:border-green-200">
            UTS (Tengah Semester)
        </button>
        <button id="btn-uas" onclick="showPlaceholderType('uas')"
                class="px-4 py-2 border-b-2 border-transparent hover:text-green-700 hover:border-green-200">
            UAS (Akhir Semester)
        </button>
    </div>

    <!-- All Placeholders Content -->
    <div id="all-placeholders" class="placeholder-content">
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="py-2 px-4 border-b text-left">Placeholder</th>
                        <th class="py-2 px-4 border-b text-left">Deskripsi</th>
                        <th class="py-2 px-4 border-b text-left">Kategori</th>
                        <th class="py-2 px-4 border-b text-left">Wajib?</th>
                        <th class="py-2 px-4 border-b text-left">UTS</th>
                        <th class="py-2 px-4 border-b text-left">UAS</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data Siswa -->
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_siswa}</code></td>
                        <td class="py-2 px-4">Nama lengkap siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nisn}</code></td>
                        <td class="py-2 px-4">NISN siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nis}</code></td>
                        <td class="py-2 px-4">NIS siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kelas}</code></td>
                        <td class="py-2 px-4">Kelas siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${tahun_ajaran}</code></td>
                        <td class="py-2 px-4">Tahun ajaran</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${tempat_lahir}</code></td>
                        <td class="py-2 px-4">Tempat lahir siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${jenis_kelamin}</code></td>
                        <td class="py-2 px-4">Jenis kelamin siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${agama}</code></td>
                        <td class="py-2 px-4">Agama siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${alamat_siswa}</code></td>
                        <td class="py-2 px-4">Alamat siswa</td>
                        <td class="py-2 px-4">Data Siswa</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    
                    <!-- Data Keluarga -->
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_ayah}</code></td>
                        <td class="py-2 px-4">Nama ayah siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_ibu}</code></td>
                        <td class="py-2 px-4">Nama ibu siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${pekerjaan_ayah}</code></td>
                        <td class="py-2 px-4">Pekerjaan ayah siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${pekerjaan_ibu}</code></td>
                        <td class="py-2 px-4">Pekerjaan ibu siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${alamat_orangtua}</code></td>
                        <td class="py-2 px-4">Alamat orang tua siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${wali_siswa}</code></td>
                        <td class="py-2 px-4">Nama wali siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${pekerjaan_wali}</code></td>
                        <td class="py-2 px-4">Pekerjaan wali siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${alamat_wali}</code></td>
                        <td class="py-2 px-4">Alamat wali siswa</td>
                        <td class="py-2 px-4">Data Keluarga</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    
                    <!-- Data Sekolah -->
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_sekolah}</code></td>
                        <td class="py-2 px-4">Nama sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${alamat_sekolah}</code></td>
                        <td class="py-2 px-4">Alamat sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kelurahan}</code></td>
                        <td class="py-2 px-4">Kelurahan sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kecamatan}</code></td>
                        <td class="py-2 px-4">Kecamatan sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kabupaten}</code></td>
                        <td class="py-2 px-4">Kabupaten sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${provinsi}</code></td>
                        <td class="py-2 px-4">Provinsi sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kode_pos}</code></td>
                        <td class="py-2 px-4">Kode pos sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nomor_telepon}</code></td>
                        <td class="py-2 px-4">Nomor telepon sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${website}</code></td>
                        <td class="py-2 px-4">Website sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${email_sekolah}</code></td>
                        <td class="py-2 px-4">Email sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${npsn}</code></td>
                        <td class="py-2 px-4">NPSN sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${kepala_sekolah}</code></td>
                        <td class="py-2 px-4">Nama kepala sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nip_kepala_sekolah}</code></td>
                        <td class="py-2 px-4">NIP kepala sekolah</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${wali_kelas}</code></td>
                        <td class="py-2 px-4">Nama wali kelas</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nip_wali_kelas}</code></td>
                        <td class="py-2 px-4">NIP wali kelas</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${ttd_wali_kelas}</code></td>
                        <td class="py-2 px-4">Gambar tanda tangan wali kelas. Jika belum tersedia, area akan kosong.</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">Tidak</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">Ya</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">Ya</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${tanggal_terbit}</code></td>
                        <td class="py-2 px-4">Tanggal terbit rapor</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${tempat_terbit}</code></td>
                        <td class="py-2 px-4">Tempat terbit rapor</td>
                        <td class="py-2 px-4">Data Sekolah</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    
                    <!-- Ketentuan Akademik -->
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${fase}</code></td>
                        <td class="py-2 px-4">Fase pembelajaran siswa</td>
                        <td class="py-2 px-4">Akademik</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${semester}</code></td>
                        <td class="py-2 px-4">Semester (Ganjil/Genap)</td>
                        <td class="py-2 px-4">Akademik</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    
                    <!-- Kehadiran -->
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${sakit}</code></td>
                        <td class="py-2 px-4">Jumlah hari sakit</td>
                        <td class="py-2 px-4">Kehadiran</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${izin}</code></td>
                        <td class="py-2 px-4">Jumlah hari izin</td>
                        <td class="py-2 px-4">Kehadiran</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${tanpa_keterangan}</code></td>
                        <td class="py-2 px-4">Jumlah hari tanpa keterangan</td>
                        <td class="py-2 px-4">Kehadiran</td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                    
                    <!-- Catatan -->
                    <tr class="border-b bg-gray-50">
                        <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${catatan_guru}</code></td>
                        <td class="py-2 px-4">Catatan dari guru</td>
                        <td class="py-2 px-4">Catatan</td>
                        <td class="py-2 px-4"><span class="text-red-600">✗</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="mt-6">
            <h4 class="font-medium mb-3 text-lg">Placeholder Mata Pelajaran Dinamis</h4>
            <p class="mb-3">Placeholder dinamis untuk mata pelajaran, dari 1 hingga 10:</p>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-2 px-4 border-b text-left">Placeholder</th>
                            <th class="py-2 px-4 border-b text-left">Deskripsi</th>
                            <th class="py-2 px-4 border-b text-left">UTS</th>
                            <th class="py-2 px-4 border-b text-left">UAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_matapelajaran1}</code></td>
                            <td class="py-2 px-4">Nama mata pelajaran 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nilai_matapelajaran1}</code></td>
                            <td class="py-2 px-4">Nilai mata pelajaran 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${capaian_matapelajaran1}</code></td>
                            <td class="py-2 px-4">Capaian mata pelajaran 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b bg-gray-50">
                            <td class="py-2 px-4 font-medium" colspan="4">Dan seterusnya hingga <code class="bg-gray-100 px-1 rounded">${nama_matapelajaran10}, ${nilai_matapelajaran10}, ${capaian_matapelajaran10}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <h4 class="font-medium mb-3 mt-6 text-lg">Placeholder Muatan Lokal Dinamis</h4>
            <p class="mb-3">Placeholder dinamis untuk muatan lokal, dari 1 hingga 5:</p>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-2 px-4 border-b text-left">Placeholder</th>
                            <th class="py-2 px-4 border-b text-left">Deskripsi</th>
                            <th class="py-2 px-4 border-b text-left">UTS</th>
                            <th class="py-2 px-4 border-b text-left">UAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nama_mulok1}</code></td>
                            <td class="py-2 px-4">Nama muatan lokal 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${nilai_mulok1}</code></td>
                            <td class="py-2 px-4">Nilai muatan lokal 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${capaian_mulok1}</code></td>
                            <td class="py-2 px-4">Capaian muatan lokal 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b bg-gray-50">
                            <td class="py-2 px-4 font-medium" colspan="4">Dan seterusnya hingga <code class="bg-gray-100 px-1 rounded">${nama_mulok5}, ${nilai_mulok5}, ${capaian_mulok5}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <h4 class="font-medium mb-3 mt-6 text-lg">Placeholder Ekstrakurikuler Dinamis</h4>
            <p class="mb-3">Placeholder dinamis untuk ekstrakurikuler, dari 1 hingga 6:</p>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-2 px-4 border-b text-left">Placeholder</th>
                            <th class="py-2 px-4 border-b text-left">Deskripsi</th>
                            <th class="py-2 px-4 border-b text-left">UTS</th>
                            <th class="py-2 px-4 border-b text-left">UAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${ekskul1_nama}</code></td>
                            <td class="py-2 px-4">Nama ekstrakurikuler 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4"><code class="bg-gray-100 px-1 rounded">${ekskul1_keterangan}</code></td>
                            <td class="py-2 px-4">Keterangan ekstrakurikuler 1</td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                            <td class="py-2 px-4"><span class="text-green-600">✓</span></td>
                        </tr>
                        <tr class="border-b bg-gray-50">
                            <td class="py-2 px-4 font-medium" colspan="4">Dan seterusnya hingga <code class="bg-gray-100 px-1 rounded">${ekskul6_nama}, ${ekskul6_keterangan}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- UTS Placeholders (Simplified) -->
    <div id="uts-placeholders" class="placeholder-content hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Data Siswa Section - UTS -->
            <div class="bg-white p-4 rounded-lg border">
                <h4 class="font-semibold text-gray-700 mb-2">Data Siswa</h4>
                <div class="space-y-2">
                    <div class="flex flex-col">
                        <div class="flex items-center">
                            <code class="text-xs px-1 py-0.5 bg-gray-100 rounded mr-2">${kelas}</code>
                            <span class="text-sm">Kelas siswa</span>
                            <span class="ml-1 text-red-500 text-xs font-bold">*</span>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center">
                            <code class="text-xs px-1 py-0.5 bg-gray-100 rounded mr-2">${tahun_ajaran}</code>
                            <span class="text-sm">Tahun ajaran</span>
                            <span class="ml-1 text-red-500 text-xs font-bold">*</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-4 text-sm text-gray-500">
            <p><span class="text-red-500 font-bold">*</span> Placeholder wajib yang harus ada dalam template UAS:</p>
            <ul class="list-disc list-inside ml-4">
                <li>nama_siswa</li>
                <li>nisn</li>
                <li>nis</li>
                <li>kelas</li>
                <li>tahun_ajaran</li>
                <li>sakit</li>
                <li>nama_sekolah</li>
                <li>alamat_sekolah</li>
            </ul>
            <p>Format UAS lebih kompleks dan memiliki lebih banyak placeholder. Untuk referensi lengkap, lihat file template contoh UAS yang bisa didownload melalui tombol "Download Contoh" di halaman utama.</p>
        </div>
    </div>
</div>

<script>
    function showPlaceholderType(type) {
        // Hide all content
        document.querySelectorAll('.placeholder-content').forEach(el => {
            el.classList.add('hidden');
        });
        
        // Reset all tab buttons
        document.querySelectorAll('#btn-all, #btn-uts, #btn-uas').forEach(el => {
            el.classList.remove('border-green-500', 'text-green-700', 'font-medium');
            el.classList.add('border-transparent', 'hover:text-green-700', 'hover:border-green-200');
        });
        
        // Show selected content and activate tab
        if (type === 'all') {
            document.getElementById('all-placeholders').classList.remove('hidden');
            document.getElementById('btn-all').classList.remove('border-transparent', 'hover:text-green-700', 'hover:border-green-200');
            document.getElementById('btn-all').classList.add('border-green-500', 'text-green-700', 'font-medium');
        } else if (type === 'uts') {
            document.getElementById('uts-placeholders').classList.remove('hidden');
            document.getElementById('btn-uts').classList.remove('border-transparent', 'hover:text-green-700', 'hover:border-green-200');
            document.getElementById('btn-uts').classList.add('border-green-500', 'text-green-700', 'font-medium');
        } else {
            document.getElementById('uas-placeholders').classList.remove('hidden');
            document.getElementById('btn-uas').classList.remove('border-transparent', 'hover:text-green-700', 'hover:border-green-200');
            document.getElementById('btn-uas').classList.add('border-green-500', 'text-green-700', 'font-medium');
        }
    }
</script>
