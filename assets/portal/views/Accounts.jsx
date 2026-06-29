import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';
import { navigate } from '../router.js';

export default function Accounts() {
  const [items, setItems]     = useState(null);
  const [error, setError]     = useState(null);
  const [busy, setBusy]       = useState({});

  const load = useCallback(async () => {
    setError(null);
    try {
      const { status, body } = await apiGet('accounts');
      if (status === 200 && body.ok) setItems(body.items);
      else setError('HTTP ' + status);
    } catch (e) { setError(String(e)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    const onMsg = (ev) => {
      if (ev && ev.data && ev.data.type === 'mg-oauth') {
        load();
      }
    };
    window.addEventListener('message', onMsg);
    return () => window.removeEventListener('message', onMsg);
  }, [load]);

  const startOauthConnect = async (provider, accountId = null) => {
    const key = accountId || ('new-' + provider);
    setBusy((b) => ({ ...b, [key]: 'oauth' }));
    try {
      const { status, body } = await apiPost(`oauth/${provider}/start`, accountId ? { account_id: accountId } : {});
      if (status === 200 && body.ok && body.authorize_url) {
        const w = 520, h = 720;
        const left = window.screenX + (window.outerWidth - w) / 2;
        const top  = window.screenY + (window.outerHeight - h) / 2;
        window.open(body.authorize_url, 'mg-oauth-' + provider, `width=${w},height=${h},left=${left},top=${top}`);
      } else {
        alert(body.message || body.error || ('HTTP ' + status));
      }
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[key]; return n; });
    }
  };

  const startMicrosoftConnect = (accountId = null) => startOauthConnect('microsoft', accountId);
  const startGoogleConnect    = (accountId = null) => startOauthConnect('google', accountId);

  const disconnectOauth = async (id, label) => {
    if (!window.confirm(`OAuth-Verbindung "${label || id}" trennen? Pull wird gestoppt.`)) return;
    setBusy((b) => ({ ...b, [id]: 'disc' }));
    try {
      await apiPost(`accounts/${id}/oauth/disconnect`);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const test = async (id) => {
    setBusy((b) => ({ ...b, [id]: 'test' }));
    try {
      const { body } = await apiPost(`accounts/${id}/test`);
      const msg = body.ok
        ? `✔ Verbindung steht (${body.probe.messages} Mails in ${body.probe.folder}).`
        : `✘ ${body.detail || body.error || 'Fehler'}`;
      alert(msg);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const remove = async (id, label) => {
    if (!window.confirm(`Konto "${label || id}" wirklich löschen?`)) return;
    setBusy((b) => ({ ...b, [id]: 'del' }));
    try {
      await fetch(((window.itdatexMailguard || {}).restUrl || '') + `accounts/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
      });
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
          <h2 style={{ margin: 0 }}>IMAP-Postfächer</h2>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button
              className="mg-btn"
              disabled={busy['new-microsoft'] === 'oauth'}
              onClick={() => startMicrosoftConnect()}
              title="Outlook.com, Office 365, Microsoft 365 Family mit eigener Domain"
            >
              {busy['new-microsoft'] === 'oauth' ? '…' : '🔑 Mit Microsoft verbinden'}
            </button>
            <button
              className="mg-btn"
              disabled={busy['new-google'] === 'oauth'}
              onClick={() => startGoogleConnect()}
              title="Gmail, Google Workspace"
            >
              {busy['new-google'] === 'oauth' ? '…' : '🔑 Mit Google verbinden'}
            </button>
            <button className="mg-btn mg-btn--primary" onClick={() => navigate('accounts/new')}>+ IMAP manuell</button>
          </div>
        </div>
        <p className="mg-muted">Hier verbindest du deine Mail-Postfächer. Wir holen die Header per IMAP ab und scannen sie auf Phishing/Spam — die Mails bleiben in deinem Postfach.</p>
      </div>

      {error && <div className="mg-card mg-error">{error}</div>}

      {items === null && <div className="mg-card">Lade …</div>}

      {items && items.length === 0 && (
        <div className="mg-card mg-muted">
          Noch keine Konten. Leg eins an, um loszulegen.
        </div>
      )}

      {items && items.length > 0 && (
        <div className="mg-stack">
          {items.map((it) => {
            const isOauth = it.auth_type && it.auth_type !== 'basic';
            return (
            <div key={it.id} className="mg-card mg-account">
              <div className="mg-account__head">
                <div>
                  <strong>{it.label || it.host}</strong>
                  <div className="mg-muted mg-tiny">
                    {it.username}{!isOauth && `@${it.host}:${it.port} (${it.encryption.toUpperCase()})`}
                  </div>
                </div>
                <div style={{ display: 'flex', gap: 6 }}>
                  {isOauth && (
                    <span className="mg-pill mg-pill--ok" title={it.oauth_provider}>
                      {it.oauth_provider === 'microsoft' ? 'Microsoft OAuth'
                        : it.oauth_provider === 'google' ? 'Google OAuth'
                        : (it.oauth_provider + ' OAuth')}
                    </span>
                  )}
                  <span className={'mg-pill mg-pill--' + (it.status === 'active' ? 'ok' : 'muted')}>{it.status}</span>
                </div>
              </div>
              <div className="mg-account__test">
                {it.last_test_at ? (
                  <span className={it.last_test_ok ? 'mg-ok' : 'mg-err-inline'}>
                    {it.last_test_ok ? '✔' : '✘'} {it.last_test_detail || ''} <span className="mg-muted mg-tiny">({fmtDate(it.last_test_at)})</span>
                  </span>
                ) : (
                  <span className="mg-muted">noch nicht getestet</span>
                )}
              </div>
              <div className="mg-account__actions">
                <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => test(it.id)}>{busy[it.id] === 'test' ? '…' : '↻ Test'}</button>
                {isOauth ? (
                  <>
                    <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => startOauthConnect(it.oauth_provider || 'microsoft', it.id)}>
                      {busy[it.id] === 'oauth' ? '…' : '↻ Neu verbinden'}
                    </button>
                    <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => disconnectOauth(it.id, it.label)}>
                      {busy[it.id] === 'disc' ? '…' : 'OAuth trennen'}
                    </button>
                  </>
                ) : (
                  <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => navigate(`accounts/${it.id}/edit`)}>Bearbeiten</button>
                )}
                <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => remove(it.id, it.label)}>{busy[it.id] === 'del' ? '…' : 'Löschen'}</button>
              </div>
              <Folders accountId={it.id} />
            </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function Folders({ accountId }) {
  const [items, setItems]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [picker, setPicker] = useState(null); // {available: [], selected: Set}

  const load = useCallback(async () => {
    const { status, body } = await apiGet(`accounts/${accountId}/folders`);
    if (status === 200 && body.ok) setItems(body.items);
  }, [accountId]);

  useEffect(() => { load(); }, [load]);

  const openPicker = async () => {
    setBusy((b) => ({ ...b, _disc: 1 }));
    try {
      const { status, body } = await apiGet(`accounts/${accountId}/folders/discover`);
      if (status === 200 && body.ok) {
        setPicker({ available: body.items, selected: new Set() });
      } else {
        alert(body.detail || body.error || ('HTTP ' + status));
      }
    } finally {
      setBusy((b) => { const n = { ...b }; delete n._disc; return n; });
    }
  };

  const togglePick = (name) => {
    setPicker((p) => {
      const s = new Set(p.selected);
      if (s.has(name)) s.delete(name); else s.add(name);
      return { ...p, selected: s };
    });
  };

  const savePicked = async () => {
    const folders = Array.from(picker.selected);
    if (folders.length === 0) { setPicker(null); return; }
    setBusy((b) => ({ ...b, _save: 1 }));
    try {
      await apiPost(`accounts/${accountId}/folders`, { folders });
      setPicker(null);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n._save; return n; });
    }
  };

  const test = async (fid) => {
    setBusy((b) => ({ ...b, [fid]: 'test' }));
    try {
      const { body } = await apiPost(`folders/${fid}/test`);
      alert(body.ok ? `✔ ${body.probe.messages} Mails in ${body.probe.folder}` : `✘ ${body.detail || body.error}`);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[fid]; return n; });
    }
  };

  const pull = async (fid) => {
    setBusy((b) => ({ ...b, [fid]: 'pull' }));
    try {
      const { body } = await apiPost(`folders/${fid}/pull`);
      alert(body.ok ? `+${body.fetched} neu, ${body.duplicates} dup, ${body.errors} Fehler` : `Fehler: ${body.detail || body.error}`);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[fid]; return n; });
    }
  };

  const remove = async (fid, name) => {
    if (!window.confirm(`Folder "${name}" entfernen? Pull stoppt, bestehende Mails bleiben.`)) return;
    setBusy((b) => ({ ...b, [fid]: 'del' }));
    try {
      await fetch(((window.itdatexMailguard || {}).restUrl || '') + `folders/${fid}`, {
        method: 'DELETE', credentials: 'same-origin',
      });
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[fid]; return n; });
    }
  };

  if (items === null) return null;

  return (
    <div className="mg-folders">
      <div className="mg-folders__head">
        <strong className="mg-tiny">📁 Folder ({items.length})</strong>
        <button className="mg-btn mg-tiny" disabled={!!busy._disc} onClick={openPicker}>
          {busy._disc ? '…' : '+ Folder hinzufügen'}
        </button>
      </div>
      {items.length === 0 && <div className="mg-muted mg-tiny">Noch keine Folder — klick „+ Folder hinzufügen", wir listen alle verfügbaren auf.</div>}
      {items.map((f) => (
        <div key={f.id} className="mg-folder-row">
          <span className="mg-folder-name">{f.folder_name}</span>
          <span className="mg-muted mg-tiny">
            uid={f.last_uid}
            {f.last_test_at && ' · '}
            {f.last_test_at && (f.last_test_ok ? '✔' : '✘')}
            {f.last_test_detail && ` ${f.last_test_detail}`}
          </span>
          <span className="mg-folder-actions">
            <button className="mg-btn mg-tiny" disabled={!!busy[f.id]} onClick={() => test(f.id)}>{busy[f.id] === 'test' ? '…' : 'Test'}</button>
            <button className="mg-btn mg-tiny" disabled={!!busy[f.id]} onClick={() => pull(f.id)}>{busy[f.id] === 'pull' ? '…' : 'Pull'}</button>
            <button className="mg-btn mg-tiny" disabled={!!busy[f.id]} onClick={() => remove(f.id, f.folder_name)}>{busy[f.id] === 'del' ? '…' : '×'}</button>
          </span>
        </div>
      ))}

      {picker && (
        <div className="mg-modal-overlay" onClick={() => setPicker(null)}>
          <div className="mg-modal" onClick={(e) => e.stopPropagation()}>
            <h3>Folder im Postfach</h3>
            <p className="mg-muted mg-tiny">Häkchen setzen für die Folder, die MailGuard zusätzlich abrufen soll.</p>
            <div className="mg-folder-pick-list">
              {picker.available.map((f) => (
                <label key={f.name} className={'mg-folder-pick' + (f.configured ? ' mg-folder-pick--done' : '')}>
                  <input
                    type="checkbox"
                    disabled={f.configured}
                    checked={f.configured || picker.selected.has(f.name)}
                    onChange={() => togglePick(f.name)}
                  />
                  <span>{f.name}</span>
                  {f.configured && <span className="mg-pill mg-pill--muted">aktiv</span>}
                </label>
              ))}
              {picker.available.length === 0 && <div className="mg-muted">Keine Folder gefunden — Verbindung okay?</div>}
            </div>
            <div className="mg-form__row" style={{ justifyContent: 'flex-end', marginTop: 12 }}>
              <button className="mg-btn" onClick={() => setPicker(null)}>Abbrechen</button>
              <button className="mg-btn mg-btn--primary" disabled={!!busy._save || picker.selected.size === 0} onClick={savePicked}>
                {busy._save ? '…' : `${picker.selected.size} hinzufügen`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}
