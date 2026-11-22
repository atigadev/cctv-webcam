<?php
/**
 * traffic_api.php - Total traffic (rx/tx bps) dari Mikrotik + riwayat rolling.
 *
 * Perbaikan dibanding versi lama:
 *  - Mikrotik hanya ditanya maksimal sekali per SAMPLE_INTERVAL detik,
 *    berapapun jumlah orang yang membuka halaman traffic (dulu tiap viewer
 *    menambah titik data sendiri -> data dobel & interval kacau).
 *  - Baca & tulis riwayat di bawah lock, jadi riwayat tidak bisa lagi
 *    terbaca kosong lalu tertimpa.
 *  - Kalau Mikrotik gagal, percobaan berikutnya ditahan sebentar supaya
 *    router tidak dibanjiri koneksi.
 *
 * Response: { status, now: {t,rx,tx}|null, history: [...], message? }
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/routeros_api.php';
require_auth_json();

const SAMPLE_INTERVAL = 5;    // detik
const MAX_HISTORY     = 720;  // 720 x 5 detik = 1 jam

$config = require __DIR__ . '/includes/mikrotik_config.php';
$historyPath = data_path('traffic_history.json');

try {
    $result = with_lock($historyPath, LOCK_EX, function () use ($historyPath, $config) {
        $data = json_read_raw($historyPath, []);
        // Format lama = array titik; format baru = {history, lastAttempt, lastError}
        $store = isset($data['history']) ? $data : ['history' => $data, 'lastAttempt' => 0, 'lastError' => null];

        $now = time();
        if ($now - (int)$store['lastAttempt'] < SAMPLE_INTERVAL) {
            return $store; // masih dalam interval, pakai data terakhir
        }
        $store['lastAttempt'] = $now;

        try {
            $api = new RouterosAPI();
            $api->port = $config['port'] ?? 8728;
            $api->timeout = 4;
            $api->connect($config['host'], $config['user'], $config['pass']);

            $rx = 0;
            $tx = 0;
            foreach ($config['interfaces'] as $iface) {
                foreach ($api->comm('/interface/monitor-traffic', ['=interface=' . $iface, '=once']) as $s) {
                    if ($s['type'] === '!re') {
                        $rx += (int)($s['attrs']['rx-bits-per-second'] ?? 0);
                        $tx += (int)($s['attrs']['tx-bits-per-second'] ?? 0);
                    }
                }
            }
            $api->disconnect();

            $store['history'][] = ['t' => $now * 1000, 'rx' => $rx, 'tx' => $tx];
            $store['history'] = array_slice($store['history'], -MAX_HISTORY);
            $store['lastError'] = null;
        } catch (Exception $e) {
            $store['lastError'] = $e->getMessage();
        }

        json_write_raw($historyPath, $store);
        return $store;
    });

    $history = $result['history'];
    json_response([
        'status'   => $result['lastError'] ? 'error' : 'success',
        'message'  => $result['lastError'],
        'interval' => SAMPLE_INTERVAL,
        'now'      => $history ? end($history) : null,
        'history'  => $history,
    ]);
} catch (Throwable $e) {
    error_log('traffic_api: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Gagal membaca data traffic.', 'history' => []], 500);
}
