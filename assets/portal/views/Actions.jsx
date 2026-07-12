import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';
import AccountTabs, { useCurrentAccount } from '../components/AccountTabs.jsx';

const ACTION_LABEL = {
  quarantine:      { txt: 'In Quarantäne verschoben', icon: '🛡' },
  undo_quarantine: { txt: 'Wiederhergestellt',         icon: '↶' },
};

const STATUS_TONE = {
  done:   { txt: 'erledigt',       cls: 'mg-pill--ok' },
  undone: { txt: 'rückgängig',     cls: 'mg-pill--muted' },
  failed: { txt: 'fehlgeschlagen', cls: 'mg-pill--err' },
};

export default function Actions() {
  const [accounts, accountId, switchAccount] = useCurrentAccount();
  const [data, setData]     = useState({ items: [], total: 0, per_page: 50 });
  const [page, setPage]     = useState(1);
  const [busy, setBusy]     = useState({});
  const [error, setError]   = useState(null);
  const [loading, setLoad]  = useState(false);
  const activeAccount = accounts.find((a) => a.id === accountId) || null;

  // Beim Postfach-Wechsel: zurueck auf Seite 1, sonst wuerde der Pager
  // Seiten fuer den falschen Log zeigen.
  useEffect(() => { setPage(1); }, [accountId]);

  const load = useCallback(async () => {
    setLoad(true); setError(null);
    try {
      const acc = accountId ? `&account_id=${accountId}` : '';
      const { status, body } = await apiGet(`actions?page=${page}&per_page=50${acc}`);
      if (status >= 400) setError('HTTP ' + status);
      else setData(body);
    } catch (e) { setError(String(e)); }
    finally { setLoad(false); }
  }, [page, accountId]);

  useEffect(() => { load(); }, [load]);

  const undo = async (id) => {
    setBusy((b) => ({ ...b, [id]: true }));
    try {
      const { status, body } = await apiPost(`actions/${id}/undo`);
      if (status !== 200 || !body.ok) {
        alert('Undo fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
      }
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 50)));

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Aktionen</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          {activeAccount
            ? <>Audit-Log für <strong>{activeAccount.label || activeAccount.host}</strong>. Bis zum jeweiligen Ablauf-Datum kannst du eine Aktion mit einem Klick wieder rückgängig machen — die Mail wandert dann zurück in den Ursprungs-Ordner.</>
            : 'Audit-Log aller Quarantäne-Verschiebungen. Bis zum jeweiligen Ablauf-Datum kannst du eine Aktion mit einem Klick wieder rückgängig machen — die Mail wandert dann zurück in den Ursprungs-Ordner.'}
        </p>
      </div>

      <AccountTabs accounts={accounts} activeId={accountId} onSwitch={switchAccount} />

      {data.retention_days && (
        <div className="mg-card mg-muted" style={{ padding: '10px 14px', fontSize: 13 }}>
          ℹ Free-Plan zeigt nur die letzten <strong>{data.retention_days} Tage</strong>. Für unbegrenzte Historie: Upgrade unter „Plan".
        </div>
      )}
      {error && <div className="mg-card mg-error">{error}</div>}
      {loading && <div className="mg-card">Lade …</div>}

      {!loading && data.items.length === 0 && (
        <div className="mg-card mg-muted">
          Noch keine Aktionen. Sobald du eine Mail aus der Inbox in die Quarantäne verschiebst, taucht sie hier auf.
        </div>
      )}

      {!loading && data.items.length > 0 && (
        <div className="mg-stack">
          {data.items.map((a) => {
            const lbl  = ACTION_LABEL[a.action] || { txt: a.action, icon: '·' };
            const tone = STATUS_TONE[a.status]  || { txt: a.status, cls: 'mg-pill--muted' };
            return (
              <div key={a.id} className="mg-card">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '1rem', flexWrap: 'wrap' }}>
                  <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                    <div style={{ fontWeight: 600 }}>
                      <span style={{ marginRight: '0.4rem' }}>{lbl.icon}</span>
                      {lbl.txt}
                      {a.actor === 'auto' && (
                        <span className="mg-pill mg-pill--muted" style={{ marginLeft: '0.5rem' }} title="Automatisch durch den Scanner ausgelöst">⚙ Auto</span>
                      )}
                    </div>
                    <div style={{ marginTop: '0.25rem', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      {a.subject_snap || <em className="mg-muted">(ohne Subject)</em>}
                    </div>
                    <div className="mg-muted mg-tiny" style={{ marginTop: '0.2rem' }}>
                      {a.from_addr_snap} · {a.source_folder} → {a.target_folder}
                      {a.verdict_score_snap !== null && <> · Score {a.verdict_score_snap} ({a.verdict_snap})</>}
                    </div>
                    {a.error_detail && (
                      <div className="mg-error mg-tiny" style={{ marginTop: '0.2rem' }}>{a.error_detail}</div>
                    )}
                  </div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', alignItems: 'flex-end' }}>
                    <span className={'mg-pill ' + tone.cls}>{tone.txt}</span>
                    <span className="mg-muted mg-tiny">{fmtDate(a.created_at)}</span>
                    {a.action === 'quarantine' && a.undo_available && (
                      <button
                        className="mg-btn"
                        disabled={!!busy[a.id]}
                        onClick={() => undo(a.id)}
                      >
                        {busy[a.id] ? '…' : '↶ Rückgängig'}
                      </button>
                    )}
                    {a.action === 'quarantine' && a.status === 'done' && !a.undo_available && a.undo_until && (
                      <span className="mg-muted mg-tiny" title={'Ablauf: ' + a.undo_until}>Undo abgelaufen</span>
                    )}
                  </div>
                </div>
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
