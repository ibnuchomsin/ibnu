# =====================================================================
#  Backup Otomatis Portal KAIZEN
#  Isi   : 1) Dump MySQL (user kaizen_app, tanpa password root)
#          2) ZIP folder uploads/ (foto & dokumen user)
#          3) ZIP snapshot kode sumber (tanpa .git, uploads, tools/data)
#  Retensi: file lebih tua dari 30 hari dihapus otomatis
#  Jalur : D:\Backups\kaizen\   |  Log: backup.log
#  Jadwal: Task Scheduler "KAIZEN Backup Harian" (01:00, catch-up jika
#          PC menyala terlambat)
#  Manual: powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\kaizen\tools\backup_otomatis.ps1"
#
#  Catatan versi 2 (tahan gangguan):
#   - Dump ditulis ke berkas .part dulu; berkas sementara dibersihkan bila
#     gagal (tidak lagi meninggalkan .sql 0 byte).
#   - Exit code + pesan error mysqldump dicatat ke log (tidak lagi 2>nul).
#   - Dump diulang 1x bila gagal, lalu divalidasi (ukuran, CREATE TABLE,
#     baris penutup "Dump completed").
#   - Setiap tahap dicatat, durasi total ikut dicatat.
# =====================================================================
$ErrorActionPreference = 'Stop'

$Root     = 'C:\xampp\htdocs\kaizen'
$Dest     = 'D:\Backups\kaizen'
$KeepDays = 30
$Stamp    = Get-Date -Format 'yyyy-MM-dd_HHmm'
$CredFile = Join-Path $Root 'tools\data\kredensial_backup.txt'
$MysqlBin = 'C:\xampp\mysql\bin'
$Log      = Join-Path $Dest 'backup.log'
$Mulai    = Get-Date

function Log($msg) {
    $line = '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    $line | Out-File -FilePath $Log -Append -Encoding utf8
}

# Berkas sementara yang harus dibersihkan bila backup gagal
$Sementara = New-Object System.Collections.ArrayList

function BersihkanSementara {
    foreach ($f in $Sementara) {
        Remove-Item -LiteralPath $f -Force -ErrorAction SilentlyContinue
    }
}

