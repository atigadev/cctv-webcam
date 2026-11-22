-- Skema database untuk GIIAS Monitor (opsional, alternatif dari config.json)
-- Import lewat phpMyAdmin atau: mysql -u USER -p NAMA_DB < schema.sql

CREATE TABLE IF NOT EXISTS halls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    iframe_url TEXT NOT NULL,
    petugas VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hall_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hall_id INT NOT NULL,
    label VARCHAR(100) DEFAULT '',
    ip VARCHAR(100) NOT NULL,
    FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contoh data awal (opsional, boleh dihapus)
INSERT INTO halls (name, iframe_url, petugas) VALUES
    ('Hall A', 'https://contoh-streaming.com/hall-a', 'Andi Wijaya'),
    ('Hall B', 'https://contoh-streaming.com/hall-b', 'Sri Rahayu');

INSERT INTO hall_ips (hall_id, label, ip) VALUES
    (1, 'Encoder', '192.168.1.10'),
    (1, 'Kamera', '192.168.1.11'),
    (2, 'Encoder', '192.168.1.20'),
    (2, 'Kamera', '192.168.1.21');