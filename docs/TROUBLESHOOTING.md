# Troubleshooting Rapor Digital

Panduan ini berisi gejala, kemungkinan penyebab, dan solusi aman untuk masalah umum.

## Masalah Admin/User

### Tidak Bisa Login

Gejala:

- Login gagal.
- Password dianggap salah.

Kemungkinan penyebab:

- Username/password salah.
- Akun belum aktif.
- Guru wajib ganti password.

Solusi aman:

1. Cek username.
2. Minta Admin reset password jika perlu.
3. Jika Admin utama terkunci, teknisi perlu cek database sesuai prosedur sekolah.

### Role Tidak Muncul

Gejala:

- Guru tidak melihat role Pengajar atau Wali Kelas.

Kemungkinan penyebab:

- Guru belum diberi assignment.
- Wali kelas belum ditetapkan.
- Tahun ajaran aktif berbeda.

Solusi:

- Admin cek data guru, wali kelas, dan mata pelajaran.
- Pastikan konteks tahun ajaran aktif benar.

### Data Tidak Muncul Karena Filter

Gejala:

- Daftar kelas/siswa/mapel tampak kosong.

Kemungkinan penyebab:

- Search/filter masih aktif.

Solusi:

- Kosongkan pencarian.
- Klik Reset Filter.
- Muat ulang halaman jika perlu.

### Siswa Tidak Terlihat

Kemungkinan penyebab:

- Siswa belum masuk kelas aktif.
- Enrollment semester/tahun ajaran tidak sesuai.
- Filter aktif.

Solusi:

- Admin cek kelas siswa.
- Reset filter.
- Cek tahun ajaran/semester aktif.

### Import Excel Gagal

Kemungkinan penyebab:

- Format kolom berubah.
- Kelas tidak cocok.
- NIS/NISN duplikat.
- Tanggal tidak memakai `YYYY-MM-DD`.

Solusi:

- Download ulang template.
- Perbaiki baris sesuai pesan error.
- Upload ulang.

### Template UTS/UAS Ditolak

Template UTS harus memuat teks:

```text
RAPOR TENGAH SEMESTER
```

Template UAS tidak boleh memuat teks tersebut.

Solusi:

- Pastikan jenis yang dipilih benar.
- Upload file DOCX yang sesuai.

### Tidak Ada Template Aktif

Gejala:

- Rapor tidak bisa dibuat.
- Wali melihat pesan template belum tersedia.

Solusi:

- Admin aktifkan template Global atau template khusus kelas.
- Jika baru install, jalankan default template seeding setelah tahun ajaran aktif:

```bash
php artisan initial-data:seed-default-report-templates
```

### Rapor Belum Bisa Dibuat

Kemungkinan penyebab:

- Jenis rapor belum dibuka Admin.
- Template tidak aktif.
- Nilai belum lengkap.
- Data siswa tidak sesuai kelas aktif.

Solusi:

- Cek jenis rapor dibuka.
- Cek template.
- Cek nilai dan data siswa.

### PDF Sedang Disiapkan Terus

Kemungkinan penyebab:

- Queue worker tidak jalan.
- Supervisor berhenti.
- LibreOffice bermasalah.
- Aplikasi masih maintenance mode.

Solusi teknis:

```bash
php artisan about | grep -i maintenance
php artisan queue:failed
sudo supervisorctl status
tail -n 100 storage/logs/laravel.log
```

Jika maintenance mode aktif:

```bash
php artisan up
php artisan queue:restart
sudo supervisorctl restart rapor-worker:*
```

### Notifikasi Menumpuk

Solusi:

- Klik Informasi.
- Gunakan filter.
- Klik Tandai semua dibaca.
- Hapus semua notifikasi milik sendiri jika diperlukan.

Menghapus notifikasi tidak menghapus data akademik.

### Pusat Bantuan Terlalu Kecil/Besar

Floating widget hanya untuk bantuan singkat. Untuk panduan lengkap, klik `Buka Pusat Bantuan Lengkap`.

### LM/TP Belum Lengkap

Gejala:

- Download template nilai tidak aktif.

Solusi:

- Lengkapi Tujuan Pembelajaran pada setiap Lingkup Materi.
- Gunakan salin LM/TP dari kelas paralel jika tersedia.

### Upload Nilai Excel Gagal

Kemungkinan penyebab:

- File bukan template aplikasi.
- Sheet salah.
- Kelas/mapel tidak cocok.
- Nilai di luar 0-100.

Solusi:

- Download ulang template.
- Isi hanya kolom nilai.
- Jangan ubah kolom tersembunyi.

## Masalah Teknis

### 500 Error

Solusi:

```bash
tail -n 100 storage/logs/laravel.log
php artisan optimize:clear
```

Jangan aktifkan `APP_DEBUG=true` di production kecuali sangat terbatas dan sementara.

### Vite Manifest Not Found

Gejala:

- Halaman gagal memuat asset.

Solusi:

```bash
npm ci
npm run build
ls -lah public/build/manifest.json
```

### Storage Permission

Solusi:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
php artisan storage:link
```

### Queue Worker Not Running

Solusi:

```bash
sudo supervisorctl status
php artisan queue:restart
sudo supervisorctl restart rapor-worker:*
```

### Supervisor Socket Error

Solusi:

```bash
sudo systemctl status supervisor
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
```

### LibreOffice/PDF Failed

Solusi:

```bash
which soffice
soffice --version
php artisan queue:failed
tail -n 100 storage/logs/laravel.log
```

Pastikan `LIBREOFFICE_PATH` benar dan user web server dapat menjalankannya.

### Database Connection Error

Solusi:

- Cek `.env`.
- Cek database berjalan.
- Cek user/password database.

```bash
php artisan optimize:clear
php artisan migrate:status
```

### APP_KEY Missing

Solusi lokal:

```bash
php artisan key:generate
```

Perhatian: jangan generate ulang `APP_KEY` di production lama tanpa memahami dampaknya.

### Cache/Config Stale

Solusi:

```bash
php artisan optimize:clear
php artisan optimize
```

### Maintenance Mode Tidak Sengaja Aktif

Gejala:

- Website tidak bisa diakses normal.
- PDF/job tidak berjalan sesuai harapan.

Solusi:

```bash
php artisan about | grep -i maintenance
php artisan up
php artisan queue:restart
sudo supervisorctl restart rapor-worker:*
```

## Health Check Commands

```bash
php artisan about | grep -i maintenance
php artisan queue:failed
sudo supervisorctl status
ls -lah public/build/manifest.json
tail -n 100 storage/logs/laravel.log
```
