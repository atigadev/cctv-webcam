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
 * Akun login disimpan di data/users.json (diblokir dari web, tidak di-commit).
 * Kalau file belum ada, dibuat otomatis dengan akun default di bawah,
 * dan pengguna WAJIB mengganti password setelah login pertama.
 */
const DEFAULT_USERNAME = 'admin';
const DEFAULT_PASSWORD = 'admin12345';
const PASSWORD_MIN_LENGTH = 8;

function users_path(): string
{
    return data_path('users.json');
}

function users_load(): array
{
    if (!is_file(users_path())) {
        json_update(users_path(), [], function ($users) {
            if (!$users) {
                $users[DEFAULT_USERNAME] = [
                    'hash'        => password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT),
                    'must_change' => true,
                ];
            }
            return $users;
        });
    }
    return json_read(users_path(), []);
}

function user_set_password(string $username, string $newPassword): void
{
    json_update(users_path(), [], function ($users) use ($username, $newPassword) {
        $users[$username] = [
            'hash'        => password_hash($newPassword, PASSWORD_DEFAULT),
            'must_change' => false,
            'changed_at'  => date('c'),
        ];
        return $users;
    });
}

/** Kembalikan pesan error, atau '' kalau password baru boleh dipakai. */
function validate_new_password(string $username, string $current, string $new, string $confirm): string
{
    $users = users_load();
    $hash = $users[$username]['hash'] ?? '';
    if (!password_verify($current, $hash)) {
        return 'Password saat ini salah.';
    }
    if (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
        return 'Password baru minimal ' . PASSWORD_MIN_LENGTH . ' karakter.';
    }
    if ($new === DEFAULT_PASSWORD || password_verify($new, $hash)) {
        return 'Password baru harus berbeda dari password lama/default.';
    }
    if (strcasecmp($new, $username) === 0) {
        return 'Password tidak boleh sama dengan username.';
    }
    if ($new !== $confirm) {
        return 'Konfirmasi password tidak cocok.';
    }
    return '';
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function must_change_password(): bool
{
    return !empty($_SESSION['must_change']);
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
    // Password default belum diganti -> paksa ke halaman ganti password
    if (must_change_password() && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        header('Location: change_password.php');
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
    if (must_change_password()) {
        json_response(['status' => 'error', 'code' => 'password_change_required', 'message' => 'Ganti password default terlebih dahulu.'], 403);
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
    $user = users_load()[$username] ?? null;
    $hash = $user['hash'] ?? null;
    // Tetap jalankan password_verify walau user tidak ada, supaya waktu
    // respons tidak membocorkan username mana yang valid.
    $ok = password_verify($password, $hash ?? '$2y$10$abcdefghijklmnopqrstuuJ8eS1mF1v4cR7Gk6bQnJbqgFvC3d1zO');
    if ($ok && $hash !== null) {
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['must_change'] = !empty($user['must_change']);
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
