<?php
/**
 * change_password.php - Ganti password.
 * Wajib dibuka setelah login pertama dengan password default.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (!is_logged_in()) {
    header('Location: login.php?redirect=change_password.php');
    exit;
}

$forced = must_change_password();
$redirect = basename((string)($_GET['redirect'] ?? 'monitor3.php'));
if (!preg_match('/^[a-z0-9_]+\.php$/i', $redirect) || in_array($redirect, ['login.php', 'change_password.php'], true)) {
    $redirect = 'monitor3.php';
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        $error = 'Sesi form kedaluwarsa. Silakan coba lagi.';
    } else {
        $error = validate_new_password(current_user(), $current, $new, $confirm);
        if ($error === '') {
            user_set_password(current_user(), $new);
            session_regenerate_id(true);
            $_SESSION['must_change'] = false;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $success = true;
            $forced = false;
        }
    }
}

csrf_token();
session_write_close();

if ($success) {
    header('Refresh: 2; url=' . $redirect);
}
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Ganti Password'); ?>
</head>

<body class="auth-page">
    <?php icon_sprite(); ?>
    <main class="login">
        <div class="brand">
            <span class="brand-mark"><span class="live-dot"></span></span>
            <span class="brand-text">GIIAS <strong>Live Monitoring</strong></span>
        </div>

        <?php if ($success): ?>
            <h1>Password diganti</h1>
            <div class="alert is-ok" role="status"><?= icon('check') ?><span>Password baru tersimpan. Mengalihkan…</span></div>
            <a class="btn btn-primary" style="margin-top:16px;width:100%" href="<?= e($redirect) ?>">Lanjut ke Monitoring</a>
        <?php else: ?>
            <h1><?= $forced ? 'Buat password baru' : 'Ganti password' ?></h1>
            <p>
                <?php if ($forced): ?>
                    Anda masuk dengan password default. Demi keamanan, ganti password sebelum melanjutkan.
                <?php else: ?>
                    Akun: <strong><?= e(current_user()) ?></strong>
                <?php endif; ?>
            </p>

            <form method="post" action="change_password.php?redirect=<?= e(urlencode($redirect)) ?>" id="pwForm">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

                <?php if ($error): ?>
                    <div class="alert is-bad" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div>
                <?php endif; ?>

                <div class="field">
                    <label for="current">Password saat ini</label>
                    <input class="input" id="current" name="current" type="password" autocomplete="current-password" required autofocus>
                </div>
                <div class="field">
                    <label for="new">Password baru</label>
                    <div class="pw-wrap">
                        <input class="input" id="new" name="new" type="password" autocomplete="new-password"
                            minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
                        <button type="button" class="pw-toggle" data-toggle="new" aria-label="Tampilkan password">Lihat</button>
                    </div>
                    <ul class="pw-rules" id="rules">
                        <li data-rule="len">Minimal <?= PASSWORD_MIN_LENGTH ?> karakter</li>
                        <li data-rule="diff">Berbeda dari password saat ini</li>
                        <li data-rule="match">Konfirmasi cocok</li>
                    </ul>
                </div>
                <div class="field">
                    <label for="confirm">Ulangi password baru</label>
                    <input class="input" id="confirm" name="confirm" type="password" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary" id="saveBtn"><?= icon('lock') ?>Simpan password</button>
            </form>

            <?php if ($forced): ?>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <button type="submit" class="auth-back" style="background:none;border:0;cursor:pointer;padding:0"><?= icon('logout') ?>Keluar</button>
                </form>
            <?php else: ?>
                <a class="auth-back" href="monitor3.php"><?= icon('left') ?>Kembali ke Monitoring</a>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php if (!$success): ?>
        <script>
            const $ = (id) => document.getElementById(id);
            const minLen = <?= PASSWORD_MIN_LENGTH ?>;

            function check() {
                const cur = $('current').value, nw = $('new').value, cf = $('confirm').value;
                const rules = {
                    len: nw.length >= minLen,
                    diff: nw !== '' && nw !== cur,
                    match: cf !== '' && nw === cf,
                };
                Object.entries(rules).forEach(([k, ok]) => {
                    document.querySelector(`[data-rule="${k}"]`).classList.toggle('is-ok', ok);
                });
                return Object.values(rules).every(Boolean);
            }
            ['current', 'new', 'confirm'].forEach(id => $(id).addEventListener('input', check));

            document.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', () => {
                const show = $('new').type === 'password';
                ['new', 'confirm'].forEach(id => { $(id).type = show ? 'text' : 'password'; });
                btn.textContent = show ? 'Sembunyi' : 'Lihat';
            }));

            $('pwForm').addEventListener('submit', (e) => {
                if (!check()) {
                    e.preventDefault();
                    (!$('new').value || $('new').value.length < minLen ? $('new') : $('confirm')).focus();
                    return;
                }
                $('saveBtn').disabled = true;
            });
        </script>
    <?php endif; ?>
</body>

</html>
