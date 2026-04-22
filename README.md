# Rapot Digital SDIT Al Hidayah

**Rapot Digital SDIT Al Hidayah** adalah sebuah **eksperimen pengembangan aplikasi** yang dibangun sepenuhnya menggunakan **Claude** (AI Assistant by Anthropic). 

Proyek ini bertujuan untuk mendemonstrasikan bagaimana AI dapat membantu membangun sistem manajemen sekolah yang kompleks dari nol, mencakup pengelolaan data siswa, penilaian, absensi, hingga pembuatan laporan akademik otomatis dengan teknologi web modern.

---

## Teknologi yang Digunakan

- **Laravel 11** - Framework backend PHP.
- **TurboHotwire** - Meningkatkan interaksi dan performa aplikasi.
- **Alpine.js** - Library JavaScript minimalis.
- **PHPWord** - Membantu dalam menghasilkan dokumen Word (untuk rapor).
- **Tailwind CSS** - Framework CSS untuk desain responsif.
- **Vite** - Build tool modern untuk frontend.
- **Flowbite** - Komponen UI berbasis Tailwind.

---

## Fitur Utama

### Role Pengguna
- **Admin**
  - Mengelola data guru.
  - Mengatur profil sekolah.
  - Mengelola kelas, siswa, mata pelajaran, format rapor, dan pengaturan aplikasi.
  
- **Pengajar**
  - Mengelola data siswa, absensi, nilai, ekstrakurikuler, dan catatan siswa.
  - Menambahkan tanda tangan digital untuk validasi laporan.
  
- **Wali Kelas**
  - Melihat dan memverifikasi rapor siswa di kelas yang diampu.

### Fitur Tambahan
- **Cetak Rapor**: Format Word yang dapat diunduh dan dicetak.
- **Integrasi Komponen Modern**: Memanfaatkan TurboHotwire, Flowbite, dan Tailwind CSS.

---

# Panduan Instalasi & Menjalankan Website

Panduan ini ditujukan untuk developer baru yang ingin menjalankan proyek ini dari awal setelah melakukan clone dari GitHub.

---

## 1. Persiapan Software (Prerequisites)
Pastikan komputer Anda sudah terinstall software berikut:

1.  **XAMPP** atau **Laragon**:
    *   **Laragon (sangat di Rekomendasi)**: Download versi "Full" yang sudah mencakup PHP, MySQL, Apache/Nginx.
    *   **XAMPP**: Jika pakai XAMPP, pastikan PHP versi **8.2** atau lebih baru.
2.  **Composer**: Download di [getcomposer.org](https://getcomposer.org/).
3.  **Node.js**: Download versi LTS (v20 atau v22) di [nodejs.org](https://nodejs.org/).
4.  **Git**: Untuk clone repository.

---

## 2. Clone Repository
Buka terminal (Git Bash / PowerShell / Terminal VS Code), lalu arahkan ke folder `www` (Laragon) atau `htdocs` (XAMPP).

```bash
# Contoh untuk Laragon
cd C:\laragon\www

# Clone repository
git clone https://github.com/username-anda/RaporDigitalSDITALHIDAYAH0.git

# Masuk ke folder proyek
cd RaporDigitalSDITALHIDAYAH0
```

---

## 3. Setup Environment (.env)
File `.env` tidak ikut di-upload ke GitHub demi keamanan. Anda perlu membuatnya dari file contoh.

1.  **Copy file .env.example**:
    ```bash
    cp .env.example .env
    ```
    *(Atau copy-paste manual file `.env.example` lalu rename jadi `.env`)*

2.  **Edit file .env**:
    Buka file `.env` di text editor, lalu sesuaikan bagian database:
    ```ini
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=rapot_digital_db  <-- Ganti nama database sesuai keinginan
    DB_USERNAME=root
    DB_PASSWORD=                  <-- Kosongkan jika pakai default Laragon/XAMPP
    ```

---

## 4. Install Dependensi (Wajib)
Jalankan perintah ini berturut-turut di terminal untuk mengunduh semua pustaka yang dibutuhkan:

```bash
# 1. Install library PHP (Laravel)
composer install

# 2. Install library JavaScript (Vite/Tailwind)
# Jika ada error rollup di Windows, gunakan --force
npm install --force 
# 3. Opsional (bila error masih ada untuk rollup-win32-x64-msvc)
npm install --force --no-save @rollup/rollup-win32-x64-msvc
```



---

## 5. Setup Database
1.  Buka **HeidiSQL** (Laragon) atau **phpMyAdmin** (XAMPP).
2.  Buat database baru dengan nama yang sama persis dengan yang Anda tulis di `.env` tadi (contoh: `rapot_digital_db`).
3.  Kembali ke terminal, jalankan perintah ini untuk membuat tabel & data dummy:

```bash
# Generate key aplikasi (hanya sekali seumur hidup)
php artisan key:generate

# Migrasi tabel dan isi data awal (User Admin, Guru, dll)
php artisan migrate:fresh --seed
```

---

## 6. Setup Symbolic Link
Agar gambar profil dan logo sekolah bisa muncul, jalankan:

```bash
php artisan storage:link
```

---

## 7. Menjalankan Aplikasi
Untuk menjalankan website secara penuh (Backend + Frontend), Anda perlu membuka **2 Terminal** sekaligus:

**Terminal 1 (Menjalankan Server Laravel):**
```bash
php artisan serve
```
*Website akan bisa diakses di: http://127.0.0.1:8000*

**Terminal 2 (Menjalankan Vite/Frontend):**
```bash
npm run dev
```
*Ini memproses Tailwind CSS dan JavaScript secara real-time.*

---

## 🔑 Akun Login Default
Gunakan akun ini untuk masuk pertama kali:

*   **Admin**:
    *   Email: `admin@example.com`
    *   Password: `password123`

---

## Troubleshooting (Jika Ada Masalah)

**Masalah 1: Error "Vite manifest not found"**
*   **Solusi**: Pastikan Anda sudah menjalankan `npm run dev` di terminal kedua.

**Masalah 2: Error Database "Access denied" atau "Unknown database"**
*   **Solusi**: Cek lagi file `.env`, pastikan nama database sudah benar dan database tersebut sudah dibuat di phpMyAdmin/HeidiSQL. Lalu jalankan `php artisan config:clear`.

**Masalah 3: Gambar tidak muncul**
*   **Solusi**: Jalankan `php artisan storage:link`.

Lisensi
Proyek ini dirilis di bawah lisensi MIT. Anda bebas menggunakannya sesuai kebutuhan.

Kontribusi dan Dukungan
Jika menemukan bug, ajukan issue di repository ini.
Untuk kontribusi, buat pull request setelah melakukan fork repository ini.
