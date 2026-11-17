<?php
/**
 * Backup logis database Kaizen HSE Portal (CLI only).
 *
 * Pemakaian:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\kaizen\tools\backup_db.php
 *   C:\xampp\php\php.exe ... --days=7 --dir="D:\kaizen-backups\db"
 *
 * Hasil:
 *   <dir>\<database>_YYYY-MM-DD_HH-MM-SS.sql
 *   <base>\uploads\uploads_YYYY-MM-DD_HH-MM-SS.zip
 *   <base>\logs\backup.log
 *
 * Kredensial tidak disalin: skrip memakai objek $DB dari config.php yang sama
 * dengan api.php. File backup berisi data sensitif (termasuk hash password) —
 * jangan dibagikan. Restore (contoh):
 *   mysql -u <user> -p <database> < kaizen_....sql
 *   tar -xf uploads_....zip -C C:\xampp\htdocs\kaizen
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help' || $a === '-h') {
        echo "Pemakaian: php backup_db.php [--dir=PATH] [--days=N]\n";
        echo "  --dir   folder tujuan database (default D:\\kaizen-backups\\db)\n";
        echo "  --days  masa simpan dalam hari untuk database & uploads (default 7)\n";
        echo "  Uploads disimpan ke D:\\kaizen-backups\\uploads\n";
        exit(0);
    }
    if (preg_match('/^--(dir|days)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$baseDir = 'D:\\kaizen-backups';
$dir     = isset($args['dir']) ? rtrim($args['dir'], "\\/") : $baseDir . '\\db';
$logDir  = $baseDir . '\\logs';
$logFile = $logDir . '\\backup.log';
$days    = isset($args['days']) ? max(1, (int)$args['days']) : 7;

function say(string $msg, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function fail(string $msg, string $logFile, int $code = 1): void
{
    say('GAGAL: ' . $msg, $logFile);
    exit($code);
}

function writeAll($fh, string $data, string $logFile): void
{
    if (fwrite($fh, $data) === false) {
        fail('Gagal menulis file backup (disk penuh / akses ditolak).', $logFile);
    }
}

if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail("Tidak bisa membuat folder tujuan: $dir", $logFile);
}
if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    fail("Tidak bisa membuat folder log: $logDir", $logFile);
}
if (!is_writable($dir)) {
    fail("Folder tujuan tidak bisa ditulis: $dir", $logFile);
}
if (is_file($logFile) && filesize($logFile) > 2 * 1024 * 1024) {
    @unlink($logFile . '.1');
    @rename($logFile, $logFile . '.1');
}

$lockPath = $dir . '\\.backup.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Backup lain sedang berjalan (lock aktif).', $logFile, 2);
}
register_shutdown_function(static function () use ($lock): void {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

require __DIR__ . '/../config.php';

if (!isset($DB) || !($DB instanceof mysqli)) {
    fail('Koneksi $DB dari config.php tidak tersedia.', $logFile);
}
if ($DB->connect_errno) {
    fail('Koneksi database gagal: ' . $DB->connect_error, $logFile);
}
$DB->set_charset('utf8mb4');

$dbRes  = $DB->query('SELECT DATABASE()');
$dbName = $dbRes ? (string)$dbRes->fetch_row()[0] : '';
if ($dbRes) {
    $dbRes->free();
}
if ($dbName === '') {
    fail('Nama database tidak terbaca dari koneksi.', $logFile);
}

$DB->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
if (!$DB->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')) {
    fail('Gagal memulai snapshot konsisten: ' . $DB->error, $logFile);
}

$tables = [];
$res = $DB->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
if (!$res) {
    fail('Gagal membaca daftar tabel: ' . $DB->error, $logFile);
}
while ($row = $res->fetch_row()) {
    $tables[] = $row[0];
}
$res->free();
if (!$tables) {
    fail('Tidak ada tabel yang bisa dibackup.', $logFile);
}

$warnings = [];
$nonInnoDb = [];
$engRes = $DB->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
if ($engRes) {
    while ($r = $engRes->fetch_assoc()) {
        if (strcasecmp((string)$r['ENGINE'], 'InnoDB') !== 0) {
            $nonInnoDb[] = $r['TABLE_NAME'] . ' (' . $r['ENGINE'] . ')';
        }
    }
    $engRes->free();
}
if ($nonInnoDb) {
    $warnings[] = 'Tabel non-InnoDB, snapshot bisa tidak konsisten: ' . implode(', ', $nonInnoDb);
}

$trgRes = $DB->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
$trgCount = $trgRes ? (int)$trgRes->fetch_row()[0] : 0;
if ($trgRes) {
    $trgRes->free();
}
if ($trgCount > 0) {
    $warnings[] = "Database memiliki $trgCount trigger; skrip ini hanya mendump tabel.";
}

$rtnRes = $DB->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()");
$rtnCount = $rtnRes ? (int)$rtnRes->fetch_row()[0] : 0;
if ($rtnRes) {
    $rtnRes->free();
}
if ($rtnCount > 0) {
    $warnings[] = "Database memiliki $rtnCount routine (procedure/function); tidak ikut didump.";
}

$safeDbName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dbName);
$stamp = date('Y-m-d_H-i-s');
$final = $dir . '\\' . $safeDbName . '_' . $stamp . '.sql';
$tmp   = $final . '.tmp';

$fh = @fopen($tmp, 'wb');
if (!$fh) {
    fail("Tidak bisa menulis file backup: $tmp", $logFile);
}

$started = microtime(true);
$header = "-- Kaizen HSE Portal — backup database\n"
    . "-- Database : $dbName\n"
    . "-- Dibuat   : " . date('Y-m-d H:i:s') . " (Asia/Jakarta)\n"
    . "-- Tabel    : " . count($tables) . "\n"
    . "-- PERINGATAN: file ini berisi data sensitif (termasuk hash password). Jangan dibagikan.\n";
if ($warnings) {
    $header .= "-- CATATAN  : " . implode(' | ', $warnings) . "\n";
}
$header .= "--\n"
    . "-- Restore   : mysql -u <user> -p <database> < " . basename($final) . "\n\n"
    . "SET NAMES utf8mb4;\n"
    . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
    . "SET time_zone='+07:00';\n"
    . "SET FOREIGN_KEY_CHECKS=0;\n\n";
writeAll($fh, $header, $logFile);

$sqlValue = static function ($value, string $type) use ($DB): string {
    if ($value === null) {
        return 'NULL';
    }
    $t = strtolower($type);
    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/', $t)) {
        return is_numeric($value) ? (string)$value : "'" . $DB->real_escape_string((string)$value) . "'";
    }
    if (preg_match('/^(tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/', $t)) {
        return '0x' . bin2hex((string)$value);
    }
    return "'" . $DB->real_escape_string((string)$value) . "'";
};

$problems = [];
$dumpedTables = 0;
$totalRows = 0;

foreach ($tables as $table) {
    $qt = '`' . str_replace('`', '``', $table) . '`';

    $cntRes = $DB->query("SELECT COUNT(*) FROM $qt");
    if (!$cntRes) {
        $problems[] = "COUNT $table: " . $DB->error;
        continue;
    }
    $expected = (int)$cntRes->fetch_row()[0];
    $cntRes->free();

    $crRes = $DB->query("SHOW CREATE TABLE $qt");
    if (!$crRes) {
        $problems[] = "SHOW CREATE $table: " . $DB->error;
        continue;
    }
    $crRow = $crRes->fetch_row();
    $crRes->free();
    $createSql = $crRow[1] ?? '';
    if ($createSql === '') {
        $problems[] = "Struktur tabel $table tidak terbaca.";
        continue;
    }

    $cols = [];
    $colRes = $DB->query("SHOW COLUMNS FROM $qt");
    if (!$colRes) {
        $problems[] = "SHOW COLUMNS $table: " . $DB->error;
        continue;
    }
    while ($c = $colRes->fetch_assoc()) {
        $cols[] = $c;
    }
    $colRes->free();
    if (!$cols) {
        $problems[] = "Kolom tabel $table tidak terbaca.";
        continue;
    }

    writeAll(
        $fh,
        "-- ------------------------------------------------------------\n"
        . "-- Tabel $table ($expected baris)\n"
        . "-- ------------------------------------------------------------\n",
        $logFile
    );
    writeAll($fh, "DROP TABLE IF EXISTS $qt;\n" . $createSql . ";\n\n", $logFile);

    if ($expected > 0) {
        $colNames = array_map(
            static fn(array $c): string => '`' . str_replace('`', '``', $c['Field']) . '`',
            $cols
        );
        $colTypes = array_map(static fn(array $c): string => (string)$c['Type'], $cols);
        $insertHead = 'INSERT INTO ' . $qt . ' (' . implode(', ', $colNames) . ") VALUES\n";

        $sel = $DB->query("SELECT * FROM $qt", MYSQLI_USE_RESULT);
        if (!$sel) {
            $problems[] = "SELECT $table: " . $DB->error;
            continue;
        }

        $batch = [];
        $batchBytes = 0;
        $rows = 0;
        while ($row = $sel->fetch_row()) {
            $vals = [];
            foreach ($row as $i => $v) {
                $vals[] = $sqlValue($v, $colTypes[$i] ?? '');
            }
            $tuple = '(' . implode(', ', $vals) . ')';
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
            if (count($batch) >= 100 || $batchBytes >= 1048576) {
                writeAll($fh, $insertHead . implode(",\n", $batch) . ";\n", $logFile);
                $batch = [];
                $batchBytes = 0;
            }
            $rows++;
        }
        $sel->free();
        if ($batch) {
            writeAll($fh, $insertHead . implode(",\n", $batch) . ";\n", $logFile);
        }
        writeAll($fh, "\n", $logFile);

        if ($rows !== $expected) {
            $problems[] = "Jumlah baris $table tidak cocok ($rows vs $expected).";
        }
        $totalRows += $rows;
    }

    $dumpedTables++;
}

writeAll($fh, "SET FOREIGN_KEY_CHECKS=1;\n-- Dump selesai: " . date('Y-m-d H:i:s') . "\n", $logFile);
fclose($fh);
$DB->query('COMMIT');

if ($problems) {
    @unlink($tmp);
    fail('Backup dibatalkan: ' . implode(' | ', $problems), $logFile);
}
if ($dumpedTables !== count($tables)) {
    @unlink($tmp);
    fail("Jumlah tabel tidak cocok ($dumpedTables dari " . count($tables) . ").", $logFile);
}

$size = @filesize($tmp);
$content = @file_get_contents($tmp);
if (!$size || $size < 100 || $content === false || strpos($content, 'CREATE TABLE') === false) {
    @unlink($tmp);
    fail('File backup tidak valid (kosong / tidak ada CREATE TABLE).', $logFile);
}
unset($content);

if (!@rename($tmp, $final)) {
    @unlink($tmp);
    fail("Gagal memindahkan file sementara ke $final", $logFile);
}

$hash = @hash_file('sha256', $final);
$deleted = 0;
$cutoff = time() - ($days * 86400);
foreach (glob($dir . '\\' . $safeDbName . '_*.sql') ?: [] as $old) {
    if ($old === $final) {
        continue;
    }
    $mtime = @filemtime($old);
    if ($mtime !== false && $mtime < $cutoff && @unlink($old)) {
        $deleted++;
    }
}
foreach (glob($dir . '\\*.tmp') ?: [] as $orphan) {
    $mtime = @filemtime($orphan);
    if ($mtime !== false && $mtime < time() - 86400 && @unlink($orphan)) {
        $deleted++;
    }
}

$elapsed = number_format(microtime(true) - $started, 2);
say(
    'OK: ' . $final
    . ' | ' . number_format((int)$size) . ' bytes'
    . ' | tabel=' . $dumpedTables
    . ' baris=' . number_format($totalRows)
    . ' | ' . $elapsed . 's'
    . ' | sha256=' . ($hash ?: 'n/a')
    . ' | retensi_hapus=' . $deleted
    . ' | retensi_hari=' . $days,
    $logFile
);
foreach ($warnings as $w) {
    say('CATATAN: ' . $w, $logFile);
}

/* ------------------------------------------------------------
 * Backup folder uploads (foto & dokumen)
 * ------------------------------------------------------------ */
