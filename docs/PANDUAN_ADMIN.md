# Panduan Admin Rapor Digital

Panduan ini ditujukan untuk Admin sekolah yang mengelola data utama aplikasi Rapor Digital.

## A. Peran Admin

Admin bertanggung jawab menyiapkan dan menjaga data inti aplikasi, antara lain:

- Profil sekolah.
- Tahun ajaran dan semester aktif.
- Kelas dan wali kelas.
- Guru/Pengajar.
- Siswa.
- Mata pelajaran.
- Lingkup Materi dan Tujuan Pembelajaran.
- Template rapor UTS/UAS.
- Jenis rapor yang dibuka untuk Wali Kelas.
- Kenaikan kelas.
- Notifikasi dan pemantauan dasar.

Admin sebaiknya tidak mengubah data besar tanpa backup, terutama sebelum perpindahan semester, kenaikan kelas, import siswa besar, atau deploy aplikasi.

## B. Alur Setup Awal Admin

Checklist awal:

1. Login sebagai Admin.
2. Lengkapi Profil Sekolah.
3. Buat atau aktifkan Tahun Ajaran.
4. Buat Kelas.
5. Tambah Pengajar/Guru.
6. Tambah atau import Siswa.
7. Tambah Mata Pelajaran.
8. Lengkapi Template Rapor UTS/UAS.
9. Buka jenis rapor untuk Wali Kelas.
10. Cek Pusat Bantuan dan Notifikasi.

## C. Profil Sekolah

Data profil sekolah digunakan di rapor. Pastikan data berikut benar sebelum cetak rapor:

- Nama sekolah.
- Alamat sekolah.
- NPSN.
- Email sekolah.
- Nama kepala sekolah.
- NUPTK kepala sekolah.
- Logo atau identitas sekolah jika tersedia.

Perhatian: kesalahan profil sekolah dapat tampil di banyak rapor. Perbaiki sebelum Wali Kelas mulai mencetak.

## D. Tahun Ajaran dan Semester

Tahun ajaran aktif adalah konteks utama aplikasi. Banyak data seperti kelas, siswa, mata pelajaran, nilai, dan rapor mengikuti tahun ajaran serta semester aktif.

Semester yang digunakan:

- Ganjil.
- Genap.

Penting: UTS dan UAS adalah jenis rapor dalam semester aktif. UAS bukan berarti semester Genap. Contoh: jika semester aktif adalah Ganjil dan jenis rapor dibuka adalah UAS, maka Wali Kelas mengakses UAS semester Ganjil.

### Membuat Tahun Ajaran Baru

1. Buka menu Tahun Ajaran.
2. Klik tambah tahun ajaran.
3. Isi nama tahun ajaran, misalnya `2026/2027`.
4. Pilih semester awal.
5. Simpan.

### Mengaktifkan Tahun Ajaran

1. Buka daftar Tahun Ajaran.
2. Pilih tahun ajaran yang akan digunakan.
3. Aktifkan.
4. Pastikan hanya konteks tahun ajaran/semester yang benar digunakan untuk input data.

### Melanjutkan dari Semester Ganjil ke Genap

1. Pastikan data semester Ganjil sudah siap.
2. Backup database.
3. Buka Tahun Ajaran aktif.
4. Gunakan aksi lanjut ke semester berikutnya.
5. Cek kelas, siswa, mata pelajaran, dan data pendukung setelah proses selesai.

Jika semester Genap sudah ada di arsip, pulihkan terlebih dahulu jika aman. Jangan membuat ulang data akademik secara sembarangan.

### Membuat Tahun Ajaran Berikutnya

1. Selesaikan semester Genap.
2. Pastikan rapor akhir sudah selesai.
3. Backup database.
4. Siapkan proses kenaikan kelas.
5. Buat tahun ajaran berikutnya.
6. Cek kembali kelas, wali kelas, siswa, dan mata pelajaran.

