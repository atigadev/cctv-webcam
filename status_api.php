<?php
/**
 * status_api.php - Status online/offline SEMUA perangkat dalam satu request.
 *
 * - Hanya IP yang terdaftar di config yang diping (tidak bisa dipakai
 *   untuk memindai jaringan).
 * - Hasil di-cache di server selama `ping_interval` detik, jadi berapapun
 *   layar yang membuka monitor, tiap IP hanya diping sekali per interval.
 * - Debounce: perangkat baru OFFLINE setelah gagal `offline_after_fails`
 *   kali berturut-turut. Sebelum itu statusnya "unstable".
 *
 * Response:
 * {
 *   status: "success",
 *   checkedAt: <ms>,
 *   configVersion: "<hash>",   // berubah saat data Hall diubah di admin
 *   devices: { "<ip>": { state: online|unstable|offline, since: <ms>, lastSeen: <ms|null> } }
 * }
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ping.php';
require_auth_json();

$settings = app_settings();
$statePath = data_path('ping_state.json');

try {
    $halls = load_halls();
    $ips = configured_ips($halls);

    $state = with_lock($statePath, LOCK_EX, function () use ($statePath, $ips, $settings) {
        $state = json_read_raw($statePath, ['checkedAt' => 0, 'devices' => []]);
        $nowMs = (int)round(microtime(true) * 1000);
        $known = array_keys($state['devices']);
        $fresh = ($nowMs - $state['checkedAt']) < ($settings['ping_interval'] * 1000 - 500);

        // Pakai cache kalau masih baru dan daftar IP tidak berubah
        if ($fresh && !array_diff($ips, $known)) {
            return $state;
        }

        $results = ping_many($ips);
        $nowMs = (int)round(microtime(true) * 1000);
        $devices = [];

        foreach ($ips as $ip) {
            $prev = $state['devices'][$ip] ?? ['state' => 'unknown', 'fails' => 0, 'since' => $nowMs, 'lastSeen' => null];
            $d = $prev;

            if ($results[$ip]) {
                $d['fails'] = 0;
                $d['lastSeen'] = $nowMs;
                $new = 'online';
            } else {
                $d['fails'] = ($prev['fails'] ?? 0) + 1;
                $new = $d['fails'] >= $settings['offline_after_fails'] ? 'offline' : 'unstable';
                // Perangkat yang belum pernah online sejak dipantau langsung offline
                if ($prev['state'] === 'unknown' || $prev['state'] === 'offline') {
                    $new = 'offline';
                }
            }

            // "unstable" tidak mengubah waktu "sejak" (masih dianggap online)
            $wasUp = in_array($prev['state'], ['online', 'unstable'], true);
            $isUp = in_array($new, ['online', 'unstable'], true);
            if ($prev['state'] === 'unknown' || $wasUp !== $isUp) {
                $d['since'] = $nowMs;
            }

            $d['state'] = $new;
            $devices[$ip] = $d;
        }

        $state = ['checkedAt' => $nowMs, 'devices' => $devices];
        json_write_raw($statePath, $state);
        return $state;
    });

    $devices = [];
    foreach ($state['devices'] as $ip => $d) {
        $devices[$ip] = ['state' => $d['state'], 'since' => $d['since'], 'lastSeen' => $d['lastSeen']];
    }

    json_response([
        'status'        => 'success',
        'checkedAt'     => $state['checkedAt'],
        'configVersion' => halls_version($halls),
        'devices'       => (object)$devices,
    ]);
} catch (Throwable $e) {
    error_log('status_api: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Gagal mengecek status perangkat.'], 500);
}
