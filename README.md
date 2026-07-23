# Rapor Digital SDIT Al-Hidayah

## 1. Judul Proyek

**Rapor Digital SDIT Al-Hidayah** adalah aplikasi web Laravel untuk membantu pengelolaan data akademik, input nilai, capaian kompetensi, dan pembuatan rapor siswa SDIT Al-Hidayah.

## 2. Ringkasan

Aplikasi ini menghubungkan tiga alur utama sekolah:

- Admin mengelola data dasar sekolah, akun, kelas, mata pelajaran, tahun ajaran, template rapor, pengaturan nilai, audit, notifikasi, dan Recycle Bin.
- Pengajar mengelola Lingkup Materi, Tujuan Pembelajaran, input nilai, import nilai Excel, dan preview nilai.
- Wali Kelas mengelola data siswa kelasnya, absensi, catatan, capaian kompetensi, dan pembuatan rapor DOCX/PDF sesuai periode yang dibuka Admin.

Repository ini sedang aktif dikembangkan pada branch `develop`. Gunakan staging dan test sebelum perubahan dipakai untuk data sekolah nyata.

## 3. Status Proyek

Status saat ini: **development/staging aktif**.

Catatan penting:

- Branch kerja utama di repository ini adalah `develop`.
- Fitur inti sudah memiliki banyak feature test, tetapi dokumentasi ini bukan jaminan production-ready.
- Monitoring route, query, queue, PDF, dan request lambat di staging tetap diperlukan.
- Jangan menaruh data siswa asli, credential, atau dump database ke repository.

## 4. Fitur Utama

- Autentikasi Admin dan Guru.
- Role Guru sebagai **Pengajar**, **Wali Kelas**, atau keduanya.
- Role switching Guru melalui POST + CSRF.
- Pengelolaan siswa, guru/pengajar, kelas, mata pelajaran, KKM, dan bobot nilai.
- Tahun ajaran, semester aktif, snapshot semester, dan alur kenaikan kelas.
- Lingkup Materi dan Tujuan Pembelajaran.
- Input nilai manual dan import nilai Excel untuk Pengajar.
- Capaian Kompetensi Wali Kelas, termasuk frasa default dan template rentang.
- Absensi, catatan siswa, catatan mata pelajaran, ekstrakurikuler, dan prestasi.
- Template rapor DOCX, mapping placeholder, preview, riwayat rapor, dan download DOCX.
- Export PDF melalui konversi LibreOffice dan queue background.
- Recycle Bin dengan restore dan permanent delete.
- Audit log dan notifikasi.
- Help Center/FAQ per role.
- Gemini chat bila `GEMINI_API_KEY` dan konfigurasi terkait tersedia.
- Import siswa melalui Excel dan generator template import siswa.

## 5. Role dan Hak Akses

| Role | Ringkasan akses |
| --- | --- |
| Admin | Mengatur data master, pengajar, kelas, siswa, mata pelajaran, tahun ajaran, template rapor, KKM/bobot, audit log, notifikasi, Recycle Bin, dan pengaturan sekolah. |
| Pengajar | Mengelola mata pelajaran yang diampu, Lingkup Materi, Tujuan Pembelajaran, input nilai, import/preview nilai, dan dashboard progres. |
| Wali Kelas | Mengelola siswa kelasnya, absensi, catatan, capaian kompetensi, ekstrakurikuler, dashboard progres, serta pembuatan/unduhan rapor. |

Guru yang memiliki dua akses dapat berpindah role dari UI. Role aktif disimpan di session dan state cache agar navigasi Pengajar/Wali tetap konsisten.

## 6. Alur Aplikasi Secara Singkat

1. Admin menyiapkan profil sekolah, tahun ajaran, semester, kelas, guru, mata pelajaran, dan penugasan.
2. Admin atau command yang sesuai menyiapkan template rapor dan placeholder.
3. Pengajar membuat atau melengkapi Lingkup Materi dan Tujuan Pembelajaran.
4. Pengajar menginput nilai manual atau melalui Excel.
5. Wali Kelas melengkapi absensi, catatan, capaian kompetensi, dan data pendukung.
6. Admin membuka periode rapor yang relevan.
7. Wali Kelas melakukan preview, membuat DOCX, dan meminta PDF bila diperlukan.
8. Worker queue memproses PDF, lalu file dapat diunduh melalui akses yang terotorisasi.

## 7. Teknologi

Sumber utama: `composer.json`, `package.json`, dan konfigurasi Laravel.