try {
    if (-not (Test-Path $Dest)) { New-Item -ItemType Directory -Path $Dest -Force | Out-Null }
    Log '========== BACKUP MULAI =========='

    # ---------- 0) Baca kredensial dump ----------
    if (-not (Test-Path $CredFile)) { throw ('File kredensial tidak ada: ' + $CredFile) }
    $user = $null; $pass = $null
    Get-Content $CredFile | ForEach-Object {
        if ($_ -match '^user=(.+)$') { $user = $Matches[1].Trim() }
        if ($_ -match '^pass=(.+)$') { $pass = $Matches[1].Trim() }
    }
    if (-not $user -or -not $pass) { throw 'Format kredensial_backup.txt tidak valid (butuh baris user=... dan pass=...)' }

    # ---------- 1) Dump database MySQL ----------
    $dbFile = Join-Path $Dest ('kaizen_db_' + $Stamp + '.sql')
    $partDb = $dbFile + '.part'
    $errFile = Join-Path $env:TEMP ('kaizen_dump_err_' + $Stamp + '.txt')
    $cnf = Join-Path $env:TEMP 'kaizen_dump_cnf.cnf'
    [void]$Sementara.Add($partDb)
    [void]$Sementara.Add($errFile)

    '[client]' | Set-Content -Path $cnf -Encoding ASCII
    ('user=' + $user)     | Add-Content -Path $cnf -Encoding ASCII
    ('password=' + $pass) | Add-Content -Path $cnf -Encoding ASCII
    [void]$Sementara.Add($cnf)

    $dumpExe = Join-Path $MysqlBin 'mysqldump.exe'
    if (-not (Test-Path $dumpExe)) { throw ('mysqldump tidak ditemukan: ' + $dumpExe) }

    $dumpCmdCore = '""' + $dumpExe + '""' +
                   ' --defaults-extra-file=""' + $cnf + '""' +
                   ' --single-transaction --quick --default-character-set=utf8mb4' +
                   ' kaizen_hse_portal'

    $dumpOk = $false
    for ($coba = 1; $coba -le 2; $coba++) {
        Remove-Item $partDb, $errFile -Force -ErrorAction SilentlyContinue
        cmd /c ($dumpCmdCore + ' > ""' + $partDb + '"" 2> ""' + $errFile + '""')
        $dumpExit = $LASTEXITCODE

        $partSize = 0
        if (Test-Path $partDb) { $partSize = [Math]::Round((Get-Item $partDb).Length / 1KB, 0) }
        $jmlTabel = 0
        if (Test-Path $partDb) { $jmlTabel = (Select-String -Path $partDb -SimpleMatch 'CREATE TABLE' -ErrorAction SilentlyContinue | Measure-Object).Count }
        $adaPenutup = $false
        if (Test-Path $partDb) { $adaPenutup = [bool](Select-String -Path $partDb -SimpleMatch 'Dump completed' -ErrorAction SilentlyContinue | Select-Object -First 1) }

        if ($dumpExit -eq 0 -and $partSize -ge 2 -and $jmlTabel -ge 1 -and $adaPenutup) {
            $dumpOk = $true
            break
        }

        $pesan = ''
        if (Test-Path $errFile) { $pesan = ((Get-Content $errFile -ErrorAction SilentlyContinue) -join ' | ').Trim() }
        if (-not $pesan) { $pesan = '(tidak ada pesan dari mysqldump)' }
        if ($pesan.Length -gt 300) { $pesan = $pesan.Substring(0, 300) + '...' }
        Log ('DB   !! : percobaan ' + $coba + ' gagal (exit=' + $dumpExit + ', ' + $partSize + ' KB, tabel=' + $jmlTabel + ', penutup=' + $adaPenutup + ') ' + $pesan)
        if ($coba -lt 2) { Start-Sleep -Seconds 5 }
    }
    if (-not $dumpOk) { throw 'Dump database gagal setelah 2 percobaan (lihat baris DB !! di atas)' }

    Move-Item -LiteralPath $partDb -Destination $dbFile -Force
    [void]$Sementara.Remove($partDb)
    Remove-Item $errFile -Force -ErrorAction SilentlyContinue
    [void]$Sementara.Remove($errFile)

    $dbSize = [Math]::Round((Get-Item $dbFile).Length / 1KB, 0)
    $tables = (Select-String -Path $dbFile -SimpleMatch 'CREATE TABLE' | Measure-Object).Count
    Log ('DB   OK : ' + (Split-Path $dbFile -Leaf) + ' (' + $dbSize + ' KB, ' + $tables + ' tabel)')

    # ---------- 2) ZIP uploads/ ----------
    $uploadsDir = Join-Path $Root 'uploads'
    $upZip = Join-Path $Dest ('kaizen_uploads_' + $Stamp + '.zip')
    $jmlUp = (Get-ChildItem $uploadsDir -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count
    if ($jmlUp -gt 0) {
        [void]$Sementara.Add($upZip)
        Compress-Archive -Path (Join-Path $uploadsDir '*') -DestinationPath $upZip -Force
        $upSize = [Math]::Round((Get-Item $upZip).Length / 1KB, 0)
        [void]$Sementara.Remove($upZip)
        Log ('UP   OK : ' + (Split-Path $upZip -Leaf) + ' (' + $upSize + ' KB, ' + $jmlUp + ' berkas)')
    } else {
        Log 'UP   -- : uploads/ kosong, dilewati'
    }

    # ---------- 3) ZIP snapshot kode sumber ----------
    $staging = Join-Path $env:TEMP ('kaizen_code_' + $Stamp)
    if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
    robocopy $Root $staging /E /XD .git uploads 'tools\data' /XF *.log *.tmp | Out-Null
    $rcExit = $LASTEXITCODE
    if ($rcExit -gt 7) { throw ('robocopy gagal (exit=' + $rcExit + ') saat menyalin kode sumber') }
    if (-not (Test-Path (Join-Path $staging 'index.html'))) { throw 'Snapshot kode sumber tidak lengkap (index.html tidak ada)' }

    $codeZip = Join-Path $Dest ('kaizen_code_' + $Stamp + '.zip')
    [void]$Sementara.Add($codeZip)
    Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $codeZip -Force
    Remove-Item $staging -Recurse -Force
    $codeSize = [Math]::Round((Get-Item $codeZip).Length / 1KB, 0)
    [void]$Sementara.Remove($codeZip)
    Log ('CODE OK : ' + (Split-Path $codeZip -Leaf) + ' (' + $codeSize + ' KB)')

    # ---------- 4) Retensi: hapus > KeepDays ----------
    $hapus = Get-ChildItem $Dest -File |
        Where-Object { $_.Name -ne 'backup.log' -and $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) }
    if ($hapus) {
        $hapus | Remove-Item -Force
        Log ('RETENSI  : dihapus ' + @($hapus).Count + ' berkas lebih tua dari ' + $KeepDays + ' hari')
    } else {
        Log ('RETENSI  : tidak ada berkas melewati ' + $KeepDays + ' hari')
    }

    $durasi = [Math]::Round(((Get-Date) - $Mulai).TotalSeconds, 1)
    Log ('========== BACKUP SELESAI OK (' + $durasi + ' detik) ==========')
    exit 0
}
catch {
    BersihkanSementara
    Log ('ERROR : ' + $_.Exception.Message)
    Log '========== BACKUP GAGAL =========='
    exit 1
}
