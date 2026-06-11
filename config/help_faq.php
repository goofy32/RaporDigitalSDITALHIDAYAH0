<?php

return [
    'admin' => [
        [
            'category' => 'Import Siswa',
            'question' => 'Cara import siswa dari Excel',
            'answer' => 'Buka Admin > Siswa, unduh template import, isi data siswa, lalu upload kembali. Pastikan tahun ajaran aktif sudah dipilih dan semua kelas pada file sudah dibuat di sistem.',
            'keywords' => ['siswa', 'excel', 'import', 'upload', 'template'],
        ],
        [
            'category' => 'Import Siswa',
            'question' => 'Kenapa import siswa gagal karena kelas tidak ditemukan',
            'answer' => 'Import siswa tidak membuat kelas otomatis. Kolom kelas harus cocok dengan salah satu kelas pada tahun ajaran aktif. Buat kelas terlebih dahulu di menu Kelas, lalu ulangi import.',
            'keywords' => ['kelas', 'tidak ditemukan', 'import', 'siswa'],
        ],
        [
            'category' => 'Kelas',
            'question' => 'Cara membuat kelas',
            'answer' => 'Buka Admin > Kelas > Tambah Data. Isi nomor kelas, nama kelas, dan tahun ajaran yang sesuai. Wali kelas dikelola dari menu Pengajar, bukan dari form kelas.',
            'keywords' => ['kelas', 'buat kelas', 'rombel'],
        ],
        [
            'category' => 'Pengajar',
            'question' => 'Cara membuat guru dan wali kelas',
            'answer' => 'Buka Admin > Pengajar > Tambah Data. Isi identitas guru, pilih jabatan, lalu atur kelas wali atau kelas mengajar sesuai tanggung jawab guru.',
            'keywords' => ['guru', 'wali kelas', 'pengajar'],
        ],
        [
            'category' => 'Mata Pelajaran',
            'question' => 'Cara mengatur mata pelajaran',
            'answer' => 'Buka Admin > Pelajaran. Pilih kelas, jenis mata pelajaran, dan guru pengampu. Mata pelajaran wajib reguler hanya memakai wali kelas, sedangkan muatan lokal/spesialis memakai guru non-wali yang ditugaskan pada kelas tersebut.',
            'keywords' => ['mapel', 'mata pelajaran', 'guru', 'muatan lokal'],
        ],
        [
            'category' => 'Tahun Ajaran',
            'question' => 'Cara mengubah tahun ajaran atau semester',
            'answer' => 'Gunakan menu Tahun Ajaran untuk mengaktifkan tahun ajaran atau memilih semester. Pastikan konteks tahun ajaran aktif sudah benar sebelum mengelola data.',
            'keywords' => ['tahun ajaran', 'semester', 'aktif'],
        ],
        [
            'category' => 'Template Rapor',
            'question' => 'Cara mengelola template rapor',
            'answer' => 'Buka Admin > Format Rapor. Upload template sesuai jenis rapor dan kelas/tahun ajaran, lalu aktifkan template yang akan digunakan.',
            'keywords' => ['template', 'rapor', 'docx', 'pdf'],
        ],
        [
            'category' => 'Data Siswa',
            'question' => 'Kenapa data siswa tidak muncul',
            'answer' => 'Periksa tahun ajaran dan semester aktif. Data siswa ditampilkan berdasarkan enrollment pada konteks tahun ajaran/semester tersebut.',
            'keywords' => ['siswa', 'tidak muncul', 'enrollment', 'semester'],
        ],
    ],

    'pengajar' => [
        [
            'category' => 'Nilai',
            'question' => 'Cara input nilai',
            'answer' => 'Buka Pengajar > Input Nilai, pilih mata pelajaran, lalu isi nilai pada siswa yang muncul. Setelah lengkap, simpan nilai.',
            'keywords' => ['nilai', 'input nilai', 'simpan'],
        ],
        [
            'category' => 'Nilai',
            'question' => 'Kenapa siswa tidak muncul di input nilai',
            'answer' => 'Siswa muncul berdasarkan enrollment kelas pada tahun ajaran dan semester mata pelajaran. Hubungi admin jika siswa belum masuk kelas yang sesuai.',
            'keywords' => ['siswa', 'tidak muncul', 'input nilai', 'kelas'],
        ],
        [
            'category' => 'TP dan LM',
            'question' => 'Cara membuat atau mengelola Lingkup Materi dan TP',
            'answer' => 'Buka menu Mata Pelajaran, pilih mapel yang diajar, lalu kelola Lingkup Materi dan Tujuan Pembelajaran sebelum mengisi nilai.',
            'keywords' => ['tp', 'lm', 'lingkup materi', 'tujuan pembelajaran'],
        ],
        [
            'category' => 'Nilai',
            'question' => 'Kenapa nilai tidak bisa disimpan',
            'answer' => 'Pastikan siswa berasal dari kelas mata pelajaran yang benar, semua isian wajib valid, dan tahun ajaran/semester aktif sesuai.',
            'keywords' => ['nilai', 'gagal simpan', 'validasi'],
        ],
        [
            'category' => 'Progress',
            'question' => 'Cara melihat progress nilai',
            'answer' => 'Progress nilai dapat dilihat di dashboard pengajar dan pada daftar mata pelajaran. Progress mengikuti konteks tahun ajaran dan semester aktif.',
            'keywords' => ['progress', 'dashboard', 'nilai'],
        ],
        [
            'category' => 'Progress',
            'question' => 'Arti progress nilai belum lengkap',
            'answer' => 'Progress belum lengkap berarti masih ada siswa, TP/LM, atau nilai akhir rapor yang belum memenuhi syarat kelengkapan.',
            'keywords' => ['progress', 'belum lengkap', 'nilai akhir'],
        ],
        [
            'category' => 'Role',
            'question' => 'Cara ganti role pengajar/wali jika guru punya dua peran',
            'answer' => 'Gunakan menu profil/dropdown akun untuk berpindah role. Role hanya tersedia jika guru memiliki assignment yang sesuai pada tahun ajaran aktif.',
            'keywords' => ['role', 'ganti role', 'wali kelas', 'pengajar'],
        ],
    ],

    'wali_kelas' => [
        [
            'category' => 'Siswa',
            'question' => 'Cara melihat daftar siswa',
            'answer' => 'Buka Wali Kelas > Siswa. Daftar siswa mengikuti enrollment kelas wali pada tahun ajaran dan semester aktif.',
            'keywords' => ['siswa', 'daftar siswa', 'kelas wali'],
        ],
        [
            'category' => 'Absensi',
            'question' => 'Cara mengisi absensi',
            'answer' => 'Buka Wali Kelas > Absensi, isi jumlah sakit, izin, dan tanpa keterangan untuk setiap siswa, lalu simpan.',
            'keywords' => ['absensi', 'sakit', 'izin', 'alpha'],
        ],
        [
            'category' => 'Catatan',
            'question' => 'Cara mengisi catatan siswa',
            'answer' => 'Gunakan menu Catatan untuk mengisi catatan siswa atau catatan mata pelajaran. Catatan tersimpan sesuai tahun ajaran dan semester aktif.',
            'keywords' => ['catatan', 'siswa', 'mata pelajaran'],
        ],
        [
            'category' => 'Ekstrakurikuler',
            'question' => 'Cara mengisi ekstrakurikuler',
            'answer' => 'Buka Wali Kelas > Ekstrakurikuler, pilih kegiatan, isi nilai atau deskripsi, lalu simpan untuk semester yang sedang aktif.',
            'keywords' => ['ekstrakurikuler', 'nilai ekstra'],
        ],
        [
            'category' => 'Rapor',
            'question' => 'Cara preview rapor',
            'answer' => 'Buka Wali Kelas > Rapor, pilih siswa dan jenis rapor, lalu gunakan preview untuk memeriksa data sebelum download.',
            'keywords' => ['rapor', 'preview', 'download'],
        ],
        [
            'category' => 'Rapor',
            'question' => 'Kenapa PDF atau DOCX rapor belum tersedia',
            'answer' => 'Pastikan template rapor aktif tersedia untuk kelas, tahun ajaran, dan jenis rapor yang dipilih. Untuk PDF, server juga perlu mendukung konversi dokumen.',
            'keywords' => ['pdf', 'docx', 'template', 'rapor'],
        ],
        [
            'category' => 'Siswa',
            'question' => 'Kenapa siswa tidak muncul di wali kelas',
            'answer' => 'Siswa harus memiliki enrollment pada kelas wali untuk tahun ajaran dan semester aktif. Hubungi admin untuk memeriksa data kelas siswa.',
            'keywords' => ['siswa', 'tidak muncul', 'wali kelas'],
        ],
        [
            'category' => 'Rapor',
            'question' => 'Kenapa rapor belum lengkap',
            'answer' => 'Rapor belum lengkap jika masih ada nilai, absensi, catatan, capaian, ekstrakurikuler, atau template yang belum tersedia untuk semester aktif.',
            'keywords' => ['rapor', 'belum lengkap', 'nilai', 'absensi'],
        ],
    ],
];
