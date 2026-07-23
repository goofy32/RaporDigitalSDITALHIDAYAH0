# Backup dan Restore

Backup adalah perlindungan utama sebelum deploy, migrasi, import massal, kenaikan kelas, atau perpindahan semester/tahun ajaran.

## A. Apa yang Harus Dibackup

Backup minimal:

- Database.
- Folder `storage/app`.
- Folder `public/storage` jika digunakan melalui storage link.
- Template rapor.
- Foto siswa.
- File upload penting lain.
- File `.env` secara aman.

Perhatian: backup berisi data sensitif siswa. Jangan upload ke GitHub publik.

## B. Manual DB Backup

Contoh sederhana:

```bash
mysqldump -u rapor_user -p rapor_digital > backup_rapor_digital.sql
```

Command ini akan meminta password.

Untuk menghindari password muncul di shell history, gunakan file opsi sementara yang aman:

```ini
# /root/.my.cnf-rapor-backup
[client]
user=rapor_user
password=isi_password_database
host=127.0.0.1
```

Set permission:

```bash
chmod 600 /root/.my.cnf-rapor-backup
mysqldump --defaults-extra-file=/root/.my.cnf-rapor-backup rapor_digital > backup_rapor_digital.sql
```

Simpan file opsi ini dengan aman atau hapus jika hanya dipakai sementara.

## C. File Backup

Contoh backup file upload/storage:

```bash
tar -czf backup_storage_rapor_digital.tar.gz storage/app public/storage
```

Jika path berbeda di server Anda, sesuaikan.

## D. Restore DB

Perhatian: restore database dapat menimpa data yang ada.

```bash
mysql -u rapor_user -p rapor_digital < backup_rapor_digital.sql
```

Sebaiknya restore diuji dulu di staging/lokal sebelum production.

## E. Restore Files

```bash
tar -xzf backup_storage_rapor_digital.tar.gz
sudo chown -R www-data:www-data storage public/storage
sudo chmod -R ug+rw storage bootstrap/cache
```

Sesuaikan user web server jika bukan `www-data`.

## F. Backup Schedule

Rekomendasi:

- Database harian.
- File storage mingguan.
- Backup sebelum deploy besar.
- Backup sebelum migrasi database.
- Backup sebelum import siswa massal.
- Backup sebelum perpindahan semester/tahun ajaran.
- Backup sebelum kenaikan kelas.

## G. Verify Backup

Jangan percaya backup yang belum diuji.

Checklist:

- File backup ada.
- Ukuran file masuk akal.
- File dapat diekstrak.
- Database dapat direstore di staging/lokal.
- Aplikasi dapat login setelah restore.
- Template, foto, dan rapor masih terbaca.

## H. Security

Perhatian:

- Backup berisi data pribadi siswa dan guru.
- Simpan di lokasi aman.
- Batasi akses hanya untuk pengelola yang berwenang.
- Jangan upload backup ke GitHub.
- Gunakan enkripsi jika backup disimpan di cloud.
- Jangan kirim backup melalui kanal publik.
