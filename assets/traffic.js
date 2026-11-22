/* =====================================================
   GIIAS Monitor - halaman Traffic
   ===================================================== */
(function () {
    'use strict';

    const { api, formatClock, storage } = window.App;
    const POLL_MS = 5000;
    const $ = (id) => document.getElementById(id);

    const RX = '#22c55e';
    const TX = '#3b82f6';

    let history = [];
    let rangeMin = storage.get('trafficRange', 15);

    /** Format bps otomatis: bps / Kbps / Mbps / Gbps */
    function fmt(bps) {
        if (bps == null || isNaN(bps)) return '–';
        const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
        let i = 0, v = bps;
        while (v >= 1000 && i < units.length - 1) { v /= 1000; i++; }
        return `${v.toFixed(v >= 100 || i === 0 ? 0 : v >= 10 ? 1 : 2)} ${units[i]}`;
    }

    function fmtStat(el, bps) {
        const [val, unit] = fmt(bps).split(' ');
        el.innerHTML = unit ? `${val} <span>${unit}</span>` : val;
    }

    const chart = new Chart($('chart').getContext('2d'), {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                { label: 'Download (RX)', data: [], borderColor: RX, backgroundColor: 'rgba(34,197,94,0.12)', fill: true },
                { label: 'Upload (TX)', data: [], borderColor: TX, backgroundColor: 'rgba(59,130,246,0.10)', fill: true },
            ].map(d => ({ ...d, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3 })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    ticks: { color: '#6e6e78', maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                    grid: { color: 'rgba(255,255,255,0.04)' },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#6e6e78', callback: (v) => fmt(v) },
                    grid: { color: 'rgba(255,255,255,0.06)' },
                },
            },
            plugins: {
                legend: { labels: { color: '#a4a4ad', usePointStyle: true, pointStyle: 'line' } },
                tooltip: {
                    backgroundColor: '#1b1b1f',
                    borderColor: '#3a3a42',
                    borderWidth: 1,
                    callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${fmt(ctx.parsed.y)}` },
                },
            },
        },
    });

    function render() {
        const cutoff = Date.now() - rangeMin * 60 * 1000;
        const points = history.filter(p => p.t >= cutoff);

        chart.data.labels = points.map(p => formatClock(new Date(p.t)));
        chart.data.datasets[0].data = points.map(p => p.rx);
        chart.data.datasets[1].data = points.map(p => p.tx);
        chart.options.scales.y.display = points.length > 0;
        chart.update();

        const last = history[history.length - 1];
        const stale = !last || Date.now() - last.t > 60 * 1000;
        const lastText = last
            ? new Date(last.t).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).replace(/\./g, ':')
            : '';

        const empty = $('chartEmpty');
        empty.style.display = points.length ? 'none' : 'grid';
        empty.textContent = last
            ? `Tidak ada data dalam ${rangeMin >= 60 ? '1 jam' : rangeMin + ' menit'} terakhir. Data terakhir: ${lastText}.`
            : 'Belum ada data.';

        // Angka lama jangan tampil seolah-olah data terkini
        document.querySelectorAll('.stat').forEach(s => {
            s.classList.toggle('is-stale', stale);
            s.title = stale && last ? `Data terakhir ${lastText}` : '';
        });
        fmtStat($('rxNow'), stale ? null : last?.rx);
        fmtStat($('txNow'), stale ? null : last?.tx);

        const stat = (key) => {
            if (!points.length) return ['–', '–'];
            const vals = points.map(p => p[key]);
            return [fmt(vals.reduce((a, b) => a + b, 0) / vals.length), fmt(Math.max(...vals))];
        };
        const [rxAvg, rxPeak] = stat('rx');
        const [txAvg, txPeak] = stat('tx');
        $('rxAvg').textContent = rxAvg;
        $('rxPeak').textContent = rxPeak;
        $('txAvg').textContent = txAvg;
        $('txPeak').textContent = txPeak;
    }

    function setLive(state, text) {
        $('liveState').innerHTML = `<span class="dot ${state}"></span><span>${text}</span>`;
    }

    async function poll() {
        try {
            const data = await api('traffic_api.php').catch(err => {
                // status "error" dari Mikrotik tetap membawa riwayat
                if (err.data?.history) return err.data;
                throw err;
            });
            history = data.history || [];
            render();

            if (data.status === 'success') {
                $('banner').classList.remove('is-show');
                const last = history[history.length - 1];
                setLive('online', last ? `Live · ${formatClock(new Date(last.t))}` : 'Live');
            } else {
                const reason = String(data.message || 'error tidak diketahui').trim().replace(/[.\s]+$/, '');
                $('bannerText').textContent = `Mikrotik tidak dapat dihubungi (${reason}). Grafik menampilkan data terakhir yang tersimpan.`;
                $('banner').classList.add('is-show');
                setLive('offline', 'Terputus');
            }
        } catch (err) {
            $('bannerText').textContent = err.message;
            $('banner').classList.add('is-show');
            setLive('offline', 'Terputus');
        } finally {
            setTimeout(poll, document.hidden ? POLL_MS * 4 : POLL_MS);
        }
    }

    const group = $('rangeGroup');
    group.querySelectorAll('button').forEach(b => b.classList.toggle('is-on', Number(b.dataset.min) === rangeMin));
    group.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-min]');
        if (!btn) return;
        rangeMin = Number(btn.dataset.min);
        storage.set('trafficRange', rangeMin);
        group.querySelectorAll('button').forEach(b => b.classList.toggle('is-on', b === btn));
        render();
    });

    poll();
})();
