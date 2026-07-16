# Dokumentasi Rapor Digital

Dokumentasi ini dibuat untuk internal sekolah, operator aplikasi, dan pengelola teknis yang membantu menjalankan Rapor Digital. Gunakan panduan sesuai peran masing-masing agar setup, input nilai, rapor, PDF, dan operasional server berjalan lebih aman.

## Mulai dari Sini

- [Panduan Admin Sekolah](PANDUAN_ADMIN.md)
- [Panduan Pengajar](PANDUAN_PENGAJAR.md)
- [Panduan Wali Kelas](PANDUAN_WALI_KELAS.md)
- [Setup Lokal](SETUP_LOKAL.md)
- [Deploy Production](DEPLOYMENT_PRODUCTION.md)
- [DNS, Domain, dan VPS](DNS_DOMAIN_VPS.md)
- [Backup dan Restore](BACKUP_RESTORE.md)
- [Troubleshooting](TROUBLESHOOTING.md)
- [Skrip Video Tutorial](SKRIP_VIDEO_TUTORIAL.md)

## Untuk Admin Sekolah

Admin mengatur data utama aplikasi: profil sekolah, tahun ajaran, kelas, guru, siswa, mata pelajaran, template rapor, jenis rapor yang dibuka untuk Wali Kelas, dan proses kenaikan kelas.

Baca: [Panduan Admin Sekolah](PANDUAN_ADMIN.md)

## Untuk Pengajar

Pengajar mengelola pembelajaran, Lingkup Materi, Tujuan Pembelajaran, input nilai manual, template nilai Excel, upload nilai Excel, dan preview nilai.

Baca: [Panduan Pengajar](PANDUAN_PENGAJAR.md)

## Untuk Wali Kelas

Wali Kelas mengelola daftar siswa kelas, absensi, catatan siswa, dan download rapor DOCX/PDF sesuai jenis rapor yang dibuka Admin.

Baca: [Panduan Wali Kelas](PANDUAN_WALI_KELAS.md)

## Untuk Developer dan Operator

- Setup komputer lokal: [Setup Lokal](SETUP_LOKAL.md)
- Deploy ke VPS production: [Deploy Production](DEPLOYMENT_PRODUCTION.md)
- Pengaturan domain dan SSL: [DNS, Domain, dan VPS](DNS_DOMAIN_VPS.md)
- Backup dan restore data: [Backup dan Restore](BACKUP_RESTORE.md)
- Solusi masalah umum: [Troubleshooting](TROUBLESHOOTING.md)

## Aturan Keamanan Dokumentasi

Perhatian:

- Jangan memasukkan file `.env` ke GitHub.
- Jangan memasukkan password, private key, token, atau credential server ke GitHub.
- Jangan memasukkan database dump berisi data siswa asli ke GitHub.
- Jangan memasukkan foto siswa asli ke repository publik.
- Gunakan data dummy untuk demo, video tutorial, screenshot publik, atau testing terbuka.
- Backup database dan file storage harus disimpan di tempat aman, bukan di repository publik.

## Catatan

Jika ada perbedaan antara dokumentasi ini dan kondisi server sekolah, ikuti konfigurasi server yang sudah disepakati oleh pengelola aplikasi. Untuk perubahan besar seperti migrasi, deploy, atau pergantian tahun ajaran, selalu siapkan backup terlebih dahulu.
