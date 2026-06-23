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
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2 style={{ margin: 0 }}>IMAP-Postfächer</h2>
          <button className="mg-btn mg-btn--primary" onClick={() => navigate('accounts/new')}>+ Neues Konto</button>
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
          {items.map((it) => (
            <div key={it.id} className="mg-card mg-account">
              <div className="mg-account__head">
                <div>
                  <strong>{it.label || it.host}</strong>
                  <div className="mg-muted mg-tiny">{it.username}@{it.host}:{it.port} ({it.encryption.toUpperCase()})</div>
                </div>
                <span className={'mg-pill mg-pill--' + (it.status === 'active' ? 'ok' : 'muted')}>{it.status}</span>
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
                <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => navigate(`accounts/${it.id}/edit`)}>Bearbeiten</button>
                <button className="mg-btn" disabled={!!busy[it.id]} onClick={() => remove(it.id, it.label)}>{busy[it.id] === 'del' ? '…' : 'Löschen'}</button>
              </div>
            </div>
          ))}
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
