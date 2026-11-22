<?php
/**
 * admin_api.php - CRUD data Hall di data/config.json.
 *
 *   GET  ?action=list                        -> { status, halls }
 *   POST ?action=add    (JSON)               -> { status, hall }
 *   POST ?action=update (JSON, parsial)      -> { status, hall }
 *   POST ?action=delete (JSON { id })        -> { status }
 *
 * Semua POST wajib header X-CSRF-Token. Read-modify-write berjalan di
 * bawah satu lock, jadi dua admin yang menyimpan bersamaan tidak saling
 * menimpa perubahan.
 */

require_once __DIR__ . '/includes/auth.php';
require_auth_json();

if (app_settings()['backend'] === 'mysql') {
    require __DIR__ . '/admin_api_mysql.php';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    json_response(['status' => 'success', 'halls' => load_halls()]);
}

if ($method !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Aksi tidak dikenali.'], 400);
}

require_csrf();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['status' => 'error', 'message' => 'Body request harus JSON.'], 400);
}

class ApiError extends Exception {}

/** Validasi & normalisasi field Hall. $partial = hanya field yang dikirim. */
function hall_fields(array $input, array $base): array
{
    $hall = $base;
    if (array_key_exists('name', $input)) {
        $hall['name'] = clean_text($input['name'], 80);
    }
    if (array_key_exists('iframeUrl', $input)) {
        $hall['iframeUrl'] = trim((string)$input['iframeUrl']);
    }
    if (array_key_exists('petugas', $input)) {
        $hall['petugas'] = clean_text($input['petugas'], 80);
    }
    if (array_key_exists('ips', $input)) {
        $hall['ips'] = sanitize_ips($input['ips']);
    }

    if (($hall['name'] ?? '') === '') {
        throw new ApiError('Nama Hall wajib diisi.');
    }
    if (!is_valid_url($hall['iframeUrl'] ?? '')) {
        throw new ApiError('URL streaming tidak valid. Gunakan http:// atau https://.');
    }
    return $hall;
}

try {
    $response = ['status' => 'success'];

    json_update(config_path(), ['halls' => []], function ($config) use ($action, $input, &$response) {
        $halls = is_array($config['halls'] ?? null) ? $config['halls'] : [];
        $id = (int)($input['id'] ?? 0);

        if ($action === 'add') {
            $newId = 1;
            foreach ($halls as $h) {
                $newId = max($newId, (int)$h['id'] + 1);
            }
            $hall = hall_fields($input, ['id' => $newId, 'name' => '', 'iframeUrl' => '', 'ips' => [], 'petugas' => '']);
            $halls[] = $hall;
            $response['hall'] = $hall;
        } elseif ($action === 'update') {
            $index = array_search($id, array_map('intval', array_column($halls, 'id')), true);
            if ($index === false) {
                throw new ApiError('Hall tidak ditemukan.');
            }
            $halls[$index] = hall_fields($input, $halls[$index] + ['ips' => [], 'petugas' => '']);
            $response['hall'] = $halls[$index];
        } elseif ($action === 'delete') {
            $before = count($halls);
            $halls = array_values(array_filter($halls, function ($h) use ($id) {
                return (int)$h['id'] !== $id;
            }));
            if (count($halls) === $before) {
                throw new ApiError('Hall tidak ditemukan.');
            }
        } elseif ($action === 'reorder') {
            // input: { ids: [3,1,2] }
            $order = array_map('intval', (array)($input['ids'] ?? []));
            usort($halls, function ($a, $b) use ($order) {
                $ia = array_search((int)$a['id'], $order, true);
                $ib = array_search((int)$b['id'], $order, true);
                return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
            });
        } else {
            throw new ApiError('Aksi tidak dikenali.');
        }

        $config['halls'] = $halls;
        return $config;
    });

    json_response($response);
} catch (ApiError $e) {
    json_response(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('admin_api: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Gagal menyimpan data. Cek permission folder data/.'], 500);
}
