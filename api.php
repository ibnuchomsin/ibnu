<?php
/**
 * ============================================================
 * KAIZEN IT SYSTEM — HSE PORTAL  |  REST API  v2 (HARDENED)
 * ============================================================
 * Perubahan v2:
 *  1. SESSION AUTH  : login membuat sesi server (cookie). Semua operasi data
 *                     (getInitialData, list, saveRecord, deleteRecord) WAJIB sesi.
 *  2. BCRYPT        : password disimpan sebagai hash (password_hash). Password
 *                     lama (plain) otomatis di-upgrade saat login. Baru diset
 *                     selalu di-hash. Hash yang dikirim balik oleh form edit
 *                     user otomatis di-skip (tidak double-hash).
 *  3. UPLOADS       : foto/berkas base64 dari app di-decode ke folder uploads/,
 *                     kolom DB hanya menyimpan path (hemat ruang database).
 *  4. REMINDERS     : op khusus untuk temuan Gemba HSE yang mendekati/lewat
 *                     jatuh tempo (due_date) — dipakai banner + tombol WA.
 *
 * Endpoint:
 *  POST {op:"login"}                                  -> sesi + user
 *  POST {op:"logout"}                                 -> hapus sesi
 *  GET  ?op=getInitialData   (butuh sesi)
 *  GET  ?op=list&sheet=...   (butuh sesi)
 *  GET  ?op=reminders        (butuh sesi)  -> temuan mendekati/lewat due
 *  POST {op:"saveRecord", sheetName, record}   (butuh sesi)
 *  POST {op:"deleteRecord", sheetName, recordId} (butuh sesi)
 *  POST {op:"connectionTest"}                        -> tanpa sesi (alive check)
 */
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');   // jangan bocorkan versi PHP

/* ------------------------------------------------------------
 * Konfigurasi upload & reminder
 * ------------------------------------------------------------ */
const UPLOAD_DIR = __DIR__ . '/uploads';           // C:\xampp\htdocs\kaizen\uploads
const UPLOAD_WEB = '/kaizen/uploads';              // path URL absolut dari web root
const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;          // max 6MB per foto/berkas
const REMINDER_HOUR_REFRESH_DAYS = 3;              // ambang: <=3 hari menuju due_date

// Rate limit login: file throttle di folder tools/ (sudah diblokir dari web)
const LOGIN_WINDOW_SECONDS   = 900;   // 15 menit
const LOGIN_MAX_PER_ACCOUNT  = 5;     // gagal per IP+username
const LOGIN_MAX_PER_IP       = 20;    // gagal per IP (semua username)
const LOGIN_ATTEMPT_FILE     = __DIR__ . '/tools/data/login_attempts.json';
const LOGIN_ATTEMPT_MAX_AGE  = 604800; // simpan 7 hari
const LOGIN_ATTEMPT_MAX_ROWS = 5000;   // batas ukuran file saat diserang
$IMG_EXTS  = ['jpeg','jpg','png','gif','webp'];
$DOC_EXTS  = ['pdf','doc','docx','xls','xlsx','csv','txt'];

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);

/* ------------------------------------------------------------
 * SESSION AUTH
 * ------------------------------------------------------------ */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mfa.php';   // MFA / verifikasi dua langkah (OTP email & WhatsApp)
@ini_set('display_errors', '0');   // jangan bocorkan error PHP ke output JSON
kaizenSessionStart();

