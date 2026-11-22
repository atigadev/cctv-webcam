<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_login();
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Kepadatan Pengunjung', ['traffic.css', 'people.css']); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>

<body class="page-people">
    <?php icon_sprite(); ?>
    <?php topbar('people'); ?>

    <main class="container">
        <div class="page-head">
            <div>
                <h1>Kepadatan Pengunjung</h1>
                <p class="page-desc">Jumlah orang yang terlihat di kamera saat ini, dihitung otomatis oleh AI dari snapshot kamera.
                    Angka adalah perkiraan; orang yang tertutup atau terlalu jauh bisa tidak terhitung.</p>
            </div>
            <div class="live-state" id="liveState"><span class="dot unknown"></span><span>Menghubungkan…</span></div>
        </div>

        <div class="banner is-bad" id="banner" role="alert"><?= icon('alert') ?><span id="bannerText"></span></div>

        <div class="people-summary">
            <div class="stat total-stat">
                <div class="stat-label"><?= icon('users') ?>Total terpantau</div>
                <div class="stat-value num" id="totalNow">–</div>
                <div class="stat-meta num" id="totalMeta">&nbsp;</div>
            </div>
        </div>

        <section class="hall-grid" id="hallGrid" aria-label="Jumlah orang per Hall">
            <div class="panel empty-panel">Memuat data…</div>
        </section>

        <section class="panel chart-panel">
            <div class="panel-head">
                <h2>Riwayat <span class="muted-sm">(rata-rata per menit)</span></h2>
                <div class="segmented" role="group" aria-label="Rentang waktu" id="rangeGroup">
                    <button type="button" data-h="1" class="is-on">1 jam</button>
                    <button type="button" data-h="6">6 jam</button>
                    <button type="button" data-h="24">24 jam</button>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="chart" aria-label="Grafik jumlah orang per Hall" role="img"></canvas>
                <div class="chart-empty" id="chartEmpty">Belum ada data.</div>
            </div>
        </section>
    </main>

    <!-- Modal: snapshot besar -->
    <div class="modal" id="snapModal" role="dialog" aria-modal="true" aria-labelledby="snapTitle" aria-hidden="true">
        <div class="modal-box snap-box">
            <div class="modal-head">
                <h3 id="snapTitle">Snapshot</h3>
                <button type="button" class="icon-btn" data-close title="Tutup"><?= icon('x') ?></button>
            </div>
            <div class="snap-body">
                <img id="snapImg" alt="">
            </div>
        </div>
    </div>

    <script src="<?= asset('app.js') ?>"></script>
    <script src="<?= asset('people.js') ?>"></script>
</body>

</html>
