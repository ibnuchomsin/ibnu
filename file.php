<?php
/**
 * Penyaji berkas uploads/ yang mewajibkan sesi login.
 *
 * URL publik tetap /kaizen/uploads/<nama>; uploads/.htaccess me-rewrite
 * permintaan ke endpoint ini. Tanpa sesi -> 401, isi file tidak dikirim.
 */

if (PHP_SAPI === 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/auth.php';
@ini_set('display_errors', '0');
kaizenSessionStart();

function fileDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

if (!isAuthed()) {
    fileDeny(401, 'Unauthorized');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    fileDeny(405, 'Method Not Allowed');
}

$name = isset($_GET['name']) ? (string)$_GET['name'] : '';
if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
    fileDeny(400, 'Nama file tidak valid');
}

$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'  => 'text/csv; charset=utf-8',
    'txt'  => 'text/plain; charset=utf-8',
];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    fileDeny(400, 'Tipe file tidak diizinkan');
}

$base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
$path = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . $name) : false;
if ($base === false || $path === false || !is_file($path)) {
    fileDeny(404, 'File tidak ditemukan');
}

$baseNorm = str_replace('\\', '/', $base);
$pathNorm = str_replace('\\', '/', $path);
if (!str_starts_with(strtolower($pathNorm), strtolower($baseNorm) . '/')) {
    fileDeny(404, 'File tidak ditemukan');
}

$size = @filesize($path);
if ($size === false) {
    fileDeny(404, 'File tidak ditemukan');
}

// Lepaskan lock sesi sebelum streaming agar banyak gambar bisa dimuat paralel.
session_write_close();

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
$disposition = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');

if ($method === 'HEAD') {
    exit;
}
readfile($path);
exit;
