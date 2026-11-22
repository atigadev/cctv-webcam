/* =====================================================
   GIIAS Monitor - helper JS bersama (dimuat di semua halaman)
   ===================================================== */
(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function icon(name, cls = '') {
        return `<svg class="icon ${cls}" aria-hidden="true"><use href="#i-${name}"></use></svg>`;
    }

    class ApiError extends Error {}

    /**
     * fetch JSON dengan CSRF + penanganan sesi habis.
     * 401 -> otomatis redirect ke halaman login (bukan diam-diam "offline").
     */
    async function api(url, { method = 'GET', body } = {}) {
        const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf;
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch (err) {
            throw new ApiError('Tidak dapat terhubung ke server.');
        }

        if (res.status === 401) {
            const back = encodeURIComponent(location.pathname.split('/').pop() || 'monitor3.php');
            location.href = `login.php?redirect=${back}&expired=1`;
            throw new ApiError('Sesi berakhir.');
        }
        if (res.status === 403 && res.headers.get('Content-Type')?.includes('json')) {
            const peek = await res.clone().json().catch(() => ({}));
            if (peek.code === 'password_change_required') {
                location.href = 'change_password.php?first=1';
                throw new ApiError(peek.message);
            }
        }

        let data;
        try {
            data = await res.json();
        } catch {
            throw new ApiError(`Respons server tidak valid (HTTP ${res.status}).`);
        }
        if (data.status !== 'success') {
            const err = new ApiError(data.message || `Permintaan gagal (HTTP ${res.status}).`);
            err.data = data;
            throw err;
        }
        return data;
    }

    /* ---------- Toast ---------- */
    let toastWrap;
    function toast(message, { type = 'info', title = '', timeout = 4000 } = {}) {
        if (!toastWrap) {
            toastWrap = document.createElement('div');
            toastWrap.className = 'toasts';
            toastWrap.setAttribute('role', 'status');
            toastWrap.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastWrap);
        }
        const ico = { ok: 'check', bad: 'alert', warn: 'alert' }[type] || 'bell';
        const el = document.createElement('div');
        el.className = `toast is-${type}`;
        el.innerHTML = `${icon(ico)}<div>${title ? `<div class="toast-title">${escapeHtml(title)}</div>` : ''}<div class="toast-msg">${escapeHtml(message)}</div></div>`;
        toastWrap.appendChild(el);

        const close = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 220);
        };
        el.addEventListener('click', close);
        if (timeout) setTimeout(close, timeout);
        while (toastWrap.children.length > 5) toastWrap.firstChild.remove();
        return close;
    }

    /* ---------- Modal ---------- */
    function openModal(modal, focusEl) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        modal._prevFocus = document.activeElement;
        setTimeout(() => (focusEl || modal.querySelector('input, button'))?.focus(), 30);
    }

    function closeModal(modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modal._prevFocus?.focus?.();
    }

    document.addEventListener('click', (e) => {
        const modal = e.target.closest('.modal');
        if (modal && (e.target === modal || e.target.closest('[data-close]'))) closeModal(modal);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const open = document.querySelector('.modal.is-open');
            if (open) {
                e.stopImmediatePropagation();
                closeModal(open);
            }
        }
    }, true);

    /** Dialog konfirmasi pengganti confirm() bawaan browser. */
    function confirmDialog({ title, message, confirmText = 'Ya', danger = false }) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = `
                <div class="modal-box">
                    <div class="modal-head"><h3>${escapeHtml(title)}</h3></div>
                    <div class="modal-body">${escapeHtml(message)}</div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-ghost" data-act="no">Batal</button>
                        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-act="yes">${escapeHtml(confirmText)}</button>
                    </div>
                </div>`;
            document.body.appendChild(modal);
            const done = (val) => {
                closeModal(modal);
                setTimeout(() => modal.remove(), 200);
                resolve(val);
            };
            modal.addEventListener('click', (e) => {
                const act = e.target.closest('[data-act]')?.dataset.act;
                if (act) done(act === 'yes');
                else if (e.target === modal) done(false);
            });
            modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') done(false); });
            requestAnimationFrame(() => openModal(modal, modal.querySelector('[data-act="yes"]')));
        });
    }

    /* ---------- Format waktu ---------- */
    function formatClock(date = new Date()) {
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
    }

    function formatDuration(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        if (s < 60) return `${s} dtk`;
        const m = Math.floor(s / 60);
        if (m < 60) return `${m} mnt`;
        const h = Math.floor(m / 60);
        if (h < 24) return `${h} j ${m % 60} m`;
        return `${Math.floor(h / 24)} hr ${h % 24} j`;
    }

    // Jam di top bar
    const clockEl = document.getElementById('clock');
    if (clockEl) {
        const tick = () => { clockEl.textContent = formatClock(); };
        tick();
        setInterval(tick, 1000);
    }

    const storage = {
        get(key, fallback) {
            try {
                const v = localStorage.getItem('giias.' + key);
                return v === null ? fallback : JSON.parse(v);
            } catch { return fallback; }
        },
        set(key, value) {
            try { localStorage.setItem('giias.' + key, JSON.stringify(value)); } catch { /* abaikan */ }
        },
    };

    window.App = { api, ApiError, toast, openModal, closeModal, confirmDialog, escapeHtml, icon, formatClock, formatDuration, storage };
})();
