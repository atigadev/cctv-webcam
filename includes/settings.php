<?php
/**
 * settings.php - Pengaturan aplikasi di satu tempat.
 * Folder includes/ diblokir dari akses langsung lewat .htaccess.
 */

return [
    // 'json'  = data Hall disimpan di data/config.json (default)
    // 'mysql' = data Hall dari MySQL (lihat includes/db.php & schema.sql)
    'backend' => 'json',

    // Interval cek perangkat (detik). Hasil ping di-cache di server selama
    // ini, jadi berapapun jumlah layar yang membuka monitor, tiap IP hanya
    // diping sekali per interval.
    'ping_interval' => 5,

    // Perangkat baru dianggap OFFLINE setelah gagal ping N kali berturut-turut
    // (mencegah alarm palsu karena 1 paket hilang).
    'offline_after_fails' => 2,

    // Batas percobaan login gagal per IP.
    'login_max_attempts' => 5,
    'login_lock_minutes' => 15,
];
