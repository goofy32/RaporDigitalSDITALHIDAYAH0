# DNS, Domain, dan VPS

Panduan ini menjelaskan hubungan domain, DNS, VPS, dan SSL untuk menjalankan Rapor Digital secara online.

## A. Konsep Dasar

| Istilah | Arti Sederhana |
| --- | --- |
| Domain | Alamat aplikasi, misalnya `rapor.namasekolah.sch.id`. |
| VPS | Server tempat aplikasi berjalan. |
| DNS | Pengarah domain ke IP VPS. |
| SSL/HTTPS | Pengaman koneksi agar akses memakai `https://`. |

## B. Recommended Setup

Gunakan domain atau subdomain resmi sekolah.

Contoh:

- `rapor.namasekolah.sch.id`
- `rapordigital.namasekolah.sch.id`

Sebaiknya akun domain/VPS dimiliki sekolah atau yayasan, bukan akun pribadi.

## C. DNS Records

Record utama biasanya adalah A record.

Contoh:

| Type | Name | Value | TTL |
| --- | --- | --- | --- |
| A | `rapor` | `VPS_IP` | `300` atau Auto |

Penjelasan:

- Type `A` mengarah ke IPv4 VPS.
- Type `AAAA` digunakan jika VPS memakai IPv6.
- Type `CNAME` digunakan untuk alias ke domain lain jika dibutuhkan.
- TTL menentukan seberapa cepat perubahan DNS menyebar.

Jika domain utama adalah `namasekolah.sch.id`, maka `Name: rapor` biasanya menjadi `rapor.namasekolah.sch.id`.

## D. Propagation

DNS tidak selalu langsung aktif. Propagasi bisa berlangsung beberapa menit sampai beberapa jam.

Cek dengan:

```bash
ping rapor.namasekolah.sch.id
nslookup rapor.namasekolah.sch.id
```

Jika belum mengarah ke IP VPS, tunggu dan cek kembali.

## E. VPS Firewall

Port yang umum dibutuhkan:

- SSH, biasanya port 22.
- HTTP, port 80.
- HTTPS, port 443.

Contoh UFW:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

Perhatian: pastikan akses SSH tidak terkunci sebelum mengaktifkan firewall.

## F. SSL

Gunakan Certbot setelah DNS mengarah ke VPS.

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d rapor.namasekolah.sch.id
```

Pastikan aplikasi dapat diakses dengan `https://`.

## G. Jika Sekolah Sudah Memiliki Domain

Minta admin domain sekolah membuat subdomain.

Yang dibutuhkan:

- Nama subdomain, misalnya `rapor`.
- IP VPS.
- A record mengarah ke IP VPS.

Tidak perlu transfer domain jika hanya membuat subdomain.

## H. Jika Menggunakan Domain Baru

1. Beli domain melalui akun sekolah/yayasan.
2. Arahkan DNS ke VPS.
3. Simpan credential domain di tempat aman.
4. Jangan memakai akun pribadi untuk aset production sekolah jika memungkinkan.

## I. Warning

Perhatian:

- Jangan host production di laptop pribadi.
- Jangan membagikan password VPS/domain lewat chat umum.
- Simpan credential di password manager atau dokumen internal yang aman.
- Pastikan backup aktif sebelum aplikasi digunakan resmi.
