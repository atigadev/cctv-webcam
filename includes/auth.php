<?php
/**
 * auth.php - Sesi, login, CSRF, dan pembatasan percobaan login.
 */

require_once __DIR__ . '/storage.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('GIIASMON');
session_start();

/**
 * Akun login ada di includes/users.php (tidak ikut di-commit).
 * Salin dari includes/users.example.php untuk instalasi baru.
 */
$AUTH_USERS = is_file(__DIR__ . '/users.php') ? require __DIR__ . '/users.php' : [];

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function current_user(): string
{
    return (string)($_SESSION['user'] ?? '');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Untuk halaman HTML: redirect ke login kalau belum masuk. */
function require_login(): void
{
    if (!is_logged_in()) {
        $redirect = urlencode(basename($_SERVER['SCRIPT_NAME']));
        header("Location: login.php?redirect={$redirect}");
        exit;
    }
    csrf_token();
    // Lepas lock file sesi supaya request API paralel dari browser yang
    // sama tidak saling menunggu.
    session_write_close();
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Untuk endpoint API: 401 JSON kalau belum masuk. */
function require_auth_json(): void
{
    if (!is_logged_in()) {
        json_response(['status' => 'error', 'code' => 'unauthorized', 'message' => 'Sesi berakhir, silakan login ulang.'], 401);
    }
    // PENTING: tanpa ini semua request ping/traffic dari satu browser
    // diproses satu per satu karena PHP mengunci file sesi.
    session_write_close();
}

/** Wajib untuk semua request yang mengubah data. */
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_response(['status' => 'error', 'code' => 'csrf', 'message' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
    }
}

/* ---------- Pembatasan percobaan login (per IP) ---------- */

function login_attempts_path(): string
{
    return data_path('login_attempts.json');
}

/** Sisa detik terkunci untuk IP ini (0 = tidak terkunci). */
function login_locked_seconds(string $ip): int
{
    $s = app_settings();
    $rows = json_read(login_attempts_path(), []);
    $row = $rows[$ip] ?? null;
    if (!$row || $row['count'] < $s['login_max_attempts']) {
        return 0;
    }
    $remaining = ($row['first'] + $s['login_lock_minutes'] * 60) - time();
    return max(0, $remaining);
}

function login_register_attempt(string $ip, bool $success): void
{
    $window = app_settings()['login_lock_minutes'] * 60;
    json_update(login_attempts_path(), [], function ($rows) use ($ip, $success, $window) {
        $now = time();
        foreach ($rows as $k => $row) {
            if ($now - $row['first'] > $window) {
                unset($rows[$k]);
            }
        }
        if ($success) {
            unset($rows[$ip]);
        } else {
            $rows[$ip] = $rows[$ip] ?? ['count' => 0, 'first' => $now];
            $rows[$ip]['count']++;
        }
        return $rows;
    });
}

function login(string $username, string $password): bool
{
    global $AUTH_USERS;
    $hash = $AUTH_USERS[$username] ?? null;
    // Tetap jalankan password_verify walau user tidak ada, supaya waktu
    // respons tidak membocorkan username mana yang valid.
    $ok = password_verify($password, $hash ?? '$2y$10$abcdefghijklmnopqrstuuJ8eS1mF1v4cR7Gk6bQnJbqgFvC3d1zO');
    if ($ok && $hash !== null) {
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
    session_destroy();
}
