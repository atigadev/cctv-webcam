# GIIAS Live Monitoring

Dashboard monitoring CCTV/stream per Hall: tampilan mosaic semua kamera, status online/offline perangkat (ping), alarm saat perangkat mati, dan grafik traffic jaringan dari Mikrotik.

Dibuat dengan PHP 7.4+ tanpa framework, berjalan di Apache (XAMPP) dengan `.htaccess` aktif (`AllowOverride All`).

## Instalasi

1. Salin folder ini ke `htdocs/`.
2. Buat file konfigurasi lokal dari contohnya (file aslinya tidak ikut di-commit):
   - `includes/mikrotik_config.example.php` → `includes/mikrotik_config.php`
   - `data/config.example.json` → `data/config.json` (opsional; Hall juga bisa ditambah lewat halaman **Kelola Hall**)
3. Pastikan folder `data/` bisa ditulis oleh web server.
4. Buka `http://localhost/<folder>/` dan masuk dengan akun default:

   | Username | Password |
   | --- | --- |
   | `admin` | `admin12345` |

   Setelah berhasil masuk, Anda **wajib membuat password baru** sebelum bisa membuka halaman lain. Password disimpan (ter-hash) di `data/users.json`.

   **Lupa password?** Hapus `data/users.json`; akun kembali ke default di atas dan wajib diganti lagi saat login.

Pengaturan interval ping, ambang offline, dan batas percobaan login ada di `includes/settings.php`.

## Hitung orang (AI)

Worker Python di `people_counter/` mengambil snapshot kamera dari **Agent DVR** (`/grab.jpg?oid=N`) setiap ±5 detik, lalu menghitung orang dengan model YOLO11s (ONNX, jalan di CPU, input 1920px agar orang yang jauh tetap terdeteksi). Hasilnya tampil di halaman **Kepadatan** dan sebagai badge di layar Monitoring.

1. Instal (sekali): `powershell -ExecutionPolicy Bypass -File people_counter\setup.ps1`
   Tambahkan `-Autostart` agar worker berjalan otomatis saat login Windows (Task Scheduler).
2. Di **Kelola Hall** → Edit Hall → centang **Hitung orang (AI)**. Kosongkan ID kamera untuk menghitung semua kamera di DVR, atau klik **Ambil daftar** lalu pilih kamera.
3. Jalankan / hentikan manual: `people_counter\start.bat` / `people_counter\stop.bat`. Log: `people_counter\counter.log`.

Catatan:
- Angka = jumlah orang **yang terlihat saat itu**, bukan pengunjung unik. Pada kerumunan rapat hasilnya perkiraan (orang yang tertutup bisa tidak terhitung).
- Tiap kamera butuh ±2–3 detik CPU. Untuk banyak kamera, naikkan `interval` di `people_counter\settings.json` (salin dari `settings.example.json`).
- Komputer yang menjalankan worker harus bisa menjangkau DVR tiap Hall.
- Model `models/yolo11s_1920.onnx` diekspor dari Ultralytics YOLO11 (lisensi AGPL-3.0). Ekspor ulang: `pip install ultralytics` lalu `yolo export model=yolo11s.pt format=onnx imgsz=1920`.

## Struktur

| Path | Isi |
| --- | --- |
| `monitor3.php`, `people.php`, `admin.php`, `traffic.php`, `login.php` | Halaman |
| `status_api.php`, `config.php`, `admin_api.php`, `traffic_api.php`, `people_api.php` | API JSON (wajib login, POST wajib CSRF token) |
| `people_counter/` | Worker Python hitung orang (diblokir dari akses web) |
| `includes/` | Library & konfigurasi server (diblokir dari akses web) |
| `assets/` | CSS & JS |
| `data/` | Data runtime JSON (diblokir dari akses web, tidak di-commit) |
