<?php
/**
 * ping.php (library) - Ping banyak IP secara PARALEL.
 *
 * Tiap IP dijalankan sebagai proses `ping` terpisah sekaligus, jadi 20 IP
 * selesai dalam ~1 detik (bukan 20 detik). Output ditulis ke file temp
 * karena stream_select pada pipe tidak berfungsi di Windows.
 *
 * Status online ditentukan dari adanya "TTL=" di output - di Windows,
 * balasan "Destination host unreachable" dari gateway tetap memberi exit
 * code 0, jadi exit code saja tidak bisa dipercaya.
 */

function ping_many(array $ips, int $timeoutMs = 1000): array
{
    $results = array_fill_keys($ips, false);
    if (!$ips) {
        return $results;
    }

    if (!function_exists('proc_open')) {
        foreach ($ips as $ip) {
            $results[$ip] = tcp_probe($ip);
        }
        return $results;
    }

    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    $procs = [];

    foreach ($ips as $ip) {
        $cmd = $isWindows
            ? ['ping', '-n', '1', '-w', (string)$timeoutMs, $ip]
            : ['ping', '-c', '1', '-W', (string)max(1, (int)ceil($timeoutMs / 1000)), $ip];

        $outFile = tempnam(sys_get_temp_dir(), 'png');
        $proc = @proc_open($cmd, [
            0 => ['file', $isWindows ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $outFile, 'a'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (is_resource($proc)) {
            $procs[$ip] = [$proc, $outFile];
        } else {
            @unlink($outFile);
            $results[$ip] = tcp_probe($ip);
        }
    }

    // Tunggu semua proses selesai (batas aman: timeout + 2 detik)
    $deadline = microtime(true) + ($timeoutMs / 1000) + 2;
    while ($procs) {
        foreach ($procs as $ip => [$proc, $outFile]) {
            $status = proc_get_status($proc);
            if (!$status['running'] || microtime(true) > $deadline) {
                if ($status['running']) {
                    proc_terminate($proc);
                }
                proc_close($proc);
                $output = (string)@file_get_contents($outFile);
                @unlink($outFile);
                $results[$ip] = stripos($output, 'ttl=') !== false;
                unset($procs[$ip]);
            }
        }
        if ($procs) {
            usleep(50000);
        }
    }

    return $results;
}

/** Cadangan kalau proc_open dimatikan hosting: cek port TCP umum kamera. */
function tcp_probe(string $ip): bool
{
    foreach ([80, 554, 8080] as $port) {
        $conn = @fsockopen($ip, $port, $errno, $errstr, 0.7);
        if ($conn) {
            fclose($conn);
            return true;
        }
    }
    return false;
}
