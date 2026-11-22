<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_login();
?>
<!doctype html>
<html lang="id">

<head>
    <?php page_head('Kelola Hall', ['admin.css']); ?>
</head>

<body class="page-admin">
    <?php icon_sprite(); ?>
    <?php topbar('admin'); ?>

    <main class="container">
        <div class="page-head">
            <div>
                <h1>Kelola Hall</h1>
                <p class="page-desc">Atur Hall, URL stream kamera, IP perangkat yang dipantau, dan petugas jaga.
                    Perubahan langsung terlihat di layar monitoring dalam ±5 detik.</p>
            </div>
            <button type="button" class="btn btn-primary" id="newBtn"><?= icon('plus') ?>Tambah Hall</button>
        </div>

        <div class="layout">
            <section class="panel list-panel" aria-labelledby="listTitle">
                <div class="panel-head">
                    <h2 id="listTitle">Daftar Hall <span class="count" id="hallCount"></span></h2>
                    <label class="search">
                        <?= icon('search') ?>
                        <span class="sr-only">Cari Hall</span>
                        <input class="input" type="search" id="searchInput" placeholder="Cari nama, IP, petugas…">
                    </label>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="col-order"><span class="sr-only">Urutan</span></th>
                                <th>Hall</th>
                                <th>Perangkat</th>
                                <th>Petugas Jaga</th>
                                <th class="col-actions"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody id="hallTable">
                            <tr>
                                <td colspan="5" class="empty">Memuat data…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="panel form-panel" id="formPanel" aria-labelledby="formTitle">
                <div class="panel-head">
                    <h2 id="formTitle">Tambah Hall Baru</h2>
                    <span class="mode-badge" id="modeBadge" hidden>Mode edit</span>
                </div>
                <form class="panel-body form" id="hallForm" novalidate>
                    <div class="field">
                        <label for="hallName">Nama Hall <span class="req">*</span></label>
                        <input class="input" type="text" id="hallName" maxlength="80" placeholder="Contoh: Hall 1" required>
                        <div class="field-error" data-for="name"></div>
                    </div>

                    <div class="field">
                        <label for="hallUrl">URL stream kamera <span class="req">*</span></label>
                        <input class="input" type="url" id="hallUrl" placeholder="http://192.168.1.50:8090/?viewIndex=0#Live" required>
                        <div class="hint">Alamat halaman player yang akan ditampilkan di dalam iframe.</div>
                        <div class="field-error" data-for="url"></div>
                    </div>

                    <div class="field">
                        <label for="hallPetugas">Petugas jaga</label>
                        <input class="input" type="text" id="hallPetugas" maxlength="80" placeholder="Nama petugas yang bertugas">
                    </div>

                    <fieldset class="field ip-fieldset">
                        <legend class="label">Perangkat yang dipantau (ping)</legend>
                        <div class="ip-rows" id="ipRows"></div>
                        <button type="button" class="btn btn-ghost btn-sm add-ip" id="addIpBtn"><?= icon('plus') ?>Tambah perangkat</button>
                        <div class="field-error" data-for="ips"></div>
                    </fieldset>

                    <div class="form-actions">
                        <button type="button" class="btn btn-ghost" id="cancelBtn" hidden>Batal</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn"><?= icon('check') ?><span>Simpan Hall</span></button>
                    </div>
                </form>
            </aside>
        </div>
    </main>

    <script src="<?= asset('app.js') ?>"></script>
    <script src="<?= asset('admin.js') ?>"></script>
</body>

</html>
