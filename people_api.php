<?php
/**
 * people_api.php - Data hitung orang dari worker people_counter/counter.py.
 *
 *   GET ?action=status                 -> { status, now, worker, total, halls }
 *   GET ?action=history&hours=1|6|24   -> { status, halls: { id: [[ts, avg, max], ...] } }
 *   GET ?action=snapshot&key=...       -> image/jpeg (snapshot dengan kotak deteksi)
 *   GET ?action=cameras&url=...        -> { status, cameras: [{oid, name, online}] }  (untuk form admin)
 */

require_once __DIR__ . '/includes/auth.php';
require_auth_json();

$action = $_GET['action'] ?? 'status';

/** Baca JSON yang ditulis worker (diganti atomik, jadi cukup coba ulang sekali). */
function read_worker_json(string $name, array $default): array
{
    $path = data_path($name);
    for ($i = 0; $i < 3; $i++) {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (is_array($data)) {
            return $data;
        }
        usleep(50000);
    }
    return $default;
}

/** Hall yang mengaktifkan hitung orang, sesuai urutan di config. */
function people_halls(): array
{
    $out = [];
    foreach (load_halls() as $hall) {
        if (!empty($hall['peopleCount']['enabled'])) {
            $out[] = ['id' => (int)$hall['id'], 'name' => (string)$hall['name']];
        }
    }
    return $out;
}

if ($action === 'status') {
    $state = read_worker_json('people_count.json', []);
    $now = time();
    $interval = (int)($state['worker']['interval'] ?? 5);
    $updated = (int)($state['updatedAt'] ?? 0);
    // satu putaran bisa lama kalau banyak kamera; beri kelonggaran
    $alive = $updated > 0 && ($now - $updated) <= max(60, $interval * 6);

    $halls = [];
    foreach (people_halls() as $h) {
        $row = $state['halls'][(string)$h['id']] ?? null;
        $halls[] = [
            'id'    => $h['id'],
            'name'  => $h['name'],
            'count' => $alive && $row ? $row['count'] : null,
            'at'    => $row['at'] ?? null,
            'error' => $row ? (string)($row['error'] ?? '') : ($alive ? 'Menunggu putaran berikutnya' : ''),
            'cams'  => array_map(function ($c) use ($alive) {
                return [
                    'oid'   => (int)($c['oid'] ?? 0),
                    'name'  => (string)($c['name'] ?? ''),
                    'key'   => (string)($c['key'] ?? ''),
                    'count' => $alive ? ($c['count'] ?? null) : null,
                    'at'    => $c['at'] ?? null,
                    'error' => (string)($c['error'] ?? ''),
                ];
            }, $row['cams'] ?? []),
        ];
    }
    $total = null;
    foreach ($halls as $h) {
        if ($h['count'] !== null) {
            $total = ($total ?? 0) + (int)$h['count'];
        }
    }

    json_response([
        'status' => 'success',
        'now'    => $now,
        'worker' => [
            'alive'     => $alive,
            'updatedAt' => $updated ?: null,
            'model'     => $state['worker']['model'] ?? null,
            'interval'  => $interval,
        ],
        'total'  => $total,
        'halls'  => $halls,
    ]);
}

if ($action === 'history') {
    $hours = (int)($_GET['hours'] ?? 1);
    $hours = in_array($hours, [1, 6, 24], true) ? $hours : 1;
    $cutoff = time() - $hours * 3600;
    $hist = read_worker_json('people_history.json', ['halls' => []]);
    $out = [];
    foreach (people_halls() as $h) {
        $rows = $hist['halls'][(string)$h['id']] ?? [];
        $out[(string)$h['id']] = array_values(array_filter($rows, function ($r) use ($cutoff) {
            return is_array($r) && ($r[0] ?? 0) >= $cutoff;
        }));
    }
    json_response(['status' => 'success', 'hours' => $hours, 'halls' => $out]);
}

if ($action === 'snapshot') {
    $key = (string)($_GET['key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+_\d+$/', $key)) {
        json_response(['status' => 'error', 'message' => 'Key tidak valid.'], 400);
    }
    $path = data_path('people/' . $key . '.jpg');
    $data = is_file($path) ? @file_get_contents($path) : false;
    if ($data === false || $data === '') {
        json_response(['status' => 'error', 'message' => 'Snapshot belum tersedia.'], 404);
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

if ($action === 'cameras') {
    $url = trim((string)($_GET['url'] ?? ''));
    if (!is_valid_url($url)) {
        json_response(['status' => 'error', 'message' => 'URL stream tidak valid.'], 422);
    }
    $p = parse_url($url);
    $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false]]);
    $raw = @file_get_contents($base . '/command/getObjects', false, $ctx);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['objectList'])) {
        json_response([
            'status'  => 'error',
            'message' => 'Tidak bisa membaca daftar kamera dari ' . $base . '. Pastikan alamat ini Agent DVR dan bisa dijangkau dari server.',
        ], 502);
    }
    $cams = [];
    foreach ($data['objectList'] as $o) {
        if ((int)($o['typeID'] ?? 0) === 2) {
            $cams[] = [
                'oid'    => (int)$o['id'],
                'name'   => clean_text($o['name'] ?? ('Kamera ' . $o['id']), 60),
                'online' => (bool)($o['data']['online'] ?? true),
            ];
        }
    }
    json_response(['status' => 'success', 'base' => $base, 'cameras' => $cams]);
}

json_response(['status' => 'error', 'message' => 'Aksi tidak dikenali.'], 400);
