<#
  PASANG.ps1 - Memasang Portal KAIZEN di PC kantor / PC baru (XAMPP Windows)
  ---------------------------------------------------------------------------
  Isi paket yang harus ada di folder yang sama dengan skrip ini:
    kaizen\                      -> kode aplikasi lengkap
    database\kaizen_hse_portal.sql  -> dump database
    database\01_buat_database_dan_user.sql -> buat DB + user aplikasi
    database\db.conf             -> nama DB, user, password (dibuat otomatis)
    SSL\server.crt, SSL\server.key -> sertifikat HTTPS (opsional)

  Cara pakai (PowerShell biasa, TIDAK perlu admin kecuali tulis ke C:\xampp):
    powershell -NoProfile -ExecutionPolicy Bypass -File .\PASANG.ps1

  Opsi:
    -Xampp 'D:\xampp'      lokasi XAMPP di PC baru (default C:\xampp)
    -RootUser root          user MySQL admin
    -RootPass 'xxx'         password root MySQL (XAMPP baru biasanya kosong)
    -LewatiSertifikat       jangan timpa sertifikat HTTPS Apache
#>
[CmdletBinding()]
param(
    [string]$Xampp = 'C:\xampp',
    [string]$RootUser = 'root',
    [string]$RootPass = '',
    [switch]$LewatiSertifikat
)

$ErrorActionPreference = 'Stop'

$Paket     = $PSScriptRoot
$SumberApp = Join-Path $Paket 'kaizen'
$SqlBuat   = Join-Path $Paket 'database\01_buat_database_dan_user.sql'
$SqlDump   = Join-Path $Paket 'database\kaizen_hse_portal.sql'
$DbConf    = Join-Path $Paket 'database\db.conf'

function Judul($t) { Write-Host ''; Write-Host ('=== ' + $t + ' ===') -ForegroundColor Cyan }
function Ok($t)    { Write-Host ('  [OK] ' + $t) -ForegroundColor Green }
function Info($t)  { Write-Host ('       ' + $t) -ForegroundColor Gray }
function Peringatan($t) { Write-Host ('  [!]  ' + $t) -ForegroundColor Yellow }

function PortTerbuka([int]$port) {
    try {
        $c = New-Object Net.Sockets.TcpClient
        $c.Connect('127.0.0.1', $port)
        $c.Close()
        return $true
    } catch { return $false }
}

