<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Hanya izinkan redirect ke halaman lokal (cegah open redirect)
$redirect = basename((string)($_GET['redirect'] ?? 'monitor3.php'));
if (!preg_match('/^[a-z0-9_]+\.php$/i', $redirect) || $redirect === 'login.php') {
    $redirect = 'monitor3.php';
}

if (is_logged_in()) {
    header('Location: ' . $redirect);
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$error = '';
$notice = isset($_GET['expired']) ? 'Sesi Anda berakhir. Silakan masuk kembali.' : '';
$username = '';
$locked = login_locked_seconds($clientIp);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token = (string)($_POST['csrf'] ?? '');

    if ($locked > 0) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($locked / 60) . ' menit.';
    } elseif (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        $error = 'Sesi form kedaluwarsa. Silakan coba lagi.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username dan password harus diisi.';
    } elseif (login($username, $password)) {
        login_register_attempt($clientIp, true);
        header('Location: ' . $redirect);
        exit;
    } else {
        login_register_attempt($clientIp, false);
        $locked = login_locked_seconds($clientIp);
        $error = $locked > 0
            ? 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($locked / 60) . ' menit.'
            : 'Username atau password salah.';
    }
}

csrf_token();
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Masuk'); ?>
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
            background:
                radial-gradient(1200px 600px at 50% -10%, rgba(230, 0, 0, 0.18), transparent 60%),
                var(--bg);
        }

        .login {
            width: min(400px, 100%);
            padding: 32px 28px 28px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        .login .brand {
            margin-bottom: 24px;
        }

        .login .brand-text {
            display: inline;
        }

        .login h1 {
            margin: 0 0 4px;
            font-size: 1.35rem;
        }

        .login p {
            margin: 0 0 22px;
            color: var(--text-2);
            font-size: 0.88rem;
        }

        .login form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .login .btn {
            height: 44px;
            margin-top: 6px;
        }

        .pw-wrap {
            position: relative;
        }

        .pw-wrap .input {
            padding-right: 44px;
        }

        .pw-toggle {
            position: absolute;
            right: 4px;
            top: 4px;
            height: 32px;
            padding: 0 8px;
            border: 0;
            background: transparent;
            color: var(--text-3);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border-radius: 4px;
        }

        .pw-toggle:hover {
            color: var(--text);
        }

        .alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 12px;
            border-radius: var(--radius);
            font-size: 0.85rem;
        }

        .alert.is-bad {
            background: var(--bad-soft);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #ffb4b4;
        }

        .alert.is-info {
            background: var(--warn-soft);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #fcd38a;
        }
    </style>
</head>

<body>
    <?php icon_sprite(); ?>
    <main class="login">
        <div class="brand">
            <span class="brand-mark"><span class="live-dot"></span></span>
            <span class="brand-text">GIIAS <strong>Live Monitoring</strong></span>
        </div>
        <h1>Masuk</h1>
        <p>Gunakan akun Anda untuk membuka layar monitoring.</p>

        <form method="post" action="login.php?redirect=<?= e(urlencode($redirect)) ?>" id="loginForm">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <?php if ($error): ?>
                <div class="alert is-bad" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div>
            <?php elseif ($notice): ?>
                <div class="alert is-info" role="status"><?= icon('alert') ?><span><?= e($notice) ?></span></div>
            <?php endif; ?>

            <div class="field">
                <label for="username">Username</label>
                <input class="input" id="username" name="username" type="text" autocomplete="username"
                    value="<?= e($username) ?>" required <?= $username === '' ? 'autofocus' : '' ?>>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input class="input" id="password" name="password" type="password" autocomplete="current-password"
                        required <?= $username !== '' ? 'autofocus' : '' ?>>
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Tampilkan password">Lihat</button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="loginBtn" <?= $locked > 0 ? 'disabled' : '' ?>>Masuk</button>
        </form>
    </main>

    <script>
        const pw = document.getElementById('password');
        const toggle = document.getElementById('pwToggle');
        toggle.addEventListener('click', () => {
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Sembunyi' : 'Lihat';
            toggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
            pw.focus();
        });
        document.getElementById('loginForm').addEventListener('submit', () => {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.textContent = 'Memeriksa…';
        });
    </script>
</body>

</html>
