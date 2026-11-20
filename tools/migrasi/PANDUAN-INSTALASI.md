# Panduan Pindah Portal KAIZEN ke PC Kantor

Paket ini sudah **lengkap**: kode aplikasi + isi database + sertifikat HTTPS +
skrip pemasangan otomatis. Tidak perlu meng-install ulang aplikasi dari nol.

```
KAIZEN-MIGRASI-YYYY-MM-DD\
├── kaizen\                        kode aplikasi (siap salin ke htdocs)
├── database\
│   ├── kaizen_hse_portal.sql      isi database (struktur + data)
│   ├── 01_buat_database_dan_user.sql
│   └── db.conf                    nama DB, user, password (JANGAN disebar)
├── SSL\
│   ├── server.crt                 sertifikat HTTPS Apache
│   └── server.key                 kunci privat sertifikat
├── PASANG.ps1                     pemasangan otomatis (1 perintah)
├── BUAT-SERTIFIKAT-BARU.ps1       bikin sertifikat untuk IP PC kantor
├── PANDUAN-INSTALASI.md           file ini
├── VERSI.txt                      versi PHP/MySQL saat paket dibuat
└── SHA256.txt                     checksum untuk cek file tidak rusak
```

> **PENTING:** paket ini memuat password database dan token WhatsApp (Fonnte).
> Simpan di flashdisk/folder pribadi, jangan di-upload ke GitHub atau grup umum.

---

## 1. Yang perlu disiapkan di PC kantor

| Kebutuhan | Keterangan |
|---|---|
| **XAMPP** | Versi dengan **PHP 8.2** dan **MariaDB 10.4** (sama seperti sekarang). Unduh di apachefriends.org |
| Windows | 10 / 11 (64-bit) |
| Ruang disk | minimal 2 GB |
| Jaringan | tersambung ke LAN kantor (dan internet bila pakai domain) |
| Hak akses | bisa menulis ke folder XAMPP (biasanya `C:\xampp`) |

Ekstensi PHP yang dipakai aplikasi (`mysqli`, `curl`, `openssl`, `mbstring`,
`gd`) sudah aktif otomatis di XAMPP standar — tidak perlu diubah.

---

## 2. Cara memasang (3 langkah)

### Langkah 1 — Salin paket ke PC kantor

Salin seluruh folder `KAIZEN-MIGRASI-YYYY-MM-DD` (atau file `.zip`-nya lalu
ekstrak) ke PC kantor, misalnya ke `D:\KAIZEN-MIGRASI-YYYY-MM-DD`.

### Langkah 2 — Jalankan pemasangan otomatis

Buka **PowerShell** di folder paket, lalu:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PASANG.ps1
```

Bila XAMPP tidak di `C:\xampp`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PASANG.ps1 -Xampp "D:\xampp"
```

Bila MySQL root diberi password:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PASANG.ps1 -RootPass "passwordroot"
```

Skrip akan otomatis:

1. memeriksa kelengkapan paket dan XAMPP,
2. menyalakan MySQL bila belum jalan,
3. menyalin aplikasi ke `<XAMPP>\htdocs\kaizen` (folder lama di-`rename`
   menjadi `kaizen.lama-YYYYMMDD-HHmm`, tidak dihapus),
4. membuat database `kaizen_hse_portal` + user aplikasi,
5. mengimpor seluruh isi database,
6. memverifikasi koneksi aplikasi ke database,
7. memasang sertifikat HTTPS Apache (cadangan otomatis dibuat),
8. mengecek halaman portal.

### Langkah 3 — Nyalakan Apache

Buka **XAMPP Control Panel** → **Start Apache** (MySQL sudah dinyalakan skrip).

Lalu buka:

```
https://localhost/kaizen/
```

Login memakai akun yang sudah ada (data user ikut terbawa di database).

---

## 3. Agar bisa diakses PC/HP lain di kantor

### a. Izinkan Apache di Windows Firewall

Jalankan **PowerShell sebagai Administrator**:

```powershell
New-NetFirewallRule -DisplayName "KAIZEN HTTP"  -Direction Inbound -Protocol TCP -LocalPort 80  -Action Allow
New-NetFirewallRule -DisplayName "KAIZEN HTTPS" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow
```

### b. Catat alamat IP PC kantor

```powershell
ipconfig
```

Contoh: `192.168.1.50` → akses dari perangkat lain:
`https://192.168.1.50/kaizen/`

### c. Buat sertifikat untuk IP kantor (sekali saja)

