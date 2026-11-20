<#
  buat_paket_migrasi.ps1
  ---------------------------------------------------------------------------
  Membuat paket pindahan Portal KAIZEN ke PC kantor / VPS dalam SATU folder
  (dan satu file .zip), berisi:
    - kode aplikasi lengkap (termasuk config.php, uploads/, vendor/)
    - dump database terbaru
    - sertifikat HTTPS Apache
    - skrip pemasangan otomatis + panduan

  Cara pakai:
    powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\kaizen\tools\buat_paket_migrasi.ps1"

  Hasil default:
    D:\Backups\kaizen-migrasi\KAIZEN-MIGRASI-yyyy-MM-dd\      (folder)
    D:\Backups\kaizen-migrasi\KAIZEN-MIGRASI-yyyy-MM-dd.zip  (1 file siap copy)

  Opsi:
    -Tujuan "E:\Pindahan"   lokasi hasil
    -Nama "KAIZEN-KANTOR"   nama folder/zip
#>
[CmdletBinding()]
param(
    [string]$Tujuan = 'D:\Backups\kaizen-migrasi',
    [string]$Nama = ''
)

$ErrorActionPreference = 'Stop'

$Root      = 'C:\xampp\htdocs\kaizen'
$MysqlBin  = 'C:\xampp\mysql\bin'
$Php       = 'C:\xampp\php\php.exe'
$CertCrt   = 'C:\xampp\apache\conf\ssl.crt\server.crt'
$CertKey   = 'C:\xampp\apache\conf\ssl.key\server.key'
$SumberMig = Join-Path $Root 'tools\migrasi'
$ConfigPhp = Join-Path $Root 'config.php'

if (-not $Nama) { $Nama = 'KAIZEN-MIGRASI-' + (Get-Date -Format 'yyyy-MM-dd') }
$PaketDir = Join-Path $Tujuan $Nama

function Langkah($t) { Write-Host ''; Write-Host ('=== ' + $t + ' ===') -ForegroundColor Cyan }
function Ok($t)      { Write-Host ('  [OK] ' + $t) -ForegroundColor Green }
function Info($t)    { Write-Host ('       ' + $t) -ForegroundColor Gray }
function Gagal($t)   { Write-Host ('  [X]  ' + $t) -ForegroundColor Red }

