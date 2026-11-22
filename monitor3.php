<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$summary = '
<div class="summary" id="summary" aria-live="polite">
    <a class="pill pill-people" id="sumPeople" href="people.php" title="Total orang terpantau kamera (AI)" hidden>' . icon('users') . '<strong>–</strong><span class="hide-sm">Orang</span></a>
    <span class="pill" id="sumOnline" title="Perangkat online"><span class="dot online"></span><strong>–</strong><span class="hide-sm">Online</span></span>
    <span class="pill" id="sumOffline" title="Perangkat offline"><span class="dot offline"></span><strong>–</strong><span class="hide-sm">Offline</span></span>
</div>
<button type="button" class="icon-btn" id="muteBtn" title="Suara alarm (M)">' . icon('bell') . '</button>';
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Monitoring', ['monitor.css']); ?>
</head>

<body class="page-monitor">
    <?php icon_sprite(); ?>
    <?php topbar('monitor', $summary); ?>

    <main class="workspace">
        <div class="viewbar">
            <div class="viewbar-group">
                <button type="button" class="btn btn-ghost btn-sm" id="showAllBtn" title="Tampilkan semua kamera (G)">
                    <?= icon('grid') ?><span>Semua Kamera</span>
                </button>
            </div>

            <div class="viewbar-title">
                <button type="button" class="icon-btn" id="prevBtn" title="Hall sebelumnya (←)" hidden><?= icon('left') ?></button>
                <div class="title-block">
                    <h1 class="view-title" id="viewTitle">Semua Kamera</h1>
                    <div class="view-sub" id="viewSub"></div>
                </div>
                <button type="button" class="icon-btn" id="nextBtn" title="Hall berikutnya (→)" hidden><?= icon('right') ?></button>
            </div>

            <div class="viewbar-group viewbar-right">
                <button type="button" class="icon-btn" id="rotateBtn" title="Ganti Hall otomatis (R)"><?= icon('play') ?></button>
                <button type="button" class="icon-btn" id="listBtn" title="Tampilkan/sembunyikan daftar Hall (L)"><?= icon('panel') ?></button>
                <button type="button" class="icon-btn" id="fullscreenBtn" title="Layar penuh (F)"><?= icon('expand') ?></button>
                <button type="button" class="icon-btn hide-sm" id="helpBtn" title="Pintasan keyboard (?)"><?= icon('keyboard') ?></button>
            </div>
        </div>

        <div class="banner is-bad" id="connBanner" role="alert">
            <?= icon('alert') ?><span id="connBannerText"></span>
        </div>

        <section class="stage" id="stage" aria-label="Tampilan kamera">
            <div class="stage-empty" id="stageEmpty">
                <div class="spinner"></div>
                <div>Memuat data Hall…</div>
            </div>
        </section>

        <section class="strip" id="strip" aria-label="Daftar Hall">
            <div class="strip-head">
                <span class="strip-title">Daftar Hall <span class="strip-count" id="hallCount"></span></span>
                <span class="strip-meta">
                    <span class="legend"><span class="dot online"></span>Online</span>
                    <span class="legend"><span class="dot unstable"></span>Tidak stabil</span>
                    <span class="legend"><span class="dot offline"></span>Offline</span>
                    <span class="strip-updated num" id="lastChecked">Belum dicek</span>
                </span>
            </div>
            <div class="strip-cards" id="cards" role="list"></div>
        </section>
    </main>

    <!-- Modal: ubah petugas jaga -->
    <div class="modal" id="petugasModal" role="dialog" aria-modal="true" aria-labelledby="petugasTitle" aria-hidden="true">
        <form class="modal-box" id="petugasForm">
            <div class="modal-head">
                <h3 id="petugasTitle">Ubah Petugas Jaga</h3>
                <button type="button" class="icon-btn" data-close title="Tutup"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <div class="field">
                    <label for="petugasInput">Nama petugas jaga</label>
                    <input class="input" type="text" id="petugasInput" maxlength="80" placeholder="Nama petugas…" autocomplete="off">
                    <div class="field-error" id="petugasError"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>Batal</button>
                <button type="submit" class="btn btn-primary" id="petugasSave">Simpan</button>
            </div>
        </form>
    </div>

    <!-- Modal: pintasan keyboard -->
    <div class="modal" id="helpModal" role="dialog" aria-modal="true" aria-labelledby="helpTitle" aria-hidden="true">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="helpTitle">Pintasan Keyboard</h3>
                <button type="button" class="icon-btn" data-close title="Tutup"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <dl class="shortcuts">
                    <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>Hall sebelumnya / berikutnya</dd>
                    <dt><kbd>G</kbd></dt><dd>Semua kamera</dd>
                    <dt><kbd>1</kbd>–<kbd>9</kbd></dt><dd>Buka Hall ke-n</dd>
                    <dt><kbd>R</kbd></dt><dd>Ganti Hall otomatis</dd>
                    <dt><kbd>L</kbd></dt><dd>Tampilkan/sembunyikan daftar Hall</dd>
                    <dt><kbd>F</kbd></dt><dd>Layar penuh</dd>
                    <dt><kbd>M</kbd></dt><dd>Suara alarm on/off</dd>
                </dl>
            </div>
        </div>
    </div>

    <script src="<?= asset('app.js') ?>"></script>
    <script src="<?= asset('monitor.js') ?>"></script>
</body>

</html>
