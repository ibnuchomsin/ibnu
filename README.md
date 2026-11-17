# KAIZEN IT SYSTEM & HSE PORTAL — Enterprise V1.0

Portal operasional internal (Helpdesk IT, Aset IT, Gemba HSE 5R, Gemba QC, Arsip Dokumen, Laporan) berbasis **HTML + PHP + MySQL**, berjalan penuh di jaringan lokal (XAMPP) tanpa ketergantungan internet.

---

## 1. Akses

| Jenis | URL |
|---|---|
| **HTTPS (utama, direkomendasi)** | `https://192.168.1.42/kaizen/` |
| HTTPS (jaringan kantor, adapter hidup) | `https://10.10.0.236/kaizen/` |
| HTTP (cadangan) | `http://192.168.1.42/kaizen/` atau `http://10.10.0.236/kaizen/` |

- Sertifikat HTTPS **self-signed sudah di-trust** di Windows (Trusted Root) — Chrome menampilkan gembok langsung tanpa peringatan.
- Kedua protokol (HTTP & HTTPS) menayangkan aplikasi yang sama; sertifikat berlaku utk `192.168.1.42`, `10.10.0.236`, `localhost`, dan hostname `SERSAN-IBNU` sampai **Okt 2036** (10 tahun).

### Install PWA di HP
1. Buka salah satu URL **HTTPS** di Chrome HP.
2. Login dulu → menu Chrome ⋮ → *Add to Home Screen*.
3. Ikon portal muncul di Home Screen, berjalan seperti aplikasi.

> ⚠️ Setelah revisi besar, bila tampilan terasa lama: `Ctrl+F5` di PC; di HP tutup tab & buka ulang (header `no-store` sudah aktif sejak 22 Okt, kejadian ini semestinya tidak terulang).

---

## 2. Akun Login

- Akun dikelola dari menu **Manajemen User** (khusus superadmin/admin) dengan **hak akses per modul**: Helpdesk, Aset IT, Gemba HSE, Gemba QC, Arsip.
- Password akun disimpan sebagai hash bcrypt; tidak pernah dikirim balik ke aplikasi.
- Tiap user dapat mengganti sandinya sendiri: klik **chip nama (pojok kanan-atas) → Ganti Password**, atau tombol ganti password di sidebar.
- Password superadmin direset ke sandi kuat pada **24 Okt 2026** — ganti berkala.

---

## 3. Fitur Utama

### Modul
- **Dashboard Analytics** — statistik shelter per periode (filter bulan/tahun/tombol "Semua Waktu"), 4 grafik + grafik aktivitas login (klik batang → detail user & jumlah login), kotak "Top 5 Pemakai Login", tombol **Export** riwayat login (Excel 2 sheet: Riwayat + Rekap).
- **Helpdesk & Tiket IT** — tiket keluhan + foto before/after, status alur (Open → Proses → Selesai → Closed), penugasan teknisi.
- **Pendataan Aset IT** — aset + S/N, tanggal beli, biaya servis, foto.
- **Gemba HSE (5R)** — temuan dengan **Kategori** wajib, PIC, divisi, due date, foto before/after.
- **Gemba QC** — temuan deviasi produksi (alur serupa).
- **Arsip Dokumen** — folder berwarna + unggah berkas (pdf/office/gambar, maks 6MB/berkas), fisik tersimpan di `uploads/`.
- **Laporan** — Excel/CSV/PDF-cetak per periode; foto disisipkan OTOMATIS ke dalam file .xlsx.
- **Manajemen User** — buat/edit user, role (superadmin/admin/user), status Aktif/Non-aktif, hak akses per modul.

### Kepatuhan & Otomatis
- **Banner reminder WhatsApp** otomatis (temuan mendekati/lewat jatuh tempo) + tombol kirim WA ke admin.
- **Task Scheduler Windows**: tugas `Kaizen WA Reminder HSE` berjalan 07:00 tiap hari → `tools/whatsapp_reminder.php` (Fonnte).
- Semua menu dapat dibuka di **tab baru** (`?tab=...`) dengan cek izin otomatis — perlu login ulang di tab tsb (by design, aman).

---

## 4. Teknis Lingkungan

