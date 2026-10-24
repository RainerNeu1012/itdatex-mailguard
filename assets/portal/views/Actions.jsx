import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';
import AccountTabs, { useCurrentAccount } from '../components/AccountTabs.jsx';
import { PageShell, PageTopbar, Section, SectionHeader, HairlineList, HairlineRow } from '../components/Section.jsx';

const ACTION_TAG = {
  quarantine:      'QUARANTINE',
  undo_quarantine: 'UNDO',
  purge:           'PURGE',
  move:            'MOVE',
};

export default function Actions({ me }) {
  const [accounts, accountId, switchAccount] = useCurrentAccount();
  const [data, setData]     = useState({ items: [], total: 0, per_page: 50 });
  const [page, setPage]     = useState(1);
  const [busy, setBusy]     = useState({});
  const [error, setError]   = useState(null);
  const [loading, setLoad]  = useState(false);
  const activeAccount = accounts.find((a) => a.id === accountId) || null;

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
        setError('Undo fehlgeschlagen: ' + (body.error || status) + (body.detail ? ' — ' + body.detail : ''));
      }
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 50)));
  const now = Date.now();

  return (
    <PageShell>
      <PageTopbar kicker="MAILGUARD · AKTIONEN" user={me?.email} />

      <Section>
        <SectionHeader
          num="001"
          title={activeAccount ? `AUDIT-LOG · ${(activeAccount.label || activeAccount.host || '').toUpperCase()}` : 'AUDIT-LOG · ALLE POSTFÄCHER'}
          counter={data.total ? `${num(data.total)} GESAMT` : null}
        />
        <AccountTabs accounts={accounts} activeId={accountId} onSwitch={switchAccount} />

        {data.retention_days && (
          <div className="mg-dash__notice mg-mono">
            FREE-PLAN · NUR LETZTE {data.retention_days} TAGE SICHTBAR
          </div>
        )}
        {error && <div className="mg-dash__err">{error}</div>}
        {loading && <div className="mg-dash__empty">LADE …</div>}

        {!loading && data.items.length === 0 && (
          <div className="mg-dash__empty">
            Noch keine Aktionen protokolliert.
          </div>
        )}

        {!loading && data.items.length > 0 && (
          <HairlineList variant="log">
            {data.items.map((a) => {
              const canUndo = a.action === 'quarantine' && a.undo_available;
              const tag = ACTION_TAG[a.action] || a.action.toUpperCase();
              return (
                <HairlineRow key={a.id} variant="log">
                  <span className="mg-dash__row-time mg-mono">{fmtShortTime(a.created_at, now)}</span>
                  <span className={'mg-dash__action-tag mg-dash__action-tag--' + a.action}>{tag}</span>
                  <span className="mg-dash__row-label mg-mono mg-dash__row-label--mono">{a.from_addr_snap || '—'}</span>
                  <span className="mg-dash__row-sub">{a.subject_snap || <em style={{ opacity: 0.5 }}>(ohne Subject)</em>}</span>
                  {a.verdict_score_snap !== null && (
                    <span className={'mg-dash__row-tag mg-mono mg-dash__verdict-' + (a.verdict_snap || 'clean')}>
                      {(a.verdict_snap || '').toUpperCase()} · {a.verdict_score_snap}
                    </span>
                  )}
                  {a.actor === 'auto' && <span className="mg-dash__row-tag mg-mono">AUTO</span>}
                  {canUndo && (
                    <button className="mg-btn" style={{ fontSize: '0.75rem', padding: '0.3rem 0.7rem' }} disabled={!!busy[a.id]} onClick={() => undo(a.id)}>
                      {busy[a.id] ? '…' : '↶ UNDO'}
                    </button>
                  )}
                </HairlineRow>
              );
            })}
          </HairlineList>
        )}
      </Section>

      {totalPages > 1 && (
        <div className="mg-dash__pager mg-mono">
          <button className="mg-btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹ ZURÜCK</button>
          <span>SEITE {page} / {totalPages}</span>
          <button className="mg-btn" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>WEITER ›</button>
        </div>
      )}
    </PageShell>
  );
}

function num(n) { return n == null ? '—' : Number(n).toLocaleString('de-DE'); }

function fmtShortTime(s, now = Date.now()) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return s;
  const diff = (now - d.getTime()) / 1000;
  if (diff < 60) return 'JUST NOW';
  if (diff < 3600) return Math.floor(diff / 60) + 'M AGO';
  const h = String(d.getUTCHours()).padStart(2, '0');
  const m = String(d.getUTCMinutes()).padStart(2, '0');
  const sameDay = (new Date().toISOString().slice(0, 10)) === d.toISOString().slice(0, 10);
  return sameDay ? `${h}:${m}` : `${d.toISOString().slice(5, 10).replace('-', '/')} ${h}:${m}`;
}
