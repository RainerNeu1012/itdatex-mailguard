import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';
import { VerdictBadge, ReasonsList } from '../components/Verdict.jsx';

export default function Inbox() {
  const [accounts, setAccounts] = useState([]);
  const [filter, setFilter]     = useState({ account_id: 0, unsub_only: 0, verdict: '', q: '', page: 1 });
  const [busy, setBusy]         = useState({});
  const [data, setData]         = useState({ items: [], total: 0, per_page: 25 });
  const [stats, setStats]       = useState(null);
  const [loading, setLoading]   = useState(false);
  const [pulling, setPulling]   = useState(false);
  const [expanded, setExpanded] = useState(null);
  const [error, setError]       = useState(null);

  const loadAccounts = useCallback(async () => {
    const { body } = await apiGet('accounts');
    if (body && body.ok) setAccounts(body.items);
  }, []);

  const loadInbox = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = new URLSearchParams();
      if (filter.account_id) qs.set('account_id', filter.account_id);
      if (filter.unsub_only)  qs.set('unsub_only', '1');
      if (filter.verdict)     qs.set('verdict', filter.verdict);
      if (filter.q)           qs.set('q', filter.q);
      qs.set('page', filter.page);
      qs.set('per_page', 25);
      const [{ body: listBody, status }, { body: statBody }] = await Promise.all([
        apiGet('inbox/messages?' + qs.toString()),
        apiGet('inbox/stats'),
      ]);
      if (status >= 400) {
        setError('HTTP ' + status);
      } else {
        setData(listBody);
        if (statBody && statBody.ok) setStats(statBody.stats);
      }
    } catch (e) {
      setError(String(e));
    } finally {
      setLoading(false);
    }
  }, [filter]);

  useEffect(() => { loadAccounts(); }, [loadAccounts]);
  useEffect(() => { loadInbox();    }, [loadInbox]);

  const pullAll = async () => {
    if (!accounts.length) { alert('Erst ein Postfach anlegen.'); return; }
    setPulling(true);
    try {
      const results = [];
      for (const a of accounts.filter((x) => x.status === 'active')) {
        const { body } = await apiPost(`accounts/${a.id}/pull`);
        results.push(`${a.label || a.host}: ` + (body.ok ? `+${body.fetched} (dup ${body.duplicates})` : `Fehler ${body.error}`));
      }
      alert(results.join('\n'));
      loadInbox();
    } finally {
      setPulling(false);
    }
  };

  const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 25)));

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '1rem', flexWrap: 'wrap' }}>
          <div>
            <h2 style={{ margin: '0 0 0.25rem' }}>Inbox</h2>
            <p className="mg-muted" style={{ margin: 0 }}>Eingehende Mails aller verbundenen Postfächer.</p>
          </div>
          <button className="mg-btn mg-btn--primary" disabled={pulling} onClick={pullAll}>
            {pulling ? '…' : '↓ Jetzt abholen'}
          </button>
        </div>
      </div>

      {stats && (
        <div className="mg-card mg-stats">
          <Stat label="Mails"          value={stats.total} />
          <Stat label="Newsletter"     value={stats.has_unsub} />
          <Stat label="Sauber"         value={stats.clean}      tone="ok" />
          <Stat label="Verdächtig"     value={stats.suspicious} tone="susp" />
          <Stat label="Gefährlich"     value={stats.dangerous}  tone="danger" />
          <Stat label="Scan offen"     value={stats.pending_scan} tone="muted" />
        </div>
      )}

      <div className="mg-card">
        <div className="mg-form__row">
          <label style={{ flex: 1 }}>Konto
            <select value={filter.account_id} onChange={(e) => setFilter({ ...filter, account_id: parseInt(e.target.value, 10), page: 1 })}>
              <option value={0}>— alle —</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.label || a.host}</option>)}
            </select>
          </label>
          <label style={{ flex: 1 }}>Verdict
            <select value={filter.verdict} onChange={(e) => setFilter({ ...filter, verdict: e.target.value, page: 1 })}>
              <option value="">— alle —</option>
              <option value="risky">⚠ verdächtig/gefährlich</option>
              <option value="dangerous">gefährlich</option>
              <option value="suspicious">verdächtig</option>
              <option value="clean">sauber</option>
              <option value="unscanned">noch nicht gescannt</option>
            </select>
          </label>
          <label style={{ flex: 2 }}>Suche (Subject/Absender)
            <input type="search" value={filter.q} onChange={(e) => setFilter({ ...filter, q: e.target.value, page: 1 })} />
          </label>
          <label style={{ flexBasis: '100%' }}>
            <input type="checkbox" checked={!!filter.unsub_only} onChange={(e) => setFilter({ ...filter, unsub_only: e.target.checked ? 1 : 0, page: 1 })} />
            {' '}Nur Newsletter (List-Unsubscribe vorhanden)
          </label>
        </div>
      </div>

      {error && <div className="mg-card mg-error">{error}</div>}

      {loading && <div className="mg-card">Lade …</div>}

      {!loading && data.items.length === 0 && (
        <div className="mg-card mg-muted">
          Keine Mails. Tipp: erst „↓ Jetzt abholen" klicken, dann zeigen wir hier alles was reinkommt.
        </div>
      )}

      {!loading && data.items.length > 0 && (
        <div className="mg-stack">
          {data.items.map((m) => (
            <Row
              key={m.id}
              m={m}
              expanded={expanded === m.id}
              busy={busy[m.id]}
              onToggle={() => setExpanded(expanded === m.id ? null : m.id)}
              onRescan={async () => {
                setBusy((b) => ({ ...b, [m.id]: 'rescan' }));
                try {
                  await apiPost(`inbox/messages/${m.id}/rescan`);
                  loadInbox();
                } finally {
                  setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; });
                }
              }}
              onUnsub={async () => {
                if (m.scan_verdict === 'dangerous' && !window.confirm('Diese Mail ist als Phishing eingestuft. Trotzdem auf den Abmelde-Link klicken? (Empfehlung: NICHT)')) {
                  return;
                }
                setBusy((b) => ({ ...b, [m.id]: 'unsub' }));
                try {
                  const { body } = await apiPost(`inbox/messages/${m.id}/unsubscribe`, {});
                  const msg = body.ok
                    ? `✔ Abgemeldet (${body.api && body.api.status})`
                    : `Status: ${body.api && body.api.status || body.error || 'unbekannt'}`;
                  alert(msg);
                  loadInbox();
                } finally {
                  setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; });
                }
              }}
              onQuarantine={async () => {
                const score = m.scan_score;
                // Sicherheitsdialog: bei Score < 70 oder unbekannt explizit bestätigen,
                // damit ein versehentlicher Klick keine saubere Mail aus der Inbox kippt.
                if ((score === null || score < 70) && !window.confirm(
                  'Diese Mail ist NICHT eindeutig als gefährlich eingestuft (Score ' + (score ?? '–') + '). Trotzdem in den Quarantäne-Ordner verschieben?'
                )) {
                  return;
                }
                setBusy((b) => ({ ...b, [m.id]: 'quarantine' }));
                try {
                  const { body, status } = await apiPost(`inbox/messages/${m.id}/quarantine`);
                  if (status === 200 && body.ok) {
                    // Undo-Toast: 10 Sekunden zum sofortigen Rückgängig-Machen.
                    const undo = window.confirm('Mail in Quarantäne verschoben.\n\nOK = Aktion belassen.\nAbbrechen = sofort rückgängig machen.');
                    if (!undo && body.action_id) {
                      await apiPost(`actions/${body.action_id}/undo`);
                    }
                  } else {
                    alert('Quarantäne fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
                  }
                  loadInbox();
                } finally {
                  setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; });
                }
              }}
              onUndoQuarantine={async () => {
                if (!m.quarantine_action_id) return;
                setBusy((b) => ({ ...b, [m.id]: 'undo' }));
                try {
                  const { body, status } = await apiPost(`actions/${m.quarantine_action_id}/undo`);
                  if (status !== 200 || !body.ok) {
                    alert('Wiederherstellen fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
                  }
                  loadInbox();
                } finally {
                  setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; });
                }
              }}
            />
          ))}
        </div>
      )}

      {totalPages > 1 && (
        <div className="mg-card" style={{ textAlign: 'center' }}>
          <button className="mg-btn" disabled={filter.page <= 1} onClick={() => setFilter({ ...filter, page: filter.page - 1 })}>‹ Zurück</button>
          {' '}Seite {filter.page} / {totalPages}{' '}
          <button className="mg-btn" disabled={filter.page >= totalPages} onClick={() => setFilter({ ...filter, page: filter.page + 1 })}>Weiter ›</button>
        </div>
      )}
    </div>
  );
}