try {
    Write-Host ''
    Write-Host '  PASANG PORTAL KAIZEN - PC BARU' -ForegroundColor White
    Write-Host '  --------------------------------' -ForegroundColor DarkGray

    # ---------- 0) Cek isi paket ----------
    Judul '0. Memeriksa isi paket'
    foreach ($f in @($SumberApp, $SqlBuat, $SqlDump, $DbConf)) {
        if (-not (Test-Path $f)) { throw ('Paket tidak lengkap, tidak ditemukan: ' + $f) }
    }
    if (-not (Test-Path (Join-Path $SumberApp 'index.html'))) { throw 'Folder kaizen\ tidak berisi index.html' }
    Ok 'Isi paket lengkap'

    # ---------- 1) Cek XAMPP ----------
    Judul '1. Memeriksa XAMPP'
    $mysql   = Join-Path $Xampp 'mysql\bin\mysql.exe'
    $mysqld  = Join-Path $Xampp 'mysql\bin\mysqld.exe'
    $myIni   = Join-Path $Xampp 'mysql\bin\my.ini'
    $php     = Join-Path $Xampp 'php\php.exe'
    foreach ($f in @($mysql, $php)) {
        if (-not (Test-Path $f)) { throw ('XAMPP tidak lengkap di ' + $Xampp + ' (tidak ada ' + $f + '). Pasang XAMPP dulu, atau jalankan dengan -Xampp "D:\xampp".') }
    }
    Ok ('XAMPP ditemukan di ' + $Xampp)
    Info ('PHP  : ' + (& $php -r "echo PHP_VERSION;"))

    # ---------- 2) Pastikan MySQL menyala ----------
    Judul '2. Memastikan MySQL menyala'
    if (-not (PortTerbuka 3306)) {
        Peringatan 'MySQL belum menyala, mencoba menyalakan...'
        if (Test-Path $mysqld) {
            Start-Process -FilePath $mysqld -ArgumentList ('--defaults-file="' + $myIni + '"', '--standalone') -WindowStyle Hidden | Out-Null
            for ($i = 0; $i -lt 30; $i++) {
                Start-Sleep -Seconds 1
                if (PortTerbuka 3306) { break }
            }
        }
        if (-not (PortTerbuka 3306)) {
            throw 'MySQL belum bisa dihubungi di port 3306. Buka XAMPP Control Panel -> Start "MySQL", lalu jalankan ulang skrip ini.'
        }
        Ok 'MySQL dinyalakan'
    } else {
        Ok 'MySQL sudah berjalan (port 3306)'
    }

    # ---------- 3) Baca kredensial database dari paket ----------
    Judul '3. Membaca kredensial database'
    $cfg = @{}
    Get-Content $DbConf | ForEach-Object {
        if ($_ -match '^\s*([A-Za-z_]+)\s*=\s*(.*)$') { $cfg[$Matches[1].ToLower()] = $Matches[2].Trim() }
    }
    if (-not $cfg['db'] -or -not $cfg['user']) { throw 'db.conf tidak valid (butuh baris db=, user=, pass=)' }
    Ok ('Database : ' + $cfg['db'])
    Ok ('User app : ' + $cfg['user'])

    # ---------- 4) Salin aplikasi ke htdocs ----------
    Judul '4. Menyalin aplikasi ke htdocs'
    $htdocs = Join-Path $Xampp 'htdocs'
    if (-not (Test-Path $htdocs)) { New-Item -ItemType Directory -Path $htdocs -Force | Out-Null }
    $tujuan = Join-Path $htdocs 'kaizen'

    if (Test-Path $tujuan) {
        $lama = $tujuan + '.lama-' + (Get-Date -Format 'yyyyMMdd-HHmm')
        Move-Item -LiteralPath $tujuan -Destination $lama -Force
        Peringatan ('Folder kaizen lama dipindahkan ke: ' + $lama)
    }
    robocopy $SumberApp $tujuan /E /NFL /NDL /NJH /NJS /R:2 /W:2 | Out-Null
    if ($LASTEXITCODE -gt 7) { throw ('Gagal menyalin aplikasi (robocopy exit ' + $LASTEXITCODE + ')') }
    if (-not (Test-Path (Join-Path $tujuan 'index.html'))) { throw 'Penyalinan aplikasi gagal (index.html tidak ada)' }
    $jml = (Get-ChildItem $tujuan -Recurse -File | Measure-Object).Count
    Ok ('Aplikasi tersalin ke ' + $tujuan + ' (' + $jml + ' berkas)')

    # ---------- 5) Buat database + user aplikasi ----------
    Judul '5. Membuat database & user aplikasi'
    $cnf = Join-Path $env:TEMP ('kaizen_root_' + [guid]::NewGuid().ToString('N') + '.cnf')
    ("[client]`r`nuser=" + $RootUser + "`r`npassword=" + $RootPass) | Set-Content -Path $cnf -Encoding ASCII

    function JalankanSql([string]$file, [string]$dbName) {
        $errFile = Join-Path $env:TEMP ('kaizen_sql_err_' + [guid]::NewGuid().ToString('N') + '.txt')
        $argList = @('--defaults-extra-file=' + $cnf, '--default-character-set=utf8mb4')
        if ($dbName) { $argList += $dbName }
        $p = Start-Process -FilePath $mysql -ArgumentList $argList -RedirectStandardInput $file -RedirectStandardError $errFile -NoNewWindow -Wait -PassThru
        $pesan = ''
        if (Test-Path $errFile) { $pesan = (Get-Content $errFile -Raw -ErrorAction SilentlyContinue); Remove-Item $errFile -Force -ErrorAction SilentlyContinue }
        if ($p.ExitCode -ne 0) {
            throw ('Gagal menjalankan ' + (Split-Path $file -Leaf) + ' (exit ' + $p.ExitCode + '): ' + ($pesan -replace "`r?`n", ' '))
        }
    }

    try {
        JalankanSql $SqlBuat $null
        Ok 'Database & user aplikasi dibuat'
        JalankanSql $SqlDump $cfg['db']
        Ok 'Isi database (dump) berhasil diimpor'
    } finally {
        Remove-Item $cnf -Force -ErrorAction SilentlyContinue
    }

    # ---------- 6) Verifikasi database lewat aplikasi ----------
    Judul '6. Verifikasi koneksi aplikasi ke database'
    $cekFile = Join-Path $env:TEMP ('kaizen_cek_' + [guid]::NewGuid().ToString('N') + '.php')
    $configPath = (Join-Path $tujuan 'config.php') -replace '\\', '/'
    @"
<?php
require '$configPath';
`$t = `$DB->query('SHOW TABLES');
`$n = `$t ? `$t->num_rows : 0;
`$u = 0;
`$r = `$DB->query('SELECT COUNT(*) c FROM users');
if (`$r) { `$u = (int)`$r->fetch_assoc()['c']; }
echo 'tabel=' . `$n . ' user=' . `$u;
"@ | Set-Content -Path $cekFile -Encoding ASCII
    $hasil = & $php $cekFile 2>&1
    Remove-Item $cekFile -Force -ErrorAction SilentlyContinue
    if ($hasil -match 'tabel=(\d+) user=(\d+)') {
        $nTabel = [int]$Matches[1]; $nUser = [int]$Matches[2]
        if ($nTabel -lt 1) { throw 'Koneksi database berhasil tetapi tidak ada tabel. Impor dump gagal.' }
        Ok ("Koneksi aplikasi OK: $nTabel tabel, $nUser user")
    } else {
        throw ('Verifikasi gagal: ' + ($hasil -join ' '))
    }

    # ---------- 7) Sertifikat HTTPS ----------
    Judul '7. Sertifikat HTTPS Apache'
    $crtSrc = Join-Path $Paket 'SSL\server.crt'
    $keySrc = Join-Path $Paket 'SSL\server.key'
    if ($LewatiSertifikat) {
        Peringatan 'Dilewati (-LewatiSertifikat). Apache memakai sertifikat bawaan XAMPP.'
    } elseif ((Test-Path $crtSrc) -and (Test-Path $keySrc)) {
        $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
        $crtDst = Join-Path $Xampp 'apache\conf\ssl.crt\server.crt'
        $keyDst = Join-Path $Xampp 'apache\conf\ssl.key\server.key'
        foreach ($pair in @(@($crtDst, $crtSrc), @($keyDst, $keySrc))) {
            if (Test-Path $pair[0]) { Copy-Item $pair[0] ($pair[0] + '.bak-' + $stamp) -Force }
            Copy-Item $pair[1] $pair[0] -Force
        }
        Ok ('Sertifikat HTTPS dipasang (cadangan .bak-' + $stamp + ')')
        Info 'Bila IP PC ini berbeda, jalankan BUAT-SERTIFIKAT-BARU.ps1 agar tidak muncul peringatan sertifikat.'
    } else {
        Peringatan 'Folder SSL\ tidak ada di paket, sertifikat Apache tidak diubah.'
    }

    # ---------- 8) Cek halaman login ----------
    Judul '8. Cek halaman portal'
    if (PortTerbuka 443) {
        try {
            add-type @"
using System.Net; using System.Security.Cryptography.X509Certificates;
public class TrustAllCert : ICertificatePolicy {
  public bool CheckValidationResult(ServicePoint sp, X509Certificate c, WebRequest r, int p) { return true; }
}
"@ -ErrorAction SilentlyContinue
            [Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCert
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $resp = Invoke-WebRequest -Uri 'https://localhost/kaizen/' -UseBasicParsing -TimeoutSec 20
            if ($resp.StatusCode -eq 200) { Ok 'Halaman https://localhost/kaizen/ merespons (HTTP 200)' }
            else { Peringatan ('Halaman merespons HTTP ' + $resp.StatusCode) }
        } catch {
            Peringatan ('Belum bisa membuka halaman: ' + $_.Exception.Message + ' (Apache mungkin belum Start)')
        }
    } else {
        Peringatan 'Apache (port 443) belum menyala. Start "Apache" di XAMPP Control Panel, lalu buka https://localhost/kaizen/'
    }

    # ---------- Selesai ----------
    Judul 'SELESAI - LANGKAH BERIKUTNYA'
    Write-Host '  1. Buka XAMPP Control Panel -> Start "Apache" (MySQL sudah jalan).' -ForegroundColor White
    Write-Host '  2. Buka https://localhost/kaizen/ lalu login memakai akun Anda.' -ForegroundColor White
    Write-Host '  3. Agar bisa diakses PC/HP lain di jaringan kantor:' -ForegroundColor White
    Write-Host '     - izinkan Apache di Windows Firewall (port 80 & 443)' -ForegroundColor Gray
    Write-Host '     - pasang kaizen-ca.crt di perangkat lain (Trusted Root) bila ingin tanpa peringatan' -ForegroundColor Gray
    Write-Host '  4. Untuk domain kaizensystem.my.id: pasang Cloudflare Tunnel di PC ini.' -ForegroundColor White
    Write-Host '  5. Backup harian: lihat tools\README_BACKUP.md.' -ForegroundColor White
    Write-Host ''
    exit 0
}
catch {
    Write-Host ''
    Write-Host ('  GAGAL: ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host ''
    exit 1
}
