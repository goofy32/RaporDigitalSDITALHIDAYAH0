# Deployment Production Rapor Digital

Panduan ini untuk deploy ke Ubuntu VPS dengan Nginx, MySQL/MariaDB, PHP-FPM, Supervisor, dan LibreOffice.

Perhatian: sesuaikan semua command dengan server Anda. Jangan menjalankan command production tanpa backup.

## A. Production Checklist

Pastikan sudah siap:

- Domain atau subdomain.
- VPS.
- SSH access.
- Database MySQL/MariaDB.
- PHP-FPM.
- Nginx.
- Composer.
- Node.js/npm.
- LibreOffice.
- Supervisor.
- SSL certificate.
- Backup plan.
- `APP_DEBUG=false`.

## B. Server Preparation

Contoh Ubuntu:

```bash
sudo apt update
sudo apt install nginx mysql-server unzip git curl supervisor libreoffice
```

Install PHP dan extension yang dibutuhkan Laravel:

- PHP-FPM.
- MySQL extension.
- mbstring.
- XML.
- curl.
- zip.
- bcmath.
- GD atau Imagick jika dipakai untuk gambar.

Contoh nama paket dapat berbeda sesuai versi Ubuntu/PHP:

```bash
sudo apt install php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip php-bcmath php-gd
```

## C. Clone Project

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone <repo-url> rapor-digital
cd /var/www/rapor-digital
```

## D. Permissions

```bash
sudo chown -R www-data:www-data /var/www/rapor-digital
sudo find /var/www/rapor-digital -type f -exec chmod 644 {} \;
sudo find /var/www/rapor-digital -type d -exec chmod 755 {} \;
sudo chmod -R ug+rwx storage bootstrap/cache
```

Pastikan `storage` dan `bootstrap/cache` writable oleh user web server.

## E. Production `.env`

Buat `.env` dari `.env.example`, lalu sesuaikan:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rapor.namasekolah.sch.id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rapor_digital
DB_USERNAME=rapor_user
DB_PASSWORD=isi_password_aman

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

LIBREOFFICE_PATH=/usr/bin/soffice
STAGING_TEST_TOOLS_ENABLED=false
```

Perhatian:

- Jangan commit `.env`.
- Jangan share password database.
- Jangan aktifkan `APP_DEBUG=true` di production.

## F. Install

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Jika `.env` dan `APP_KEY` sudah ada dari deploy lama, jangan generate key baru sembarangan karena dapat memengaruhi data terenkripsi/session.

## G. Seed Default Templates

Setelah ada tahun ajaran aktif:

```bash
php artisan initial-data:seed-default-report-templates
```

Jika gagal, buat atau aktifkan tahun ajaran terlebih dahulu dari aplikasi.

## H. Nginx Config

Contoh server block:

```nginx
server {
    listen 80;
    server_name rapor.namasekolah.sch.id;
    root /var/www/rapor-digital/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Sesuaikan `server_name`, root, dan versi PHP-FPM.

Aktifkan:

```bash
sudo ln -s /etc/nginx/sites-available/rapor-digital /etc/nginx/sites-enabled/rapor-digital
sudo nginx -t
sudo systemctl reload nginx
```

## I. SSL Certbot

Setelah DNS mengarah ke VPS:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d rapor.namasekolah.sch.id
```

Pastikan HTTPS aktif sebelum aplikasi digunakan production.

## J. Supervisor Queue Worker

Contoh file `/etc/supervisor/conf.d/rapor-worker.conf`:

```ini
[program:rapor-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/rapor-digital/artisan queue:work database --queue=pdf,pdf-warm,default --sleep=1 --tries=3 --timeout=300 --max-time=3600
numprocs=2
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/rapor-digital/storage/logs/worker.log
stopwaitsecs=360
```

Aktifkan:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Sesuaikan `numprocs` dengan RAM server. Untuk server kecil, mulai dari 1-2 worker.

## K. LibreOffice

LibreOffice diperlukan untuk PDF.

Cek:

```bash
which soffice
soffice --version
```

Jika project memakai wrapper script, misalnya `/usr/local/bin/soffice-www`, set:

```ini
LIBREOFFICE_PATH=/usr/local/bin/soffice-www
```

Pastikan user `www-data` dapat menjalankan LibreOffice.

## L. Deploy Update Routine

Contoh update:

```bash
cd /var/www/rapor-digital
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
sudo systemctl reload php8.3-fpm
php artisan queue:restart
sudo supervisorctl restart rapor-worker:*
```

Sesuaikan nama service PHP-FPM.

## M. Post Deploy Test

Checklist:

- Buka aplikasi.
- Login Admin.
- Pastikan `APP_DEBUG=false`.
- Buka Format Rapor.
- Cek template UTS/UAS.
- Generate DOCX.
- Generate PDF.
- Cek queue worker:

```bash
sudo supervisorctl status
php artisan queue:failed
tail -n 100 storage/logs/laravel.log
```

## N. Rollback Basic

Rollback harus hati-hati.

Langkah umum:

1. Pastikan ada backup database dan file.
2. Checkout commit sebelumnya.
3. Jalankan `composer install`.
4. Jalankan `npm ci && npm run build`.
5. Jalankan migrasi rollback hanya jika aman.
6. Restore DB backup jika migrasi/data sudah berubah dan rollback kode tidak cukup.

Perhatian: rollback database production berisiko menghapus data baru. Lakukan hanya dengan keputusan pengelola.
