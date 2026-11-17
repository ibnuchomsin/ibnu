<?php
/**
 * Bootstrap sesi & pemeriksaan login bersama untuk api.php dan file.php.
 * Nama cookie dan timeout harus sama di kedua endpoint.
 */

const KAIZEN_SESSION_NAME = 'KAIZENSESSID';
const KAIZEN_SESSION_IDLE = 28800; // 8 jam

function kaizenSessionStart(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_name(KAIZEN_SESSION_NAME);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.gc_maxlifetime', (string)KAIZEN_SESSION_IDLE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isAuthed(): bool
{
    if (!isset($_SESSION['kaizen_user_id']) || $_SESSION['kaizen_user_id'] === '') {
        return false;
    }
    $now = time();
    if (isset($_SESSION['kaizen_last']) && ($now - (int)$_SESSION['kaizen_last']) > KAIZEN_SESSION_IDLE) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return false;
    }
    $_SESSION['kaizen_last'] = $now;
    return true;
}
