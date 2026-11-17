<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Migrasi password sekali jalan:
 *  - Semua password plain -> password_hash (bcrypt)
 *  - 'admin' & 'user' yang masih '1234' diganti password kuat
 *        admin : KaizenAdmin#2026
 *        user  : KaizenUser#2026
 *  Aman dijalankan berulang: baris yang sudah hash ($2y$) tidak disentuh.
 */
require __DIR__ . '/../config.php';

$replacement = [
    'admin' => 'KaizenAdmin#2026',
    'user'  => 'KaizenUser#2026',
];

$res = $DB->query('SELECT id, username, password FROM users');
$changed = [];
while ($row = $res->fetch_assoc()) {
    $stored = (string) $row['password'];
    if (strlen($stored) >= 60 && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$'))) {
        continue; // sudah bcrypt
    }
    // ganti 1234 di akun default dengan password kuat
    $plain = $stored;
    if ($row['username'] === 'admin' && $stored === '1234')       $plain = $replacement['admin'];
    if ($row['username'] === 'user'  && $stored === '1234')       $plain = $replacement['user'];

    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $u = $DB->prepare('UPDATE users SET password = ? WHERE id = ?');
    $u->bind_param('ss', $hash, $row['id']);
    $u->execute();
    $u->close();
    $changed[] = $row['username'];
}

echo json_encode([
    'ok' => true,
    'migrated' => $changed,
    'total_users' => $res->num_rows,
    'note' => 'Password plain -> bcrypt. admin/user yang masih 1234 -> KaizenAdmin#2026 / KaizenUser#2026',
], JSON_UNESCAPED_UNICODE), PHP_EOL;
