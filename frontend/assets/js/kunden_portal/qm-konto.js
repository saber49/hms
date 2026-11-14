/* ============================================
   Kundenportal › shared dialog/toast/POST helpers + Passwort
   (start password must be changed on first login — spec §3).
   ============================================ */

async function kpSend(method, path, body) {
    try {
        const res = await fetch(KP_API + path, {
            method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + kpToken() },
            body: JSON.stringify(body || {}),
        });
        if (res.status === 401) { kpGoLogin(); return { ok: false, data: {} }; }
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    } catch (e) {
        return { ok: false, data: { error: 'Keine Verbindung' } };
    }
}

function kpToast(msg, err = false) {
    const t = document.createElement('div');
    t.className = 'kp-toast' + (err ? ' kp-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
}

/** Simple modal. onOk returns an error string (stays open) or nothing (closes). */
function kpDialog(title, html, okLabel, onOk, { pflicht = false } = {}) {
    document.getElementById('kpDlg')?.remove();
    document.body.insertAdjacentHTML('beforeend', `
        <div class="kp-dlg-overlay" id="kpDlg"><div class="kp-dlg" role="dialog" aria-modal="true">
            <div class="kp-dlg-title">${kpEsc(title)}</div>
            <div class="kp-dlg-body">${html}<div class="kp-dlg-err" id="kpDlgErr"></div></div>
            <div class="kp-dlg-actions">${pflicht ? '' : '<button class="kp-chip-btn" id="kpDlgNo">Abbrechen</button>'}<button class="kp-btn-primary" id="kpDlgOk">${kpEsc(okLabel)}</button></div>
        </div></div>`);
    const close = () => document.getElementById('kpDlg')?.remove();
    document.getElementById('kpDlgNo')?.addEventListener('click', close);
    document.getElementById('kpDlgOk').addEventListener('click', async e => {
        e.target.disabled = true;
        const err = await onOk();
        e.target.disabled = false;
        if (err) document.getElementById('kpDlgErr').textContent = err; else close();
    });
    setTimeout(() => document.querySelector('#kpDlg textarea, #kpDlg input')?.focus(), 50);
}

function kpPasswortDialog(pflicht = false) {
    kpDialog(pflicht ? 'Bitte eigenes Passwort festlegen' : 'Passwort ändern', `
        ${pflicht ? '<p class="kp-muted">Sie haben sich mit einem Start-Passwort angemeldet. Bitte legen Sie jetzt ein eigenes Passwort fest.</p>' : ''}
        <label class="kp-label">Bisheriges Passwort</label><input id="kpPwAlt" type="password" class="kp-input" autocomplete="current-password">
        <label class="kp-label">Neues Passwort (mind. 10 Zeichen, Buchstaben und Ziffern)</label><input id="kpPwNeu" type="password" class="kp-input" autocomplete="new-password">
        <label class="kp-label">Neues Passwort wiederholen</label><input id="kpPwNeu2" type="password" class="kp-input" autocomplete="new-password">`,
        'Speichern', async () => {
            const neu = document.getElementById('kpPwNeu').value;
            if (neu !== document.getElementById('kpPwNeu2').value) return 'Die neuen Passwörter stimmen nicht überein.';
            const r = await kpSend('POST', '/passwort', { alt: document.getElementById('kpPwAlt').value, neu });
            if (!r.ok) return r.data.error || 'Speichern fehlgeschlagen';
            kpToast('Passwort geändert');
            if (pflicht) { kpMe.passwort_aendern = false; kpLoad(); }
        }, { pflicht });
}
