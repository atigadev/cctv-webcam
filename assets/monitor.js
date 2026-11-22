/* =====================================================
   GIIAS Monitor - halaman monitoring
   ===================================================== */
(function () {
    'use strict';

    const { api, toast, openModal, closeModal, escapeHtml, icon, formatClock, formatDuration, storage } = window.App;

    const POLL_MS = 5000;
    const ROTATE_MS = 20000;
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    const $ = (id) => document.getElementById(id);
    const el = {
        stage: $('stage'), cards: $('cards'), strip: $('strip'),
        title: $('viewTitle'), sub: $('viewSub'),
        showAll: $('showAllBtn'), prev: $('prevBtn'), next: $('nextBtn'),
        rotate: $('rotateBtn'), list: $('listBtn'), fullscreen: $('fullscreenBtn'), help: $('helpBtn'),
        mute: $('muteBtn'), sumOnline: $('sumOnline'), sumOffline: $('sumOffline'),
        hallCount: $('hallCount'), lastChecked: $('lastChecked'),
        banner: $('connBanner'), bannerText: $('connBannerText'),
        petugasModal: $('petugasModal'), petugasForm: $('petugasForm'), petugasTitle: $('petugasTitle'),
        petugasInput: $('petugasInput'), petugasError: $('petugasError'), petugasSave: $('petugasSave'),
        helpModal: $('helpModal'),
    };

    const state = {
        halls: [],
        version: null,
        devices: {},          // ip -> {state, since, lastSeen}
        firstStatus: true,
        view: 'all',          // 'all' | hallId
        rotateTimer: null,
        muted: storage.get('muted', false),
        listHidden: storage.get('listHidden', isMobile),
        editingHallId: null,
        lastOkAt: null,
    };

    /* ---------- Util ---------- */

    function isSafeUrl(url) {
        try {
            return ['http:', 'https:'].includes(new URL(url, location.href).protocol);
        } catch { return false; }
    }

    function hallState(hall) {
        const states = (hall.ips || []).map(e => state.devices[e.ip]?.state || 'unknown');
        if (!states.length) return 'none';
        if (states.includes('offline')) return 'offline';
        if (states.includes('unstable')) return 'unstable';
        if (states.every(s => s === 'unknown')) return 'unknown';
        return 'online';
    }

    function offlineCount(hall) {
        return (hall.ips || []).filter(e => state.devices[e.ip]?.state === 'offline').length;
    }

    const STATE_LABEL = { online: 'Online', unstable: 'Tidak stabil', offline: 'Offline', unknown: 'Mengecek…', none: 'Tanpa IP' };

    function hallById(id) {
        return state.halls.find(h => String(h.id) === String(id));
    }

    /* ---------- Data ---------- */

    async function loadConfig() {
        const data = await api('config.php');
        state.halls = (data.halls || []).filter(h => h && h.id && h.name && isSafeUrl(h.iframeUrl));
        state.version = data.version;
    }

    async function pollStatus() {
        try {
            const data = await api('status_api.php');
            state.lastOkAt = Date.now();
            hideBanner();

            if (data.configVersion && state.version && data.configVersion !== state.version) {
                await reloadConfig();
            }

            const prev = state.devices;
            state.devices = data.devices || {};
            if (!state.firstStatus) announceChanges(prev, state.devices);
            state.firstStatus = false;

            el.lastChecked.textContent = 'Dicek ' + formatClock(new Date(data.checkedAt));
            renderStatus();
        } catch (err) {
            showBanner(`Koneksi ke server bermasalah: ${err.message}` +
                (state.lastOkAt ? ` · status terakhir pukul ${formatClock(new Date(state.lastOkAt))}` : ''));
            document.body.classList.add('is-stale');
        } finally {
            setTimeout(pollStatus, document.hidden ? POLL_MS * 3 : POLL_MS);
        }
    }

    async function reloadConfig() {
        const current = state.view;
        await loadConfig();
        renderCards();
        if (current !== 'all' && hallById(current)) {
            showHall(current, { keepRotate: true });
        } else {
            showAll();
        }
        toast('Data Hall diperbarui dari halaman admin.', { type: 'ok' });
    }

    function showBanner(text) {
        el.bannerText.textContent = text;
        el.banner.classList.add('is-show');
    }

    function hideBanner() {
        el.banner.classList.remove('is-show');
        document.body.classList.remove('is-stale');
    }

    /* ---------- Alarm ---------- */

    function deviceLabel(ip) {
        const owners = [];
        state.halls.forEach(h => (h.ips || []).forEach(e => {
            if (e.ip === ip) owners.push(`${h.name}${e.label ? ' · ' + e.label : ''}`);
        }));
        return owners.join(', ') || ip;
    }

    function announceChanges(prev, next) {
        let wentDown = 0;
        Object.entries(next).forEach(([ip, d]) => {
            const was = prev[ip]?.state;
            if (!was || was === d.state) return;
            if (d.state === 'offline') {
                wentDown++;
                toast(`${deviceLabel(ip)} (${ip}) tidak merespons.`, { type: 'bad', title: 'Perangkat offline', timeout: 0 });
            } else if (was === 'offline' && d.state === 'online') {
                toast(`${deviceLabel(ip)} (${ip}) kembali online.`, { type: 'ok', title: 'Pulih' });
            }
        });
        if (wentDown) beep();
    }

    let audioCtx = null;
    function ensureAudio() {
        if (!audioCtx) {
            try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch { /* tidak didukung */ }
        }
        if (audioCtx?.state === 'suspended') audioCtx.resume();
    }
    // Browser hanya mengizinkan audio setelah ada interaksi pengguna
    document.addEventListener('pointerdown', ensureAudio, { once: true });
    document.addEventListener('keydown', ensureAudio, { once: true });

    function beep() {
        if (state.muted || !audioCtx) return;
        const t = audioCtx.currentTime;
        [0, 0.28].forEach((offset) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'square';
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, t + offset);
            gain.gain.exponentialRampToValueAtTime(0.15, t + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + offset + 0.22);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(t + offset);
            osc.stop(t + offset + 0.24);
        });
    }

    function setMuted(muted, fromUser = false) {
        state.muted = muted;
        storage.set('muted', muted);
        el.mute.innerHTML = icon(muted ? 'bell-off' : 'bell');
        el.mute.title = muted ? 'Suara alarm mati (M)' : 'Suara alarm aktif (M)';
        el.mute.classList.toggle('is-muted', muted);
        if (fromUser && !muted) {
            ensureAudio();
            toast('Suara alarm aktif.', { type: 'ok', timeout: 2000 });
        }
    }

    /* ---------- Render: kartu Hall ---------- */

    function renderCards() {
        el.hallCount.textContent = state.halls.length ? `(${state.halls.length})` : '';

        if (!state.halls.length) {
            el.cards.innerHTML = '<div class="strip-empty">Belum ada Hall. <a href="admin.php">Tambah di Kelola Hall →</a></div>';
            return;
        }

        el.cards.innerHTML = state.halls.map((hall, i) => {
            const ips = (hall.ips || []).map(e => `
                <li class="dev" data-ip="${escapeHtml(e.ip)}">
                    <span class="dot unknown"></span>
                    <span class="dev-name">${escapeHtml(e.label || 'Perangkat')}</span>
                    <span class="dev-ip num">${escapeHtml(e.ip)}</span>
                    <span class="dev-since num"></span>
                </li>`).join('');
            return `
                <button type="button" class="card" role="listitem" data-hall-id="${escapeHtml(hall.id)}"
                    title="Buka ${escapeHtml(hall.name)}${i < 9 ? ` (${i + 1})` : ''}">
                    <span class="card-head">
                        <span class="dot unknown card-dot"></span>
                        <span class="card-name">${escapeHtml(hall.name)}</span>
                        ${i < 9 ? `<kbd class="card-key">${i + 1}</kbd>` : ''}
                    </span>
                    <span class="card-officer">${icon('user')}${hall.petugas ? escapeHtml(hall.petugas) : '<em>Belum ada petugas</em>'}</span>
                    <ul class="devs">${ips || '<li class="dev dev-none">Tidak ada IP dipantau</li>'}</ul>
                </button>`;
        }).join('');
    }

    function renderStatus() {
        const now = Date.now();

        // Ringkasan perangkat unik
        let on = 0, off = 0;
        Object.values(state.devices).forEach(d => {
            if (d.state === 'offline') off++;
            else if (d.state === 'online' || d.state === 'unstable') on++;
        });
        el.sumOnline.querySelector('strong').textContent = on;
        el.sumOffline.querySelector('strong').textContent = off;
        el.sumOffline.classList.toggle('is-bad', off > 0);
        document.title = (off ? `(${off}) ⚠ ` : '') + 'Monitoring · GIIAS Monitor';

        // Kartu
        el.cards.querySelectorAll('.card').forEach(card => {
            const hall = hallById(card.dataset.hallId);
            if (!hall) return;
            const hs = hallState(hall);
            card.dataset.state = hs;
            card.querySelector('.card-dot').className = `dot card-dot ${hs === 'none' ? 'unknown' : hs}`;
            card.querySelectorAll('.dev[data-ip]').forEach(row => {
                const d = state.devices[row.dataset.ip];
                const s = d?.state || 'unknown';
                row.querySelector('.dot').className = `dot ${s}`;
                row.dataset.state = s;
                const since = row.querySelector('.dev-since');
                if (d && s !== 'unknown') {
                    since.textContent = formatDuration(now - d.since);
                    since.title = `${STATE_LABEL[s]} sejak ${formatClock(new Date(d.since))}` +
                        (s === 'offline' && d.lastSeen ? ` · terakhir online ${formatClock(new Date(d.lastSeen))}` : '');
                } else {
                    since.textContent = '';
                }
            });
        });

        // Sel mosaic
        el.stage.querySelectorAll('.cell').forEach(cell => {
            const hall = hallById(cell.dataset.hallId);
            if (!hall) return;
            const hs = hallState(hall);
            cell.dataset.state = hs;
            const badge = cell.querySelector('.cell-badge');
            const n = offlineCount(hall);
            badge.innerHTML = `<span class="dot ${hs === 'none' ? 'unknown' : hs}"></span>` +
                escapeHtml(hs === 'offline' ? `${n} offline` : STATE_LABEL[hs]);
        });

        // Judul tampilan tunggal
        if (state.view !== 'all') renderSingleMeta(hallById(state.view));
    }

    /* ---------- Render: panggung (stage) ---------- */

    function clearStage() {
        el.stage.innerHTML = '';
    }

    function makeIframe(hall) {
        const frame = document.createElement('iframe');
        frame.src = hall.iframeUrl;
        frame.title = `Kamera ${hall.name}`;
        frame.allow = 'autoplay; fullscreen';
        frame.referrerPolicy = 'no-referrer';
        frame.addEventListener('load', () => frame.classList.add('is-loaded'));
        return frame;
    }

    function showAll() {
        stopRotate();
        state.view = 'all';
        clearStage();
        setActiveCard(null);

        el.title.textContent = 'Semua Kamera';
        el.sub.innerHTML = state.halls.length ? `${state.halls.length} Hall dipantau` : '';
        el.prev.hidden = el.next.hidden = true;
        el.showAll.classList.add('is-current');

        if (!state.halls.length) {
            el.stage.innerHTML = `<div class="stage-empty">${icon('video', 'icon-lg')}<div>Belum ada Hall untuk ditampilkan.</div><a class="btn btn-primary" href="admin.php">${icon('plus')}Tambah Hall</a></div>`;
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'mosaic';
        state.halls.forEach((hall, i) => {
            const cell = document.createElement('div');
            cell.className = 'cell';
            cell.dataset.hallId = hall.id;
            cell.tabIndex = 0;
            cell.setAttribute('role', 'button');
            cell.setAttribute('aria-label', `Buka ${hall.name}`);
            cell.innerHTML = `
                <div class="cell-loading">Memuat stream…</div>
                <div class="cell-top">
                    <span class="cell-name">${i < 9 ? `<kbd>${i + 1}</kbd>` : ''}${escapeHtml(hall.name)}</span>
                    <span class="cell-badge"></span>
                </div>
                <div class="cell-bottom">
                    <span class="cell-officer">${icon('user')}${hall.petugas ? escapeHtml(hall.petugas) : '<em>Belum ada petugas</em>'}</span>
                    <button type="button" class="cell-edit" title="Ubah petugas jaga">${icon('edit')}</button>
                </div>`;
            cell.insertBefore(makeIframe(hall), cell.querySelector('.cell-top'));
            // Lapisan klik di atas iframe (iframe menelan event klik)
            const hit = document.createElement('div');
            hit.className = 'cell-hit';
            cell.insertBefore(hit, cell.querySelector('.cell-top'));
            grid.appendChild(cell);
        });
        el.stage.appendChild(grid);
        layoutMosaic();
        renderStatus();
    }

    function showHall(id, { keepRotate = false } = {}) {
        const hall = hallById(id);
        if (!hall) return;
        if (!keepRotate) stopRotate();

        state.view = hall.id;
        clearStage();
        setActiveCard(hall.id);
        el.showAll.classList.remove('is-current');
        el.prev.hidden = el.next.hidden = state.halls.length < 2;

        const wrap = document.createElement('div');
        wrap.className = 'single';
        wrap.innerHTML = '<div class="cell-loading">Memuat stream…</div>';
        wrap.appendChild(makeIframe(hall));
        el.stage.appendChild(wrap);

        renderSingleMeta(hall);
    }

    function renderSingleMeta(hall) {
        if (!hall) return;
        el.title.textContent = hall.name;
        const hs = hallState(hall);
        el.sub.innerHTML = `
            <span class="status-chip" data-state="${hs}"><span class="dot ${hs === 'none' ? 'unknown' : hs}"></span>${escapeHtml(STATE_LABEL[hs])}</span>
            <span class="sub-officer">${icon('user')}${hall.petugas ? escapeHtml(hall.petugas) : '<em>Belum ada petugas</em>'}</span>
            <button type="button" class="link-btn" data-edit="${escapeHtml(hall.id)}" title="Ubah petugas jaga">${icon('edit')}Ubah</button>`;
    }

    function setActiveCard(id) {
        el.cards.querySelectorAll('.card').forEach(c => {
            const on = id !== null && String(c.dataset.hallId) === String(id);
            c.classList.toggle('is-active', on);
            if (on) c.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
        });
    }

    function navigate(dir) {
        if (!state.halls.length) return;
        let idx = state.halls.findIndex(h => String(h.id) === String(state.view));
        if (idx === -1) idx = dir > 0 ? -1 : 0;
        const next = state.halls[(idx + dir + state.halls.length) % state.halls.length];
        showHall(next.id, { keepRotate: !!state.rotateTimer });
    }

    /**
     * Hitung jumlah kolom mosaic supaya semua kamera muat di layar tanpa
     * scroll dengan ukuran sel sebesar mungkin (rasio 16:9).
     */
    function layoutMosaic() {
        const grid = el.stage.querySelector('.mosaic');
        if (!grid) return;
        const n = state.halls.length;
        const gap = 8;
        const W = el.stage.clientWidth - gap * 2;
        const H = el.stage.clientHeight - gap * 2;
        let best = { cols: 1, w: 0 };

        for (let cols = 1; cols <= n; cols++) {
            const rows = Math.ceil(n / cols);
            let w = (W - gap * (cols - 1)) / cols;
            let h = w * 9 / 16;
            if (h * rows + gap * (rows - 1) > H) {
                h = (H - gap * (rows - 1)) / rows;
                w = h * 16 / 9;
            }
            if (w > best.w) best = { cols, w };
        }

        // Kalau sel jadi terlalu kecil (layar sempit), izinkan scroll vertikal
        const minW = 260;
        if (best.w < minW) {
            const cols = Math.max(1, Math.floor((W + gap) / (minW + gap)));
            grid.style.setProperty('--cols', cols);
            grid.style.setProperty('--cell-w', 'auto');
            grid.classList.add('is-scroll');
        } else {
            grid.style.setProperty('--cols', best.cols);
            grid.style.setProperty('--cell-w', `${Math.floor(best.w)}px`);
            grid.classList.remove('is-scroll');
        }
    }

    /* ---------- Auto-rotate ---------- */

    function startRotate() {
        if (state.halls.length < 2) {
            toast('Butuh minimal 2 Hall untuk ganti otomatis.', { type: 'warn' });
            return;
        }
        if (state.view === 'all') showHall(state.halls[0].id, { keepRotate: true });
        state.rotateTimer = setInterval(() => navigate(1), ROTATE_MS);
        el.rotate.classList.add('is-on');
        el.rotate.innerHTML = icon('pause');
        el.rotate.title = `Hentikan ganti otomatis (R) · tiap ${ROTATE_MS / 1000} dtk`;
        toast(`Ganti Hall otomatis setiap ${ROTATE_MS / 1000} detik.`, { type: 'ok', timeout: 2500 });
    }

    function stopRotate() {
        if (!state.rotateTimer) return;
        clearInterval(state.rotateTimer);
        state.rotateTimer = null;
        el.rotate.classList.remove('is-on');
        el.rotate.innerHTML = icon('play');
        el.rotate.title = 'Ganti Hall otomatis (R)';
    }

    function toggleRotate() {
        state.rotateTimer ? stopRotate() : startRotate();
    }

    /* ---------- Daftar Hall & layar penuh ---------- */

    function setListHidden(hidden) {
        state.listHidden = hidden;
        storage.set('listHidden', hidden);
        el.strip.classList.toggle('is-hidden', hidden);
        el.list.classList.toggle('is-on', !hidden);
        // tunggu transisi selesai lalu hitung ulang mosaic
        setTimeout(layoutMosaic, 230);
    }

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function toggleFullscreen() {
        const root = document.documentElement;
        if (!isFullscreen()) {
            (root.requestFullscreen || root.webkitRequestFullscreen)?.call(root);
        } else {
            (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
        }
    }

    function onFullscreenChange() {
        const fs = isFullscreen();
        document.body.classList.toggle('is-fullscreen', fs);
        el.fullscreen.innerHTML = icon(fs ? 'shrink' : 'expand');
        el.fullscreen.title = fs ? 'Keluar layar penuh (F)' : 'Layar penuh (F)';
        el.fullscreen.classList.toggle('is-on', fs);
        setTimeout(layoutMosaic, 100);
    }

    /* ---------- Modal petugas ---------- */

    function openPetugas(id) {
        const hall = hallById(id);
        if (!hall) return;
        state.editingHallId = hall.id;
        el.petugasTitle.textContent = `Petugas Jaga · ${hall.name}`;
        el.petugasInput.value = hall.petugas || '';
        el.petugasError.textContent = '';
        openModal(el.petugasModal, el.petugasInput);
        el.petugasInput.select();
    }

    el.petugasForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const hall = hallById(state.editingHallId);
        if (!hall) return;
        const value = el.petugasInput.value.trim();

        el.petugasSave.disabled = true;
        el.petugasSave.textContent = 'Menyimpan…';
        try {
            const data = await api('admin_api.php?action=update', { method: 'POST', body: { id: hall.id, petugas: value } });
            hall.petugas = data.hall?.petugas ?? value;
            // Perbarui versi supaya poll berikutnya tidak memicu reload penuh
            const cfg = await api('config.php');
            state.version = cfg.version;
            updatePetugasText(hall);
            closeModal(el.petugasModal);
            toast(`Petugas ${hall.name} diperbarui.`, { type: 'ok' });
        } catch (err) {
            el.petugasError.textContent = err.message;
        } finally {
            el.petugasSave.disabled = false;
            el.petugasSave.textContent = 'Simpan';
        }
    });

    function updatePetugasText(hall) {
        const html = `${icon('user')}${hall.petugas ? escapeHtml(hall.petugas) : '<em>Belum ada petugas</em>'}`;
        const cardOfficer = el.cards.querySelector(`.card[data-hall-id="${CSS.escape(String(hall.id))}"] .card-officer`);
        if (cardOfficer) cardOfficer.innerHTML = html;
        const cellOfficer = el.stage.querySelector(`.cell[data-hall-id="${CSS.escape(String(hall.id))}"] .cell-officer`);
        if (cellOfficer) cellOfficer.innerHTML = html;
        if (String(state.view) === String(hall.id)) renderSingleMeta(hall);
    }

    /* ---------- Event ---------- */

    el.cards.addEventListener('click', (e) => {
        const card = e.target.closest('.card');
        if (card) showHall(card.dataset.hallId);
    });

    el.stage.addEventListener('click', (e) => {
        const edit = e.target.closest('.cell-edit');
        const cell = e.target.closest('.cell');
        if (edit && cell) {
            openPetugas(cell.dataset.hallId);
        } else if (cell) {
            showHall(cell.dataset.hallId);
        }
    });
    el.stage.addEventListener('keydown', (e) => {
        const cell = e.target.closest('.cell');
        if (cell && (e.key === 'Enter' || e.key === ' ') && e.target === cell) {
            e.preventDefault();
            showHall(cell.dataset.hallId);
        }
    });

    el.sub.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-edit]');
        if (btn) openPetugas(btn.dataset.edit);
    });

    el.showAll.addEventListener('click', showAll);
    el.prev.addEventListener('click', () => navigate(-1));
    el.next.addEventListener('click', () => navigate(1));
    el.rotate.addEventListener('click', toggleRotate);
    el.list.addEventListener('click', () => setListHidden(!state.listHidden));
    el.fullscreen.addEventListener('click', toggleFullscreen);
    el.help.addEventListener('click', () => openModal(el.helpModal));
    el.mute.addEventListener('click', () => setMuted(!state.muted, true));
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);

    let resizeRaf;
    window.addEventListener('resize', () => {
        cancelAnimationFrame(resizeRaf);
        resizeRaf = requestAnimationFrame(layoutMosaic);
    });

    // Perbarui teks durasi "sejak" tiap 30 detik walau status tidak berubah
    setInterval(renderStatus, 30000);

    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.target.closest('input, textarea, select, .modal.is-open')) return;
        if (document.querySelector('.modal.is-open')) return;

        const key = e.key.toLowerCase();
        const actions = {
            arrowleft: () => navigate(-1),
            arrowright: () => navigate(1),
            g: showAll,
            escape: () => { if (state.view !== 'all') showAll(); },
            r: toggleRotate,
            l: () => setListHidden(!state.listHidden),
            f: toggleFullscreen,
            m: () => setMuted(!state.muted, true),
            '?': () => openModal(el.helpModal),
        };
        if (actions[key]) {
            e.preventDefault();
            actions[key]();
        } else if (/^[1-9]$/.test(key) && state.halls[Number(key) - 1]) {
            showHall(state.halls[Number(key) - 1].id);
        }
    });

    /* ---------- Mulai ---------- */

    async function init() {
        setMuted(state.muted);
        setListHidden(state.listHidden);
        try {
            await loadConfig();
        } catch (err) {
            el.stage.innerHTML = `<div class="stage-empty">${icon('alert', 'icon-lg')}<div>Gagal memuat data Hall: ${escapeHtml(err.message)}</div><button class="btn btn-secondary" onclick="location.reload()">${icon('refresh')}Coba lagi</button></div>`;
            return;
        }
        renderCards();
        if (isMobile && state.halls.length) {
            showHall(state.halls[0].id);
        } else {
            showAll();
        }
        pollStatus();
    }

    init();
})();