$kaizenRoot  = dirname(__DIR__);
$uploadsSrc  = $kaizenRoot . DIRECTORY_SEPARATOR . 'uploads';
$uploadsDir  = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
$uploadsFinal = $uploadsDir . DIRECTORY_SEPARATOR . 'uploads_' . $stamp . '.zip';
$uploadsTmp   = $uploadsDir . DIRECTORY_SEPARATOR . 'uploads_' . $stamp . '.part.zip';

if (!function_exists('exec')) {
    fail('Backup database selesai (' . $final . '), tetapi fungsi exec() dinonaktifkan sehingga arsip uploads tidak bisa dibuat.', $logFile);
}
if (!is_dir($uploadsSrc)) {
    fail('Backup database selesai (' . $final . '), tetapi folder uploads tidak ditemukan: ' . $uploadsSrc, $logFile);
}
if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
    fail('Backup database selesai (' . $final . '), tetapi folder tujuan uploads tidak bisa dibuat: ' . $uploadsDir, $logFile);
}

$tarExe = null;
foreach (['C:\\Windows\\System32\\tar.exe', 'C:\\Windows\\Sysnative\\tar.exe', 'tar.exe'] as $candidate) {
    $probe = [];
    $probeRc = 1;
    @exec(escapeshellarg($candidate) . ' --version 2>&1', $probe, $probeRc);
    if ($probeRc === 0) {
        $tarExe = $candidate;
        break;
    }
}
if ($tarExe === null) {
    fail('Backup database selesai (' . $final . '), tetapi tar.exe tidak ditemukan untuk membuat arsip uploads.', $logFile);
}

