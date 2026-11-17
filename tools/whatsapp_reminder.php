<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * ============================================================
 * KAIZEN HSE PORTAL — Reminder WhatsApp otomatis (CLI)
 * ============================================================
 * Dijalankan Windows Task Scheduler harian (mis. 07:00).
 *
 * Fungsi:
 *  1. Hitung temuan Gemba HSE & QC yang status != 'Closed'
 *     dan due_date <= H+3 (atau sudah terlambat).
 *  2. Tulis hasil ke tabel reminder_log (riwayat harian).
 *  3. Bila gateway WhatsApp dikonfigurasi (Fonnte/Wablas), kirim
 *     pesan otomatis ke nomor admin. Bila token kosong, hanya
 *     log (mode log-only) — banding aman tanpa layanan pihak ketiga.
 *
 * Cara pasang gateway rekomendasi (pilih salah satu):
 *  - Fonnte  : daftar fonnte.com (WA biasa jadi robot) -> salin TOKEN
 *  - Wablas  : daftar wablas.com (perangkat WA reseller)
 *  Lalu simpan token di file env: C:\xampp\htdocs\kaizen\tools\gateway_token.txt
 *  (format 2 baris: baris1 = 'fonnte' atau 'wablas', baris2 = token,
 *   baris3 = nomor tujuan 628xxxxxxxxxx)
 */
require __DIR__ . '/../config.php';

const THRESHOLD_DAYS = 3;

/* ---- baca konfigurasi gateway (opsional) ---- */
$gw = ['driver' => '', 'token' => '', 'target' => ''];
$gwFile = __DIR__ . '/gateway_token.txt';
if (is_file($gwFile)) {
    $lines = file($gwFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $gw = [
        'driver' => strtolower(trim($lines[0] ?? '')),
        'token'  => trim($lines[1] ?? ''),
        'target' => preg_replace('/\D/', '', $lines[2] ?? ''),
    ];
}

/* ---- ambil temuan mendekati/lewat due ---- */
$items = [];
foreach (['gemba_findings' => 'Gemba HSE', 'gemba_qc_findings' => 'Gemba QC'] as $table => $label) {
    $res = $DB->query("SELECT id, title, area, location, priority, status, due_date, pic, divisi
                       FROM `$table`
                       WHERE status <> 'Closed' AND due_date IS NOT NULL
                         AND due_date <= CURDATE() + INTERVAL " . THRESHOLD_DAYS . " DAY
                       ORDER BY due_date ASC");
    while ($r = $res->fetch_assoc()) { $r['module'] = $label; $items[] = $r; }
}

$count = count($items);

if ($count > 0) {
    $lines = ["*REMINDER KAIZEN HSE PORTAL*", "Ada $count temuan mendekati / terlambat jatuh tempo:", "*Segera follow-up. Detail:*", ""];
    foreach (array_slice($items, 0, 10) as $i => $it) {          // tampilkan max 10
        $diff = (int)((strtotime($it['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $due  = $diff >= 0 ? "H-$diff" : "LATE ".(-$diff)." hari";
        $lines[] = sprintf("%d. [%s] %s\n    Area: %s | PIC: %s | Due: %s (%s)",
            $i + 1, $it['module'], $it['title'], $it['area'], $it['pic'] ?: '-', $it['due_date'], $due);
    }
    if ($count > 10) $lines[] = "... + " . ($count - 10) . " temuan lainnya (lihat portal)";
    // URL portal ikut IP LAN aktif saat ini (PC bisa berpindah jaringan)
    $ips = gethostbynamel(gethostname());
    $portalUrl = 'http://' . ($ips[0] ?? 'localhost') . '/kaizen/';
    $lines[] = ""; $lines[] = "Portal: $portalUrl";
    $message = implode("\n", $lines);
} else {
    $message = '*REMINDER KAIZEN HSE PORTAL* — Tidak ada temuan yang jatuh tempo hari ini. \u{2705}';
}

/* ---- kirim bila gateway terpasang; selain itu log-only ---- */
$sentStatus = 'log-only';
if ($gw['token'] !== '' && $gw['target'] !== '') {
    if ($gw['driver'] === 'fonnte') {
        $ch = curl_init('https://api.fonnte.com/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: {$gw['token']}"],
            CURLOPT_POSTFIELDS => http_build_query(['target' => $gw['target'], 'message' => $message, 'countryCode' => '62']),
        ]);
        $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        $sentStatus = $err !== '' ? 'error: ' . $err : 'sent';
    } elseif ($gw['driver'] === 'wablas') {
        // isi bila menggunakan wablas.com
        $sentStatus = 'belum-diimplementasi';
    }
}

/* ---- simpan log harian ---- */
$stmt = $DB->prepare('INSERT INTO reminder_log (run_date, findings_count, sent_status, detail)
                      VALUES (CURDATE(), ?, ?, ?)
                      ON DUPLICATE KEY UPDATE findings_count = VALUES(findings_count), sent_status = VALUES(sent_status), detail = VALUES(detail)');
$detail = json_encode(['items' => array_column($items, 'id')], JSON_UNESCAPED_UNICODE);
$stmt->bind_param('iss', $count, $sentStatus, $detail);
$stmt->execute();
$stmt->close();

/* ---- keluaran CLI ---- */
echo '[' . date('Y-m-d H:i') . "] Temuan terdeteksi: $count | Status kirim: $sentStatus | Target: " . ($gw['target'] ?: '-') . PHP_EOL;
echo $message . PHP_EOL;
