<?php
/**
 * config.php - Daftar Hall untuk halaman monitor (JSON atau MySQL,
 * sesuai includes/settings.php).
 */

require_once __DIR__ . '/includes/auth.php';
require_auth_json();

try {
    $halls = load_halls();
    json_response(['status' => 'success', 'version' => halls_version($halls), 'halls' => $halls]);
} catch (Throwable $e) {
    error_log('config.php: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Gagal memuat data Hall.'], 500);
}
