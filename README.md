# Rapot Digital SDIT Al Hidayah

**Rapot Digital SDIT Al Hidayah** adalah sebuah **eksperimen pengembangan aplikasi** yang dibangun sepenuhnya menggunakan **Claude** (AI Assistant by Anthropic). 

Proyek ini bertujuan untuk mendemonstrasikan bagaimana AI dapat membantu membangun sistem manajemen sekolah yang kompleks dari nol, mencakup pengelolaan data siswa, penilaian, absensi, hingga pembuatan laporan akademik otomatis dengan teknologi web modern.

---

## 🚀 Teknologi yang Digunakan

- **Laravel 11** - Framework backend PHP.
- **TurboHotwire** - Meningkatkan interaksi dan performa aplikasi.
- **Alpine.js** - Library JavaScript minimalis.
- **PHPWord** - Membantu dalam menghasilkan dokumen Word (untuk rapor).
- **Tailwind CSS** - Framework CSS untuk desain responsif.
- **Vite** - Build tool modern untuk frontend.
- **Flowbite** - Komponen UI berbasis Tailwind.

---

## 🎯 Fitur Utama

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
- **Tampilan Responsif**: Optimasi untuk PC, tablet, dan mobile.
- **Cetak Rapor**: Format Word yang dapat diunduh dan dicetak.
- **Integrasi Komponen Modern**: Memanfaatkan TurboHotwire, Flowbite, dan Tailwind CSS.

---

## 💻 Cara Instalasi dan Penggunaan

### Prasyarat
Pastikan Anda sudah menginstal:
- **PHP 8.2** atau versi lebih baru.
- **Composer**.
- **Node.js** dan **npm**.
- **Visual Studio Code** *(opsional)*.

###  💻 Langkah Instalasi dan Penggunaan

1. **Clone Repository**
   ```bash
   git clone (https://github.com/username/rapot-digital-sdit-alhidayah.git)
   cd rapot-digital-sdit-alhidayah

2. **Install Dependencies**
Jalankan perintah berikut untuk menginstal dependensi PHP dan Node.js:

    ```bash
    composer install
    npm install    

3. **Konfigurasi Environment**
Salin file .env.example menjadi .env dan edit sesuai kebutuhan:

    ```bash
    Copy code
    cp .env.example .env

4. **Konfigurasi Environment Salin file .env.example menjadi .env dan edit sesuai kebutuhan:**

    ```bash
    Copy code
    cp .env.example .env

5. **Ubah pengaturan database di file .env:**

    env

    ```bash
    Copy Code
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=nama_database
    DB_USERNAME=username_database
    DB_PASSWORD=password_database

6. **Generate Application Key Jalankan perintah berikut untuk membuat application key**
Copy code

    ```bash
    php artisan key:generate

Migrasi Database Jalankan migrasi dan seeder untuk membuat tabel dan data awal:

     ```bash
    Copy code
    php artisan migrate --seed
    Build Frontend Jalankan perintah untuk membangun file frontend:


    Copy code
    npm run dev
    Menjalankan Server Jalankan server lokal dengan perintah berikut:


    Copy code
    php artisan serve
    Akses aplikasi di browser pada http://localhost:8000.

🎓**Informasi Tambahan**
Akun Default
Gunakan akun berikut untuk login awal:

Admin
-
Email: admin@example.com
Password: password
Pengajar

Email: teacher@example.com
Password: password
Wali Kelas

Email: walikelas@example.com
Password: password
📄 Lisensi
Proyek ini dirilis di bawah lisensi MIT. Anda bebas menggunakannya sesuai kebutuhan.

📫 Kontribusi dan Dukungan
Jika menemukan bug, ajukan issue di repository ini.
Untuk kontribusi, buat pull request setelah melakukan fork repository ini.