- Laravel 11 (`laravel/framework` ^11.9; `php artisan --version` saat audit: Laravel 11.54.0).
- PHP ^8.2, dengan platform composer dikunci ke PHP 8.2.31.
- MySQL/MariaDB sebagai database aplikasi utama.
- Database cache/session/queue tersedia sebagai default konfigurasi repository.
- Redis tersedia di konfigurasi Laravel dan dapat dipakai untuk cache/session/queue jika environment deployment mengaktifkannya.
- Database queue untuk job umum dan PDF.
- Supervisor direkomendasikan di server untuk menjaga worker queue tetap berjalan.
- Node.js dan npm mengikuti `package.json` (`node` v22.12.0, `npm` 10.9.0).
- Vite, Tailwind CSS, Alpine.js, Turbo, Flowbite.
- PhpSpreadsheet/Maatwebsite Excel untuk import/export Excel.
- PHPWord untuk DOCX.
- LibreOffice untuk konversi DOCX ke PDF.
- PHPUnit 11 untuk test.

## 8. Persyaratan Lokal

Minimal lokal:

- PHP 8.2 atau lebih baru dengan extension umum Laravel dan `pdo_mysql`.
- Composer.
- MySQL atau MariaDB.
- Node.js dan npm sesuai `package.json`.
- Git.
- LibreOffice jika ingin menguji PDF.

Opsional:

- Redis untuk simulasi konfigurasi cache/session/queue staging.
- SQLite extension hanya untuk menjalankan test yang memakai database in-memory; ini bukan database utama aplikasi.

## 9. Instalasi Lokal

Contoh alur lokal:

```bash
git clone https://github.com/goofy32/RaporDigitalSDITALHIDAYAH0.git
cd RaporDigitalSDITALHIDAYAH0
git checkout develop

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Di Windows PowerShell, salin `.env.example` dapat dilakukan dengan:

```powershell
Copy-Item .env.example .env
```

Buat database MySQL/MariaDB kosong, lalu sesuaikan `.env`.

## 10. Konfigurasi Environment

Jangan commit `.env`. Gunakan `.env.example` sebagai daftar variabel yang perlu diisi.

Variabel penting:

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nama_database_lokal
DB_USERNAME=user_lokal
DB_PASSWORD=

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

LIBREOFFICE_PATH=/usr/bin/soffice
PDF_CONVERSION_ENABLED=true
```

Untuk Windows, `LIBREOFFICE_PATH` biasanya mengarah ke:

```ini
LIBREOFFICE_PATH="C:\\Program Files\\LibreOffice\\program\\soffice.exe"
```

Jika memakai Redis, sesuaikan `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, dan variabel `REDIS_*` sesuai environment. Jangan menyalin credential server ke README atau issue publik.

## 11. Database dan Migration

Jalankan migration setelah `.env` benar dan database kosong tersedia:

```bash
php artisan migrate
php artisan storage:link
```

Untuk setup storage yang lebih lengkap, repository juga memiliki command:

```bash
php artisan app:setup-storage
```

Jangan menjalankan `migrate:fresh` pada database berisi data nyata karena akan menghapus tabel.

## 12. Data Awal/Demo

Peringatan seeder:

- Jangan menjalankan `php artisan db:seed` sembarangan.
- `DatabaseSeeder` memanggil `AdminSeeder` dan `ReportPlaceholdersSeeder`.
- Beberapa seeder lama atau seeder demo/test dapat membuat akun dummy, melakukan truncate, atau hanya aman untuk local/testing/demo.
- `TestDataSeeder` berisi operasi truncate jika dipanggil langsung.
- `DemoSemesterGanjilSeeder` dibatasi untuk environment local/testing/demo.
- Command generator dummy seperti `initial-data:*` dan `staging:*` harus dibaca dulu sebelum dipakai, terutama pada database yang berisi data nyata.

Gunakan seeder atau command demo hanya setelah review dan hanya di environment yang tepat. Jangan mendokumentasikan atau membagikan password demo sebagai credential produksi.

## 13. Menjalankan Aplikasi

Mode development:

```bash
php artisan serve
npm run dev
```

Mode build lokal:

```bash
npm run build
php artisan serve
```

Aplikasi biasanya tersedia di `http://127.0.0.1:8000` ketika memakai `php artisan serve`.

## 14. Queue dan Worker

Fitur PDF dan beberapa proses background membutuhkan queue.

Konfigurasi default repository memakai database queue. Jalankan worker lokal:

```bash
php artisan queue:work database --queue=pdf,pdf-warm,default --sleep=1 --tries=3 --timeout=300
```

