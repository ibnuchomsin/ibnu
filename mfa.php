<?php
/**
 * ============================================================
 * KAIZEN PORTAL — Modul MFA / Verifikasi Dua Langkah (OTP)
 * ============================================================
 * Dipakai api.php. Fungsi:
 *  - Konfigurasi dari tools/data/mfa_config.php (di luar git & web)
 *  - Kirim OTP 6 digit via Email (PHPMailer + SMTP) atau WhatsApp (Fonnte)
 *  - Kode disimpan sebagai SHA-256 (bukan plaintext), TTL, batas percobaan
 *  - Perangkat terpercaya (cookie 30 hari) -> login tanpa OTP dari PC yang sama
 * Semua fungsi diawali kaizenMfa* agar tidak bentrok.
 */

const KAIZEN_MFA_COOKIE   = 'KAIZENMFA';
const KAIZEN_MFA_LOG      = __DIR__ . '/tools/data/mfa_debug.log';
const KAIZEN_MFA_PHPMAILER = __DIR__ . '/lib/phpmailer';

function kaizenMfaConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = @include __DIR__ . '/tools/data/mfa_config.php';
        if (!is_array($cfg)) $cfg = [];
        $cfg += [
            'debug' => true, 'from_email' => '', 'from_name' => 'KAIZEN Portal',
            'smtp_host' => '', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '',
            'wa_gateway' => 'fonnte', 'wa_token' => '',
            'code_ttl_minutes' => 5, 'resend_cooldown_seconds' => 30,
            'max_attempts' => 5, 'device_days' => 30,
        ];
    }
    return $cfg;
}

function kaizenMfaDebugLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(KAIZEN_MFA_LOG, $line, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------
 * Target & penyamaran (jangan bocorkan nomor/email penuh di UI)
 * ------------------------------------------------------------ */
function kaizenMfaNormalizePhone(string $in): string
{
    $d = preg_replace('/\D+/', '', $in);
    if ($d === '') return '';
    if (strpos($d, '0') === 0) $d = '62' . substr($d, 1);      // 08xx -> 628xx
    elseif (strpos($d, '62') !== 0) $d = '62' . $d;            // 8xx -> 628xx
    return (strlen($d) >= 9 && strlen($d) <= 15) ? $d : '';
}

function kaizenMfaMask(string $channel, string $target): string
{
    if ($channel === 'email') {
        $at = strpos($target, '@');
        if ($at === false) return $target;
        $name = substr($target, 0, $at);
        $dom  = substr($target, $at);
        $head = substr($name, 0, 2);
        return $head . str_repeat('*', max(2, min(5, strlen($name) - 2))) . $dom;
    }
    return '+' . substr($target, 0, 3) . ' **** ' . substr($target, -3);
}

/* ------------------------------------------------------------
 * Pengaturan MFA per-user
 * ------------------------------------------------------------ */
function kaizenMfaSettings(mysqli $DB, string $userId): array
{
    $st = $DB->prepare('SELECT enabled, channel, email, whatsapp FROM mfa_settings WHERE user_id = ? LIMIT 1');
    $st->bind_param('s', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return ['enabled' => 0, 'channel' => 'both', 'email' => '', 'whatsapp' => ''];
    $row['enabled']  = (int)$row['enabled'];
    $row['email']    = (string)$row['email'];
    $row['whatsapp'] = (string)$row['whatsapp'];
    return $row;
}

/** Daftar channel yang valid utk user (channel tunggal vs 'both') + pilihan awal */
function kaizenMfaChannelsFor(array $s): array
{
    $allowed = [];
    if ($s['email'] !== ''    && $s['channel'] !== 'whatsapp') $allowed[] = 'email';
    if ($s['whatsapp'] !== '' && $s['channel'] !== 'email')    $allowed[] = 'whatsapp';
    if (empty($allowed)) return ['allowed' => [], 'default' => ''];
    $def = $s['channel'] === 'both' ? $allowed[0] : ($s['channel'] === 'whatsapp' ? 'whatsapp' : 'email');
    if (!in_array($def, $allowed, true)) $def = $allowed[0];
    return ['allowed' => $allowed, 'default' => $def];
}

function kaizenMfaTargetFor(array $s, string $channel): string
{
    return $channel === 'whatsapp' ? $s['whatsapp'] : $s['email'];
}

/* ------------------------------------------------------------
 * Pengiriman
 * ------------------------------------------------------------ */
function kaizenMfaMessage(string $code, int $ttlMin): string
{
    return "KODE VERIFIKASI KAIZEN PORTAL\n\n"
         . "Kode Anda: {$code}\n"
         . "Berlaku {$ttlMin} menit. JANGAN berikan kode ini ke siapa pun, termasuk yang mengaku IT.\n\n"
         . "Bila Anda tidak meminta kode ini, abaikan pesan ini.";
}

function kaizenMfaSendEmail(string $to, string $code, array $cfg): array
{
    if ($cfg['debug']) {
        kaizenMfaDebugLog("OTP email -> {$to} KODE={$code}");
        return ['ok' => true];
    }
    if ($cfg['smtp_user'] === '' || $cfg['smtp_pass'] === '' || $cfg['smtp_host'] === '') {
        return ['ok' => false, 'error' => 'SMTP belum dikonfigurasi (isi smtp_user/smtp_pass di mfa_config.php)'];
    }
    require_once KAIZEN_MFA_PHPMAILER . '/Exception.php';
    require_once KAIZEN_MFA_PHPMAILER . '/PHPMailer.php';
    require_once KAIZEN_MFA_PHPMAILER . '/SMTP.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->Port       = (int)$cfg['smtp_port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'];
        $mail->Password   = $cfg['smtp_pass'];
        $mail->SMTPSecure = ((int)$cfg['smtp_port'] === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['smtp_user'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Kode Verifikasi Login — ' . $cfg['from_name'];
        $mail->Body    = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden">'
            . '<div style="background:#0a0f1e;color:#fff;padding:18px 24px;font-weight:bold">' . htmlspecialchars($cfg['from_name']) . '</div>'
            . '<div style="padding:24px;color:#0f172a;line-height:1.6">'
            . '<p>Kode verifikasi login Anda:</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:8px 0">' . htmlspecialchars($code) . '</p>'
            . '<p>Berlaku <b>' . (int)$cfg['code_ttl_minutes'] . ' menit</b>. Jangan berikan kode ini ke siapa pun, termasuk yang mengaku IT.</p>'
            . '<p style="color:#64748b;font-size:12px">Bila Anda tidak meminta kode ini, abaikan email ini.</p>'
            . '</div></div>';
        $mail->AltBody = kaizenMfaMessage($code, (int)$cfg['code_ttl_minutes']);
        $mail->send();
        return ['ok' => true];
    } catch (\Throwable $e) {
        kaizenMfaDebugLog('GAGAL kirim email ke ' . $to . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Gagal mengirim email OTP: ' . $e->getMessage()];
    }
}

function kaizenMfaSendWhatsApp(string $phone, string $code, array $cfg): array
{
    if ($cfg['debug']) {
        kaizenMfaDebugLog("OTP whatsapp -> {$phone} KODE={$code}");
        return ['ok' => true];
    }
    if ($cfg['wa_token'] === '') {
        return ['ok' => false, 'error' => 'Gateway WhatsApp belum dikonfigurasi (isi wa_token di mfa_config.php)'];
    }
    if ($cfg['wa_gateway'] !== 'fonnte') {
        return ['ok' => false, 'error' => 'Gateway WhatsApp tidak dikenal: ' . $cfg['wa_gateway']];
    }
    $payload = http_build_query([
        'target'  => $phone,
        'message' => kaizenMfaMessage($code, (int)$cfg['code_ttl_minutes']),
    ]);
    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $cfg['wa_token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) {
        kaizenMfaDebugLog("GAGAL kirim WA ke {$phone}: {$err}");
        return ['ok' => false, 'error' => 'Gagal menghubungi gateway WhatsApp: ' . $err];
    }
    $json = json_decode((string)$res, true);
    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['status'])) {
        return ['ok' => true];
    }
    $reason = is_array($json) ? (string)($json['reason'] ?? $res) : substr((string)$res, 0, 200);
    kaizenMfaDebugLog("GAGAL kirim WA ke {$phone} (HTTP {$code}): {$reason}");
    return ['ok' => false, 'error' => 'Gateway WhatsApp menolak: ' . $reason];
}

/* ------------------------------------------------------------
 * Terbitkan kode OTP (simpan hash, hapus kode lama, kirim)
 * ------------------------------------------------------------ */
function kaizenMfaIssueCode(mysqli $DB, string $userId, string $channel, string $target, array $cfg, string $userName = ''): array
{
    $code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash  = hash('sha256', $code);
    $ttl   = max(1, (int)$cfg['code_ttl_minutes']);
    $mins  = '+' . $ttl . ' minutes';
    $now   = date('Y-m-d H:i:s');
    $exp   = date('Y-m-d H:i:s', strtotime($mins));

    // kode lama yang belum terpakai -> matikan (satu kode aktif per user)
    $del = $DB->prepare('DELETE FROM mfa_codes WHERE user_id = ? AND used = 0');
    $del->bind_param('s', $userId);
    $del->execute();
    $del->close();

    $ins = $DB->prepare('INSERT INTO mfa_codes (user_id, code_hash, channel, target, expires_at) VALUES (?, ?, ?, ?, ?)');
    $ins->bind_param('sssss', $userId, $hash, $channel, $target, $exp);
    $ins->execute();
    $ins->close();

    $sent = $channel === 'whatsapp'
        ? kaizenMfaSendWhatsApp($target, $code, $cfg)
        : kaizenMfaSendEmail($target, $code, $cfg);

    if (!$sent['ok']) {
        $del2 = $DB->prepare('DELETE FROM mfa_codes WHERE user_id = ? AND used = 0');
        $del2->bind_param('s', $userId);
        $del2->execute();
        $del2->close();
        return $sent;
    }

    $mask = kaizenMfaMask($channel, $target);
    $who  = $userName !== '' ? $userName : $userId;
    if ($cfg['debug']) kaizenMfaDebugLog("ISSUE user={$who} channel={$channel} target={$mask} KODE={$code} berlaku {$ttl} menit");
    return ['ok' => true, 'channel' => $channel, 'target' => $mask, 'expiresAt' => str_replace(' ', 'T', $exp), 'ttl' => $ttl];
}

/* ------------------------------------------------------------
 * Pending attempt (password sudah benar, menunggu OTP)
 * ------------------------------------------------------------ */
function kaizenMfaPendingUser(): ?string
{
    if (empty($_SESSION['mfa_uid']) || empty($_SESSION['mfa_token'])) return null;
    if (isset($_SESSION['mfa_time']) && (time() - (int)$_SESSION['mfa_time']) > 900) return null; // 15 menit
    return (string)$_SESSION['mfa_uid'];
}

function kaizenMfaPendingToken(): string
{
    return (string)($_SESSION['mfa_token'] ?? '');
}

/* ------------------------------------------------------------
 * Perangkat terpercaya (cookie KAIZENMFA, hash tersimpan di DB)
 * ------------------------------------------------------------ */
function kaizenMfaTrustedDevice(mysqli $DB, string $userId): bool
{
    if (empty($_COOKIE[KAIZEN_MFA_COOKIE])) return false;
    $hash = hash('sha256', (string)$_COOKIE[KAIZEN_MFA_COOKIE]);
    $st = $DB->prepare('SELECT id FROM mfa_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1');
    $st->bind_param('ss', $userId, $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (bool)$row;
}

function kaizenMfaRememberDevice(mysqli $DB, string $userId): void
{
    $cfg  = kaizenMfaConfig();
    $days = max(1, (int)$cfg['device_days']);
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = date('Y-m-d H:i:s', time() + $days * 86400);
    $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $st = $DB->prepare('INSERT INTO mfa_devices (user_id, token_hash, expires_at, user_agent) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), user_agent = VALUES(user_agent)');
    $st->bind_param('ssss', $userId, $hash, $exp, $ua);
    $st->execute();
    $st->close();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(KAIZEN_MFA_COOKIE, $token, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(KAIZEN_MFA_COOKIE, $token, time() + $days * 86400, '/', '', true, true);
    }
}

/* Bersih-bersih lazy: kode kedaluwarsa & perangkat lewat masa berlaku */
function kaizenMfaCleanup(mysqli $DB): void
{
    $DB->query('DELETE FROM mfa_codes WHERE expires_at < NOW() - INTERVAL 1 DAY OR (used = 1 AND created_at < NOW() - INTERVAL 1 DAY)');
    $DB->query('DELETE FROM mfa_devices WHERE expires_at < NOW()');
}
