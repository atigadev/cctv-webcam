/* =====================================================
   GIIAS Monitor - halaman Kepadatan Pengunjung
   ===================================================== */
(function () {
    'use strict';

    const { api, openModal, escapeHtml, icon, formatClock, storage } = window.App;
    const POLL_MS = 5000;
    const HISTORY_MS = 60000;
    const API = 'people_api.php';
    const $ = (id) => document.getElementById(id);

    // Palet kategorikal tetap (urutan tidak diputar), varian untuk latar gelap.
    const COLORS = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#0ca30c', '#9085e9', '#e66767'];

    let halls = [];          // dari status terakhir
    let signature = '';
    let hours = storage.get('peopleRange', 1);
    let historyTimer = null;

    const colorOf = (idx) => COLORS[idx % COLORS.length];
    const snapUrl = (key, at) => `${API}?action=snapshot&key=${encodeURIComponent(key)}&t=${at || 0}`;
    const fmtCount = (n) => (n == null ? '–' : Number(n).toLocaleString('id-ID'));

    /* ---------- Status ---------- */

    function setLive(state, text) {
        $('liveState').innerHTML = `<span class="dot ${state}"></span><span>${escapeHtml(text)}</span>`;
    }

    function showBanner(text) {
        $('bannerText').textContent = text;
        $('banner').classList.add('is-show');
    }

    function renderEmpty() {
        $('hallGrid').innerHTML = `
            <div class="panel empty-panel">
                ${icon('users', 'icon-lg')}
                <div>Belum ada Hall yang mengaktifkan hitung orang.</div>
                <div class="hint">Buka <b>Kelola Hall</b>, edit Hall, lalu centang <b>Hitung orang (AI)</b>.</div>
                <a class="btn btn-primary btn-sm" href="admin.php">${icon('settings')}Kelola Hall</a>
            </div>`;
    }

    function cardHtml(h, idx) {
        const cams = h.cams.map(c => `
            <button type="button" class="cam" data-key="${escapeHtml(c.key)}" data-name="${escapeHtml(h.name + ' · ' + c.name)}"
                title="Lihat snapshot ${escapeHtml(c.name)}">
                <span class="cam-img">${c.key ? `<img loading="lazy" alt="" data-at="">` : ''}<span class="cam-empty">Belum ada snapshot</span></span>
                <span class="cam-label"><span class="cam-name">${escapeHtml(c.name)}</span><span class="cam-count num"></span></span>
            </button>`).join('');
        return `
            <article class="panel hall-card" data-id="${h.id}">
                <div class="hc-head">
                    <span class="hc-swatch" style="background:${colorOf(idx)}"></span>
                    <h3>${escapeHtml(h.name)}</h3>
                    <span class="hc-time num"></span>
                </div>
                <div class="hc-count"><strong class="num">–</strong><span>orang</span></div>
                <div class="hc-error" hidden>${icon('alert')}<span></span></div>
                <div class="cams">${cams}</div>
            </article>`;
    }

    function renderHalls(data) {
        halls = data.halls || [];
        if (!halls.length) {
            signature = '';
            renderEmpty();
            return;
        }
        const sig = JSON.stringify(halls.map(h => [h.id, h.name, h.cams.map(c => c.key + c.name)]));
        if (sig !== signature) {
            signature = sig;
            $('hallGrid').innerHTML = halls.map(cardHtml).join('');
        }

        halls.forEach(h => {
            const card = document.querySelector(`.hall-card[data-id="${h.id}"]`);
            if (!card) return;
            card.querySelector('.hc-count strong').textContent = fmtCount(h.count);
            card.classList.toggle('is-stale', h.count == null);
            card.querySelector('.hc-time').textContent = h.at ? formatClock(new Date(h.at * 1000)) : '';
            const err = card.querySelector('.hc-error');
            err.hidden = !h.error;
            err.querySelector('span').textContent = h.error || '';

            h.cams.forEach(c => {
                const btn = card.querySelector(`.cam[data-key="${CSS.escape(c.key)}"]`);
                if (!btn) return;
                btn.querySelector('.cam-count').textContent = c.count == null ? (c.error ? '!' : '–') : `${fmtCount(c.count)} orang`;
                btn.title = c.error || `Lihat snapshot ${c.name}`;
                btn.classList.toggle('has-error', !!c.error);
                const img = btn.querySelector('img');
                if (img && c.at && img.dataset.at !== String(c.at)) {
                    // muat di belakang layar supaya gambar tidak berkedip
                    const pre = new Image();
                    pre.onload = () => { img.src = pre.src; img.dataset.at = String(c.at); btn.classList.add('has-img'); };
                    pre.src = snapUrl(c.key, c.at);
                }
            });
        });
    }

    async function poll() {
        try {
            const data = await api(`${API}?action=status`);
            renderHalls(data);

            $('totalNow').textContent = fmtCount(data.total);
            const enabled = halls.length;
            $('totalMeta').textContent = enabled ? `dari ${enabled} Hall yang dipantau` : 'Belum ada Hall yang dipantau';
            document.querySelector('.total-stat').classList.toggle('is-stale', data.total == null);

            const w = data.worker || {};
            if (!enabled) {
                $('banner').classList.remove('is-show');
                setLive('unknown', 'Tidak aktif');
            } else if (!w.alive) {
                showBanner('Worker penghitung tidak berjalan' +
                    (w.updatedAt ? ` (data terakhir ${formatClock(new Date(w.updatedAt * 1000))})` : '') +
                    '. Jalankan people_counter\\start.bat di server, atau cek people_counter\\counter.log.');
                setLive('offline', 'Worker berhenti');
            } else {
                $('banner').classList.remove('is-show');
                setLive('online', `Live · ${formatClock(new Date(w.updatedAt * 1000))}`);
            }
        } catch (err) {
            showBanner(`Koneksi ke server bermasalah: ${err.message}`);
            setLive('offline', 'Terputus');
        } finally {
            setTimeout(poll, document.hidden ? POLL_MS * 4 : POLL_MS);
        }
    }

    /* ---------- Grafik riwayat ---------- */

    const chart = new Chart($('chart').getContext('2d'), {
        type: 'line',
        data: { datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            parsing: false,
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            scales: {
                x: {
                    type: 'linear',
                    ticks: {
                        color: '#6e6e78', maxRotation: 0, maxTicksLimit: 8,
                        callback: (v) => new Date(v).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace('.', ':'),
                    },
                    grid: { color: 'rgba(255,255,255,0.04)' },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#6e6e78', precision: 0 },
                    grid: { color: 'rgba(255,255,255,0.06)' },
                },
            },
            plugins: {
                legend: { labels: { color: '#a4a4ad', usePointStyle: true, pointStyle: 'line' } },
                tooltip: {
                    backgroundColor: '#1b1b1f',
                    borderColor: '#3a3a42',
                    borderWidth: 1,
                    callbacks: {
                        title: (items) => items.length ? formatClock(new Date(items[0].parsed.x)) : '',
                        label: (ctx) => ` ${ctx.dataset.label}: ${fmtCount(Math.round(ctx.parsed.y))} orang (puncak ${fmtCount(ctx.raw.max)})`,
                    },
                },
            },
        },
    });

    async function loadHistory() {
        clearTimeout(historyTimer);
        try {
            const data = await api(`${API}?action=history&hours=${hours}`);
            const list = halls.length ? halls : [];
            chart.data.datasets = list.map((h, idx) => ({
                label: h.name,
                data: (data.halls?.[h.id] || []).map(r => ({ x: r[0] * 1000, y: r[1], max: r[2] })),
                borderColor: colorOf(idx),
                backgroundColor: colorOf(idx),
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                tension: 0.25,
                spanGaps: false,
            }));
            const now = Date.now();
            chart.options.scales.x.min = now - hours * 3600 * 1000;
            chart.options.scales.x.max = now;
            const hasData = chart.data.datasets.some(d => d.data.length);
            chart.options.scales.y.display = hasData;
            chart.options.plugins.legend.display = chart.data.datasets.length > 1;
            chart.update();
            $('chartEmpty').style.display = hasData ? 'none' : 'grid';
            $('chartEmpty').textContent = list.length
                ? `Belum ada data dalam ${hours} jam terakhir. Riwayat tercatat tiap menit selama worker berjalan.`
                : 'Belum ada Hall yang dipantau.';
        } catch {
            /* status poll sudah menampilkan error koneksi */
        } finally {
            historyTimer = setTimeout(loadHistory, HISTORY_MS);
        }
    }

    const group = $('rangeGroup');
    group.querySelectorAll('button').forEach(b => b.classList.toggle('is-on', Number(b.dataset.h) === hours));
    group.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-h]');
        if (!btn) return;
        hours = Number(btn.dataset.h);
        storage.set('peopleRange', hours);
        group.querySelectorAll('button').forEach(b => b.classList.toggle('is-on', b === btn));
        loadHistory();
    });

    /* ---------- Snapshot besar ---------- */

    $('hallGrid').addEventListener('click', (e) => {
        const btn = e.target.closest('.cam');
        if (!btn || !btn.classList.contains('has-img')) return;
        const img = btn.querySelector('img');
        $('snapTitle').textContent = btn.dataset.name;
        $('snapImg').src = img.src;
        $('snapImg').alt = `Snapshot ${btn.dataset.name} dengan kotak deteksi orang`;
        openModal($('snapModal'));
    });

    /* ---------- Mulai ---------- */

    (async () => {
        await poll();
        loadHistory();
    })();
})();
