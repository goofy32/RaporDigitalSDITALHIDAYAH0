<?php

return [
    'roles' => [
        'admin' => ['Admin', 'Pengajar', 'Wali Kelas', 'FAQ', 'Masalah Umum'],
        'pengajar' => ['Pengajar', 'FAQ', 'Masalah Umum'],
        'wali_kelas' => ['Wali Kelas', 'FAQ', 'Masalah Umum'],
    ],

    'topics' => [
        [
            'category' => 'Admin',
            'question' => 'Setup awal aplikasi untuk Admin',
            'answer' => "Gunakan urutan ini saat menyiapkan Rapor Digital pertama kali.\n\nLangkah-langkah:\n1. Lengkapi Profil Sekolah, termasuk nama sekolah, alamat, NPSN, email, kepala sekolah, dan data kontak.\n2. Buat atau aktifkan tahun ajaran yang sedang digunakan.\n3. Pastikan semester aktif sudah benar, ganjil atau genap.\n4. Buat data kelas, termasuk kelas paralel bila ada.\n5. Tambahkan guru dan tentukan apakah guru menjadi pengajar, wali kelas, atau keduanya.\n6. Tambahkan siswa secara manual atau melalui import Excel.\n7. Tambahkan mata pelajaran dan guru pengajarnya.\n8. Lengkapi Lingkup Materi dan Tujuan Pembelajaran.\n9. Upload template rapor UTS dan UAS di Format Rapor.\n10. Pilih Jenis Rapor yang Dibuka untuk Wali Kelas.",
            'keywords' => ['setup awal', 'profil sekolah', 'tahun ajaran', 'kelas', 'guru', 'siswa', 'mata pelajaran', 'format rapor'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Profil Sekolah dan data yang muncul di rapor',
            'answer' => "Profil Sekolah dipakai sebagai identitas resmi di rapor. Pastikan nama sekolah, alamat, NPSN, email, kepala sekolah, NIP atau NUPTK kepala sekolah, dan data kontak sudah benar sebelum rapor dibuat.\n\nJika ada data sekolah yang berubah, perbarui Profil Sekolah lalu buat ulang dokumen rapor yang diperlukan.",
            'keywords' => ['profil sekolah', 'nama sekolah', 'alamat', 'npsn', 'kepala sekolah', 'rapor'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Mengelola tahun ajaran dan semester',
            'answer' => "Tahun ajaran aktif menentukan data kelas, siswa, mata pelajaran, nilai, absensi, dan rapor yang sedang dikerjakan. Semester aktif menentukan apakah data yang dibuka adalah ganjil atau genap.\n\nLangkah penting:\n1. Aktifkan hanya tahun ajaran yang sedang digunakan.\n2. Setelah semester ganjil selesai, gunakan fitur lanjut ke semester genap.\n3. Setelah tahun ajaran selesai, buat tahun ajaran berikutnya melalui alur yang tersedia.\n4. Gunakan arsip sebagai penyimpanan aman untuk data lama.\n5. Jangan menghapus permanen data akademik kecuali benar-benar yakin tidak lagi terkait siswa, nilai, rapor, atau alur kenaikan kelas.",
            'keywords' => ['tahun ajaran', 'semester', 'ganjil', 'genap', 'arsip', 'hapus permanen', 'kenaikan kelas'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Data Kelas untuk kelas paralel',
            'answer' => "Data Kelas dipakai untuk menempatkan siswa, wali kelas, mata pelajaran, dan rapor. Saat membuat kelas, pilih tingkat kelas dengan benar dan beri nama kelas yang mudah dikenali, misalnya 1 Ubay atau 1 Zaid.\n\nUntuk kelas paralel, buat satu kelas untuk setiap rombongan belajar. Gunakan search dan filter untuk mencari kelas berdasarkan tingkat, wali kelas, atau urutan nama.",
            'keywords' => ['data kelas', 'kelas paralel', 'tingkat kelas', 'wali kelas', 'filter kelas', 'search kelas'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Data Guru, role, dan login',
            'answer' => "Saat menambahkan guru, isi nama dan akun login dengan benar. Guru dapat memiliki lebih dari satu peran, misalnya sebagai Pengajar dan Wali Kelas.\n\nCatatan penting:\n1. Username dipakai untuk login guru.\n2. Password awal dapat diganti guru setelah login.\n3. NUPTK, email, nomor HP, tanggal lahir, dan alamat bersifat data profil bila tersedia.\n4. Satu guru dapat mengajar beberapa kelas atau mapel sesuai penugasan.\n5. Perubahan peran guru memengaruhi menu yang dapat dibuka guru.",
            'keywords' => ['data guru', 'pengajar', 'wali kelas', 'username', 'login', 'nuptk', 'email', 'nomor hp'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Data Siswa dan import Excel',
            'answer' => "Admin dapat menambahkan siswa secara manual atau melalui import Excel. Untuk import, unduh template terbaru dari aplikasi, isi data sesuai kolom, lalu upload kembali.\n\nPerhatikan:\n1. NIS dan NISN harus unik.\n2. Nama kelas harus sama dengan kelas yang ada di tahun ajaran aktif.\n3. Tanggal lahir gunakan format YYYY-MM-DD, contoh 2017-05-21.\n4. Foto siswa dapat ditambahkan bila tersedia.\n5. Jika import gagal, baca pesan baris yang muncul, perbaiki file, lalu upload ulang.\n6. Jangan mengubah judul kolom pada template.",
            'keywords' => ['data siswa', 'import siswa', 'excel siswa', 'nis', 'nisn', 'foto siswa', 'kelas siswa', 'tanggal lahir'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Mata Pelajaran, Pengajar, dan LM/TP',
            'answer' => "Mata pelajaran menghubungkan kelas, guru pengajar, tahun ajaran, semester, Lingkup Materi, dan Tujuan Pembelajaran.\n\nLangkah-langkah:\n1. Pilih kelas dan nama mata pelajaran.\n2. Pilih guru pengajar yang berwenang.\n3. Tentukan jenis mata pelajaran bila tersedia, misalnya wajib atau muatan lokal.\n4. Lengkapi Lingkup Materi dan Tujuan Pembelajaran.\n5. Jika ada kelas paralel dengan mapel yang sama, gunakan opsi salin LM/TP dari mata pelajaran yang sama di kelas paralel.\n6. Cek status TP lengkap atau belum lengkap sebelum guru mengunduh template nilai.",
            'keywords' => ['mata pelajaran', 'guru pengajar', 'mapel wajib', 'muatan lokal', 'lm', 'tp', 'salin lm tp', 'kelas paralel'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Template Rapor UTS dan UAS',
            'answer' => "Template rapor menentukan bentuk DOCX dan PDF yang dihasilkan. UTS dan UAS memakai template berbeda.\n\nAturan penting:\n1. Template UTS harus memuat teks RAPOR TENGAH SEMESTER.\n2. Template UAS tidak boleh memuat teks RAPOR TENGAH SEMESTER.\n3. Template Global berlaku untuk semua kelas yang tidak memiliki template khusus.\n4. Template khusus kelas dipakai lebih dulu, lalu sistem memakai Template Global bila tidak ada yang khusus.\n5. Beberapa template aktif diperbolehkan.\n6. Jika tidak ada template aktif yang sesuai, rapor tidak bisa dibuat dan Wali Kelas akan melihat pesan untuk menghubungi admin.",
            'keywords' => ['template rapor', 'format rapor', 'uts', 'uas', 'rapor tengah semester', 'global template', 'template khusus kelas'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Jenis Rapor yang Dibuka untuk Wali Kelas',
            'answer' => "Pilihan ini menentukan jenis rapor yang dapat diakses Wali Kelas saat ini. Ini bukan pilihan semester.\n\nContoh:\n- Semester aktif: Ganjil.\n- Jenis rapor dibuka: UAS.\n- Artinya Wali Kelas dapat mengakses UAS semester Ganjil.\n\nJika Admin membuka UTS, Wali Kelas hanya dapat mengakses UTS. Jika Admin membuka UAS, Wali Kelas dapat mengakses UAS. UTS dan UAS tetap berada dalam semester aktif.",
            'keywords' => ['jenis rapor dibuka', 'wali kelas', 'uts', 'uas', 'semester aktif', 'bukan semester'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Notifikasi untuk Admin',
            'answer' => "Gunakan tombol Informasi untuk membaca notifikasi. Di panel Informasi, Admin dapat memfilter notifikasi, menandai semua sebagai dibaca, dan menghapus semua notifikasi milik sendiri.\n\nNotifikasi nilai dibuat per kelas dan mata pelajaran, bukan per siswa, agar tidak menumpuk terlalu banyak.",
            'keywords' => ['notifikasi', 'informasi', 'tandai semua dibaca', 'hapus semua', 'nilai per kelas', 'nilai per mapel'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Ekstrakurikuler dan Prestasi',
            'answer' => "Data ekstrakurikuler dan prestasi dapat masuk ke rapor sesuai bagian yang tersedia pada template. Tambahkan atau edit data dengan teliti, lalu gunakan search dan filter untuk mencari pembina, siswa, kelas, jenis prestasi, atau tanggal kegiatan.",
            'keywords' => ['ekstrakurikuler', 'prestasi', 'pembina', 'rapor', 'search', 'filter'],
        ],
        [
            'category' => 'Admin',
            'question' => 'Troubleshooting Admin',
            'answer' => "Masalah yang sering terjadi:\n1. Template ditolak: pastikan jenis UTS atau UAS sesuai isi file.\n2. Tidak ada template aktif: aktifkan template yang sesuai atau upload template baru.\n3. PDF belum tersedia: tunggu proses selesai, lalu cek ulang.\n4. Import siswa gagal: baca pesan baris, perbaiki file, dan upload ulang.\n5. Role guru tidak muncul: cek penugasan guru pada kelas atau mata pelajaran.\n6. Data tidak muncul: cek apakah search atau filter masih aktif, lalu reset filter bila perlu.",
            'keywords' => ['troubleshooting admin', 'template ditolak', 'pdf belum tersedia', 'import siswa gagal', 'role guru', 'filter aktif'],
        ],

        [
            'category' => 'Pengajar',
            'question' => 'Memilih role Pengajar',
            'answer' => "Jika akun guru memiliki beberapa peran, pilih role Pengajar untuk mengisi nilai dan mengelola pembelajaran. Role yang dipilih menentukan menu yang tampil. Jika halaman menolak akses, kembali ke dashboard lalu pilih role yang sesuai.",
            'keywords' => ['role pengajar', 'pilih role', 'akses pengajar', 'menu pengajar'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Data Pembelajaran Pengajar',
            'answer' => "Data Pembelajaran menampilkan kelas dan mata pelajaran yang diajar. Status siap atau belum siap membantu guru mengetahui apakah LM/TP sudah lengkap.\n\nJika muncul ikon peringatan, buka detail peringatan untuk melihat Lingkup Materi mana yang belum memiliki Tujuan Pembelajaran.",
            'keywords' => ['data pembelajaran', 'status tp', 'tp belum lengkap', 'peringatan', 'kelas mapel'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Lingkup Materi dan Tujuan Pembelajaran Pengajar',
            'answer' => "Lingkup Materi adalah kelompok atau topik materi. Tujuan Pembelajaran adalah tujuan belajar di dalam Lingkup Materi.\n\nAgar template nilai siap, setiap Lingkup Materi perlu memiliki Tujuan Pembelajaran. Jika tersedia kelas paralel dengan mata pelajaran yang sama, gunakan opsi salin LM/TP agar guru tidak perlu mengetik ulang.",
            'keywords' => ['lingkup materi', 'tujuan pembelajaran', 'lm', 'tp', 'salin lm tp', 'kelas paralel'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Input Nilai Manual',
            'answer' => "Isi nilai sesuai komponen yang tersedia pada halaman Input Nilai.\n\nPanduan singkat:\n1. Isi nilai TP bila ada kolom TP.\n2. Isi nilai LM bila tersedia.\n3. Isi nilai tes dan non-tes sesuai kebutuhan.\n4. Nilai Sumatif Akhir Semester boleh dikosongkan jika belum dinilai.\n5. Nilai kosong tidak dihitung sebagai 0.\n6. Nilai 0 tetap dianggap nilai asli bila memang diberikan.\n7. Klik Simpan agar nilai benar-benar tersimpan.",
            'keywords' => ['input nilai', 'nilai tp', 'nilai lm', 'nilai tes', 'nilai non tes', 'sumatif akhir semester', 'nilai kosong', 'nilai 0'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Download Template Nilai Excel',
            'answer' => "Guru dapat mengunduh template nilai satu pembelajaran atau semua template siap.\n\nCatatan penting:\n1. Template hanya tersedia jika LM/TP sudah lengkap.\n2. Download Template Nilai digunakan untuk memilih satu kelas dan mapel.\n3. Download Semua Template Siap digunakan jika semua pembelajaran sudah lengkap.\n4. Jangan mengubah kolom tersembunyi, judul kolom, atau struktur file.",
            'keywords' => ['download template nilai', 'template excel nilai', 'download semua template siap', 'lm tp lengkap', 'kolom tersembunyi'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Upload Nilai Excel dan preview',
            'answer' => "Upload Excel tidak langsung menyimpan semua nilai tanpa pengecekan.\n\nUntuk satu template:\n1. Buka Input Nilai.\n2. Klik Import Nilai Excel.\n3. Upload file template yang sesuai.\n4. Periksa nilai yang masuk ke form.\n5. Klik Simpan.\n\nUntuk Upload Semua Nilai Excel:\n1. Upload workbook multi-sheet dari Download Semua Template Siap.\n2. Periksa preview setiap sheet.\n3. Klik Simpan & Lanjut untuk menyimpan sheet yang sedang dibuka.\n4. Sheet yang belum disimpan tidak masuk ke nilai.",
            'keywords' => ['upload nilai excel', 'preview excel', 'multi sheet', 'simpan per sheet', 'sheet belum disimpan', 'import nilai'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Preview Nilai Pengajar',
            'answer' => "Ikon mata membuka preview nilai untuk kelas dan mata pelajaran tersebut. Preview membantu guru mengecek hasil sebelum atau setelah menyimpan. Jenis rapor yang dibuka Admin dapat memengaruhi konteks rapor yang sedang dipakai.",
            'keywords' => ['preview nilai', 'ikon mata', 'jenis rapor dibuka', 'cek nilai'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Error umum saat upload nilai Excel',
            'answer' => "Beberapa penyebab upload gagal:\n1. File bukan template dari aplikasi.\n2. Kelas atau mata pelajaran tidak cocok dengan halaman yang dibuka.\n3. Nilai di luar 0 sampai 100.\n4. Sheet yang dipakai salah.\n5. LM/TP belum lengkap.\n6. Data siswa di file tidak cocok dengan kelas.\n\nGunakan template terbaru dari tombol Download Template Nilai atau Download Semua Template Siap.",
            'keywords' => ['error excel nilai', 'file bukan template', 'kelas tidak cocok', 'mapel tidak cocok', 'nilai 0 100', 'sheet salah'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Troubleshooting Pengajar',
            'answer' => "Jika ada kendala, cek hal berikut:\n1. Tombol download template tidak aktif: LM/TP belum lengkap.\n2. Upload Excel gagal: pastikan file berasal dari aplikasi dan sesuai kelas/mapel.\n3. Nilai tidak berubah: pastikan sudah klik Simpan.\n4. Akses ditolak: pilih role Pengajar.\n5. Data tampak hilang: reset search atau filter.",
            'keywords' => ['troubleshooting pengajar', 'download tidak aktif', 'upload gagal', 'nilai tidak berubah', 'role salah', 'filter aktif'],
        ],

        [
            'category' => 'Wali Kelas',
            'question' => 'Memilih role Wali Kelas',
            'answer' => "Jika guru memiliki lebih dari satu peran, pilih role Wali Kelas untuk membuka menu wali. Wali Kelas hanya melihat kelas yang menjadi tanggung jawabnya pada tahun ajaran dan semester aktif.",
            'keywords' => ['role wali kelas', 'pilih role', 'akses wali', 'kelas tanggung jawab'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Daftar Siswa Wali Kelas',
            'answer' => "Daftar Siswa menampilkan siswa pada kelas yang menjadi tanggung jawab Wali Kelas. Gunakan search dan filter untuk mencari nama, NIS, NISN, jenis kelamin, atau data lain yang tersedia. Dari daftar ini, Wali dapat membuka catatan, absensi, dan rapor sesuai menu.",
            'keywords' => ['daftar siswa wali', 'search siswa', 'filter siswa', 'nis', 'nisn', 'catatan', 'absensi'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Mengisi Absensi Wali Kelas',
            'answer' => "Isi jumlah sakit, izin, dan tanpa keterangan sesuai data siswa. Data absensi masuk ke rapor, jadi simpan setelah mengubah nilai absensi. Jika siswa tidak muncul, cek tahun ajaran, semester, dan kelas yang sedang aktif.",
            'keywords' => ['absensi', 'sakit', 'izin', 'tanpa keterangan', 'simpan absensi', 'rapor'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Mengisi Catatan Siswa',
            'answer' => "Catatan Siswa adalah catatan umum Wali Kelas untuk siswa dan dapat masuk ke rapor. Tulis catatan dengan bahasa yang jelas dan singkat. Jika tersedia batas karakter, ikuti batas yang tampil pada halaman. Tombol Simpan Catatan berada di kanan atas halaman.",
            'keywords' => ['catatan siswa', 'catatan umum', 'simpan catatan', 'batas karakter', 'rapor'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Rapor UTS dan UAS untuk Wali Kelas',
            'answer' => "Wali Kelas hanya dapat mengakses jenis rapor yang sedang dibuka Admin. UTS dan UAS adalah jenis rapor di semester aktif.\n\nJika UAS belum dibuka Admin, tombol atau akses UAS tidak tersedia. Jika template belum ada, sistem menampilkan pesan agar Wali menghubungi Admin.",
            'keywords' => ['rapor uts', 'rapor uas', 'jenis rapor dibuka', 'admin membuka rapor', 'template belum ada'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'DOCX dan PDF Rapor',
            'answer' => "DOCX rapor dapat diunduh jika data dan template tersedia. PDF dapat membutuhkan waktu karena disiapkan oleh sistem di proses latar belakang. Jika status PDF masih disiapkan, tunggu beberapa saat lalu cek ulang. Jika terlalu lama, hubungi Admin.",
            'keywords' => ['docx rapor', 'pdf rapor', 'pdf disiapkan', 'background', 'unduh rapor'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Notifikasi untuk Wali Kelas',
            'answer' => "Gunakan tombol Informasi untuk membaca notifikasi. Di panel Informasi, Wali Kelas dapat memfilter notifikasi, menandai semua sebagai dibaca, dan menghapus notifikasi milik sendiri bila fitur tersedia.",
            'keywords' => ['notifikasi wali', 'informasi', 'filter notifikasi', 'tandai semua dibaca', 'hapus notifikasi'],
        ],
        [
            'category' => 'Wali Kelas',
            'question' => 'Troubleshooting Wali Kelas',
            'answer' => "Jika ada kendala, cek hal berikut:\n1. Rapor belum muncul: Admin mungkin belum membuka jenis rapor atau template belum aktif.\n2. PDF belum siap: tunggu proses latar belakang selesai.\n3. Siswa tidak muncul: cek kelas, tahun ajaran, semester, dan filter.\n4. Catatan atau absensi belum tersimpan: pastikan sudah klik Simpan.\n5. Tidak punya akses ke siswa tertentu: siswa mungkin bukan bagian dari kelas wali pada konteks aktif.",
            'keywords' => ['troubleshooting wali', 'rapor belum muncul', 'pdf belum siap', 'siswa tidak muncul', 'catatan belum tersimpan', 'akses siswa'],
        ],

        [
            'category' => 'FAQ',
            'question' => 'Apa bedanya UTS dan UAS di aplikasi?',
            'answer' => 'UTS dan UAS adalah jenis rapor di semester aktif. UAS bukan berarti semester genap. Contoh: jika semester aktif Ganjil dan Admin membuka UAS, maka yang dibuka adalah UAS semester Ganjil.',
            'keywords' => ['beda uts uas', 'semester aktif', 'uas bukan genap'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa saya tidak bisa melihat UAS?',
            'answer' => 'Biasanya karena Admin belum membuka jenis rapor UAS untuk Wali Kelas. Hubungi Admin jika UAS memang sudah waktunya dibuka.',
            'keywords' => ['tidak bisa melihat uas', 'uas belum dibuka', 'admin membuka uas'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa template rapor tidak bisa digunakan?',
            'answer' => 'Template mungkin belum aktif, salah jenis, tidak cocok dengan kelas, atau belum ada template Global sebagai fallback. Admin perlu mengecek Format Rapor.',
            'keywords' => ['template rapor tidak bisa', 'template belum aktif', 'salah jenis', 'global template'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa upload template UTS ditolak?',
            'answer' => 'Template UTS harus memuat teks RAPOR TENGAH SEMESTER. Jika teks itu tidak ada, sistem menolak file agar Admin tidak salah memilih template.',
            'keywords' => ['upload template uts ditolak', 'rapor tengah semester', 'template uts'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa upload template UAS ditolak?',
            'answer' => 'Jika file memuat teks RAPOR TENGAH SEMESTER, sistem menganggap file tersebut adalah template UTS. Pilih jenis UTS atau upload file UAS yang benar.',
            'keywords' => ['upload template uas ditolak', 'rapor tengah semester', 'template uas'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa nilai tidak muncul di rapor?',
            'answer' => 'Nilai mungkin belum disimpan, LM/TP belum lengkap, siswa tidak berada pada kelas dan semester yang benar, atau jenis rapor belum dibuka Admin. Cek Input Nilai dan konteks tahun ajaran aktif.',
            'keywords' => ['nilai tidak muncul', 'nilai belum disimpan', 'lm tp', 'rapor belum dibuka'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa tombol download template nilai tidak aktif?',
            'answer' => 'Tombol biasanya tidak aktif karena Lingkup Materi atau Tujuan Pembelajaran belum lengkap untuk pembelajaran tersebut. Lengkapi TP terlebih dahulu.',
            'keywords' => ['download template nilai tidak aktif', 'lm tp belum lengkap', 'lengkapi tp'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa PDF lama disiapkan?',
            'answer' => 'PDF dibuat melalui proses latar belakang. Jika banyak antrian atau file rapor besar, proses bisa lebih lama. Tunggu beberapa saat lalu cek ulang.',
            'keywords' => ['pdf lama', 'pdf disiapkan', 'antrian pdf', 'background'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Kenapa data tidak terlihat setelah search atau filter?',
            'answer' => 'Search atau filter mungkin masih aktif. Hapus kata pencarian atau klik Reset Filter untuk melihat semua data kembali.',
            'keywords' => ['data tidak terlihat', 'search aktif', 'filter aktif', 'reset filter'],
        ],
        [
            'category' => 'FAQ',
            'question' => 'Apakah menghapus notifikasi menghapus data?',
            'answer' => 'Tidak. Menghapus notifikasi hanya menghapus pemberitahuan milik user tersebut. Data nilai, rapor, siswa, kelas, dan guru tidak ikut terhapus.',
            'keywords' => ['hapus notifikasi', 'hapus data', 'notifikasi saja'],
        ],

        [
            'category' => 'Masalah Umum',
            'question' => 'Langkah cepat jika data tidak sesuai',
            'answer' => "Cek hal-hal ini sebelum melapor:\n1. Tahun ajaran aktif sudah benar.\n2. Semester aktif sudah benar.\n3. Search atau filter tidak sedang membatasi daftar.\n4. Role yang dipilih sudah sesuai.\n5. Data sudah disimpan.\n6. Jika terkait rapor, cek template aktif dan jenis rapor yang dibuka.",
            'keywords' => ['data tidak sesuai', 'cek cepat', 'tahun ajaran', 'semester', 'role', 'filter'],
        ],
        [
            'category' => 'Masalah Umum',
            'question' => 'Kapan harus menghubungi Admin?',
            'answer' => 'Hubungi Admin jika akses role tidak tersedia, siswa tidak ada di kelas yang benar, template rapor belum aktif, jenis rapor belum dibuka, atau PDF terlalu lama disiapkan setelah beberapa kali dicek ulang.',
            'keywords' => ['hubungi admin', 'akses role', 'siswa tidak ada', 'template belum aktif', 'pdf lama'],
        ],
    ],
];
