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
        header('Location: ' . (must_change_password() ? 'change_password.php?first=1&redirect=' . urlencode($redirect) : $redirect));
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
</head>

<body class="auth-page">
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
