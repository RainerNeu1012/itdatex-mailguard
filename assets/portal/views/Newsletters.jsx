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
  const [tab, setTab] = useState('subscriptions');
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Newsletter</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          Pro Absender abmelden statt pro Mail — einmal klicken reicht für alle aktuellen und künftigen Nachrichten dieses Senders.
        </p>
        <div className="mg-form__row" style={{ marginTop: '0.75rem', gap: '0.4rem' }}>
          <button
            className={'mg-btn ' + (tab === 'subscriptions' ? 'mg-btn--primary' : '')}
            onClick={() => setTab('subscriptions')}
          >Abos</button>
          <button
            className={'mg-btn ' + (tab === 'history' ? 'mg-btn--primary' : '')}
            onClick={() => setTab('history')}
          >Historie</button>
        </div>
      </div>

      {tab === 'subscriptions' ? <Subscriptions /> : <History />}
    </div>
  );
}

function Subscriptions() {
  const [items, setItems] = useState(null);
  const [busy, setBusy]   = useState({});
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const { status, body } = await apiGet('subscriptions');
      if (status >= 400) setError('HTTP ' + status);
      else setItems(body.items);
    } catch (e) { setError(String(e)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const unsub = async (from_addr) => {
    setBusy((b) => ({ ...b, [from_addr]: 'unsub' }));
    try {
      const { body } = await apiPost('subscriptions/unsubscribe', { from_addr });
      const msg = body.ok
        ? `✔ Abgemeldet (${body.api && body.api.status})`
        : `Status: ${body.api && body.api.status || body.error || 'unbekannt'}`;
      alert(msg);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  if (error)        return <div className="mg-card mg-error">{error}</div>;
  if (items === null) return <div className="mg-card">Lade …</div>;
  if (items.length === 0) {
    return (
      <div className="mg-card mg-muted">
        Aktuell keine Newsletter mit erkanntem List-Unsubscribe-Header. Sobald solche Mails ankommen, tauchen sie hier auf.
      </div>
    );
  }

  return (
    <div className="mg-stack">
      {items.map((s) => {
        const lu = s.last_unsub;
        const apiPill = lu ? (STATUS_TONE[lu.api_status] || { label: lu.api_status || '—', cls: 'mg-pill--muted' }) : null;
        const dsnPill = lu && lu.dsn_status ? (STATUS_TONE[lu.dsn_status] || { label: lu.dsn_status, cls: 'mg-pill--muted' }) : null;
        const unsubscribed = lu && lu.api_status === 'unsubscribed';
        return (
          <div key={s.from_addr} className="mg-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <strong>{s.from_name || s.from_addr}</strong>
                <div className="mg-muted mg-tiny">{s.from_addr}</div>
                <div className="mg-muted mg-tiny" style={{ marginTop: '0.25rem' }}>
                  {s.msg_count} Mail{s.msg_count === 1 ? '' : 's'} · letzte: {fmtDate(s.latest_at)}
                </div>
              </div>
              <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'flex-start' }}>
                {apiPill && <span className={'mg-pill ' + apiPill.cls}>{apiPill.label}</span>}
                {dsnPill && <span className={'mg-pill ' + dsnPill.cls}>DSN: {dsnPill.label}</span>}
              </div>
            </div>
            <div className="mg-account__actions" style={{ marginTop: '0.5rem' }}>
              {unsubscribed ? (
                <button className="mg-btn" disabled>✔ Bereits abgemeldet</button>
              ) : (
                <button
                  className="mg-btn mg-btn--primary"
                  disabled={!!busy[s.from_addr]}
                  onClick={() => unsub(s.from_addr)}
                >
                  {busy[s.from_addr] === 'unsub' ? '…' : '✉ Vom Newsletter abmelden'}
                </button>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function History() {
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

  if (error)         return <div className="mg-card mg-error">{error}</div>;
  if (items === null) return <div className="mg-card">Lade …</div>;
  if (items.length === 0) {
    return <div className="mg-card mg-muted">Noch keine durchgeführten Abmeldungen.</div>;
  }

  return (
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
