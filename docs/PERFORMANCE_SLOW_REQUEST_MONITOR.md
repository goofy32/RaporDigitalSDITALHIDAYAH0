# Lightweight Slow Request Monitor

Monitor ini membantu investigasi navigasi lambat di staging tanpa menyimpan SQL,
request body, cookie, session ID, atau data pribadi pengguna.

## Mengaktifkan di staging

Tambahkan env berikut:

```env
PERFORMANCE_MONITOR_ENABLED=true
PERFORMANCE_SLOW_REQUEST_MS=700
PERFORMANCE_QUERY_COUNT_THRESHOLD=75
PERFORMANCE_DATABASE_MS_THRESHOLD=250
PERFORMANCE_MAX_QUERY_MS_THRESHOLD=150
PERFORMANCE_MONITOR_LOG_CHANNEL=performance
PERFORMANCE_LOG_DAYS=14
```

Setelah mengubah env pada server yang memakai config cache:

```bash
php artisan optimize:clear
php artisan optimize
```

## Lokasi log

Default channel `performance` memakai driver `daily`, sehingga file aktualnya
berbentuk:

```text
storage/logs/performance-YYYY-MM-DD.log
```

Channel memakai daily rotation melalui konfigurasi Laravel normal.

Contoh membaca log terbaru:

```bash
tail -F storage/logs/performance-*.log
```

## Membaca hasil

Cari event:

```text
performance.slow_request
```

Field yang aman untuk dianalisis:

- `request_id`
- `method`
- `route_name`
- `route_uri`
- `status_code`
- `duration_ms`
- `query_count`
- `database_ms`
- `max_query_ms`
- `memory_peak_mb`
- `is_redirect`
- `guard`
- `selected_role`
- `tahun_ajaran_id`
- `semester`
- `triggers`

Gunakan `route_name`, `route_uri`, dan `triggers` untuk mengelompokkan route
yang lambat. Request redirect mutasi dan request GET tujuan dicatat sebagai
request berbeda.

Target URL/path redirect sengaja tidak dicatat agar ID, slug, token, atau path
dinamis tidak masuk ke log.

Threshold bernilai `0`, negatif, kosong, atau tidak valid menonaktifkan trigger
threshold tersebut. Status HTTP `500+` tetap dicatat sebagai `server_error`
selama monitor aktif.

Monitor mencatat metrik ketika request PHP mencapai akhir middleware. Jika
Nginx mengembalikan `504` tetapi proses PHP tetap menyelesaikan request, log
masih dapat muncul. Jika proses PHP dihentikan sebelum middleware selesai, log
Laravel mungkin tidak tercipta.

## Menonaktifkan

```env
PERFORMANCE_MONITOR_ENABLED=false
```

Lalu jalankan ulang:

```bash
php artisan optimize:clear
php artisan optimize
```

Jangan mengaktifkan monitor dengan threshold terlalu rendah di production,
karena log volume dapat meningkat tajam walaupun payload log sudah disanitasi.
