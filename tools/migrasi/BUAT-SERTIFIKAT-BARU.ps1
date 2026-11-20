<#
  BUAT-SERTIFIKAT-BARU.ps1
  ---------------------------------------------------------------------------
  Membuat ulang sertifikat HTTPS untuk PC ini (mis. PC kantor) supaya alamat
  LAN seperti https://192.168.1.50/kaizen/ tidak memunculkan peringatan
  "sertifikat tidak valid" di browser.

  Sertifikat lama hanya berlaku untuk IP rumah (192.168.1.42 / 10.10.0.236),
  jadi setelah pindah PC alamat IP-nya berbeda -> sertifikat perlu dibuat ulang.

  Cara pakai:
    powershell -NoProfile -ExecutionPolicy Bypass -File .\BUAT-SERTIFIKAT-BARU.ps1

  Opsi:
    -Xampp 'D:\xampp'   lokasi XAMPP (default C:\xampp)
    -Hari 3650          masa berlaku sertifikat
    -IpTambahan @('10.0.0.5')  IP lain yang ingin dimasukkan (opsional)

  Setelah skrip ini: Stop lalu Start "Apache" di XAMPP Control Panel.
  Lalu pasang file kaizen-ca.crt (di folder portal) ke perangkat klien:
    Windows : klik 2x -> Install Certificate -> Local Machine -> Trusted Root
    Android : Settings -> Security -> Install certificate -> CA certificate
    iPhone  : kirim file -> Settings -> Profile Downloaded -> Install
#>
[CmdletBinding()]
param(
    [string]$Xampp = 'C:\xampp',
    [int]$Hari = 3650,
    [string[]]$IpTambahan = @()
)

$ErrorActionPreference = 'Stop'

function Ok($t)   { Write-Host ('  [OK] ' + $t) -ForegroundColor Green }
function Info($t) { Write-Host ('       ' + $t) -ForegroundColor Gray }