Di staging/production, gunakan Supervisor atau process manager sejenis agar worker otomatis hidup kembali.

Command yang berguna:

```bash
php artisan queue:failed
php artisan queue:restart
```

## 15. Build Frontend

Frontend memakai Vite.

```bash
npm install
npm run dev
npm run build
```

Gunakan `npm run build` sebelum deploy jika source JS/CSS berubah. Jangan commit artefak sementara yang tidak dilacak repository.

## 16. LibreOffice dan Export PDF

DOCX rapor dibuat dari template. PDF dibuat dengan mengonversi DOCX melalui LibreOffice, lalu dapat diproses melalui queue.

Cek LibreOffice:

```bash
php artisan check:libreoffice
```

Hal yang perlu dipastikan:

- `LIBREOFFICE_PATH` benar.
- User web server/worker dapat menjalankan LibreOffice.
- Worker queue berjalan untuk antrean `pdf`, `pdf-warm`, dan `default`.
- PDF lama dapat perlu dibuat ulang setelah nilai, capaian, catatan, template, atau tanda tangan berubah.

## 17. Pengujian

Jalankan test dengan PHPUnit:

```bash
php artisan test
```

Jika CLI Windows tidak memuat SQLite extension, gunakan fallback langsung ke PHPUnit:

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --do-not-cache-result
```

Pada beberapa instalasi Windows, nama DLL eksplisit juga bisa diperlukan:

```bash
php -d extension=php_sqlite3.dll -d extension=php_pdo_sqlite.dll vendor/bin/phpunit --do-not-cache-result
```

Kategori test yang tersedia mencakup authorization, Admin hardening, role switch, student import, template import, Recycle Bin, report card, score/progress, enrollment semester, notification, frontend lifecycle contract, Help Center, dan slow request monitor.

Jangan memakai SQLite sebagai database aplikasi utama. SQLite di sini hanya untuk test environment bila dikonfigurasi demikian.

## 18. Aturan Import Excel dan NIS/NISN

Kontrak NIS dan NISN terkini:

- Disimpan sebagai string.
- Hanya digit ASCII `0-9`.
- Panjang 1 sampai 10 digit.
- Leading zero dipertahankan.
- Spasi luar di-trim.
- Spasi internal, simbol, huruf, decimal non-integer, nilai lebih dari 10 digit, formula Excel, tanggal Excel, boolean cell, dan error cell ditolak.
- Nilai numeric integer Excel 1-10 digit dapat dinormalisasi menjadi string digit.
- Template Excel siswa memformat kolom NIS dan NISN sebagai Text.

Jangan menulis aturan "harus tepat 10 digit"; kontrak saat ini adalah 1-10 digit.

## 19. Workflow Git

Penjelasan singkat:

- `develop` adalah branch lokal pengembangan yang aktif untuk repository ini.
- `origin` adalah nama remote, bukan branch.
- `origin/develop` adalah remote-tracking branch yang merepresentasikan `develop` di remote `origin`.
- README dan perubahan aplikasi dibuat pada branch lokal `develop`.
- Jangan checkout ke branch bernama `origin`.
- Jangan membuat branch baru hanya untuk patch dokumentasi kecil kecuali memang diminta.

Command umum:

```bash
git status -sb
git pull --ff-only origin develop
git push origin develop
```

Sebelum commit:

```bash
git status --short
git diff --check
git diff -- README.md
```

Untuk patch terarah, jangan gunakan `git add .`. Stage file secara eksplisit:

```bash
git add README.md
```

## 20. Deployment Staging Secara Ringkas

Jangan menjalankan deploy dari README ini tanpa prosedur server yang sudah disepakati.

Preflight read-only sebelum deployment:

```bash
git fetch origin
git status --short
git log --oneline HEAD..origin/develop
git diff --name-only HEAD..origin/develop
```

Gunakan diff untuk menentukan langkah kondisional:

- `composer.json` atau `composer.lock` berubah: sinkronkan Composer.
- `package.json` atau `package-lock.json` berubah: sinkronkan dependency Node dengan `npm ci`.
- `resources/js`, `resources/css`, atau konfigurasi Vite berubah: build frontend.
- `database/migrations` berubah: review migration, siapkan backup/rollback plan, lalu migrate bila memang siap.
- Hanya PHP/Blade tanpa dependency atau migration tidak otomatis membutuhkan semua langkah kondisional.

Langkah inti yang umumnya dijalankan:

```bash
php artisan down
sudo supervisorctl stop <nama-worker>:*

git pull --ff-only origin develop

php artisan optimize:clear
php artisan optimize