### Arsip Tahun Ajaran

Arsip berarti menyimpan data sebagai nonaktif/soft delete. Arsip bukan berarti data langsung dihancurkan permanen.

Gunakan arsip jika:

- Tahun ajaran lama tidak ingin tampil di daftar aktif.
- Data masih perlu disimpan untuk riwayat.
- Data mungkin perlu dipulihkan.

### Restore/Pulihkan Tahun Ajaran dari Arsip

1. Buka daftar arsip Tahun Ajaran.
2. Pilih data yang ingin dipulihkan.
3. Klik Pulihkan.
4. Pastikan tidak bertabrakan dengan data aktif yang sama.

### Hal yang Tidak Boleh Dilakukan Sembarangan

Perhatian:

- Jangan menghapus permanen tahun ajaran yang masih terhubung nilai, rapor, siswa, kelas, atau semester berjalan.
- Jangan mengubah semester aktif tanpa memahami dampaknya.
- Jangan menjalankan proses kenaikan kelas tanpa backup.
- Gunakan arsip sebagai penyimpanan aman.

## E. Data Kelas

Data kelas berpengaruh ke siswa, mata pelajaran, Wali Kelas, Pengajar, dan rapor.

Langkah umum:

1. Buka menu Data Kelas.
2. Tambah kelas.
3. Isi nama kelas, tingkat, dan tahun ajaran jika tersedia.
4. Tentukan Wali Kelas melalui data guru jika alur aplikasi mengaturnya dari guru.
5. Simpan.

Kelas paralel adalah kelas dengan tingkat sama, misalnya Kelas 1 Ubay dan Kelas 1 Zaid. Kelas paralel dapat membantu saat menyalin LM/TP untuk mata pelajaran yang sama.

Gunakan search/filter untuk mencari kelas berdasarkan tingkat, wali kelas, atau urutan nama.

## F. Data Pengajar / Guru

Guru dapat memiliki lebih dari satu peran, misalnya Pengajar dan Wali Kelas.

Hal penting:

- Username digunakan untuk login guru.
- Password harus dijaga oleh masing-masing user.
- Role Pengajar memberi akses input nilai dan pembelajaran.
- Role Wali Kelas memberi akses absensi, catatan siswa, dan rapor kelas.
- NUPTK bersifat optional jika guru tidak memilikinya.
- Email, nomor HP, alamat, dan tanggal lahir bersifat optional untuk kebutuhan inti rapor.

Jika guru memiliki lebih dari satu peran, guru dapat mengganti role setelah login melalui menu pergantian role.

## G. Data Siswa

Admin dapat menambah siswa manual atau import dari Excel.

### Tambah Siswa Manual

1. Buka Data Siswa.
2. Klik tambah siswa.
3. Isi data siswa.
4. Pilih kelas aktif.
5. Simpan.

### Import Siswa dari Excel

1. Buka Data Siswa.
2. Download template import.
3. Isi data siswa sesuai format.
4. Upload file.
5. Baca hasil validasi.
6. Perbaiki baris yang error jika ada.

Kolom yang umum digunakan:

| Kolom | Keterangan |
| --- | --- |
| `nis` | Wajib dan unik |
| `nisn` | Wajib dan unik jika sekolah menggunakannya |
| `nama` | Nama siswa |
| `tanggal_lahir` | Format `YYYY-MM-DD`, contoh `2017-05-21` |
| `jenis_kelamin` | Gunakan `L` atau `P` |
| `agama` | Agama siswa |
| `alamat` | Alamat siswa |
| `kelas` | Harus sesuai nama kelas di aplikasi |
| `nama_ayah` | Nama ayah |
| `nama_ibu` | Nama ibu |
| `pekerjaan_ayah` | Pekerjaan ayah |
| `pekerjaan_ibu` | Pekerjaan ibu |
| `alamat_orangtua` | Alamat orang tua |
| `photo` | Optional, sesuai fitur import/foto yang tersedia |