$expectedFiles = [];
try {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsSrc, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($kaizenRoot) + 1);
        $expectedFiles[str_replace('\\', '/', $rel)] = true;
    }
} catch (Throwable $e) {
    fail('Backup database selesai (' . $final . '), tetapi folder uploads tidak bisa dibaca: ' . $e->getMessage(), $logFile);
}

@unlink($uploadsTmp);
$out = [];
$rc = 0;
exec(
    escapeshellarg($tarExe) . ' -a -c -f ' . escapeshellarg($uploadsTmp)
    . ' -C ' . escapeshellarg($kaizenRoot) . ' ' . escapeshellarg('uploads') . ' 2>&1',
    $out,
    $rc
);
if ($rc !== 0 || !is_file($uploadsTmp) || @filesize($uploadsTmp) < 22) {
    $detail = trim(implode(' | ', array_slice($out, 0, 5)));
    @unlink($uploadsTmp);
    fail(
        'Backup database selesai (' . $final . '), tetapi arsip uploads gagal dibuat (exit ' . $rc . ')'
        . ($detail !== '' ? ': ' . $detail : ''),
        $logFile
    );
}

$entries = [];
$listRc = 0;
$listOut = [];
exec(escapeshellarg($tarExe) . ' -t -f ' . escapeshellarg($uploadsTmp) . ' 2>&1', $listOut, $listRc);
foreach ($listOut as $line) {
    $line = trim(str_replace('\\', '/', $line));
    if ($line === '') {
        continue;
    }
    if (str_starts_with($line, './')) {
        $line = substr($line, 2);
    }
    $entries[$line] = true;
}