Sertifikat di paket masih memakai IP laptop lama, jadi browser akan memberi
peringatan. Buat ulang sesuai IP PC kantor:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\BUAT-SERTIFIKAT-BARU.ps1
```

Lalu **Stop → Start Apache** di XAMPP Control Panel.

### d. Pasang `kaizen-ca.crt` di perangkat klien (opsional, agar tanpa peringatan)

File ada di `<XAMPP>\htdocs\kaizen\kaizen-ca.crt`.

| Perangkat | Cara |
|---|---|
| Windows | klik 2x → Install Certificate → **Local Machine** → **Trusted Root Certification Authorities** |
| Android | Setelan → Keamanan → Enkripsi & kredensial → Pasang sertifikat → **Sertifikat CA** |
| iPhone | kirim file → Setelan → **Profile Downloaded** → Install → lalu aktifkan di *Certificate Trust Settings* |

Tanpa langkah ini portal **tetap bisa dibuka**, hanya muncul peringatan
"sertifikat tidak aman" yang bisa dilewati (Advanced → Proceed).

---

## 4. Domain `kaizensystem.my.id` (Cloudflare Tunnel)

Domain tetap sama; yang berubah hanya "origin"-nya (PC kantor).

1. Pasang **cloudflared** di PC kantor (`winget install Cloudflare.cloudflared`
   atau unduh `cloudflared.exe`).
2. Login: `cloudflared tunnel login` (pilih zona `kaizensystem.my.id`).
3. Gunakan tunnel yang sudah ada (`kaizen-portal`) atau buat baru:
   `cloudflared tunnel create kaizen-portal`
4. Konfigurasi `config.yml`:

   ```yaml
   tunnel: <ID-TUNNEL>
   credentials-file: C:\Users\<user>\.cloudflared\<ID-TUNNEL>.json
   ingress:
     - hostname: kaizensystem.my.id
       service: https://localhost:443
       originRequest:
         noTLSVerify: true
     - service: http_status:404
   ```

5. Jalankan: `cloudflared tunnel run kaizen-portal`
6. Di dashboard Cloudflare: **DNS** → CNAME `@`/`www` ke `<ID-TUNNEL>.cfargotunnel.com`
   (proxied), dan **Rules → Redirect** `https://kaizensystem.my.id/` → `/kaizen/`
   bila ingin domain langsung membuka portal.
7. Agar jalan otomatis saat Windows menyala: buat shortcut `cloudflared tunnel run`
   di folder **Startup** (`shell:startup`), atau pakai NSSM sebagai service.

---

## 5. Backup harian di PC kantor

Skrip backup ikut tersalin di `htdocs\kaizen\tools\`.

1. Sesuaikan `tools\data\kredensial_backup.txt` bila password database diganti.
2. Pasang tugas terjadwal:

   ```powershell
   schtasks /create /tn "KAIZEN Backup Harian" /xml "C:\xampp\htdocs\kaizen\tools\backup_task.xml" /f
   ```

3. Ubah `D:\Backups\kaizen` di `backup_otomatis.ps1` bila PC kantor tidak punya
   drive `D:`.
4. Uji sekali: `schtasks /run /tn "KAIZEN Backup Harian"`

Detail lengkap: `htdocs\kaizen\tools\README_BACKUP.md`.

---

## 6. Daftar periksa setelah pindah

| No | Pemeriksaan | Cara cek |
|---|---|---|
| 1 | Portal terbuka | `https://localhost/kaizen/` → halaman login tampil |
| 2 | Login berhasil | masuk dengan akun Anda |
| 3 | Data lama utuh | cek menu QC, Material Receipt, data lama muncul |
| 4 | Upload/gambar tampil | buka record yang ada fotonya |
| 5 | MFA terkirim | login akun ber-MFA → kode masuk WhatsApp/email |
| 6 | Akses LAN | buka `https://<IP-PC>/kaizen/` dari HP |
| 7 | Domain | buka `https://kaizensystem.my.id/kaizen/` |
| 8 | Backup | `schtasks /run /tn "KAIZEN Backup Harian"` → `Last Result = 0` |

---

## 7. Bila ada masalah

| Gejala | Penyebab & solusi |
|---|---|
| `XAMPP tidak lengkap ...` | Path XAMPP salah → jalankan dengan `-Xampp "D:\xampp"` |
| `MySQL belum bisa dihubungi` | Start MySQL di XAMPP Control Panel, lalu ulangi |
| `Access denied for user 'root'` | Root MySQL punya password → ulangi dengan `-RootPass "..."` |
| Halaman menampilkan **error 500** | Cek `config.php` ada, MySQL jalan, lalu lihat `C:\xampp\apache\logs\error.log` |
| **"Gagal koneksi database"** | User `kaizen_app` belum dibuat → jalankan ulang `PASANG.ps1` |
| CSS/ikon tidak muncul | Folder `vendor`/`assets` tidak tersalin → salin ulang paket |
| Muncul peringatan sertifikat | Normal bila IP baru → jalankan `BUAT-SERTIFIKAT-BARU.ps1` |
| Tidak bisa dibuka dari HP | Firewall belum dibuka / IP salah / beda jaringan |
| Data hilang setelah impor | Impor dump ke database yang benar (`kaizen_hse_portal`) |

---

## 8. Catatan teknis

| Item | Nilai |
|---|---|
| Nama database | `kaizen_hse_portal` |
| User database aplikasi | `kaizen_app` (bukan root) |
| Karakter | `utf8mb4` / `utf8mb4_general_ci` |
| Kredensial | ada di `database\db.conf` dan `kaizen\config.php` |
| Token WhatsApp (Fonnte) | ada di `kaizen\tools\data\mfa_config.php` |
| Versi paket | lihat `VERSI.txt` |
