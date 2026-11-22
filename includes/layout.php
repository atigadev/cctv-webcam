<?php
/**
 * layout.php - Potongan HTML bersama: <head>, top bar navigasi, ikon SVG.
 */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 0;
    return 'assets/' . $path . '?v=' . $v;
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true"><use href="#i-' . e($name) . '"></use></svg>';
}

function page_head(string $title, array $css = []): void
{
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="<?= e($_SESSION['csrf'] ?? '') ?>">
    <title><?= e($title) ?> · GIIAS Monitor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23e60000'/><circle cx='16' cy='16' r='6' fill='white'/></svg>">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <?php foreach ($css as $file): ?>
        <link rel="stylesheet" href="<?= asset($file) ?>">
    <?php endforeach;
}

/**
 * Top bar navigasi. $slot = HTML tambahan di sisi kanan (mis. ringkasan status).
 */
function topbar(string $active, string $slot = ''): void
{
    $links = [
        'monitor' => ['monitor3.php', 'Monitoring', 'video'],
        'people'  => ['people.php', 'Kepadatan', 'users'],
        'traffic' => ['traffic.php', 'Traffic', 'chart'],
        'admin'   => ['admin.php', 'Kelola Hall', 'settings'],
    ];
    ?>
    <header class="topbar">
        <a class="brand" href="monitor3.php" aria-label="GIIAS Monitor - beranda">
            <span class="brand-mark"><span class="live-dot"></span></span>
            <span class="brand-text">GIIAS <strong>Live Monitoring</strong></span>
        </a>

        <nav class="nav" aria-label="Navigasi utama">
            <?php foreach ($links as $key => [$href, $label, $ico]): ?>
                <a href="<?= e($href) ?>" class="nav-link<?= $key === $active ? ' is-active' : '' ?>"
                    <?= $key === $active ? 'aria-current="page"' : '' ?>>
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-right">
            <?= $slot ?>
            <span class="clock" id="clock" aria-label="Waktu sekarang">--:--:--</span>
            <a class="icon-btn" href="change_password.php" title="Ganti password"><?= icon('key') ?></a>
            <form method="post" action="logout.php" class="logout-form">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="Keluar (<?= e(current_user()) ?>)">
                    <?= icon('user') ?><span class="hide-sm"><?= e(current_user()) ?></span><?= icon('logout') ?>
                </button>
            </form>
        </div>
    </header>
    <?php
}

/** Sprite ikon (gaya garis, 24x24). Panggil sekali di awal <body>. */
function icon_sprite(): void
{
    $paths = [
        'video'    => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 6-6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users'    => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 3.5a4 4 0 0 1 0 9M22 21a7 7 0 0 0-4.5-6.5"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'left'     => '<path d="m15 18-6-6 6-6"/>',
        'right'    => '<path d="m9 18 6-6-6-6"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'expand'   => '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'shrink'   => '<path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'bell'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'bell-off' => '<path d="M8.7 3A6 6 0 0 1 18 8c0 2.6.4 4.6 1 6"/><path d="M17 17H3s3-2 3-9a4.7 4.7 0 0 1 .3-1.7"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/><path d="m2 2 20 20"/>',
        'play'     => '<path d="m6 4 14 8-14 8z"/>',
        'pause'    => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'panel'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 15h18"/>',
        'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'trash'    => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'up'       => '<path d="m18 15-6-6-6 6"/>',
        'down'     => '<path d="m6 9 6 6 6-6"/>',
        'alert'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'keyboard' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M8 16h8"/>',
        'key'      => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/>',
        'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'refresh'  =>'<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
    ];
    echo '<svg width="0" height="0" style="position:absolute" aria-hidden="true">';
    foreach ($paths as $id => $d) {
        echo '<symbol id="i-' . $id . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</symbol>';
    }
    echo '</svg>';
}