$missing = [];
foreach (array_keys($expectedFiles) as $rel) {
    if (!isset($entries[$rel])) {
        $missing[] = $rel;
    }
}
if ($listRc !== 0 || !$entries || $missing) {
    @unlink($uploadsTmp);
    $detail = $missing
        ? 'file tidak ada di arsip: ' . implode(', ', array_slice($missing, 0, 5))
        : 'daftar isi arsip tidak terbaca';
    fail('Backup database selesai (' . $final . '), tetapi verifikasi arsip uploads gagal (' . $detail . ').', $logFile);
}

$uploadsSize = (int)@filesize($uploadsTmp);
$uploadsHash = @hash_file('sha256', $uploadsTmp);
if (!@rename($uploadsTmp, $uploadsFinal)) {
    @unlink($uploadsTmp);
    fail('Backup database selesai (' . $final . '), tetapi gagal memindahkan arsip uploads ke ' . $uploadsFinal, $logFile);
}

$uploadsDeleted = 0;
$cutoff = time() - ($days * 86400);
foreach (glob($uploadsDir . '\\uploads_*.zip') ?: [] as $old) {
    if ($old === $uploadsFinal) {
        continue;
    }
    $mtime = @filemtime($old);
    if ($mtime !== false && $mtime < $cutoff && @unlink($old)) {
        $uploadsDeleted++;
    }
}
foreach (glob($uploadsDir . '\\*.part.zip') ?: [] as $orphan) {
    $mtime = @filemtime($orphan);
    if ($mtime !== false && $mtime < time() - 86400 && @unlink($orphan)) {
        $uploadsDeleted++;
    }
}

say(
    'OK uploads: ' . $uploadsFinal
    . ' | ' . number_format($uploadsSize) . ' bytes'
    . ' | file=' . count($expectedFiles)
    . ' entri=' . count($entries)
    . ' | sha256=' . ($uploadsHash ?: 'n/a')
    . ' | retensi_hapus=' . $uploadsDeleted
    . ' | retensi_hari=' . $days,
    $logFile
);
exit(0);
