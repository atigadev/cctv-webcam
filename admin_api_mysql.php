<?php
/**
 * admin_api_mysql.php - Versi MySQL dari admin_api.php (kontrak identik).
 * Tidak perlu dipanggil langsung: admin_api.php otomatis meneruskan ke
 * file ini kalau includes/settings.php backend = 'mysql'.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_auth_json();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $pdo = db();

    if ($method === 'GET' && $action === 'list') {
        json_response(['status' => 'success', 'halls' => db_fetch_halls($pdo)]);
    }
    if ($method !== 'POST') {
        json_response(['status' => 'error', 'message' => 'Aksi tidak dikenali.'], 400);
    }

    require_csrf();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_response(['status' => 'error', 'message' => 'Body request harus JSON.'], 400);
    }
    $id = (int)($input['id'] ?? 0);

    $replaceIps = function (int $hallId, array $ips) use ($pdo) {
        $pdo->prepare('DELETE FROM hall_ips WHERE hall_id = ?')->execute([$hallId]);
        $ins = $pdo->prepare('INSERT INTO hall_ips (hall_id, label, ip) VALUES (?, ?, ?)');
        foreach ($ips as $e) {
            $ins->execute([$hallId, $e['label'], $e['ip']]);
        }
    };

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM halls WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            json_response(['status' => 'error', 'message' => 'Hall tidak ditemukan.'], 422);
        }
        json_response(['status' => 'success']);
    }

    if ($action !== 'add' && $action !== 'update') {
        json_response(['status' => 'error', 'message' => 'Aksi tidak dikenali.'], 400);
    }

    $base = ['name' => '', 'iframeUrl' => '', 'petugas' => '', 'ips' => []];
    if ($action === 'update') {
        $found = array_values(array_filter(db_fetch_halls($pdo), function ($h) use ($id) {
            return $h['id'] === $id;
        }));
        if (!$found) {
            json_response(['status' => 'error', 'message' => 'Hall tidak ditemukan.'], 422);
        }
        $base = $found[0];
    }

    $hall = $base;
    if (array_key_exists('name', $input)) $hall['name'] = clean_text($input['name'], 80);
    if (array_key_exists('iframeUrl', $input)) $hall['iframeUrl'] = trim((string)$input['iframeUrl']);
    if (array_key_exists('petugas', $input)) $hall['petugas'] = clean_text($input['petugas'], 80);
    if (array_key_exists('ips', $input)) $hall['ips'] = sanitize_ips($input['ips']);

    if ($hall['name'] === '') {
        json_response(['status' => 'error', 'message' => 'Nama Hall wajib diisi.'], 422);
    }
    if (!is_valid_url($hall['iframeUrl'])) {
        json_response(['status' => 'error', 'message' => 'URL streaming tidak valid. Gunakan http:// atau https://.'], 422);
    }

    $pdo->beginTransaction();
    if ($action === 'add') {
        $pdo->prepare('INSERT INTO halls (name, iframe_url, petugas) VALUES (?, ?, ?)')
            ->execute([$hall['name'], $hall['iframeUrl'], $hall['petugas']]);
        $hall['id'] = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE halls SET name = ?, iframe_url = ?, petugas = ? WHERE id = ?')
            ->execute([$hall['name'], $hall['iframeUrl'], $hall['petugas'], $id]);
    }
    $replaceIps($hall['id'], $hall['ips']);
    $pdo->commit();

    json_response(['status' => 'success', 'hall' => $hall]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('admin_api_mysql: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Gagal memproses data di database.'], 500);
}
