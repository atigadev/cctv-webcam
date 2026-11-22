<?php
/**
 * db.php - Koneksi MySQL (hanya dipakai kalau settings.php backend = 'mysql').
 * Sesuaikan kredensial di bawah. Jangan pakai user root tanpa password
 * di server produksi.
 */

const DB_HOST = 'localhost';
const DB_NAME = 'giias_monitor';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            // Detail error hanya ke log server, bukan ke browser.
            error_log('GIIAS Monitor DB error: ' . $e->getMessage());
            throw new RuntimeException('Koneksi database gagal.');
        }
    }
    return $pdo;
}

/** Ambil semua Hall + IP dalam 2 query (bukan 1 query per Hall). */
function db_fetch_halls(PDO $pdo): array
{
    $halls = [];
    foreach ($pdo->query('SELECT id, name, iframe_url, petugas FROM halls ORDER BY id') as $row) {
        $halls[(int)$row['id']] = [
            'id'        => (int)$row['id'],
            'name'      => $row['name'],
            'iframeUrl' => $row['iframe_url'],
            'petugas'   => (string)$row['petugas'],
            'ips'       => [],
        ];
    }
    foreach ($pdo->query('SELECT hall_id, label, ip FROM hall_ips ORDER BY id') as $row) {
        $id = (int)$row['hall_id'];
        if (isset($halls[$id])) {
            $halls[$id]['ips'][] = ['label' => (string)$row['label'], 'ip' => $row['ip']];
        }
    }
    return array_values($halls);
}