function requireAuth(): void {
    if (!isAuthed()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sesi berakhir. Silakan login ulang.', 'code' => 'SESSION_EXPIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ------------------------------------------------------------
 * Definisi koleksi: table, kolom, pemetaan nama, tipe khusus
 * ------------------------------------------------------------ */
$MAP = [
    'loginCount' => 'login_count',  'createdAt' => 'created_at',
    'creatorName' => 'creator_name','picTechnician' => 'pic_technician',
    'afterDesc' => 'after_description', 'afterImg' => 'after_image',
    'beforeDesc' => 'before_description', 'beforeImg' => 'before_image',
    'dueDate' => 'due_date',        'purchaseDate' => 'purchase_date',
    'serviceCost' => 'service_cost','userLocation' => 'user_location',
    'folderId' => 'folder_id',      'fileType' => 'file_type',
    'fileData' => 'file_data',      'fileUrl' => 'file_url',
    'desc' => 'description',
    // Tanda tangan QC Lab (Release Note / Hasil Analisa)
    'signDibuat' => 'sign_dibuat',  'signDiperiksa' => 'sign_diperiksa',
    'signDiketahui' => 'sign_diketahui',
    // Master Data (mirror QMS): Master Produk & Master Customer
    'partNo' => 'part_no',          'partName' => 'part_name',
    'sizeVal' => 'size_val',        'sizeStr' => 'size_str',
    'minStock' => 'min_stock',      'maxStock' => 'max_stock',
    'currentStock' => 'current_stock', 'customerName' => 'customer_name',
    'custId' => 'cust_id',
    // Master Data Supplier & Curah
    'supId' => 'sup_id',            'curahId' => 'curah_id',
];
$REVERSE = array_flip($MAP);

$DATETIME_COLS = ['created_at'];
$DATE_COLS     = ['due_date', 'purchase_date'];

// kolom berkas foto/berkas yang otomatis dipindah ke folder uploads/
$FILE_IMAGE_COLS = ['image','after_image','before_image','sign_dibuat','sign_diperiksa','sign_diketahui'];
$FILE_DOC_COLS   = ['file_data'];

$TABLES = [
    'Users' => [
        'table' => 'users',
        'cols'  => ['id','username','name','role','dept','status','password','permissions','login_count','created_at'],
        'json'  => ['permissions'],
        'num'   => ['login_count'],
    ],
    'Tickets' => [
        'table' => 'tickets',
        'cols'  => ['id','title','description','priority','status','image','creator_name','pic_technician','after_description','after_image','created_at'],
    ],
    'Assets' => [
        'table' => 'assets',
        'cols'  => ['id','code','category','name','serial','assignee','user_location','lifespan','service_cost','status','purchase_date','specs','image'],
        'num'   => ['service_cost'],
    ],
    'Gemba' => [
        'table' => 'gemba_findings',
        'cols'  => ['id','title','kategori','area','location','priority','status','due_date','pic','divisi','before_description','before_image','after_description','after_image','reporter','created_at'],
    ],
    'GembaQc' => [
        'table' => 'gemba_qc_findings',
        'cols'  => ['id','title','area','location','priority','status','due_date','pic','divisi','before_description','before_image','after_description','after_image','reporter','created_at'],
    ],
    'Logins' => [
        'table' => 'login_log',
        'cols'  => ['id','username','full_name','created_at'],
    ],
    'Documents' => [
        'table' => 'documents',
        'cols'  => ['id','title','category','filename','file_type','file_data','file_url','uploader','description','folder_id','created_at'],
    ],
    'Folders' => [
        'table' => 'folders',
        'cols'  => ['id','name','category','created_at'],
    ],
    // Sub-modul Quality Control (mirror QMS): satu tabel, dibedakan kolom `category`
    // ('lab'|'patrol'|'outgoing'|'receiving'|'report_supplier'|'emp_*'|'scan').
    // Kolom `note` = teks biasa; kategori report_supplier & lab menyimpan JSON string di dalamnya.
    // Kolom sign_* = path gambar tanda tangan di uploads/ (dipindah otomatis via $FILE_IMAGE_COLS).
    'Qc' => [
        'table' => 'qc_records',
        'cols'  => ['id','category','date','time','ref','item','param','inspector','status','note','sign_dibuat','sign_diperiksa','sign_diketahui','pic','created_at'],
    ],
    // Master Data (mirror QMS #products / #customers): dikelola Admin & Superadmin
    'Products' => [
        'table' => 'master_products',
        'cols'  => ['id','part_no','part_name','brand','size_val','size_str','min_stock','max_stock','current_stock','customer_name','created_at'],
        'num'   => ['size_val','min_stock','max_stock','current_stock'],
    ],
    'Customers' => [
        'table' => 'master_customers',
        'cols'  => ['id','cust_id','name','address','created_at'],
    ],
    'Suppliers' => [
        'table' => 'master_suppliers',
        'cols'  => ['id','sup_id','name','address','contact','created_at'],
    ],
    'Curahs' => [
        'table' => 'master_curahs',
        'cols'  => ['id','curah_id','name','unit','description','created_at'],
    ],
    // Transaksi gudang (Warehouse inbound/outbound): id "WH-<ts>-<i>" dari client.
    // partNo/partName dipetakan otomatis ke part_no/part_name via $MAP,
    // createdAt -> created_at ($DATETIME_COLS). qty numeric.
    'WhTx' => [
        'table' => 'wh_tx',
        'cols'  => ['id','type','date','customer','part_no','part_name','qty','note','pic','created_at'],
        'num'   => ['qty'],
    ],
];

/* ------------------------------------------------------------
 * Helper
 * ------------------------------------------------------------ */
function fail($msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function isoToDatetime(?string $v): ?string {
    if ($v === null || $v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) {
        return substr(str_replace('T', ' ', $v), 0, 19);
    }
    return $v;
}
function isoToDate(?string $v): ?string {
    if ($v === null || $v === '') return null;
    return substr($v, 0, 10);
}

/** Deteksi bcrypt/argon ($2y$, $2a$, $argon2) */
function isPasswordHash(string $v): bool {
    return str_starts_with($v, '$2y$') || str_starts_with($v, '$2a$') || str_starts_with($v, '$argon2');
}

/* ------------------------------------------------------------
 * Rate limit login (file JSON, dilindungi folder tools/)
 * ------------------------------------------------------------ */
function loginRateKey(string $username): string
{
    $key = function_exists('mb_strtolower')
        ? mb_strtolower(trim($username), 'UTF-8')
        : strtolower(trim($username));
    return hash('sha256', $key);
}

function loginAttemptsOpen()
{
    $dir = dirname(LOGIN_ATTEMPT_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return false;
    }
    $fh = @fopen(LOGIN_ATTEMPT_FILE, 'c+');
    return $fh ?: false;
}

function loginAttemptsRead($fh): array
{
    rewind($fh);
    $raw = stream_get_contents($fh);
    $rows = json_decode($raw ?: '[]', true);
    if (!is_array($rows)) {
        return [];
    }
    $now = time();
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $t  = (int)($row['t'] ?? 0);
        $u  = (string)($row['u'] ?? '');
        $ip = (string)($row['i'] ?? '');
        if ($t < $now - LOGIN_ATTEMPT_MAX_AGE || $u === '' || $ip === '') {
            continue;
        }
        $clean[] = ['u' => $u, 'i' => $ip, 't' => $t];
    }
    return $clean;
}

function loginAttemptsWrite($fh, array $rows): bool
{
    if (count($rows) > LOGIN_ATTEMPT_MAX_ROWS) {
        usort($rows, static fn(array $a, array $b): int => $b['t'] <=> $a['t']);
        $rows = array_slice($rows, 0, LOGIN_ATTEMPT_MAX_ROWS);
    }
    $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || !rewind($fh) || !ftruncate($fh, 0)) {
        return false;
    }
    $written = fwrite($fh, $json);
    if ($written === false || $written !== strlen($json)) {
        return false;
    }
    fflush($fh);
    return true;
}

function loginAttemptStats(array $rows, string $field, string $value): array
{
    $now    = time();
    $cutoff = $now - LOGIN_WINDOW_SECONDS;
    $count  = 0;
    $oldest = null;
    foreach ($rows as $row) {
        if ($row['t'] < $cutoff || $row[$field] !== $value) {
            continue;
        }
        $count++;
        if ($oldest === null || $row['t'] < $oldest) {
            $oldest = $row['t'];
        }
    }
    $retry = $oldest === null ? 1 : max(1, LOGIN_WINDOW_SECONDS - ($now - $oldest));
    return ['count' => $count, 'retry' => $retry];
}

function loginRateLimit(string $userHash, string $ip): array
{
    $fh = loginAttemptsOpen();
    if (!$fh) {
        fail('Layanan login sementara tidak tersedia.', 500);
    }
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        fail('Layanan login sementara tidak tersedia.', 500);
    }
    $rows = loginAttemptsRead($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $byIp   = loginAttemptStats($rows, 'i', $ip);
    $byUser = loginAttemptStats($rows, 'u', $userHash);
    return [
        'limited' => $byIp['count'] >= LOGIN_MAX_PER_IP || $byUser['count'] >= LOGIN_MAX_PER_ACCOUNT,
        'retry'   => max($byIp['retry'], $byUser['retry']),
    ];
}

function loginRecordFailure(string $userHash, string $ip): void
{
    $fh = loginAttemptsOpen();
    if (!$fh) {
        fail('Layanan login sementara tidak tersedia.', 500);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        fail('Layanan login sementara tidak tersedia.', 500);
    }
    $rows = loginAttemptsRead($fh);
    $rows[] = ['u' => $userHash, 'i' => $ip, 't' => time()];
    $ok = loginAttemptsWrite($fh, $rows);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        fail('Layanan login sementara tidak tersedia.', 500);
    }
}