try {
    $openssl = Join-Path $Xampp 'apache\bin\openssl.exe'
    if (-not (Test-Path $openssl)) { throw ('openssl tidak ditemukan: ' + $openssl + ' (cek -Xampp)') }

    # ---------- Kumpulkan alamat IP PC ini ----------
    $ips = @()
    try {
        $ips = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
               Where-Object { $_.IPAddress -ne '127.0.0.1' -and $_.PrefixOrigin -ne 'WellKnown' } |
               Select-Object -ExpandProperty IPAddress -Unique
    } catch {
        $ips = @([System.Net.Dns]::GetHostAddresses($env:COMPUTERNAME) |
                 Where-Object { $_.AddressFamily -eq 'InterNetwork' } |
                 ForEach-Object { $_.IPAddressToString })
    }
    $ips = @($ips | Where-Object { $_ -and $_ -ne '127.0.0.1' }) + @($IpTambahan) | Select-Object -Unique
    if (-not $ips -or $ips.Count -eq 0) { $ips = @('127.0.0.1') }

    $namaPc = $env:COMPUTERNAME
    Write-Host ''
    Write-Host '  MEMBUAT SERTIFIKAT HTTPS KAIZEN' -ForegroundColor White
    Write-Host ('  Nama PC : ' + $namaPc) -ForegroundColor Gray
    Write-Host ('  Alamat  : ' + ($ips -join ', ')) -ForegroundColor Gray
    Write-Host ''

    # ---------- Tulis konfigurasi openssl ----------
    $tempDir = Join-Path $env:TEMP ('kaizen_ssl_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
    $cfgFile = Join-Path $tempDir 'openssl.cnf'

    $altLines = New-Object System.Collections.ArrayList
    [void]$altLines.Add('DNS.1 = kaizen-portal')
    [void]$altLines.Add('DNS.2 = localhost')
    [void]$altLines.Add('DNS.3 = ' + $namaPc)
    [void]$altLines.Add('IP.1 = 127.0.0.1')
    $n = 2
    foreach ($ip in $ips) {
        if ($ip -eq '127.0.0.1') { continue }
        [void]$altLines.Add(('IP.' + $n + ' = ' + $ip))
        $n++
    }

    $cnf = @"
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
x509_extensions = v3_req

[dn]
C = ID
O = Kaizen Portal
CN = kaizen-portal

[v3_req]
basicConstraints = CA:TRUE
keyUsage = digitalSignature, keyEncipherment, keyCertSign
extendedKeyUsage = serverAuth
subjectAltName = @alt

[alt]
$($altLines -join "`r`n")
"@
    Set-Content -Path $cfgFile -Value $cnf -Encoding ASCII

    # ---------- Buat sertifikat ----------
    # Catatan: dijalankan lewat cmd /c supaya keluaran progres openssl (stderr)
    # tidak dianggap error oleh PowerShell 5.1.
    $keyOut = Join-Path $tempDir 'server.key'
    $crtOut = Join-Path $tempDir 'server.crt'
    $outLog = Join-Path $tempDir 'openssl.out.txt'
    $errLog = Join-Path $tempDir 'openssl.err.txt'
    $perintah = '""' + $openssl + '"" req -x509 -newkey rsa:2048 -sha256 -nodes' +
                ' -days ' + $Hari +
                ' -keyout ""' + $keyOut + '""' +
                ' -out ""' + $crtOut + '""' +
                ' -config ""' + $cfgFile + '""' +
                ' -extensions v3_req' +
                ' > ""' + $outLog + '"" 2> ""' + $errLog + '""'
    cmd /c $perintah
    $exitSsl = $LASTEXITCODE
    if ($exitSsl -ne 0 -or -not (Test-Path $crtOut) -or -not (Test-Path $keyOut)) {
        $pesanSsl = ''
        if (Test-Path $errLog) { $pesanSsl = ((Get-Content $errLog -ErrorAction SilentlyContinue) -join ' ').Trim() }
        if ($pesanSsl.Length -gt 300) { $pesanSsl = $pesanSsl.Substring(0, 300) + '...' }
        throw ('openssl gagal membuat sertifikat (exit ' + $exitSsl + '): ' + $pesanSsl)
    }
    Ok ('Sertifikat dibuat, berlaku ' + $Hari + ' hari')

    # ---------- Pasang ke Apache (dengan cadangan) ----------
    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $crtDst = Join-Path $Xampp 'apache\conf\ssl.crt\server.crt'
    $keyDst = Join-Path $Xampp 'apache\conf\ssl.key\server.key'
    foreach ($pair in @(@($crtDst, $crtOut), @($keyDst, $keyOut))) {
        $dir = Split-Path $pair[0] -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        if (Test-Path $pair[0]) { Copy-Item $pair[0] ($pair[0] + '.bak-' + $stamp) -Force }
        Copy-Item $pair[1] $pair[0] -Force
    }
    Ok ('Sertifikat dipasang ke Apache (cadangan .bak-' + $stamp + ')')

    # ---------- Salin juga ke folder portal agar bisa dipasang di HP/PC klien ----------
    $portalCa = Join-Path $Xampp 'htdocs\kaizen\kaizen-ca.crt'
    if (Test-Path (Split-Path $portalCa -Parent)) {
        Copy-Item $crtOut $portalCa -Force
        Ok 'File kaizen-ca.crt di folder portal diperbarui'
    }

    Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host ''
    Write-Host '  LANGKAH SELANJUTNYA' -ForegroundColor White
    Write-Host '  1. XAMPP Control Panel -> Stop "Apache" lalu Start lagi.' -ForegroundColor Gray
    Write-Host '  2. Buka di PC ini: https://localhost/kaizen/' -ForegroundColor Gray
    foreach ($ip in $ips) {
        Write-Host ('     dari perangkat lain: https://' + $ip + '/kaizen/') -ForegroundColor Gray
    }
    Write-Host '  3. Pasang kaizen-ca.crt di perangkat klien (Trusted Root) agar tanpa peringatan.' -ForegroundColor Gray
    Write-Host ''
    Write-Host ('  Alamat IP yang sudah dimasukkan ke sertifikat: ' + ($ips -join ', ')) -ForegroundColor Yellow
    if ($ips -contains '169.254.') { Write-Host '' }
    Write-Host ''
    exit 0
}
catch {
    Write-Host ''
    Write-Host ('  GAGAL: ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host ''
    exit 1
}
