import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';

const STATUS_TONE = {
  unsubscribed: { label: 'abgemeldet', cls: 'mg-pill--ok' },
  blocked:      { label: 'blockiert',  cls: 'mg-pill--err' },
  failed:       { label: 'fehler',     cls: 'mg-pill--err' },
  unknown:      { label: 'unbekannt',  cls: 'mg-pill--muted' },
  smtp_not_configured: { label: 'SMTP fehlt', cls: 'mg-pill--err' },
};

export default function Newsletters() {
  const [items, setItems]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [error, setError]   = useState(null);
  const [page, setPage]     = useState(1);
  const [total, setTotal]   = useState(0);

  const load = useCallback(async () => {
    setError(null);
    try {
      const { status, body } = await apiGet(`unsubs?page=${page}&per_page=25`);
      if (status >= 400) setError('HTTP ' + status);
      else { setItems(body.items); setTotal(body.total); }
    } catch (e) { setError(String(e)); }
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const refresh = async (id) => {
    setBusy((b) => ({ ...b, [id]: 'status' }));
    try {
      await apiPost(`unsubs/${id}/status`, {});
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const totalPages = Math.max(1, Math.ceil((total || 0) / 25));

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Newsletter-Abmeldungen</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          Alles was du über den „✉ Newsletter abmelden"-Button aus der Inbox angestoßen hast.
        </p>
      </div>

      {error && <div className="mg-card mg-error">{error}</div>}

      {items === null && <div className="mg-card">Lade …</div>}

      {items && items.length === 0 && (
        <div className="mg-card mg-muted">
          Noch keine Abmeldungen. Geh in die Inbox, klick auf eine Newsletter-Mail und dann auf „✉ Newsletter abmelden".
        </div>
      )}

      {items && items.length > 0 && (
        <div className="mg-stack">
          {items.map((u) => {
            const apiPill = STATUS_TONE[u.api_status] || { label: u.api_status || '—', cls: 'mg-pill--muted' };
            const dsnPill = u.dsn_status ? (STATUS_TONE[u.dsn_status] || { label: u.dsn_status, cls: 'mg-pill--muted' }) : null;
            return (
              <div key={u.id} className="mg-card">
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <strong>{u.msg_from_name || u.msg_from_addr || '(unbekannter Absender)'}</strong>
                    <div className="mg-muted mg-tiny">{u.msg_subject || '(kein Subject)'}</div>
                  </div>
                  <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'baseline' }}>
                    <span className={'mg-pill ' + apiPill.cls}>{apiPill.label}</span>
                    {dsnPill && <span className={'mg-pill ' + dsnPill.cls}>DSN: {dsnPill.label}</span>}
                    {u.kind && <span className="mg-pill mg-pill--muted">{u.kind}{u.one_click ? ' · 1-click' : ''}</span>}
                  </div>
                </div>
                <p className="mg-muted mg-tiny" style={{ margin: '0.5rem 0 0', wordBreak: 'break-all' }}>
                  {u.target} · {fmtDate(u.created_at)}
                </p>
                {(u.kind === 'mailto' || u.api_message_id) && (
                  <div style={{ marginTop: '0.5rem' }}>
                    <button className="mg-btn" disabled={!!busy[u.id]} onClick={() => refresh(u.id)}>
                      {busy[u.id] === 'status' ? '…' : '↻ Status'}
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {totalPages > 1 && (
        <div className="mg-card" style={{ textAlign: 'center' }}>
          <button className="mg-btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹ Zurück</button>
          {' '}Seite {page} / {totalPages}{' '}
          <button className="mg-btn" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>Weiter ›</button>
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