| Komponen | Nilai |
|---|---|
| Server | XAMPP 8.2 — `C:\xampp` (Apache2.4 + mysql, auto-start) |
| Folder aplikasi | `C:\xampp\htdocs\kaizen\` |
| Endpoint API | `api.php` (REST, JSON, sesi cookie `KAIZENSESSID`) |
| Konfigurasi DB | `config.php` — DB `kaizen_hse_portal`, user khusus `kaizen_app` (bukan root) |
| Tabel | users, tickets, assets, gemba_findings, gemba_qc_findings, documents, folders, reminder_log, login_log |

### 100% Tanpa Internet (vendor lokal, sejak 24 Okt 2026)
Semua pustaka tersimpan di `vendor/` (tidak ada lagi pemanggilan CDN):
```
vendor/tailwind.js            (Tailwind Play)
vendor/chart.umd.js           (Chart.js 4)
vendor/exceljs.min.js         (ExcelJS 4.3)
vendor/filesaver.min.js       (FileSaver)
vendor/fontawesome/           (FontAwesome 6.4 + webfonts)
vendor/fonts/inter.css        (Google Fonts Inter, 6 weight, lokal)
```

### .htaccess
- Header `Cache-Control: no-store` — HTML selalu termuat versi terbaru.
- Batas PHP: upload 16M / post 64M.
- Folder `uploads/`: **dilarang** menjalankan PHP/EXE apa pun (`Require all denied`), tanpa directory index.

### Keamanan API (kunci)
- Semua operasi data wajib sesi (401 `SESSION_EXPIRED`).
- Login: password_verify bcrypt + upgrade otomatis dari plaintext lama; `session_regenerate_id` anti-fixation; login berhasil dicatat ke `login_log`.
- Cookie sesi: `HttpOnly` + `SameSite=Lax`.
- Anti CSRF: POST wajib `Content-Type: application/json` (415 jika bukan) + header `Origin` dinonaktifkan bila bukan host server ini sendiri (403 `ORIGIN_FORBIDDEN`).
- Guard role: **hapus data & manajemen Users hanya untuk admin/superadmin** (403).
- Whistlist kolom per tabel; query parameter prepared-statement; nama tabel dinamis dari whitelist internal saja.
- Upload: whitelist MIME/ekstensi (jpg png webp gif pdf doc docx xls xlsx csv txt), maks 6 MB, nama file acak.
- XSS: semua nilai user di-escape (`escapeHTML`) sebelum dirender.
- Password pengguna TIDAK pernah dikirim balik (placeholder `__HASHED__`); kolom kosong = sandi tidak diubah.
- Header: `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy: same-origin`; versi PHP disembunyikan.

---

## 5. Pencadangan (Backup)

```bash
# Backup DENGAN data (jalankan berkala, mis. 2 mingguan):
C:\xampp\mysql\bin\mysqldump.exe -u root -p --result-file="D:\Backups\kaizen-hsel-%date%.sql" kaizen_hse_portal
```
- Snapshot terakhir: `D:\Downloads\A ANDROID\kaizen_hse_portal.sql` (24 Okt 2026, 9 tabel penuh).
- Berkas unggahan penting juga: salin folder `C:\xampp\htdocs\kaizen\uploads\`.

### memulihkan (restore)
```bash
C:\xampp\mysql\bin\mysql.exe -u root -p -e "CREATE DATABASE IF NOT EXISTS kaizen_hse_portal CHARACTER SET utf8mb4;"
C:\xampp\mysql\bin\mysql.exe -u root -p kaizen_hse_portal < "...file-dump.sql"
```
> File dump SQL lama yang tidak memuat `login_log`/`kategori` sudah tidak dipakai.

---

## 6. Masalah & Solusi Cepat

| Gejala | Solusi |
|---|---|
| Halaman tak terbuka | Layanan Apache2.4 & mysql melalui XAMPP Control Panel / services.msc |
| Port 443/80 konflik | `netstat -ano \| findstr :443` — cari proses pemilik |
| Ganti IP LAN | Update SAN sertifikat (regenerate, lihat bawah) |
| Excel kosong/berupa gambar salah | Refresh tab — modul vendor di-load dari server lokal |
| Notifikasi "Insecure download" | sudah tidak berlaku — pakai **HTTPS**`

### Regenerate sertifikat HTTPS (kalau perlu)
```bash
C:\xampp\apache\bin\openssl.exe req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 ^
  -config C:\xampp\apache\conf\openssl.cnf ^
  -keyout C:\xampp\apache\conf\ssl.key\server.key ^
  -out    C:\xampp\apache\conf\ssl.crt\server.crt ^
  -subj "/C=ID/O=Kaizen Portal/CN=kaizen-portal" ^
  -addext "subjectAltName=DNS:kaizen-portal,DNS:localhost,DNS:SERSAN-IBNU,IP:10.10.0.236,IP:192.168.1.42,IP:127.0.0.1"
# lalu Restart Apache (admin) dan import ulang:
# certutil -addstore -f Root C:\xampp\apache\conf\ssl.crt\server.crt
```

---

## 7. CatatanDasar Migrasi Online (VPS) — nanti
1. Semua bagian template tidak berubah (HTTP → HTTPS resmi, domain).
2. `config.php` — isi kredensial DB VPS.
3. Ganti semua password akun lewat menu Change Password.
4. file `uploads/` ikonik di-upload via SFTP.
5. Tools reminder WA: path CLI php sesuai distro.

---
*Lisensi internal perusahaan. Dokumentasi diperbarui 24 Okt 2026 — sepadan dengan audit keamanan & for LAN hardening.*
