/* =====================================================
   GIIAS Monitor - halaman Kelola Hall
   ===================================================== */
(function () {
    'use strict';

    const { api, toast, confirmDialog, escapeHtml, icon } = window.App;
    const API = 'admin_api.php';

    const $ = (id) => document.getElementById(id);
    const form = $('hallForm');
    const nameEl = $('hallName');
    const urlEl = $('hallUrl');
    const petugasEl = $('hallPetugas');
    const ipRows = $('ipRows');
    const table = $('hallTable');
    const search = $('searchInput');
    const submitBtn = $('submitBtn');
    const cancelBtn = $('cancelBtn');

    let halls = [];
    let editingId = null;
    let snapshot = '';

    const IPV4 = /^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/;
    function isValidIp(ip) {
        if (IPV4.test(ip)) return true;
        // IPv6 sederhana: hanya hex & ":" dan minimal dua ":"
        return /^[0-9a-f:]+$/i.test(ip) && (ip.match(/:/g) || []).length >= 2 && ip.length <= 39;
    }

    function isValidUrl(url) {
        try {
            return ['http:', 'https:'].includes(new URL(url).protocol);
        } catch { return false; }
    }

    /* ---------- Baris IP ---------- */

    function addIpRow(label = '', ip = '', focus = false) {
        const row = document.createElement('div');
        row.className = 'ip-row';
        row.innerHTML = `
            <input class="input ip-label" type="text" maxlength="40" placeholder="Label, mis. Encoder" aria-label="Label perangkat" value="${escapeHtml(label)}">
            <input class="input ip-address num" type="text" inputmode="decimal" placeholder="192.168.1.10" aria-label="Alamat IP" value="${escapeHtml(ip)}">
            <button type="button" class="icon-btn ip-remove" title="Hapus perangkat">${icon('trash')}</button>`;
        ipRows.appendChild(row);
        if (focus) row.querySelector('.ip-label').focus();
    }

    ipRows.addEventListener('click', (e) => {
        const btn = e.target.closest('.ip-remove');
        if (!btn) return;
        btn.closest('.ip-row').remove();
        if (!ipRows.children.length) addIpRow();
    });

    ipRows.addEventListener('input', (e) => {
        if (e.target.classList.contains('ip-address')) e.target.classList.remove('is-invalid');
    });

    $('addIpBtn').addEventListener('click', () => addIpRow('', '', true));

    function collectIps() {
        return Array.from(ipRows.querySelectorAll('.ip-row')).map(row => ({
            label: row.querySelector('.ip-label').value.trim(),
            ip: row.querySelector('.ip-address').value.trim(),
            row,
        })).filter(e => e.ip || e.label);
    }

    /* ---------- Hitung orang ---------- */

    const peopleEnabled = $('peopleEnabled');
    const peopleCams = $('peopleCams');
    const camChips = $('camChips');
    const fetchCamsBtn = $('fetchCamsBtn');

    function parseCamIds(text) {
        return [...new Set(String(text).split(/[\s,;]+/).map(Number).filter(n => Number.isInteger(n) && n > 0))];
    }

    function syncPeopleOpts() {
        $('peopleOpts').hidden = !peopleEnabled.checked;
    }

    function syncChips() {
        const ids = parseCamIds(peopleCams.value);
        camChips.querySelectorAll('.cam-chip').forEach(c => {
            const on = ids.includes(Number(c.dataset.oid));
            c.classList.toggle('is-on', on);
            c.setAttribute('aria-pressed', on);
        });
    }

    peopleEnabled.addEventListener('change', syncPeopleOpts);
    peopleCams.addEventListener('input', syncChips);

    camChips.addEventListener('click', (e) => {
        const chip = e.target.closest('.cam-chip');
        if (!chip) return;
        const oid = Number(chip.dataset.oid);
        let ids = parseCamIds(peopleCams.value);
        ids = ids.includes(oid) ? ids.filter(n => n !== oid) : [...ids, oid].sort((a, b) => a - b);
        peopleCams.value = ids.join(', ');
        syncChips();
    });

    fetchCamsBtn.addEventListener('click', async () => {
        const url = urlEl.value.trim();
        form.querySelector('.field-error[data-for="people"]').textContent = '';
        if (!isValidUrl(url)) {
            setError('url', 'Isi URL stream terlebih dahulu.', urlEl);
            urlEl.focus();
            return;
        }
        fetchCamsBtn.disabled = true;
        camChips.innerHTML = '<span class="hint">Menghubungi DVR…</span>';
        try {
            const data = await api(`people_api.php?action=cameras&url=${encodeURIComponent(url)}`);
            camChips.innerHTML = data.cameras.length
                ? data.cameras.map(c => `<button type="button" class="cam-chip" data-oid="${c.oid}" aria-pressed="false"
                        title="${c.online ? 'Online' : 'Offline'} di DVR"><span class="dot ${c.online ? 'online' : 'offline'}"></span>
                        <span class="num">${c.oid}</span>${escapeHtml(c.name)}</button>`).join('')
                : '<span class="hint">DVR tidak punya kamera video.</span>';
            syncChips();
        } catch (err) {
            camChips.innerHTML = '';
            form.querySelector('.field-error[data-for="people"]').textContent = err.message;
        } finally {
            fetchCamsBtn.disabled = false;
        }
    });

    function setPeople(pc) {
        peopleEnabled.checked = !!pc?.enabled;
        peopleCams.value = pc?.cams || '';
        camChips.innerHTML = '';
        syncPeopleOpts();
    }

    /* ---------- Form ---------- */

    function formState() {
        return JSON.stringify([nameEl.value, urlEl.value, petugasEl.value, collectIps().map(e => [e.label, e.ip]),
            peopleEnabled.checked, peopleCams.value.trim()]);
    }

    function isDirty() {
        return formState() !== snapshot;
    }

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(e => { e.textContent = ''; });
        form.querySelectorAll('.is-invalid').forEach(e => e.classList.remove('is-invalid'));
    }

    function setError(key, msg, input) {
        form.querySelector(`.field-error[data-for="${key}"]`).textContent = msg;
        if (input) input.classList.add('is-invalid');
    }

    function resetForm() {
        editingId = null;
        form.reset();
        ipRows.innerHTML = '';
        addIpRow();
        setPeople(null);
        clearErrors();
        $('formTitle').textContent = 'Tambah Hall Baru';
        $('modeBadge').hidden = true;
        submitBtn.querySelector('span').textContent = 'Simpan Hall';
        cancelBtn.hidden = true;
        $('formPanel').classList.remove('is-editing');
        highlightRow(null);
        snapshot = formState();
    }

    function fillForm(hall) {
        editingId = hall.id;
        nameEl.value = hall.name;
        urlEl.value = hall.iframeUrl;
        petugasEl.value = hall.petugas || '';
        ipRows.innerHTML = '';
        (hall.ips?.length ? hall.ips : [{ label: '', ip: '' }]).forEach(e => addIpRow(e.label, e.ip));
        setPeople(hall.peopleCount);
        clearErrors();
        $('formTitle').textContent = `Edit: ${hall.name}`;
        $('modeBadge').hidden = false;
        submitBtn.querySelector('span').textContent = 'Simpan Perubahan';
        cancelBtn.hidden = false;
        $('formPanel').classList.add('is-editing');
        highlightRow(hall.id);
        snapshot = formState();
    }

    async function guardDirty() {
        if (!isDirty()) return true;
        return confirmDialog({
            title: 'Buang perubahan?',
            message: 'Ada isian form yang belum disimpan. Lanjutkan tanpa menyimpan?',
            confirmText: 'Buang perubahan',
            danger: true,
        });
    }

    function focusForm() {
        $('formPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(() => nameEl.focus({ preventScroll: true }), 250);
    }

    $('newBtn').addEventListener('click', async () => {
        if (!(await guardDirty())) return;
        resetForm();
        focusForm();
    });

    cancelBtn.addEventListener('click', async () => {
        if (!(await guardDirty())) return;
        resetForm();
    });

    nameEl.addEventListener('input', () => nameEl.classList.remove('is-invalid'));
    urlEl.addEventListener('input', () => urlEl.classList.remove('is-invalid'));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();

        const ips = collectIps();
        const payload = {
            name: nameEl.value.trim(),
            iframeUrl: urlEl.value.trim(),
            petugas: petugasEl.value.trim(),
            ips: ips.filter(x => x.ip).map(({ label, ip }) => ({ label, ip })),
            peopleCount: { enabled: peopleEnabled.checked, cams: parseCamIds(peopleCams.value).join(', ') },
        };

        let ok = true;
        if (!payload.name) { setError('name', 'Nama Hall wajib diisi.', nameEl); ok = false; }
        if (!payload.iframeUrl) { setError('url', 'URL stream wajib diisi.', urlEl); ok = false; }
        else if (!isValidUrl(payload.iframeUrl)) { setError('url', 'URL harus diawali http:// atau https://', urlEl); ok = false; }

        const seen = new Set();
        ips.forEach(({ ip, row }) => {
            const input = row.querySelector('.ip-address');
            if (!ip) { input.classList.add('is-invalid'); setError('ips', 'Alamat IP belum diisi.'); ok = false; }
            else if (!isValidIp(ip)) { input.classList.add('is-invalid'); setError('ips', `"${ip}" bukan alamat IP yang valid.`); ok = false; }
            else if (seen.has(ip)) { input.classList.add('is-invalid'); setError('ips', `IP ${ip} tercantum dua kali.`); ok = false; }
            seen.add(ip);
        });
        if (!ok) {
            form.querySelector('.is-invalid')?.focus();
            return;
        }

        const isEdit = editingId !== null;
        if (isEdit) payload.id = editingId;

        submitBtn.disabled = true;
        submitBtn.querySelector('span').textContent = 'Menyimpan…';
        try {
            const data = await api(`${API}?action=${isEdit ? 'update' : 'add'}`, { method: 'POST', body: payload });
            toast(isEdit ? `Perubahan "${payload.name}" disimpan.` : `Hall "${payload.name}" ditambahkan.`, { type: 'ok' });
            await loadHalls();
            resetForm();
            if (data.hall) flashRow(data.hall.id);
        } catch (err) {
            toast(err.message, { type: 'bad', title: 'Gagal menyimpan' });
            submitBtn.querySelector('span').textContent = isEdit ? 'Simpan Perubahan' : 'Simpan Hall';
        } finally {
            submitBtn.disabled = false;
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (isDirty()) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ---------- Tabel ---------- */

    async function loadHalls() {
        try {
            const data = await api(`${API}?action=list`);
            halls = data.halls || [];
            renderTable();
        } catch (err) {
            table.innerHTML = `<tr><td colspan="5" class="empty">Gagal memuat data: ${escapeHtml(err.message)}
                <br><button class="btn btn-secondary btn-sm" onclick="location.reload()">${icon('refresh')}Coba lagi</button></td></tr>`;
        }
    }

    function matches(hall, q) {
        if (!q) return true;
        const hay = [hall.name, hall.petugas, hall.iframeUrl, ...(hall.ips || []).flatMap(e => [e.label, e.ip])].join(' ').toLowerCase();
        return hay.includes(q);
    }

    function renderTable() {
        $('hallCount').textContent = halls.length ? `(${halls.length})` : '';
        const q = search.value.trim().toLowerCase();
        const rows = halls.filter(h => matches(h, q));

        if (!halls.length) {
            table.innerHTML = `<tr><td colspan="5" class="empty">${icon('video', 'icon-lg')}<div>Belum ada Hall.</div><div class="hint">Isi form di samping untuk menambahkan Hall pertama.</div></td></tr>`;
            return;
        }
        if (!rows.length) {
            table.innerHTML = `<tr><td colspan="5" class="empty">Tidak ada Hall yang cocok dengan "${escapeHtml(q)}".</td></tr>`;
            return;
        }

        table.innerHTML = rows.map(hall => {
            const idx = halls.indexOf(hall);
            const ips = (hall.ips || []).map(e =>
                `<span class="tag"><b>${escapeHtml(e.label || 'IP')}</b><span class="num">${escapeHtml(e.ip)}</span></span>`
            ).join('') || '<span class="muted">Tidak ada</span>';
            const pc = hall.peopleCount?.enabled
                ? `<span class="tag tag-ai" title="Hitung orang aktif">${icon('users')}<b>AI</b><span>${hall.peopleCount.cams ? 'kamera ' + escapeHtml(hall.peopleCount.cams) : 'semua kamera'}</span></span>`
                : '';
            return `
                <tr data-id="${hall.id}" class="${hall.id === editingId ? 'is-editing' : ''}">
                    <td class="col-order" data-label="Urutan">
                        <div class="order-btns">
                            <button type="button" class="order-btn" data-act="up" title="Naikkan" ${idx === 0 || q ? 'disabled' : ''}>${icon('up')}</button>
                            <button type="button" class="order-btn" data-act="down" title="Turunkan" ${idx === halls.length - 1 || q ? 'disabled' : ''}>${icon('down')}</button>
                        </div>
                    </td>
                    <td data-label="Hall">
                        <div class="hall-name">${escapeHtml(hall.name)}</div>
                        <a class="hall-url" href="${escapeHtml(hall.iframeUrl)}" target="_blank" rel="noopener noreferrer" title="Buka stream di tab baru">${escapeHtml(hall.iframeUrl)}</a>
                    </td>
                    <td data-label="Perangkat"><div class="tags">${ips}${pc}</div></td>
                    <td data-label="Petugas">${hall.petugas ? escapeHtml(hall.petugas) : '<span class="muted">–</span>'}</td>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="btn btn-secondary btn-sm" data-act="edit">${icon('edit')}Edit</button>
                            <button type="button" class="icon-btn btn-del" data-act="delete" title="Hapus ${escapeHtml(hall.name)}">${icon('trash')}</button>
                        </div>
                    </td>
                </tr>`;
        }).join('');
    }

    function highlightRow(id) {
        table.querySelectorAll('tr[data-id]').forEach(tr => tr.classList.toggle('is-editing', Number(tr.dataset.id) === id));
    }

    function flashRow(id) {
        const tr = table.querySelector(`tr[data-id="${id}"]`);
        if (!tr) return;
        tr.classList.add('is-flash');
        setTimeout(() => tr.classList.remove('is-flash'), 1600);
    }

    search.addEventListener('input', renderTable);

    table.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const id = Number(btn.closest('tr').dataset.id);
        const hall = halls.find(h => h.id === id);
        if (!hall) return;
        const act = btn.dataset.act;

        if (act === 'edit') {
            if (editingId !== id && !(await guardDirty())) return;
            fillForm(hall);
            focusForm();
        } else if (act === 'delete') {
            const ok = await confirmDialog({
                title: `Hapus ${hall.name}?`,
                message: 'Hall ini akan hilang dari layar monitoring. Tindakan ini tidak bisa dibatalkan.',
                confirmText: 'Hapus',
                danger: true,
            });
            if (!ok) return;
            try {
                await api(`${API}?action=delete`, { method: 'POST', body: { id } });
                if (editingId === id) resetForm();
                toast(`Hall "${hall.name}" dihapus.`, { type: 'ok' });
                await loadHalls();
            } catch (err) {
                toast(err.message, { type: 'bad', title: 'Gagal menghapus' });
            }
        } else if (act === 'up' || act === 'down') {
            const idx = halls.indexOf(hall);
            const swap = act === 'up' ? idx - 1 : idx + 1;
            if (swap < 0 || swap >= halls.length) return;
            const ids = halls.map(h => h.id);
            [ids[idx], ids[swap]] = [ids[swap], ids[idx]];
            try {
                await api(`${API}?action=reorder`, { method: 'POST', body: { ids } });
                await loadHalls();
                flashRow(id);
            } catch (err) {
                toast(err.message, { type: 'bad', title: 'Gagal mengubah urutan' });
            }
        }
    });

    resetForm();
    loadHalls();
})();