function Stat({ label, value, tone }) {
  return (
    <div className={'mg-stat mg-stat--' + (tone || 'default')}>
      <div className="mg-stat__value">{value ?? 0}</div>
      <div className="mg-stat__label">{label}</div>
    </div>
  );
}

function Row({ m, expanded, busy, onToggle, onRescan, onUnsub, onQuarantine, onUndoQuarantine }) {
  const dangerous    = m.scan_verdict === 'dangerous';
  const quarantined  = !!m.quarantine_action_id;
  return (
    <div className={'mg-card mg-mail' + (dangerous ? ' mg-mail--danger' : '') + (quarantined ? ' mg-mail--quarantined' : '')}>
      <div className="mg-mail__head" onClick={onToggle} role="button" tabIndex={0}>
        <div className="mg-mail__from">
          <strong>{m.from_name || m.from_addr || '(unbekannt)'}</strong>
          {m.from_name && m.from_addr && <span className="mg-muted mg-tiny"> · {m.from_addr}</span>}
        </div>
        <div className="mg-mail__subject">{m.subject || '(kein Subject)'}</div>
        <div className="mg-mail__meta">
          <VerdictBadge verdict={m.scan_verdict} score={m.scan_score} status={m.scan_status} />
          {quarantined && <span className="mg-pill mg-pill--muted" title="In Quarantäne verschoben">🛡 Quarantäne</span>}
          {m.has_unsub && (m.sender_unsubscribed
            ? <span className="mg-pill mg-pill--muted" title="Sender wurde bereits abgemeldet">✓ abgemeldet</span>
            : <span className="mg-pill mg-pill--ok">Newsletter</span>)}
          <span className="mg-muted mg-tiny">{fmtDate(m.date_hdr || m.fetched_at)}</span>
        </div>
      </div>
      {expanded && (
        <div className="mg-mail__body">
          <p style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{m.body_preview || <em className="mg-muted">(keine Vorschau)</em>}</p>
          {Array.isArray(m.scan_reasons) && m.scan_reasons.length > 0 && (
            <>
              <p className="mg-muted mg-tiny" style={{ margin: '0.75rem 0 0.25rem' }}>Scan-Treffer:</p>
              <ReasonsList reasons={m.scan_reasons} />
            </>
          )}
          <div style={{ marginTop: '0.75rem', display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <button className="mg-btn" disabled={!!busy} onClick={(e) => { e.stopPropagation(); onRescan(); }}>
              {busy === 'rescan' ? '…' : '↻ Erneut scannen'}
            </button>
            {m.has_unsub && !m.sender_unsubscribed && (
              <button className="mg-btn mg-btn--primary" disabled={!!busy} onClick={(e) => { e.stopPropagation(); onUnsub(); }}>
                {busy === 'unsub' ? '…' : '✉ Newsletter abmelden'}
              </button>
            )}
            {m.has_unsub && m.sender_unsubscribed && (
              <span className="mg-muted mg-tiny" style={{ alignSelf: 'center' }}>Sender bereits abgemeldet — siehe Newsletter-Page</span>
            )}
            {!quarantined && (
              <button className="mg-btn mg-btn--danger" disabled={!!busy} onClick={(e) => { e.stopPropagation(); onQuarantine(); }}>
                {busy === 'quarantine' ? '…' : '🛡 In Quarantäne verschieben'}
              </button>
            )}
            {quarantined && (
              <button className="mg-btn" disabled={!!busy} onClick={(e) => { e.stopPropagation(); onUndoQuarantine(); }}>
                {busy === 'undo' ? '…' : '↶ Aus Quarantäne wiederherstellen'}
              </button>
            )}
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