function loginClearFailures(string $userHash, string $ip): void
{
    $fh = loginAttemptsOpen();
    if (!$fh) {
        return;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }
    $rows = loginAttemptsRead($fh);
    $kept = [];
    foreach ($rows as $row) {
        if ($row['u'] === $userHash && $row['i'] === $ip) {
            continue;
        }
        $kept[] = $row;
    }
    if (count($kept) !== count($rows)) {
        loginAttemptsWrite($fh, $kept);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

function failRateLimited(int $retry): void
{
    $retry = max(1, min(LOGIN_WINDOW_SECONDS, $retry));
    $wait  = $retry >= 60 ? (int)ceil($retry / 60) . ' menit' : $retry . ' detik';
    http_response_code(429);
    header('Retry-After: ' . $retry);
    echo json_encode([
        'ok'         => false,
        'error'      => 'Terlalu banyak percobaan login. Coba lagi dalam ' . $wait . '.',
        'code'       => 'LOGIN_RATE_LIMITED',
        'retryAfter' => $retry,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Simpan data-URL base64 -> file di uploads/, kembalikan path web (atau nilai asli bila bukan data-URL) */
function storeBase64ToUploads($value, string $tablePrefix, string $fieldCol, array $IMG_EXTS, array $DOC_EXTS): ?string {
    if ($value === null) return null;
    $str = (string)$value;
    if ($str === '') return null;                       // kosong -> NULL
    if (preg_match('#^/kaizen/uploads/[A-Za-z0-9._-]+$#', $str)) return $str;   // path round-trip edit
    if (!preg_match('#^data:([a-z0-9/+.-]+);base64,(.+)$#is', $str, $m)) {
        // Nilai bebas (mis. " onerror=...) tidak boleh masuk kolom gambar/berkas
        fail('Nilai berkas tidak valid: hanya data-URL atau path /kaizen/uploads/ yang diizinkan.', 400);
    }

    $mime   = strtolower($m[1]);
    $data   = base64_decode($m[2]);
    if ($data === false || strlen($data) < 64) return null;
    if (strlen($data) > MAX_UPLOAD_BYTES) {
        fail('Ukuran foto/berkas melebihi batas 6MB setelah dikompresi. Hapus foto lain atau perkecil.', 413);
    }
    // ekstensi = jenis file aman
    $map = [
        'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];
    $ext = $map[$mime] ?? null;
    if ($ext === null || (!in_array($ext, $IMG_EXTS, true) && !in_array($ext, $DOC_EXTS, true))) {
        fail('Tipe berkas tidak diizinkan: ' . $mime, 415);
    }
    // Pastikan isi benar-benar gambar, bukan HTML/JS yang menyamar sebagai .jpg
    if (str_starts_with($mime, 'image/') && @getimagesizefromstring($data) === false) {
        fail('Isi berkas bukan gambar yang valid.', 415);
    }
    $name = strtolower($tablePrefix) . '-' . $fieldCol . '-' . bin2hex(random_bytes(4)) . '-' . time() . '.' . $ext;
    if (file_put_contents(UPLOAD_DIR . DIRECTORY_SEPARATOR . $name, $data) === false) {
        fail('Gagal menyimpan berkas.', 500);
    }
    return UPLOAD_WEB . '/' . $name;
}

/** record app (camelCase) -> [kolom => nilai], termasuk konversi upload */
function recordToColumns(array $record, array $cfg, string $sheetKey): array {
    global $MAP, $DATETIME_COLS, $DATE_COLS, $FILE_IMAGE_COLS, $FILE_DOC_COLS, $IMG_EXTS, $DOC_EXTS;
    $data = [];
    foreach ($record as $key => $value) {
        $col = $MAP[$key] ?? $key;
        if (!in_array($col, $cfg['cols'], true)) continue;

        if (isset($cfg['json']) && in_array($col, $cfg['json'], true)) {
            $data[$col] = is_array($value)
                ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE)
                : (trim((string)$value) !== '' ? json_encode(array_values((array)json_decode((string)$value, true) ?: explode(',', (string)$value)), JSON_UNESCAPED_UNICODE) : null);
            continue;
        }
        if (isset($cfg['num']) && in_array($col, $cfg['num'], true)) {
            $data[$col] = ($value === null || trim((string)$value) === '')
                ? null
                : ( $col === 'login_count' ? (int) $value : (float) str_replace(',', '', (string)$value) );
            continue;
        }
        if ($col === 'password') {
            // Placeholder "__HASHED__" ataupun hash yang berkeliling (edit user tanpa ganti password) -> skip, JANGAN di-hash ulang.
            if (is_string($value) && (isPasswordHash($value) || $value === '__HASHED__')) continue;
            // Password kosong -> skip (jangan menimpa password lama)
            if ($value === '' || $value === null) continue;
            // Password baru -> WAJIB hash bcrypt
            $data[$col] = password_hash((string)$value, PASSWORD_DEFAULT);
            continue;
        }
        if ($col === 'file_url') {
            // Cegah javascript:/data:text/html masuk ke window.open()
            $u = trim((string)$value);
            if ($u !== '' && !preg_match('#^/kaizen/uploads/[A-Za-z0-9._-]+$#', $u) && !preg_match('#^https?://#i', $u)) {
                fail('fileUrl tidak valid', 400);
            }
            $data[$col] = $u === '' ? null : $u;
            continue;
        }
        if (in_array($col, $DATETIME_COLS, true)) { $data[$col] = isoToDatetime($value === null ? '' : (string)$value); $data[$col] = $data[$col] === '' ? null : $data[$col]; continue; }
        if (in_array($col, $DATE_COLS, true))     { $data[$col] = isoToDate($value === null ? '' : (string)$value);     $data[$col] = $data[$col] === '' ? null : $data[$col]; continue; }

        // Foto/berkas -> uploads/ (kolom tinggal menyimpan path)
        if (in_array($col, $FILE_IMAGE_COLS, true)) {
            $data[$col] = storeBase64ToUploads($value, $sheetKey, $col, $IMG_EXTS, $DOC_EXTS);
            continue;
        }
        if ($sheetKey === 'Documents' && in_array($col, $FILE_DOC_COLS, true)) {
            $stored = storeBase64ToUploads($value, 'doc', $col, $IMG_EXTS, $DOC_EXTS);
            if ($stored !== null) {
                $data[$col] = null;                 // DB hemat: isi berkas tinggal path
                $data['__doc_path'] = $stored;      // diletakkan nanti ke file_url
            } else {
                $data[$col] = null;
            }
            continue;
        }

        $data[$col] = ($value === '' || $value === null) ? null : $value;
    }
    // Pindahkan bomba berkas dokumen ke kolom file_url (ALWAYS: file baru)
    if (array_key_exists('__doc_path', $data)) {
        $data['file_url'] = $data['__doc_path'];
        unset($data['__doc_path']);
    }
    return $data;
}

/** Baris DB -> object camelCase untuk app */
function rowToApp(array $row, array $cfg): array {
    global $REVERSE;
    $out = [];
    foreach ($row as $col => $value) {
        $key = $REVERSE[$col] ?? $col;
        if (isset($cfg['json']) && in_array($col, $cfg['json'], true)) {
            $decoded = json_decode((string)$value, true);
            $out[$key] = is_array($decoded) ? $decoded : ($value ? preg_split('/\s*,\s*/', trim((string)$value)) : []);
            continue;
        }
        if ($col === 'password') {
            // jangan bocorkan hash ke browser: kirim penanda, bukan hash asli
            $out[$key] = is_string($value) && $value !== '' ? '__HASHED__' : '';
            continue;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $out[$key] = str_replace(' ', 'T', $value);
            continue;
        }
        if ($col === 'service_cost') { $out[$key] = (string)(float)$value; continue; }
        $out[$key] = $value;
    }
    return $out;
}

function rowsToApp(mysqli_result $res, array $cfg): array {
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = rowToApp($row, $cfg);
    return $list;
}

/* ------------------------------------------------------------
 * Ambil request
 * ------------------------------------------------------------ */
/* ---- 0. Pelindung lintas-situs (CSRF mentah): ----
 *  - POST wajib Content-Type application/json (blok trik form text/plain lintas situs)
 *  - Header Origin (bila dikirim browser) harus host server ini sendiri      */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($ct, 'application/json') === false) fail('Content-Type harus application/json', 415);

    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $originHost = strtolower(parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?: '(kosong)');
        // Origin same-origin selalu boleh: host di Host header bisa IP rumah, IP kantor,
        // atau nama host — tidak bergantung pada IP yang sedang terdaftar di Windows.
        $reqHost = strtolower(parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '');
        $allow = ['localhost', '127.0.0.1'];
        foreach (gethostbynamel(gethostname() ?: '') ?: [] as $ip) $allow[] = strtolower($ip);
        if (function_exists('gethostname') && gethostname()) $allow[] = strtolower(gethostname());
        if ($reqHost !== '') $allow[] = $reqHost;
        if (!in_array($originHost, array_unique(array_map('strtolower', $allow)), true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Origin tidak diizinkan', 'code' => 'ORIGIN_FORBIDDEN'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
$op = '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['op'])) {
    $op = $_GET['op'];
} else {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '[]', true);
    if (!is_array($body)) fail('Body JSON tidak valid');
    if (($body['connectionTest'] ?? false) === true) respond(['ok' => true]);
    $op = $body['op'] ?? '';
}

switch ($op) {

/* ------------------------------------------------------------
 * 1. login — verifikasi bcrypt + auto-upgrade password lama
 * ------------------------------------------------------------ */
case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = trim((string)($body['password'] ?? ''));
    if ($username === '' || $password === '') fail('Username dan password wajib diisi');

    // Throttle dicek SEBELUM verifikasi password: percobaan berulang tidak
    // membebani bcrypt dan tidak bisa dipakai menebak username.
    $usernameKey = loginRateKey($username);
    $clientIp    = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if ($clientIp === '') $clientIp = 'unknown';
    $rate = loginRateLimit($usernameKey, $clientIp);
    if ($rate['limited']) failRateLimited($rate['retry']);

    $stmt = $DB->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = false;
    if ($user) {
        $stored = (string)$user['password'];
        if (isPasswordHash($stored)) $valid = password_verify($password, $stored);
        else { $valid = $stored === $password; }           // password lama (plain)

        if ($valid && !isPasswordHash($stored)) {          // auto-upgrade ke bcrypt
            $u2 = $DB->prepare('UPDATE users SET password = ? WHERE id = ?');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $u2->bind_param('ss', $hash, $user['id']);
            $u2->execute();
            $u2->close();
            $user['password'] = $hash;
        }
    }

    if (!$user || !$valid) {
        loginRecordFailure($usernameKey, $clientIp);
        fail('Username atau password salah!', 401);
    }
    if ($user['status'] !== 'Aktif') {
        loginRecordFailure($usernameKey, $clientIp);
        fail('Akun Anda sedang Non-aktif!', 403);
    }

    loginClearFailures($usernameKey, $clientIp);

    /* ---- MFA: password benar -> cek verifikasi dua langkah (OTP) ----
       Bila MFA diaktifkan utk user ini DAN perangkat belum terpercaya,
       sesi login PENUH belum dibuat: kirim kode & minta verifikasi dulu. */
    kaizenMfaCleanup($DB);
    $mfaSet = kaizenMfaSettings($DB, $user['id']);
    if (!empty($mfaSet['enabled']) && !kaizenMfaTrustedDevice($DB, $user['id'])) {
        $mfaCh = kaizenMfaChannelsFor($mfaSet);
        if (empty($mfaCh['allowed'])) {
            // MFA aktif tapi target kosong (mis. data user dihapus): jangan kunci akun,
            // lanjut login penuh & catat agar admin memperbaiki di Kelola Akun.
            kaizenMfaDebugLog('MFA aktif tapi target kosong utk user ' . $user['username'] . ' -> login tanpa OTP');
        } else {
            $mfaChannel = (string)($body['mfaChannel'] ?? '');
            if (!in_array($mfaChannel, $mfaCh['allowed'], true)) $mfaChannel = $mfaCh['default'];
            $mfaTarget = kaizenMfaTargetFor($mfaSet, $mfaChannel);
            session_regenerate_id(true);
            $_SESSION['mfa_uid']   = $user['id'];
            $_SESSION['mfa_token'] = bin2hex(random_bytes(16));
            $_SESSION['mfa_time']  = time();
            $issued = kaizenMfaIssueCode($DB, $user['id'], $mfaChannel, $mfaTarget, kaizenMfaConfig(), $user['name']);
            if (!$issued['ok']) {
                unset($_SESSION['mfa_uid'], $_SESSION['mfa_token'], $_SESSION['mfa_time']);
                fail($issued['error'], 502);
            }
            respond([
                'ok'          => true,
                'requiresMfa' => true,
                'mfaToken'    => $_SESSION['mfa_token'],
                'channel'     => $issued['channel'],
                'channels'    => $mfaCh['allowed'],
                'target'      => $issued['target'],
                'ttl'         => $issued['ttl'],
                'cooldown'    => max(5, (int)kaizenMfaConfig()['resend_cooldown_seconds']),
                'message'     => 'Kode verifikasi dikirim ke ' . $issued['target'],
            ]);
        }
    }

    session_regenerate_id(true);
    $_SESSION['kaizen_user_id'] = $user['id'];
    $_SESSION['kaizen_username'] = $user['username'];
    $_SESSION['kaizen_role'] = $user['role'];
    $_SESSION['kaizen_last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));

    // catat riwayat login (untuk grafik aktivitas login dashboard)
    $log = $DB->prepare('INSERT INTO login_log (username, full_name) VALUES (?, ?)');
    $log->bind_param('ss', $user['username'], $user['name']);
    $log->execute();
    $log->close();

    // counter login per-user dihitung server (client tidak perlu kirim saveRecord Users)
    $inc = $DB->prepare('UPDATE users SET login_count = IFNULL(login_count, 0) + 1 WHERE id = ?');
    $inc->bind_param('s', $user['id']);
    $inc->execute();
    $inc->close();
    $user['login_count'] = ($user['login_count'] ?? 0) + 1;

    unset($user['password']);
    respond([
        'ok'    => true,
        'user'  => rowToApp($user, $TABLES['Users']),
        'token' => session_id(),          // token sesi (cookie KAIZENSESSID juga)
        'csrf'  => $_SESSION['csrf'],
        'message' => 'Login berhasil',
    ]);
}

/* ------------------------------------------------------------
 * 1b. session — cek sesi aktif saat halaman dibuka ulang:
 *     bila cookie KAIZENSESSID masih valid, kirim data user
 *     (tanpa mengapungkan counter login / log aktivitas).
 * ------------------------------------------------------------ */
case 'session': {
    if (!isAuthed()) respond(['ok' => false]);
    $uid = $_SESSION['kaizen_user_id'];
    $st = $DB->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || $user['status'] !== 'Aktif') respond(['ok' => false]);
    unset($user['password']);
    respond([
        'ok'   => true,
        'user' => rowToApp($user, $TABLES['Users']),
        'csrf' => $_SESSION['csrf'],
    ]);
}
/* ------------------------------------------------------------
 * 2. logout
 * ------------------------------------------------------------ */
/* ------------------------------------------------------------
 * 1c. mfa_send — kirim ulang / ganti channel kode OTP
 *     (masih dalam sesi verifikasi pending, bukan sesi login)
 * ------------------------------------------------------------ */
case 'mfa_send': {
    $uid = kaizenMfaPendingUser();
    if (!$uid) fail('Sesi verifikasi tidak ditemukan / kedaluwarsa. Silakan login ulang.', 401);
    if (!hash_equals(kaizenMfaPendingToken(), (string)($body['mfaToken'] ?? ''))) fail('Token verifikasi tidak sah.', 403);

    $st = $DB->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || $user['status'] !== 'Aktif') {
        unset($_SESSION['mfa_uid'], $_SESSION['mfa_token'], $_SESSION['mfa_time']);
        fail('Akun tidak tersedia.', 403);
    }

    $set   = kaizenMfaSettings($DB, $uid);
    $mfaCh = kaizenMfaChannelsFor($set);
    $channel = (string)($body['channel'] ?? '');
    if (!in_array($channel, $mfaCh['allowed'], true)) $channel = $mfaCh['default'];
    $target = kaizenMfaTargetFor($set, $channel);
    if ($target === '') fail('Target pengiriman tidak tersedia. Hubungi admin.', 400);

    // Rate limit pengiriman ulang
    $cfg      = kaizenMfaConfig();
    $cooldown = max(5, (int)$cfg['resend_cooldown_seconds']);
    // Umur kode dihitung di MySQL agar tidak terpengaruh beda zona waktu PHP vs MySQL.
    $q = $DB->query(
        'SELECT channel, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS umur'
        . ' FROM mfa_codes WHERE user_id = ' . "'" . $DB->real_escape_string($uid) . "'"
        . ' ORDER BY id DESC LIMIT 1'
    );
    $last = $q ? $q->fetch_assoc() : null;
    if ($q) $q->close();
    if ($last) {
        $elapsed = (int)($last['umur'] ?? 0);
        if ($elapsed < 0) $elapsed = 0;
        // Cooldown hanya untuk channel yang sama; ganti channel boleh langsung
        // (kode lama otomatis dimatikan saat kode baru diterbitkan).
        if ($elapsed < $cooldown && (string)$last['channel'] === $channel) {
            $sisa = max(1, $cooldown - $elapsed);
            http_response_code(429);
            respond([
                'ok'         => false,
                'error'      => 'Tunggu ' . $sisa . ' detik sebelum meminta kode lagi.',
                'code'       => 'MFA_COOLDOWN',
                'retryAfter' => $sisa,
                'cooldown'   => $cooldown,
            ]);
        }
    }

    $issued = kaizenMfaIssueCode($DB, $uid, $channel, $target, $cfg, $user['name']);
    if (!$issued['ok']) fail($issued['error'], 502);
    respond([
        'ok'      => true,
        'channel' => $issued['channel'],
        'target'  => $issued['target'],
        'ttl'     => $issued['ttl'],
        'cooldown' => $cooldown,
        'message' => 'Kode baru dikirim ke ' . $issued['target'],
    ]);
}

/* ------------------------------------------------------------
 * 1d. mfa_verify — cek kode OTP; benar -> sesi login penuh
 * ------------------------------------------------------------ */
case 'mfa_verify': {
    $uid = kaizenMfaPendingUser();
    if (!$uid) fail('Sesi verifikasi tidak ditemukan / kedaluwarsa. Silakan login ulang.', 401);
    if (!hash_equals(kaizenMfaPendingToken(), (string)($body['mfaToken'] ?? ''))) fail('Token verifikasi tidak sah.', 403);

    $code = trim((string)($body['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) fail('Kode harus 6 digit angka.');

    $cfg         = kaizenMfaConfig();
    $maxAttempts = max(3, (int)$cfg['max_attempts']);

    $st = $DB->prepare('SELECT * FROM mfa_codes WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) fail('Kode tidak tersedia. Silakan minta kode baru.', 400);
    if (strtotime((string)$row['expires_at']) < time()) fail('Kode sudah kedaluwarsa. Silakan minta kode baru.', 400);
    if ((int)$row['attempts'] >= $maxAttempts) fail('Percobaan melebihi batas. Silakan minta kode baru.', 429);

    if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
        $upd = $DB->prepare('UPDATE mfa_codes SET attempts = attempts + 1 WHERE id = ?');
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
        $left = $maxAttempts - ((int)$row['attempts'] + 1);
        fail('Kode salah. Sisa percobaan: ' . max(0, $left) . '.', 403);
    }

    $mark = $DB->prepare('UPDATE mfa_codes SET used = 1 WHERE id = ?');
    $mark->bind_param('i', $row['id']);
    $mark->execute();
    $mark->close();

    unset($_SESSION['mfa_uid'], $_SESSION['mfa_token'], $_SESSION['mfa_time']);

    $st = $DB->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || $user['status'] !== 'Aktif') fail('Akun tidak tersedia.', 403);

    // Promosikan ke sesi login penuh (identik dengan login sukses)
    session_regenerate_id(true);
    $_SESSION['kaizen_user_id'] = $user['id'];
    $_SESSION['kaizen_username'] = $user['username'];
    $_SESSION['kaizen_role']     = $user['role'];
    $_SESSION['kaizen_last']     = time();
    $_SESSION['csrf']            = bin2hex(random_bytes(16));

    $log = $DB->prepare('INSERT INTO login_log (username, full_name) VALUES (?, ?)');
    $log->bind_param('ss', $user['username'], $user['name']);
    $log->execute();
    $log->close();

    $inc = $DB->prepare('UPDATE users SET login_count = IFNULL(login_count, 0) + 1 WHERE id = ?');
    $inc->bind_param('s', $user['id']);
    $inc->execute();
    $inc->close();
    $user['login_count'] = ($user['login_count'] ?? 0) + 1;

    // "Ingat perangkat ini" -> cookie KAIZENMFA 30 hari (login berikutnya tanpa OTP)
    if (!empty($body['remember'])) kaizenMfaRememberDevice($DB, $user['id']);

    unset($user['password']);
    respond([
        'ok'    => true,
        'user'  => rowToApp($user, $TABLES['Users']),
        'token' => session_id(),
        'csrf'  => $_SESSION['csrf'],
        'message' => 'Verifikasi berhasil',
    ]);
}

/* ------------------------------------------------------------
 * 1e. mfa_cancel — batalkan layar OTP, kembali ke form login
 * ------------------------------------------------------------ */
case 'mfa_cancel': {
    if (kaizenMfaPendingUser()) unset($_SESSION['mfa_uid'], $_SESSION['mfa_token'], $_SESSION['mfa_time']);
    respond(['ok' => true]);
}

/* ------------------------------------------------------------
 * 1f. mfa_get / mfa_list / mfa_save — pengaturan MFA per-user
 *     (butuh sesi; admin & superadmin; admin tidak boleh mengatur
 *      akun superadmin, mengikuti pola reset password)
 * ------------------------------------------------------------ */
case 'mfa_get': {
    requireAuth();
    if (!in_array($_SESSION['kaizen_role'] ?? '', ['superadmin', 'admin'], true)) fail('Tidak diizinkan.', 403);
    $uid = (string)($body['userId'] ?? '');
    if ($uid === '') fail('userId wajib diisi');
    $st = $DB->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$u) fail('User tidak ditemukan', 404);
    if (($_SESSION['kaizen_role'] ?? '') === 'admin' && $u['role'] === 'superadmin') fail('Admin tidak boleh mengatur MFA akun Superadmin.', 403);
    respond(['ok' => true, 'settings' => kaizenMfaSettings($DB, $uid)]);
}

case 'mfa_list': {
    requireAuth();
    if (!in_array($_SESSION['kaizen_role'] ?? '', ['superadmin', 'admin'], true)) fail('Tidak diizinkan.', 403);
    $map = [];
    $res = $DB->query('SELECT user_id, enabled, channel FROM mfa_settings');
    if ($res) { while ($r = $res->fetch_assoc()) $map[$r['user_id']] = ['enabled' => (int)$r['enabled'], 'channel' => $r['channel']]; $res->close(); }
    respond(['ok' => true, 'map' => $map]);
}

case 'mfa_save': {
    requireAuth();
    if (!in_array($_SESSION['kaizen_role'] ?? '', ['superadmin', 'admin'], true)) fail('Tidak diizinkan.', 403);
    $uid     = (string)($body['userId'] ?? '');
    $enabled = !empty($body['enabled']) ? 1 : 0;
    $channel = (string)($body['channel'] ?? 'both');
    if (!in_array($channel, ['email', 'whatsapp', 'both'], true)) $channel = 'both';
    $email   = trim((string)($body['email'] ?? ''));
    $wa      = trim((string)($body['whatsapp'] ?? ''));
    if ($uid === '') fail('userId wajib diisi');

    $st = $DB->prepare('SELECT id, role, name FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('s', $uid);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$u) fail('User tidak ditemukan', 404);
    if (($_SESSION['kaizen_role'] ?? '') === 'admin' && $u['role'] === 'superadmin') fail('Admin tidak boleh mengatur MFA akun Superadmin.', 403);

    $waNorm = $wa !== '' ? kaizenMfaNormalizePhone($wa) : '';
    if ($wa !== '' && $waNorm === '') fail('Nomor WhatsApp tidak valid. Contoh: 0812xxxx atau 62812xxxx.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Alamat email tidak valid.');

    if ($enabled) {
        $pakaiEmail = $channel !== 'whatsapp';
        $pakaiWa    = $channel !== 'email';
        if ($pakaiEmail && $email === '') fail('Alamat email wajib diisi untuk pengiriman OTP via Email.');
        if ($pakaiWa && $waNorm === '') fail('Nomor WhatsApp wajib diisi untuk pengiriman OTP via WhatsApp.');
        if ($channel === 'both' && ($email === '' || $waNorm === '')) fail('Mode "Keduanya" butuh alamat email DAN nomor WhatsApp.');
    }

    $st = $DB->prepare('INSERT INTO mfa_settings (user_id, enabled, channel, email, whatsapp) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), channel = VALUES(channel), email = VALUES(email), whatsapp = VALUES(whatsapp)');
    $st->bind_param('sisss', $uid, $enabled, $channel, $email, $waNorm);
    $st->execute();
    $st->close();

    if (!$enabled) {
        // MFA dimatikan -> bersihkan kode pending & daftar perangkat terpercaya
        $d1 = $DB->prepare('DELETE FROM mfa_codes WHERE user_id = ?');
        $d1->bind_param('s', $uid); $d1->execute(); $d1->close();
        $d2 = $DB->prepare('DELETE FROM mfa_devices WHERE user_id = ?');
        $d2->bind_param('s', $uid); $d2->execute(); $d2->close();
    }

    kaizenMfaDebugLog('SETTING user=' . $u['name'] . " enabled={$enabled} channel={$channel}");
    respond(['ok' => true, 'settings' => kaizenMfaSettings($DB, $uid), 'message' => $enabled ? 'MFA diaktifkan.' : 'MFA dimatikan.']);
}

case 'logout': {
    $_SESSION = [];
    session_destroy();
    // Kedaluwarsakan cookie sesi di browser juga, agar tidak ada sisa sesi sah
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    respond(['ok' => true, 'message' => 'Logout berhasil']);
}

/* ------------------------------------------------------------
 * 2b. changePassword — ganti password akun sendiri (butuh sesi)
 *     Setiap user boleh mengganti password miliknya sendiri.
 * ------------------------------------------------------------ */
case 'changePassword': {
    requireAuth();
    $current = (string)($body['currentPassword'] ?? '');
    $newp    = (string)($body['newPassword'] ?? '');
    if ($current === '' || $newp === '') fail('Password lama dan password baru wajib diisi');
    if (strlen($newp) < 6) fail('Password baru minimal 6 karakter');

    $uid = (string)$_SESSION['kaizen_user_id'];
    $stmt = $DB->prepare('SELECT id, password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) fail('User tidak ditemukan', 404);

    $stored = (string)$user['password'];
    $ok = isPasswordHash($stored) ? password_verify($current, $stored) : ($stored === $current);
    if (!$ok) fail('Password lama salah!', 403);
    if (isPasswordHash($stored) && password_verify($newp, $stored)) fail('Password baru tidak boleh sama dengan password lama');

    $hash = password_hash($newp, PASSWORD_DEFAULT);
    $u2 = $DB->prepare('UPDATE users SET password = ? WHERE id = ?');
    $u2->bind_param('ss', $hash, $uid);
    $u2->execute();
    $u2->close();

    respond(['ok' => true, 'message' => 'Password berhasil diganti']);
}

/* ------------------------------------------------------------
 * 3. getInitialData (butuh sesi)
 * ------------------------------------------------------------ */
case 'getInitialData': {
    requireAuth();
    $payload = [];
    foreach ($TABLES as $sheet => $cfg) {
        try {
            $res = $DB->query('SELECT * FROM `' . $cfg['table'] . '` ORDER BY 1');
        } catch (mysqli_sql_exception $e) {
            // 1146 = tabel belum dimigrasi: jangan gagalkan seluruh data awal portal
            if ((int)$e->getCode() === 1146) { $payload[$sheet] = []; continue; }
            fail('Query gagal pada ' . $cfg['table'] . ': ' . $e->getMessage(), 500);
        }
        if ($res === false) fail('Query gagal pada ' . $cfg['table'] . ': ' . $DB->error, 500);
        $payload[$sheet] = rowsToApp($res, $cfg);
    }
    respond(['ok' => true] + $payload);
}

/* ------------------------------------------------------------
 * 4. reminders (butuh sesi) — temuan Gemba HSE & QC mendekati/lewat due
 * ------------------------------------------------------------ */
case 'reminders': {
    requireAuth();
    $items = [];
    foreach (['Gemba' => 'gemba_findings', 'GembaQc' => 'gemba_qc_findings'] as $sheetKey => $table) {
        $res = $DB->query("SELECT * FROM `$table` WHERE status <> 'Closed' AND due_date IS NOT NULL
                           AND (due_date <= CURDATE() + INTERVAL " . REMINDER_HOUR_REFRESH_DAYS . " DAY)");
        if ($res === false) continue;
        $rows = rowsToApp($res, $TABLES[$sheetKey]);
        foreach ($rows as $r) { $r['module'] = ($sheetKey === 'Gemba') ? 'gemba' : 'gemba-qc'; $items[] = $r; }
    }
    // urutkan: yang paling terlambat dulu
    usort($items, function($a, $b) { return strcmp($a['dueDate'] ?? '', $b['dueDate'] ?? ''); });
    respond(['ok' => true, 'items' => $items, 'thresholdDays' => REMINDER_HOUR_REFRESH_DAYS]);
}

/* ------------------------------------------------------------
 * 4b. uploadImage (butuh sesi) — simpan foto/dokumen form BAP ke uploads/
 *     Dipakai agar JSON `note` cukup menyimpan path, bukan base64.
 * ------------------------------------------------------------ */
case 'uploadImage': {
    requireAuth();
    $raw = (string)($body['data'] ?? '');
    if ($raw === '') fail('Data gambar wajib diisi');
    if (strlen($raw) > 8 * 1024 * 1024) fail('Ukuran gambar terlalu besar', 413);
    $prefix = preg_replace('/[^a-z0-9]/i', '', (string)($body['prefix'] ?? 'bap'));
    $field  = preg_replace('/[^a-z0-9]/i', '', (string)($body['field'] ?? 'img'));
    $path = storeBase64ToUploads($raw, $prefix !== '' ? $prefix : 'bap', $field !== '' ? $field : 'img', $IMG_EXTS, $DOC_EXTS);
    respond(['ok' => true, 'path' => $path]);
}

/* ------------------------------------------------------------
 * 5. saveRecord (butuh sesi)
 * ------------------------------------------------------------ */
case 'saveRecord': {
    requireAuth();
    $sheet = (string)($body['sheetName'] ?? '');
    if (!isset($TABLES[$sheet])) fail('sheetName tidak dikenal: ' . $sheet);
    // Hanya admin/superadmin boleh menyimpan data Users (akun & hak akses)
    if ($sheet === 'Users' && !in_array($_SESSION['kaizen_role'] ?? '', ['superadmin', 'admin'], true)) {
        fail('Tidak diizinkan: pengelolaan user hanya untuk Admin.', 403);
    }
    // Master Data (Produk/Customer): hanya Admin & Superadmin
    if (in_array($sheet, ['Products', 'Customers', 'Suppliers', 'Curahs'], true) && !in_array($_SESSION['kaizen_role'] ?? '', ['superadmin', 'admin'], true)) {
        fail('Tidak diizinkan: Master Data hanya untuk Admin.', 403);
    }
    $cfg = $TABLES[$sheet];
    $record = $body['record'] ?? null;
    if (!is_array($record) || empty($record['id'])) fail('record.id wajib ada');
    // ID ikut dirender ke atribut HTML/JS di klien -> batasi ke karakter aman
    $rid = (string)$record['id'];
    if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $rid)) fail('record.id tidak valid');

    // Proteksi akun: Admin tidak boleh mengubah akun Superadmin maupun membuat/menjadikan akun Superadmin
    if ($sheet === 'Users' && ($_SESSION['kaizen_role'] ?? '') === 'admin') {
        $adaRole = '';
        $stRole = $DB->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        if ($stRole) {
            $stRole->bind_param('s', $rid);
            $stRole->execute();
            $rsRole = $stRole->get_result();
            if ($rsRole && ($rwRole = $rsRole->fetch_assoc())) $adaRole = (string)$rwRole['role'];
            $stRole->close();
        }
        if ($adaRole === 'superadmin') fail('Tidak diizinkan: akun Superadmin hanya dapat dikelola oleh Superadmin.', 403);
        if ((string)($record['role'] ?? '') === 'superadmin') fail('Tidak diizinkan: membuat/menjadikan akun Superadmin hanya untuk Superadmin.', 403);
    }

    $data = recordToColumns($record, $cfg, $sheet);
    if (!$data) fail('Tidak ada kolom dikenali di record');

    // Documents: pastikan file/URL tetap konsisten
    if ($sheet === 'Documents' && isset($data['file_data']) && $data['file_data'] === null && !isset($data['file_url'])) {
        unset($data['file_data']);   // tidak ada berkas baru — jangan reset yang lama
    }

    $cols = array_keys($data);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $upd  = [];
    foreach ($cols as $c) if ($c !== 'created_at') $upd[] = "`$c` = VALUES(`$c`)";   // created_at tidak diubah saat update
    $sql  = 'INSERT INTO `' . $cfg['table'] . '` (`' . implode('`,`', $cols) . "`) VALUES ($ph)"
          . ($upd ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upd) : '');

    $types = '';
    $bind  = [];
    foreach ($data as $v) { $types .= 's'; $bind[] = $v === null ? null : (string)$v; }
    $stmt = $DB->prepare($sql);
    if (!$stmt) fail('Prepare gagal: ' . $DB->error, 500);
    $stmt->bind_param($types, ...$bind);
    if (!$stmt->execute()) fail('Simpan gagal: ' . $stmt->error, 500);
    $stmt->close();

    // Balas dengan isi record final dari DB (kolom foto/dokumen sudah berupa path uploads/,
    // password tetap di-mask oleh rowToApp) -> dipakai klien untuk menyegarkan cache lokal
    $sel = $DB->prepare('SELECT * FROM `' . $cfg['table'] . '` WHERE id = ? LIMIT 1');
    $final = null;
    if ($sel) {
        $sel->bind_param('s', $rid);
        $sel->execute();
        $resSel = $sel->get_result();
        if ($resSel) {
            $rowSel = $resSel->fetch_assoc();
            if ($rowSel) $final = rowToApp($rowSel, $cfg);
        }
        $sel->close();
    }

    respond(['ok' => true, 'sheetName' => $sheet, 'id' => $rid, 'record' => $final]);
}

/* ------------------------------------------------------------
 * 6. deleteRecord (butuh sesi)
 * ------------------------------------------------------------ */
case 'deleteRecord': {
    requireAuth();
    $sheet = (string)($body['sheetName'] ?? '');
    if (!isset($TABLES[$sheet])) fail('sheetName tidak dikenal: ' . $sheet);
    // Pola izin hapus (permintaan pemilik):
    //  - AKUN USER (sheet Users): hanya Superadmin
    //  - DATA OPERASIONAL (Tiket/Aset/Gemba/GembaQc/Dokumen/Folder):
    //    Superadmin, Admin IT&HSE, Manager, Supervisor, Officer
    $role = $_SESSION['kaizen_role'] ?? '';
    if ($sheet === 'Users') {
        if ($role !== 'superadmin') fail('Tidak diizinkan: hapus akun hanya untuk Superadmin.', 403);
    } else if (in_array($sheet, ['Products', 'Customers', 'Suppliers', 'Curahs'], true)) {
        if (!in_array($role, ['superadmin', 'admin'], true)) fail('Tidak diizinkan: Master Data hanya untuk Admin.', 403);
    } else if ($sheet === 'Qc') {
        // Papan Material Receipt: semua peran yang login boleh menghapus data QC/kedatangan
        // (permintaan pemilik). Menu lain tetap memakai aturan peran di bawah.
        if ($role === '') fail('Tidak diizinkan: sesi tidak valid.', 403);
    } else if (!in_array($role, ['superadmin', 'admin', 'manager', 'supervisor', 'officer'], true)) {
        fail('Tidak diizinkan: peranmu tidak berhak menghapus data.', 403);
    }
    $cfg = $TABLES[$sheet];
    $id  = (string)($body['recordId'] ?? '');
    if ($id === '') fail('recordId wajib ada');
    if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id)) fail('recordId tidak valid');

    $stmt = $DB->prepare('DELETE FROM `' . $cfg['table'] . '` WHERE id = ?');
    $stmt->bind_param('s', $id);
    if (!$stmt->execute()) fail('Hapus gagal: ' . $stmt->error, 500);
    $stmt->close();

    respond(['ok' => true, 'sheetName' => $sheet, 'id' => $id]);
}

/* ------------------------------------------------------------
 * 7. list per sheet (butuh sesi)
 * ------------------------------------------------------------ */
case 'list': {
    requireAuth();
    $sheet = $_GET['sheet'] ?? '';
    if (!isset($TABLES[$sheet])) fail('sheetName tidak dikenal: ' . $sheet);
    $res = $DB->query('SELECT * FROM `' . $TABLES[$sheet]['table'] . '`');
    $rows = rowsToApp($res, $TABLES[$sheet]);
    respond(['ok' => true, $sheet => $rows]);
}

default:
    fail('Operasi tidak dikenal. Gunakan: login | logout | getInitialData | saveRecord | deleteRecord | list | reminders');
}