try {
    Write-Host ''
    Write-Host '  MEMBUAT PAKET MIGRASI PORTAL KAIZEN' -ForegroundColor White
    Write-Host '  -----------------------------------' -ForegroundColor DarkGray

    # ---------- 0) Baca konfigurasi database ----------
    Langkah '0. Membaca konfigurasi database'
    if (-not (Test-Path $ConfigPhp)) { throw ('config.php tidak ditemukan: ' + $ConfigPhp) }
    $isiConfig = Get-Content $ConfigPhp -Raw
    $dbName = ''; $dbUser = ''; $dbPass = ''
    if ($isiConfig -match "define\('DB_NAME',\s*'([^']*)'\)") { $dbName = $Matches[1] }
    if ($isiConfig -match "define\('DB_USER',\s*'([^']*)'\)") { $dbUser = $Matches[1] }
    if ($isiConfig -match "define\('DB_PASS',\s*'([^']*)'\)") { $dbPass = $Matches[1] }
    if (-not $dbName -or -not $dbUser) { throw 'Tidak bisa membaca DB_NAME/DB_USER dari config.php' }
    Ok ('Database : ' + $dbName)
    Ok ('User app : ' + $dbUser)

    # ---------- 1) Siapkan folder paket ----------
    Langkah '1. Menyiapkan folder paket'
    if (Test-Path $PaketDir) { Remove-Item $PaketDir -Recurse -Force }
    if (-not (Test-Path $Tujuan)) { New-Item -ItemType Directory -Path $Tujuan -Force | Out-Null }
    New-Item -ItemType Directory -Path $PaketDir -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $PaketDir 'database') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $PaketDir 'SSL') -Force | Out-Null
    Ok ('Folder paket: ' + $PaketDir)

    # ---------- 2) Salin kode aplikasi ----------
    Langkah '2. Menyalin kode aplikasi'
    $appDst = Join-Path $PaketDir 'kaizen'
    robocopy $Root $appDst /E /XD .git /XF *.log *.tmp *.bak-* login_attempts.json /NFL /NDL /NJH /NJS /R:2 /W:2 | Out-Null
    if ($LASTEXITCODE -gt 7) { throw ('robocopy gagal (exit ' + $LASTEXITCODE + ')') }
    if (-not (Test-Path (Join-Path $appDst 'index.html'))) { throw 'Penyalinan aplikasi gagal (index.html tidak ada)' }
    if (-not (Test-Path (Join-Path $appDst 'config.php'))) { throw 'config.php tidak ikut tersalin' }
    if (-not (Test-Path (Join-Path $appDst 'tools\data\mfa_config.php'))) { throw 'tools\data\mfa_config.php tidak ikut tersalin' }
    $jmlApp = (Get-ChildItem $appDst -Recurse -File | Measure-Object).Count
    Ok ('Aplikasi tersalin: ' + $jmlApp + ' berkas')

    # ---------- 3) Dump database ----------
    Langkah '3. Membuat dump database'
    $dumpFile = Join-Path $PaketDir ('database\' + $dbName + '.sql')
    $partFile = $dumpFile + '.part'
    $cnf = Join-Path $env:TEMP ('kaizen_migrasi_' + [guid]::NewGuid().ToString('N') + '.cnf')
    ("[client]`r`nuser=" + $dbUser + "`r`npassword=" + $dbPass) | Set-Content -Path $cnf -Encoding ASCII
    $errFile = Join-Path $env:TEMP ('kaizen_migrasi_err_' + [guid]::NewGuid().ToString('N') + '.txt')

    $dumpExe = Join-Path $MysqlBin 'mysqldump.exe'
    if (-not (Test-Path $dumpExe)) { throw ('mysqldump tidak ditemukan: ' + $dumpExe) }

    $dumpOk = $false
    for ($coba = 1; $coba -le 2; $coba++) {
        Remove-Item $partFile, $errFile -Force -ErrorAction SilentlyContinue
        # Catatan: tanpa --routines/--events karena user aplikasi tidak punya
        # hak EVENT; database ini juga tidak memakai routine/event/trigger.
        $cmd = '""' + $dumpExe + '""' +
               ' --defaults-extra-file=""' + $cnf + '""' +
               ' --single-transaction --quick' +
               ' --default-character-set=utf8mb4' +
               ' ' + $dbName + ' > ""' + $partFile + '"" 2> ""' + $errFile + '""'
        cmd /c $cmd
        $exit = $LASTEXITCODE
        $partKb = 0
        if (Test-Path $partFile) { $partKb = [Math]::Round((Get-Item $partFile).Length / 1KB, 0) }
        $jmlTabel = 0
        $adaPenutup = $false
        if (Test-Path $partFile) {
            $jmlTabel = (Select-String -Path $partFile -SimpleMatch 'CREATE TABLE' -ErrorAction SilentlyContinue | Measure-Object).Count
            $adaPenutup = [bool](Select-String -Path $partFile -SimpleMatch 'Dump completed' -ErrorAction SilentlyContinue | Select-Object -First 1)
        }
        if ($exit -eq 0 -and $partKb -ge 2 -and $jmlTabel -ge 1 -and $adaPenutup) { $dumpOk = $true; break }
        $pesan = ''
        if (Test-Path $errFile) { $pesan = ((Get-Content $errFile -ErrorAction SilentlyContinue) -join ' | ').Trim() }
        Gagal ('Dump percobaan ' + $coba + ' gagal (exit=' + $exit + ', ' + $partKb + ' KB) ' + $pesan)
        if ($coba -lt 2) { Start-Sleep -Seconds 3 }
    }
    Remove-Item $cnf, $errFile -Force -ErrorAction SilentlyContinue
    if (-not $dumpOk) { throw 'Dump database gagal, paket tidak dibuat' }
    Move-Item $partFile $dumpFile -Force
    $dumpKb = [Math]::Round((Get-Item $dumpFile).Length / 1KB, 0)
    Ok ('Dump database: ' + (Split-Path $dumpFile -Leaf) + ' (' + $dumpKb + ' KB, ' + $jmlTabel + ' tabel)')

    # ---------- 4) Berkas database pendukung ----------
    Langkah '4. Membuat berkas pendukung database'
    $dbConf = @"
# Kredensial database paket migrasi KAIZEN ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))
# JANGAN disebarkan. Dipakai oleh PASANG.ps1.
db=$dbName
user=$dbUser
pass=$dbPass
"@
    Set-Content -Path (Join-Path $PaketDir 'database\db.conf') -Value $dbConf -Encoding ASCII

    $sqlBuat = @"
-- =====================================================================
--  Membuat database + user aplikasi Portal KAIZEN
--  Dijalankan otomatis oleh PASANG.ps1 sebagai user admin MySQL (root).
--  Aman dijalankan berulang kali (IF NOT EXISTS).
-- =====================================================================
CREATE DATABASE IF NOT EXISTS ``$dbName``
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass';
CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass';
ALTER USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPass';
ALTER USER '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass';

GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'localhost';
GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'127.0.0.1';
FLUSH PRIVILEGES;

SELECT 'Database dan user aplikasi siap.' AS hasil;
"@
    Set-Content -Path (Join-Path $PaketDir 'database\01_buat_database_dan_user.sql') -Value $sqlBuat -Encoding ASCII
    Ok 'db.conf + 01_buat_database_dan_user.sql dibuat'

    # ---------- 5) Skrip & panduan ----------
    Langkah '5. Menyalin skrip pemasangan & panduan'
    foreach ($f in @('PASANG.ps1', 'BUAT-SERTIFIKAT-BARU.ps1', 'PANDUAN-INSTALASI.md')) {
        $src = Join-Path $SumberMig $f
        if (-not (Test-Path $src)) { throw ('Berkas pemasangan tidak ditemukan: ' + $src) }
        Copy-Item $src (Join-Path $PaketDir $f) -Force
    }
    Ok 'PASANG.ps1, BUAT-SERTIFIKAT-BARU.ps1, PANDUAN-INSTALASI.md'

    # ---------- 6) Sertifikat HTTPS ----------
    Langkah '6. Menyalin sertifikat HTTPS'
    if ((Test-Path $CertCrt) -and (Test-Path $CertKey)) {
        Copy-Item $CertCrt (Join-Path $PaketDir 'SSL\server.crt') -Force
        Copy-Item $CertKey (Join-Path $PaketDir 'SSL\server.key') -Force
        Ok 'server.crt + server.key'
    } else {
        Gagal 'Sertifikat Apache tidak ditemukan, paket tanpa SSL'
    }

    # ---------- 7) Informasi versi ----------
    Langkah '7. Menulis informasi versi'
    $phpVer = (& $Php -r "echo PHP_VERSION;") -join ''
    $mysqlVer = (& (Join-Path $MysqlBin 'mysql.exe') --version) -join ''
    $versi = @"
PAKET MIGRASI PORTAL KAIZEN
Dibuat      : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
Komputer    : $env:COMPUTERNAME
Folder asal : $Root
PHP         : $phpVer
MySQL       : $mysqlVer
Database    : $dbName
Jumlah tabel: $jmlTabel
Jumlah berkas aplikasi: $jmlApp
Ukuran dump : $dumpKb KB
"@
    Set-Content -Path (Join-Path $PaketDir 'VERSI.txt') -Value $versi -Encoding UTF8
    Ok 'VERSI.txt'

    # ---------- 8) Checksum ----------
    Langkah '8. Membuat checksum SHA256'
    $baris = New-Object System.Collections.ArrayList
    Get-ChildItem $PaketDir -Recurse -File | Where-Object { $_.Name -ne 'SHA256.txt' } | Sort-Object FullName | ForEach-Object {
        $rel = $_.FullName.Substring($PaketDir.Length + 1)
        $hash = (Get-FileHash -Path $_.FullName -Algorithm SHA256).Hash.ToLower()
        [void]$baris.Add(($hash + '  ' + $rel))
    }
    Set-Content -Path (Join-Path $PaketDir 'SHA256.txt') -Value ($baris -join "`r`n") -Encoding ASCII
    Ok ('SHA256.txt (' + $baris.Count + ' berkas)')

    # ---------- 9) Ukuran & ZIP ----------
    Langkah '9. Membuat file ZIP'
    $stat = Get-ChildItem $PaketDir -Recurse -File | Measure-Object Length -Sum
    $ukuranFolder = [Math]::Round($stat.Sum / 1MB, 1)
    $zipFile = Join-Path $Tujuan ($Nama + '.zip')
    if (Test-Path $zipFile) { Remove-Item $zipFile -Force }
    Compress-Archive -Path (Join-Path $PaketDir '*') -DestinationPath $zipFile -CompressionLevel Optimal
    $ukuranZip = [Math]::Round((Get-Item $zipFile).Length / 1MB, 1)
    Ok ('ZIP: ' + (Split-Path $zipFile -Leaf) + ' (' + $ukuranZip + ' MB)')

    # ---------- Ringkasan ----------
    Langkah 'SELESAI'
    Write-Host ('  Folder : ' + $PaketDir) -ForegroundColor White
    Write-Host ('           ' + $ukuranFolder + ' MB, ' + $stat.Count + ' berkas') -ForegroundColor Gray
    Write-Host ('  ZIP    : ' + $zipFile) -ForegroundColor White
    Write-Host ('           ' + $ukuranZip + ' MB (satu file, siap dibawa flashdisk)') -ForegroundColor Gray
    Write-Host ''
    Write-Host '  Di PC kantor: ekstrak ZIP, lalu jalankan' -ForegroundColor Yellow
    Write-Host '    powershell -NoProfile -ExecutionPolicy Bypass -File .\PASANG.ps1' -ForegroundColor Gray
    Write-Host ''
    exit 0
}
catch {
    Write-Host ''
    Write-Host ('  GAGAL: ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host ''
    exit 1
}
