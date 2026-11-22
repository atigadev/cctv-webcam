<?php
/**
 * mikrotik_config.php
 * Sesuaikan dengan Mikrotik Anda.
 *
 * 'interfaces' diisi nama interface yang mewakili TOTAL traffic jaringan
 * (biasanya interface uplink/WAN, atau bridge yang menaungi semua Hall).
 * Boleh diisi lebih dari satu nama interface - traffic-nya akan DIJUMLAHKAN
 * jadi satu grafik gabungan.
 *
 * Cek nama interface lewat: /interface print   (di terminal Mikrotik)
 */

return [
    'host'       => '192.168.88.1',
    'port'       => 8728,     // 8729 kalau API-SSL diaktifkan
    'user'       => 'monitoring',
    'pass'       => 'GANTI_PASSWORD',
    'interfaces' => ['bridge-LAN'], // contoh: ['ether1'] atau ['ether1', 'ether2']
];