sudo systemctl restart <php-fpm-service>
sudo supervisorctl start <nama-worker>:*

php artisan up
```

Langkah kondisional:

Composer, hanya bila `composer.json` atau `composer.lock` berubah, folder `vendor` belum tersedia, atau dependency PHP perlu disinkronkan:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Jangan menjalankan `composer update` saat deployment kecuali ada prosedur release khusus yang sudah direview.

Frontend, hanya bila dependency Node atau source frontend berubah:

```bash
npm ci
npm run build
```

Jika dependency Node tidak berubah dan `node_modules` sudah tersedia, cukup jalankan `npm run build` ketika source frontend JS/CSS/Vite berubah. Jangan selalu menjalankan `npm ci` pada setiap deploy.

Migration, hanya bila ada migration baru yang sudah direview dan environment staging/production memang targetnya:

```bash
php artisan migrate --force
```

Jangan menjalankan migration hanya karena melakukan deployment. Jangan menjalankan migration bila diff tidak memuat migration. Jangan menjalankan `migrate:fresh` pada database berisi data. Seeder tidak menjadi bagian deployment otomatis.

Failure safety:

- Pastikan working tree server bersih.
- Aktifkan maintenance mode dan hentikan worker secara terkendali sebelum update.
- Pull fast-forward dari `origin/develop`.
- Jika `git pull`, install dependency, build, migration, atau optimize gagal, jangan langsung menghidupkan aplikasi tanpa memeriksa kondisi.
- Worker dan maintenance mode harus dikembalikan secara sadar setelah masalah selesai.
- Migration tidak boleh dilakukan berdasarkan tebakan.
- Lakukan smoke test login, dashboard, input nilai, rapor, PDF, dan Help Center.

Jangan menaruh IP server, username SSH, password, path private, token, Certbot email, atau isi `.env` di dokumentasi publik.

## 21. Keamanan dan Privasi Data

- Jangan commit `.env`.
- Jangan commit database dump berisi data siswa.
- Jangan membagikan NIS/NISN, nama siswa, credential, session, cookie, CSRF token, atau log mentah.
- Gunakan data dummy untuk demo, screenshot, video, dan test publik.
- Staging yang dapat diakses publik tidak boleh memakai `APP_DEBUG=true`.
- Pastikan permission `storage` dan `bootstrap/cache` benar di server.
- Gunakan authorization dan middleware yang tersedia; jangan bypass route protection.
- Sanitasi log sebelum dibagikan ke pihak luar.

## 22. Known Limitations/Follow-up

- Optimisasi frontend terbaru sudah mengurangi request yang tidak perlu, tetapi monitoring route/query staging masih dibutuhkan untuk menemukan bottleneck backend lain.
- Live-list sudah membatalkan request lama dan mengabaikan stale response.
- Data Settings Admin dimuat hanya ketika modal dibuka, dengan deduplikasi in-flight.
- FAQ Help Center dimuat hanya ketika widget dibuka, dengan deduplikasi in-flight dan validasi payload.
- Turbo lifecycle dibersihkan agar response halaman lama tidak menulis ke DOM baru.
- Delete siswa Admin menangani target stale dengan lebih aman.
- Recycle Bin membedakan target yang terhapus karena cascade dari kegagalan nyata.
- Command dummy/staging tetap harus diaudit sebelum dipakai karena beberapa jalur membuat data langsung tanpa melalui validasi HTTP form.
- Export PDF bergantung pada LibreOffice, queue worker, storage permission, dan ukuran/antrian dokumen.

## 23. Dokumentasi Tambahan

Dokumentasi internal tersedia di folder `docs/`:

- [docs/README.md](docs/README.md)
- [docs/SETUP_LOKAL.md](docs/SETUP_LOKAL.md)
- [docs/DEPLOYMENT_PRODUCTION.md](docs/DEPLOYMENT_PRODUCTION.md)
- [docs/PANDUAN_ADMIN.md](docs/PANDUAN_ADMIN.md)
- [docs/PANDUAN_PENGAJAR.md](docs/PANDUAN_PENGAJAR.md)
- [docs/PANDUAN_WALI_KELAS.md](docs/PANDUAN_WALI_KELAS.md)
- [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md)
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)
- [docs/PERFORMANCE_SLOW_REQUEST_MONITOR.md](docs/PERFORMANCE_SLOW_REQUEST_MONITOR.md)

Jika ada perbedaan antara README ini, folder `docs/`, dan kondisi server sekolah, verifikasi ulang dari kode, konfigurasi environment, dan prosedur operasional terbaru sebelum bertindak.
