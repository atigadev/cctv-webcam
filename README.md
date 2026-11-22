# GIIAS Live Monitoring

Dashboard monitoring CCTV/stream per Hall: tampilan mosaic semua kamera, status online/offline perangkat (ping), alarm saat perangkat mati, dan grafik traffic jaringan dari Mikrotik.

Dibuat dengan PHP 7.4+ tanpa framework, berjalan di Apache (XAMPP) dengan `.htaccess` aktif (`AllowOverride All`).

## Instalasi

1. Salin folder ini ke `htdocs/`.
2. Buat file konfigurasi lokal dari contohnya (file aslinya tidak ikut di-commit):
   - `includes/users.example.php` → `includes/users.php`, lalu isi hash password:
     `php -r "echo password_hash('PASSWORD_BARU', PASSWORD_DEFAULT);"`
   - `includes/mikrotik_config.example.php` → `includes/mikrotik_config.php`
   - `data/config.example.json` → `data/config.json` (opsional; Hall juga bisa ditambah lewat halaman **Kelola Hall**)
3. Pastikan folder `data/` bisa ditulis oleh web server.
4. Buka `http://localhost/<folder>/`.

Pengaturan interval ping, ambang offline, dan batas percobaan login ada di `includes/settings.php`.

## Struktur

| Path | Isi |
| --- | --- |
| `monitor3.php`, `admin.php`, `traffic.php`, `login.php` | Halaman |
| `status_api.php`, `config.php`, `admin_api.php`, `traffic_api.php` | API JSON (wajib login, POST wajib CSRF token) |
| `includes/` | Library & konfigurasi server (diblokir dari akses web) |
| `assets/` | CSS & JS |
| `data/` | Data runtime JSON (diblokir dari akses web, tidak di-commit) |
