<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_login();
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Traffic Jaringan', ['traffic.css']); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>

<body class="page-traffic">
    <?php icon_sprite(); ?>
    <?php topbar('traffic'); ?>

    <main class="container">
        <div class="page-head">
            <div>
                <h1>Traffic Jaringan</h1>
                <p class="page-desc">Total traffic semua interface yang dipantau di Mikrotik, diperbarui tiap 5 detik.</p>
            </div>
            <div class="live-state" id="liveState"><span class="dot unknown"></span><span>Menghubungkan…</span></div>
        </div>

        <div class="banner is-bad" id="banner" role="alert"><?= icon('alert') ?><span id="bannerText"></span></div>

        <div class="stats">
            <div class="stat rx">
                <div class="stat-label"><span class="swatch"></span>Download (RX)</div>
                <div class="stat-value num" id="rxNow">–</div>
                <div class="stat-meta num">Rata-rata <span id="rxAvg">–</span> · Puncak <span id="rxPeak">–</span></div>
            </div>
            <div class="stat tx">
                <div class="stat-label"><span class="swatch"></span>Upload (TX)</div>
                <div class="stat-value num" id="txNow">–</div>
                <div class="stat-meta num">Rata-rata <span id="txAvg">–</span> · Puncak <span id="txPeak">–</span></div>
            </div>
        </div>

        <section class="panel chart-panel">
            <div class="panel-head">
                <h2>Riwayat</h2>
                <div class="segmented" role="group" aria-label="Rentang waktu" id="rangeGroup">
                    <button type="button" data-min="5">5 mnt</button>
                    <button type="button" data-min="15" class="is-on">15 mnt</button>
                    <button type="button" data-min="60">1 jam</button>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="chart" aria-label="Grafik traffic jaringan" role="img"></canvas>
                <div class="chart-empty" id="chartEmpty">Belum ada data.</div>
            </div>
        </section>
    </main>

    <script src="<?= asset('app.js') ?>"></script>
    <script src="<?= asset('traffic.js') ?>"></script>
</body>

</html>
