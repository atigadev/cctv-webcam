<?php
/**
 * routeros_api.php
 * Klien sederhana untuk MikroTik RouterOS API (protokol biner, TCP port 8728/8729).
 * Referensi protokol: dokumentasi resmi MikroTik - Manual:API.
 *
 * Contoh pakai:
 *   $api = new RouterosAPI();
 *   $api->port = 8728; // 8729 kalau pakai API-SSL
 *   $api->connect('192.168.1.1', 'admin', 'password');
 *   $result = $api->comm('/interface/monitor-traffic', ['=interface=ether1', '=once']);
 *   $api->disconnect();
 */

class RouterosAPI
{
    public $debug = false;
    public $connected = false;
    public $port = 8728;
    public $timeout = 5;

    private $socket;

    public function connect($host, $user, $pass)
    {
        $this->socket = @fsockopen($host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new Exception("Tidak bisa konek ke {$host}:{$this->port} - {$errstr}");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $this->write('/login', ['=name=' . $user, '=password=' . $pass]);
        $response = $this->read();

        if (!empty($response) && $response[0]['type'] === '!trap') {
            $this->disconnect();
            $message = $response[0]['attrs']['message'] ?? 'Login ditolak oleh router.';
            throw new Exception('Login Mikrotik gagal: ' . $message);
        }

        $this->connected = true;
        return true;
    }

    public function disconnect()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    /**
     * Kirim satu perintah API dan kembalikan seluruh kalimat respons
     * (array of ['type' => ..., 'attrs' => [...]]) sampai ketemu "!done".
     */
    public function comm($command, $arguments = [])
    {
        $this->write($command, $arguments);
        return $this->read();
    }

    private function write($command, $arguments = [])
    {
        $this->writeWord($command);
        foreach ($arguments as $arg) {
            $this->writeWord($arg);
        }
        // Akhiri kalimat (sentence) dengan word panjang 0
        fwrite($this->socket, chr(0));
    }

    private function writeWord($word)
    {
        $this->writeLength(strlen($word));
        fwrite($this->socket, $word);
    }

    private function writeLength($length)
    {
        if ($length < 0x80) {
            fwrite($this->socket, chr($length));
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            fwrite($this->socket, chr(($length >> 24) & 0xFF));
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0));
            fwrite($this->socket, chr(($length >> 24) & 0xFF));
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        }
    }

    private function readLength()
    {
        $byte = ord(fread($this->socket, 1));

        if (($byte & 0x80) === 0) {
            return $byte;
        } elseif (($byte & 0xC0) === 0x80) {
            return (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xE0) === 0xC0) {
            return (($byte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xF0) === 0xE0) {
            return (($byte & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16)
                + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }

        $bytes = fread($this->socket, 4);
        $unpacked = unpack('N', $bytes);
        return $unpacked[1];
    }

    private function readWord()
    {
        $length = $this->readLength();
        if ($length === 0) {
            return null;
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    // Baca semua kalimat (sentence) sampai kalimat "!done" ditemukan.
    private function read()
    {
        $sentences = [];

        while (true) {
            $words = [];
            while (true) {
                $word = $this->readWord();
                if ($word === null) {
                    break; // akhir kalimat (zero-length word)
                }
                $words[] = $word;
            }

            if (empty($words)) {
                break;
            }

            $type = $words[0];
            $attrs = [];
            foreach (array_slice($words, 1) as $w) {
                if (strpos($w, '=') === 0) {
                    $parts = explode('=', substr($w, 1), 2);
                    if (count($parts) === 2) {
                        $attrs[$parts[0]] = $parts[1];
                    }
                }
            }

            $sentences[] = ['type' => $type, 'attrs' => $attrs];

            if ($type === '!done') {
                break;
            }
        }

        return $sentences;
    }
}
