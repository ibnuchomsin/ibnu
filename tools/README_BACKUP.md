# Backup Otomatis Portal KAIZEN

Folder ini berisi skrip backup harian portal **KAIZEN IT SYSTEM & HSE PORTAL**
(`C:\xampp\htdocs\kaizen`) dan definisi Tugas Terjadwal Windows-nya.

## Isi backup

| Berkas | Isi |
|---|---|
| `kaizen_db_YYYY-MM-DD_HHmm.sql` | Dump database `kaizen_hse_portal` (struktur + data) |
| `kaizen_uploads_YYYY-MM-DD_HHmm.zip` | Folder `uploads/` (foto & dokumen) |
| `kaizen_code_YYYY-MM-DD_HHmm.zip` | Snapshot kode sumber (tanpa `.git`, `uploads/`, `tools/data/`, `*.log`) |
| `backup.log` | Catatan setiap proses backup |

Lokasi hasil: **`D:\Backups\kaizen\`**
Retensi: berkas lebih tua dari **30 hari** dihapus otomatis.
Kredensial dump: `tools/data/kredensial_backup.txt` (tidak masuk Git).

## Jadwal

Tugas Terjadwal Windows: **"KAIZEN Backup Harian"**

- Jalan **setiap hari pukul 01:00**.
- `StartWhenAvailable` aktif → bila PC mati/tertidur pada 01:00, backup
  dijalankan begitu PC menyala.
- `WakeToRun` aktif → PC dibangunkan dari tidur untuk menjalankan backup.
- `RestartOnFailure` → dicoba ulang 3x (jeda 15 menit) bila gagal.
- Tetap jalan saat memakai baterai (`DisallowStartIfOnBatteries=false`).

## Menjalankan manual

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\kaizen\tools\backup_otomatis.ps1"
```

Atau lewat Task Scheduler:

```powershell
schtasks /run /tn "KAIZEN Backup Harian"
```

## Cek status

```powershell
schtasks /query /tn "KAIZEN Backup Harian" /v /fo LIST | Select-String "Last Run Time|Last Result|Next Run Time"
Get-Content "D:\Backups\kaizen\backup.log" -Tail 15
```

`Last Result` = `0` berarti sukses. Kode lain (mis. `-1073741510` = proses
dihentikan karena PC mati/tertidur di tengah backup) berarti gagal — cek log.

## Validasi isi dump

Skrip sudah memvalidasi otomatis: exit code `mysqldump` harus 0, ukuran > 2 KB,
ada `CREATE TABLE`, dan ada baris penutup `-- Dump completed`. Dump ditulis ke
berkas `.part` lebih dulu lalu di-rename, jadi tidak pernah ada `.sql` 0 byte.
Bila gagal, berkas sementara dibersihkan dan pesan error asli dicatat ke log.

## Cara restore

1. **Database**

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u kaizen_app -p kaizen_hse_portal < "D:\Backups\kaizen\kaizen_db_2026-11-20_0835.sql"
   ```

   (atau lewat phpMyAdmin → pilih database → Import)

2. **Uploads** — ekstrak `kaizen_uploads_*.zip` ke `C:\xampp\htdocs\kaizen\uploads\`.

3. **Kode** — ekstrak `kaizen_code_*.zip` ke folder web, lalu buat ulang
   `config.php` dan `tools/data/mfa_config.php` (keduanya tidak ikut backup).

## Memasang ulang tugas di PC/VPS baru

```powershell
schtasks /create /tn "KAIZEN Backup Harian" /xml "C:\xampp\htdocs\kaizen\tools\backup_task.xml" /f
```

Sesuaikan dulu path di `backup_task.xml` bila folder portal berbeda.
