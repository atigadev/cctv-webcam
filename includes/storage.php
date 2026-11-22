<?php
/**
 * storage.php - Baca/tulis file JSON di folder data/ secara aman.
 *
 * Koordinasi memakai file "<nama>.lock" terpisah:
 *   - baca  : LOCK_SH  (boleh bersamaan)
 *   - tulis : LOCK_EX  (eksklusif, pembaca menunggu sampai selesai)
 * Dengan begitu pembaca tidak pernah melihat file setengah tertulis
 * (masalah lama: traffic_history.json bisa terbaca kosong lalu tertimpa).
 */

const DATA_DIR = __DIR__ . '/../data';

function app_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = require __DIR__ . '/settings.php';
    }
    return $settings;
}

function data_path(string $name): string
{
    return DATA_DIR . '/' . $name;
}

/** Jalankan $fn sambil memegang lock untuk $path. */
function with_lock(string $path, int $mode, callable $fn)
{
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('Tidak bisa membuat file lock untuk ' . basename($path));
    }
    try {
        flock($lock, $mode);
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function json_read_raw(string $path, $default)
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function json_write_raw(string $path, $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Gagal menulis ' . basename($path) . '. Cek permission folder data/.');
    }
}

function json_read(string $path, $default = [])
{
    return with_lock($path, LOCK_SH, function () use ($path, $default) {
        return json_read_raw($path, $default);
    });
}

/**
 * Read-modify-write atomik. $fn menerima data saat ini dan mengembalikan
 * data baru. Lempar exception dari $fn untuk membatalkan tanpa menyimpan.
 */
function json_update(string $path, $default, callable $fn)
{
    return with_lock($path, LOCK_EX, function () use ($path, $default, $fn) {
        $new = $fn(json_read_raw($path, $default));
        json_write_raw($path, $new);
        return $new;
    });
}

/* ---------- Data Hall ---------- */

function config_path(): string
{
    return data_path('config.json');
}

/** Ambil daftar Hall dari backend yang aktif (json / mysql). */
function load_halls(): array
{
    if (app_settings()['backend'] === 'mysql') {
        require_once __DIR__ . '/db.php';
        return db_fetch_halls(db());
    }
    $config = json_read(config_path(), ['halls' => []]);
    return is_array($config['halls'] ?? null) ? $config['halls'] : [];
}

/** Penanda versi data Hall - berubah tiap kali admin menyimpan. */
function halls_version(array $halls): string
{
    return substr(md5(json_encode($halls)), 0, 12);
}

/** Semua IP unik yang terdaftar di config. */
function configured_ips(array $halls): array
{
    $ips = [];
    foreach ($halls as $hall) {
        foreach (($hall['ips'] ?? []) as $entry) {
            $ip = trim((string)($entry['ip'] ?? ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[$ip] = true;
            }
        }
    }
    return array_keys($ips);
}

/* ---------- Validasi bersama (dipakai admin_api*.php) ---------- */

function is_valid_url($url): bool
{
    if (!is_string($url) || trim($url) === '') {
        return false;
    }
    $url = trim($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function clean_text($value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
    return mb_substr($value, 0, $max);
}

function sanitize_ips($ips): array
{
    if (!is_array($ips)) {
        return [];
    }
    $clean = [];
    foreach ($ips as $entry) {
        $ip = trim((string)($entry['ip'] ?? ''));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        $clean[] = ['label' => clean_text($entry['label'] ?? '', 40), 'ip' => $ip];
    }
    return $clean;
}
