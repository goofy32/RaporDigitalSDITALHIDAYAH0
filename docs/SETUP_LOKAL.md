# Setup Lokal Rapor Digital

Panduan ini untuk menjalankan aplikasi di komputer lokal, misalnya Windows dengan Laragon/XAMPP atau lingkungan development lain.

## A. Kebutuhan

Siapkan:

- PHP sesuai versi project Laravel 11, minimal PHP 8.2.
- Composer.
- Node.js dan npm.
- MySQL atau MariaDB.
- Git.
- Laragon atau XAMPP untuk Windows jika diinginkan.
- LibreOffice optional untuk test konversi PDF.

Cek versi:

```bash
php -v
composer -V
node -v
npm -v
git --version
```

## B. Clone Project

```bash
git clone <repo-url>
cd RaporDigitalSDITAl-Hidayah
```

Ganti `<repo-url>` dengan URL repository GitHub yang benar.

## C. Install Dependency

```bash
composer install
npm ci
npm run build
```

Untuk development frontend aktif, gunakan `npm run dev`.

## D. Setup `.env`

Salin file contoh:

```bash
cp .env.example .env
php artisan key:generate
```

Pada Windows PowerShell, jika `cp` tidak tersedia:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Field penting:

```ini
APP_NAME="Rapor Digital"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rapor_digital_local
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Perhatian: `APP_DEBUG=true` hanya untuk lokal. Production harus `APP_DEBUG=false`.

## E. Buat Database

Contoh MySQL:

```sql
CREATE DATABASE rapor_digital_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Sesuaikan nama database dengan `.env`.

## F. Migrasi

```bash
php artisan migrate
```

Jika project menyediakan seeder demo atau initial data, cek daftar perintah:

```bash
php artisan list
```

Jangan memakai `migrate:fresh` pada database berisi data penting karena akan menghapus tabel.

## G. Storage Link

```bash
php artisan storage:link
```

Ini diperlukan agar file upload yang aman dapat diakses aplikasi sesuai kebutuhan.

## H. Default Template Rapor

Setelah ada tahun ajaran aktif:

```bash
php artisan initial-data:seed-default-report-templates
```

Jika gagal karena tidak ada tahun ajaran aktif, buat atau aktifkan tahun ajaran terlebih dahulu dari aplikasi.

## I. Jalankan Aplikasi

Opsi Laravel serve:

```bash
php artisan serve
npm run dev
```

Buka:

```text
http://127.0.0.1:8000
```

Opsi Laragon:

- Letakkan project di folder `www`.
- Pastikan virtual host aktif.
- Sesuaikan `APP_URL`.

## J. Queue Worker Local

PDF dan beberapa proses background membutuhkan queue worker.

```bash
php artisan queue:work database --queue=pdf,pdf-warm,default --sleep=1 --tries=3 --timeout=300
```

Biarkan terminal ini tetap berjalan saat menguji PDF.

## K. LibreOffice Local

LibreOffice diperlukan untuk konversi DOCX ke PDF.

Jika LibreOffice tidak terdeteksi, isi path di `.env` sesuai komputer:

```ini
LIBREOFFICE_PATH="C:\Program Files\LibreOffice\program\soffice.exe"
```

Jika hanya menguji DOCX, LibreOffice tidak selalu diperlukan.

## L. Akun Awal

Jangan mengandalkan credential dari dokumentasi lama jika tidak sesuai database Anda.

Gunakan salah satu cara berikut:

- Jalankan seeder resmi project jika tersedia.
- Buat Admin melalui alur setup yang disediakan project.
- Minta credential awal dari pengelola aplikasi.

Jangan menulis password asli ke GitHub.

## M. Troubleshooting Local

| Masalah | Solusi |
| --- | --- |
| Composer error | Jalankan `composer install` lagi dan cek versi PHP. |
| npm error | Jalankan `npm ci`; jika perlu hapus `node_modules` lalu ulangi. |
| Database connection error | Cek `.env`, nama database, user, password, dan MySQL berjalan. |
| APP_KEY missing | Jalankan `php artisan key:generate`. |
| Vite manifest missing | Jalankan `npm run build` atau `npm run dev`. |
| Storage permission | Jalankan `php artisan storage:link`. |
| PDF failed | Cek LibreOffice dan queue worker. |
| Config stale | Jalankan `php artisan optimize:clear`. |