Perhatian:

- NIS/NISN harus unik.
- Nama kelas harus sama dengan kelas di aplikasi.
- Gunakan format tanggal `YYYY-MM-DD`.
- Foto siswa optional.
- Jika error muncul, baca nomor baris dan perbaiki data di Excel.

## H. Mata Pelajaran

Mata pelajaran menghubungkan kelas, guru pengajar, dan struktur nilai.

Langkah umum:

1. Buka menu Mata Pelajaran.
2. Tambah mata pelajaran.
3. Pilih kelas.
4. Pilih guru pengajar.
5. Tentukan jenis mapel jika tersedia, seperti wajib atau muatan lokal.
6. Isi Lingkup Materi jika tidak menyalin dari kelas paralel.
7. Simpan.

### TP Lengkap/Belum Lengkap

Template nilai Pengajar membutuhkan Lingkup Materi dan Tujuan Pembelajaran yang lengkap. Jika belum lengkap, Pengajar akan melihat peringatan dan template nilai belum siap.

### Salin LM/TP dari Kelas Paralel

Jika ada mata pelajaran yang sama pada kelas paralel, Admin dapat memakai opsi salin LM/TP.

Contoh:

- Matematika Kelas 1 Ubay sudah memiliki LM/TP.
- Matematika Kelas 1 Zaid baru dibuat.
- Checkbox salin LM/TP dapat muncul jika sumber aman tersedia.

Jika checkbox dicentang:

- Lingkup Materi manual boleh kosong.
- Sistem menyalin LM dan TP dari sumber yang dipilih.
- Nilai siswa, rapor, absensi, dan data siswa tidak ikut disalin.

Jika checkbox tidak dicentang:

- Isi Lingkup Materi manual seperti biasa.

## I. Ekstrakurikuler

Ekstrakurikuler dapat masuk ke rapor sesuai pengaturan aplikasi.

Langkah umum:

1. Buka menu Ekstrakurikuler.
2. Tambah data ekstrakurikuler.
3. Isi nama kegiatan.
4. Pilih pembina jika tersedia.
5. Simpan.

Gunakan search/filter untuk mencari kegiatan atau pembina.

## J. Prestasi

Data prestasi siswa dapat ditampilkan pada rapor sesuai format yang digunakan.

Langkah umum:

1. Buka menu Prestasi.
2. Pilih siswa.
3. Isi jenis prestasi, tingkat, tanggal, dan keterangan.
4. Simpan.

Gunakan search/filter untuk mencari berdasarkan siswa, kelas, jenis prestasi, atau tanggal.

## K. Format Rapor / Template Rapor

Template rapor menentukan bentuk DOCX/PDF yang dihasilkan.

Jenis template:

- Template UTS.
- Template UAS.
- Template Global.
- Template khusus kelas.

Prioritas pemilihan template:

1. Template khusus kelas yang aktif.
2. Template Global yang aktif.
3. Jika tidak ada, rapor tidak bisa dibuat dan Wali akan melihat pesan template belum tersedia.

Aturan penting:

- Beberapa template aktif diperbolehkan.
- UTS dan UAS aktifnya independen.
- Admin boleh mematikan template UAS saat sedang membuka UTS.
- Admin boleh mematikan template Global jika memang salah.
- Jika tidak ada template aktif yang sesuai, Wali melihat pesan agar menghubungi Admin.

### Validasi Template

- Template UTS wajib memuat teks `RAPOR TENGAH SEMESTER`.
- Template UAS tidak boleh memuat teks `RAPOR TENGAH SEMESTER`.
- Jika upload ditolak, pastikan file sesuai jenis yang dipilih.
- Placeholder foto siswa menggunakan `${foto_siswa}`.
- Default template dapat disiapkan oleh teknisi dengan:

```bash
php artisan initial-data:seed-default-report-templates
```

Perintah tersebut membutuhkan tahun ajaran aktif.

## L. Jenis Rapor yang Dibuka untuk Wali Kelas

Pengaturan ini bukan semester. Pengaturan ini menentukan jenis rapor yang dapat diakses Wali Kelas saat ini.

Pilihan:

- UTS.
- UAS.

Contoh:

- Semester aktif: Ganjil.
- Jenis rapor dibuka: UAS.
- Artinya: Wali Kelas mengakses UAS semester Ganjil.

Dampak:

- Jika UTS dibuka, Wali hanya dapat mengakses UTS.
- Jika UAS dibuka, Wali hanya dapat mengakses UAS.
- PDF/warmup mengikuti jenis rapor yang dibuka.

## M. Riwayat Cetak Rapor

Riwayat cetak rapor membantu Admin melihat proses generate atau download rapor.

Cek riwayat jika:

- Wali melaporkan PDF belum siap.
- Ingin melihat status DOCX/PDF.
- Ingin memastikan rapor pernah dibuat.

## N. Kenaikan Kelas

Kenaikan kelas biasanya digunakan setelah rapor akhir selesai.

Checklist sebelum proses:

- Semua nilai selesai.
- Absensi dan catatan selesai.
- Rapor sudah dicek.
- Database sudah dibackup.
- Kelas tujuan sudah siap.

Langkah umum:

1. Buka menu kenaikan kelas.
2. Pilih siswa.
3. Pilih kelas tujuan.
4. Jalankan proses kenaikan kelas.
5. Cek siswa di kelas baru.

## O. Alur Tahun Ajaran Berikutnya

1. Selesaikan semester Genap.
2. Pastikan rapor akhir sudah selesai.
3. Backup database.
4. Jalankan proses kenaikan kelas.
5. Buat atau aktifkan tahun ajaran berikutnya.
6. Cek kelas dan siswa.
7. Cek guru dan Wali Kelas.
8. Cek mata pelajaran.
9. Siapkan template rapor.
10. Buka jenis rapor yang sesuai.

## P. Notifikasi

Tombol Informasi membuka panel notifikasi.

Fitur:

- Filter notifikasi.
- Tandai semua dibaca.
- Hapus semua notifikasi milik sendiri.
- Notifikasi nilai dibuat per kelas/mapel, bukan per siswa.

Menghapus notifikasi tidak menghapus data nilai, rapor, atau siswa.

## Q. Pusat Bantuan

Pusat Bantuan tersedia sebagai widget kecil dan halaman panduan lengkap.

Gunakan untuk:

- Mencari topik cepat.
- Membaca panduan sesuai role.
- Membantu pengguna baru tanpa bertanya ke teknisi untuk hal sederhana.

## R. Troubleshooting Admin

| Masalah | Solusi Aman |
| --- | --- |
| Template UTS ditolak | Pastikan file memuat teks `RAPOR TENGAH SEMESTER`. |
| Template UAS ditolak | Pastikan file tidak memuat teks `RAPOR TENGAH SEMESTER`. |
| Tidak ada template aktif | Aktifkan template Global atau template khusus kelas. |
| Data tidak muncul | Cek apakah search/filter masih aktif, lalu reset filter. |
| Import siswa gagal | Baca nomor baris error, perbaiki Excel, upload ulang. |
| Guru tidak bisa melihat menu | Cek role guru dan assignment kelas/mapel. |
| Wali tidak bisa melihat rapor | Cek jenis rapor yang dibuka dan template aktif. |
| PDF belum siap | Tunggu worker memproses. Jika terlalu lama, minta teknisi cek queue dan Supervisor. |
| Error production | Jangan aktifkan `APP_DEBUG=true`; minta teknisi cek log. |

Perhatian: untuk masalah server seperti queue worker, Supervisor, maintenance mode, atau LibreOffice, hubungi pengelola teknis.
