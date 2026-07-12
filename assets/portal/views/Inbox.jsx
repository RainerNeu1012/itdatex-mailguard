import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import { apiGet, apiPost } from '../api.js';
import { VerdictBadge, ReasonsList } from '../components/Verdict.jsx';
import AccountTabs, { loadStoredAccountId, saveStoredAccountId } from '../components/AccountTabs.jsx';

const TAB_STORAGE_KEY = 'mg_inbox_tab';

function loadTab() {
  try {
    const v = localStorage.getItem(TAB_STORAGE_KEY);
    return v === 'senders' ? 'senders' : 'chrono';
  } catch { return 'chrono'; }
}
function saveTab(v) {
  try { localStorage.setItem(TAB_STORAGE_KEY, v); } catch { /* ignore */ }
}

const EMPTY_FILTER = { account_id: 0, unsub_only: 0, verdict: '', q: '', page: 1, hide_quarantine: 1 };

export default function Inbox() {
  const [accounts, setAccounts]     = useState([]);
  const [filter, setFilter]         = useState(EMPTY_FILTER);
  const [tab, setTab]               = useState(loadTab);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [stats, setStats]           = useState(null);
  const [pulling, setPulling]       = useState(false);

  const loadAccounts = useCallback(async () => {
    const { body } = await apiGet('accounts');
    if (body && body.ok) setAccounts(body.items);
  }, []);

  const loadStats = useCallback(async (accountId) => {
    const qs = accountId ? `?account_id=${accountId}` : '';
    const { body } = await apiGet('inbox/stats' + qs);
    if (body && body.ok) setStats(body.stats);
  }, []);

  useEffect(() => { loadAccounts(); }, [loadAccounts]);

  // Sobald Accounts geladen sind: aktives Konto festlegen — gespeicherte Auswahl,
  // sonst erstes aktives Konto. Kein "alle Konten"-Modus mehr, damit Mails aus
  // verschiedenen Postfaechern nicht vermischt in einer Ansicht auftauchen.
  useEffect(() => {
    if (!accounts.length) return;
    if (filter.account_id && accounts.some((a) => a.id === filter.account_id)) return;
    const stored  = loadStoredAccountId();
    const preferred = stored && accounts.some((a) => a.id === stored) ? stored : (accounts.find((a) => a.status === 'active') || accounts[0]).id;
    setFilter((f) => ({ ...f, account_id: preferred, page: 1 }));
  }, [accounts]);

  // Stats folgen dem aktiven Konto.
  useEffect(() => { loadStats(filter.account_id); }, [loadStats, filter.account_id]);

  const switchAccount = (id) => {
    if (id === filter.account_id) return;
    saveStoredAccountId(id);
    setFilter((f) => ({ ...f, account_id: id, page: 1 }));
  };

  const switchTab = (t) => {
    if (t === tab) return;
    setTab(t);
    saveTab(t);
    setFilter((f) => ({ ...f, page: 1 }));
  };

  const activeAccount     = accounts.find((a) => a.id === filter.account_id) || null;
  const activeFilterCount = countActive(filter);

  const pullAll = async () => {
    if (!accounts.length) { alert('Erst ein Postfach anlegen.'); return; }
    setPulling(true);
    try {
      // Nur das aktive Konto pullen, sonst waere der Button irrefuehrend:
      // Anzeige zeigt Postfach A, Button holt auch B, C, D ab.
      const active = accounts.find((a) => a.id === filter.account_id);
      if (!active) { alert('Kein Konto ausgewaehlt.'); return; }
      const { body } = await apiPost(`accounts/${active.id}/pull`);
      alert(`${active.label || active.host}: ` + (body.ok ? `+${body.fetched} (dup ${body.duplicates})` : `Fehler ${body.error}`));
      loadStats(filter.account_id);
      setFilter((f) => ({ ...f }));
    } finally {
      setPulling(false);
    }
  };

  return (
    <div className="mg-stack">
      <div className="mg-card mg-card--head">
        <div className="mg-inbox__head">
          <div>
            <h2 style={{ margin: '0 0 0.25rem' }}>Inbox</h2>
            <p className="mg-muted" style={{ margin: 0 }}>
              {activeAccount
                ? <>Eingehende Mails von <strong>{activeAccount.label || activeAccount.host}</strong>.</>
                : 'Eingehende Mails der verbundenen Postfächer.'}
            </p>
          </div>
          <button className="mg-btn mg-btn--primary" disabled={pulling || !filter.account_id} onClick={pullAll}>
            {pulling ? '…' : '↓ Jetzt abholen'}
          </button>
        </div>
      </div>

      <AccountTabs accounts={accounts} activeId={filter.account_id} onSwitch={switchAccount} />

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

      <div className="mg-card mg-toolbar">
        <div className="mg-tabs" role="tablist">
          <button
            className={'mg-tab' + (tab === 'chrono' ? ' mg-tab--active' : '')}
            role="tab" aria-selected={tab === 'chrono'}
            onClick={() => switchTab('chrono')}
          >Chronologisch</button>
          <button
            className={'mg-tab' + (tab === 'senders' ? ' mg-tab--active' : '')}
            role="tab" aria-selected={tab === 'senders'}
            onClick={() => switchTab('senders')}
          >Nach Absender</button>
          <button
            className="mg-btn mg-filter-toggle"
            aria-expanded={filtersOpen}
            onClick={() => setFiltersOpen((v) => !v)}
          >
            🔍 Filter{activeFilterCount > 0 ? ` (${activeFilterCount})` : ''}
          </button>
        </div>

        <div className={'mg-filter-sheet' + (filtersOpen ? ' mg-filter-sheet--open' : '')}>
          <div className="mg-form__row">
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
            <label className="mg-form__checkbox">
              <input type="checkbox" checked={!!filter.unsub_only} onChange={(e) => setFilter({ ...filter, unsub_only: e.target.checked ? 1 : 0, page: 1 })} />
              {' '}Nur Newsletter (List-Unsubscribe vorhanden)
            </label>
            <label className="mg-form__checkbox" title="Automatisch quarantinierte Mails aus der Liste ausblenden — 24h widerrufbar unter Aktionen.">
              <input type="checkbox" checked={!!filter.hide_quarantine} onChange={(e) => setFilter({ ...filter, hide_quarantine: e.target.checked ? 1 : 0, page: 1 })} />
              {' '}Quarantäne verbergen
            </label>
            {activeFilterCount > 0 && (
              <button className="mg-btn mg-btn--ghost" onClick={() => setFilter((f) => ({ ...EMPTY_FILTER, account_id: f.account_id }))}>
                Zurücksetzen
              </button>
            )}
          </div>
        </div>
      </div>

      {tab === 'chrono'
        ? <ChronoList filter={filter} setFilter={setFilter} onReload={loadStats} />
        : <SenderList  filter={filter} setFilter={setFilter} onReload={loadStats} />}
    </div>
  );
}

function countActive(f) {
  // account_id ist jetzt implizit im Tab, nicht mehr im Filter-Sheet — nicht zaehlen.
  let n = 0;
  if (f.verdict)    n++;
  if (f.q)          n++;
  if (f.unsub_only) n++;
  // hide_quarantine=1 ist Default → nur zaehlen wenn ausgeschaltet.
  if (!f.hide_quarantine) n++;
  return n;
}

/* -------------------------------------------------------------------------- */
/* Chronologisch (bestehende Liste)                                           */
/* -------------------------------------------------------------------------- */

function ChronoList({ filter, setFilter, onReload }) {
  const [data, setData]     = useState({ items: [], total: 0, per_page: 25 });
  const [loading, setLoading] = useState(false);
  const [busy, setBusy]     = useState({});
  const [expanded, setExpanded] = useState(null);
  const [error, setError]   = useState(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = buildQS(filter);
      const { body, status } = await apiGet('inbox/messages?' + qs.toString());
      if (status >= 400) setError('HTTP ' + status);
      else setData(body);
    } catch (e) { setError(String(e)); }
    finally { setLoading(false); }
  }, [filter]);

  useEffect(() => { load(); }, [load]);

  const reloadAll = () => { load(); onReload(); };
  const handlers = useRowHandlers(busy, setBusy, reloadAll);
  const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 25)));

  return (
    <>
      {error && <div className="mg-card mg-error">{error}</div>}
      {loading && <div className="mg-card">Lade …</div>}
      {!loading && data.items.length === 0 && (
        <div className="mg-card mg-muted">
          Keine Mails. Tipp: erst „↓ Jetzt abholen" klicken, dann zeigen wir hier alles was reinkommt.
        </div>
      )}
      {!loading && data.items.length > 0 && (() => {
        // Quarantaene-Filter clientseitig: der aktuelle /inbox/messages-Endpoint
        // liefert quarantinierte Mails weiterhin mit — wir blenden sie nur aus.
        const visible = filter.hide_quarantine
          ? data.items.filter((m) => !m.quarantine_action_id)
          : data.items;
        const hiddenCount = data.items.length - visible.length;
        return (
          <>
            {hiddenCount > 0 && (
              <div className="mg-card mg-muted" style={{ padding: '8px 12px', fontSize: 13 }}>
                🛡 {hiddenCount} quarantinierte Mail{hiddenCount === 1 ? '' : 's'} ausgeblendet — Haekchen „Quarantaene verbergen" oben umschalten.
              </div>
            )}
            {visible.length === 0 ? (
              <div className="mg-card mg-muted">Alle Mails auf dieser Seite sind in Quarantaene.</div>
            ) : (
              <div className="mg-stack">
                {visible.map((m) => (
                  <Row
                    key={m.id} m={m}
                    expanded={expanded === m.id}
                    busy={busy[m.id]}
                    onToggle={() => setExpanded(expanded === m.id ? null : m.id)}
                    {...handlers.for(m)}
                  />
                ))}
              </div>
            )}
          </>
        );
      })()}
      {totalPages > 1 && (
        <div className="mg-card mg-pager">
          <button className="mg-btn" disabled={filter.page <= 1} onClick={() => setFilter({ ...filter, page: filter.page - 1 })}>‹ Zurück</button>
          {' '}Seite {filter.page} / {totalPages}{' '}
          <button className="mg-btn" disabled={filter.page >= totalPages} onClick={() => setFilter({ ...filter, page: filter.page + 1 })}>Weiter ›</button>
        </div>
      )}
    </>
  );
}

/* -------------------------------------------------------------------------- */
/* Nach Absender (SenderList + Accordion)                                     */
/* -------------------------------------------------------------------------- */

function SenderList({ filter, setFilter, onReload }) {
  const [data, setData]       = useState({ items: [], total: 0, per_page: 50 });
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState(null);
  // Pro from_addr: {loading:bool, items:[], expanded:bool}
  const [groups, setGroups]   = useState({});
  const [busy, setBusy]       = useState({});
  const [senderBusy, setSenderBusy] = useState({});
  const [expandedMsg, setExpandedMsg] = useState(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = buildQS(filter, 50);
      const { body, status } = await apiGet('inbox/senders?' + qs.toString());
      if (status >= 400) setError('HTTP ' + status);
      else setData(body);
    } catch (e) { setError(String(e)); }
    finally { setLoading(false); }
  }, [filter]);

  useEffect(() => { load(); }, [load]);
  // Nach Filter-Wechsel Group-Cache invalidieren, damit alte Sender-Details nicht falsch bleiben.
  useEffect(() => { setGroups({}); }, [filter.account_id, filter.verdict, filter.q, filter.unsub_only]);

  const reloadAll = () => { load(); onReload(); };

  const loadGroup = useCallback(async (from_addr) => {
    setGroups((g) => ({ ...g, [from_addr]: { ...(g[from_addr] || {}), loading: true, expanded: true } }));
    try {
      const qs = new URLSearchParams();
      qs.set('from_addr', from_addr);
      qs.set('per_page', 50);
      const { body } = await apiGet('inbox/messages?' + qs.toString());
      setGroups((g) => ({ ...g, [from_addr]: { loading: false, expanded: true, items: (body && body.items) || [] } }));
    } catch (e) {
      setGroups((g) => ({ ...g, [from_addr]: { loading: false, expanded: true, items: [], error: String(e) } }));
    }
  }, []);

  const toggleGroup = (from_addr) => {
    const g = groups[from_addr];
    if (g && g.items) {
      // schon geladen → nur expanded-flip
      setGroups((prev) => ({ ...prev, [from_addr]: { ...g, expanded: !g.expanded } }));
    } else {
      loadGroup(from_addr);
    }
  };

  const reloadGroup = (from_addr) => loadGroup(from_addr);

  const unsubSender = async (from_addr) => {
    setSenderBusy((b) => ({ ...b, [from_addr]: 'unsub' }));
    try {
      const { body, status } = await apiPost('subscriptions/unsubscribe', { from_addr });
      const manualUrl = body.manual_url || (body.api && body.api.manual_url) || '';
      if (body.needs_manual && manualUrl) {
        window.open(manualUrl, '_blank', 'noopener');
      } else if (body.reason === 'endpoints_dead') {
        const cause = body.dead_cause === 'http'
          ? 'Die Abmelde-URL antwortet dauerhaft mit einem Fehler (Kampagne abgelaufen oder Endpoint zurückgezogen).'
          : 'Die Abmelde-Adressen sind im DNS nicht mehr erreichbar.';
        if (window.confirm(
          `Absender ${from_addr} lässt sich nicht mehr regulär abmelden.\n\n${cause}\n\n` +
          `Direkt blockieren? Es wird eine Blacklist-Regel angelegt; bestehende Mails bleiben unverändert.`
        )) {
          const { body: b2, status: s2 } = await apiPost('inbox/senders/block', { from_addr });
          if (s2 !== 200 || !b2.ok)     alert('Blockieren fehlgeschlagen: ' + (b2.error || s2));
          else if (b2.existed)          alert('ℹ Sender war bereits blockiert.');
          else                          alert('✔ Sender blockiert (Regel angelegt).');
        }
      } else if (body.already) {
        alert('ℹ Bereits abgemeldet — kein neuer Versuch nötig.');
      } else if (body.ok) {
        alert(`✔ Abgemeldet (${(body.api && body.api.status) || 'ok'})`);
      } else {
        alert(formatUnsubError(body, status));
      }
      reloadAll();
    } finally {
      setSenderBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const blockSender = async (from_addr) => {
    if (!window.confirm(
      `Absender ${from_addr} blockieren?\n\n` +
      `Es wird eine Blacklist-Regel angelegt. Künftige Mails dieses Senders werden als gefährlich eingestuft ` +
      `und — bei aktivierter Auto-Quarantäne-Schwelle — automatisch in Quarantäne verschoben.\n\n` +
      `Diese Aktion löscht keine bereits vorhandenen Mails.`
    )) return;
    setSenderBusy((b) => ({ ...b, [from_addr]: 'block' }));
    try {
      const { body, status } = await apiPost('inbox/senders/block', { from_addr });
      if (status !== 200 || !body.ok) {
        alert('Blockieren fehlgeschlagen: ' + (body.error || status));
      } else if (body.existed) {
        alert('ℹ Sender war bereits blockiert.');
      } else {
        alert('✔ Sender blockiert (Regel angelegt).');
      }
      reloadAll();
    } finally {
      setSenderBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const eradicateSender = async (from_addr, msg_count) => {
    // Type-in-Confirmation — muscle-memory-sicher, weil EXPUNGE nicht rückholbar.
    const answer = window.prompt(
      `Absender ${from_addr} KOMPLETT VERNICHTEN?\n\n` +
      `Es passiert Folgendes:\n` +
      `• Best-effort Abmelde-Versuch beim Anbieter\n` +
      `• Blacklist-Regel angelegt (künftige Mails werden aussortiert)\n` +
      `• ${msg_count} Mail${msg_count === 1 ? '' : 's'} werden ENDGÜLTIG gelöscht (kein Papierkorb, kein Undo)\n\n` +
      `Zur Bestätigung "VERNICHTEN" eintippen:`
    );
    if (answer === null) return;
    if (answer.trim().toUpperCase() !== 'VERNICHTEN') {
      alert('Abgebrochen — Bestätigung stimmt nicht.');
      return;
    }

    // Zweiter Schritt: Auto-Vernichten auf die gesamte Absender-Domain
    // anbieten. Fängt Sub-Adress-Wechsel (news@, angebote@, service@…) ab,
    // die die Sender-Blacklist-Rule nicht abdeckt.
    const at = String(from_addr || '').lastIndexOf('@');
    const domain = at >= 0 ? String(from_addr).slice(at + 1).toLowerCase().trim() : '';
    const alsoDomain = !!domain && window.confirm(
      `Auch alle zukünftigen Mails von *@${domain} automatisch vernichten?\n\n` +
      `Sobald aktiv, filtert MailGuard jede neue Mail dieser Domain beim` +
      ` Abrufen direkt vom Server — sie landet nie in deiner Inbox.\n\n` +
      `Kann jederzeit unter "Auto-Vernichten-Domains" wieder aufgehoben werden.`
    );

    setSenderBusy((b) => ({ ...b, [from_addr]: 'eradicate' }));
    try {
      const { body, status } = await apiPost('subscriptions/eradicate', {
        from_addr,
        confirm: 'VERNICHTEN',
      });
      if (status === 422) {
        alert('Abgebrochen: ' + (body.message || 'Bestätigung fehlgeschlagen.'));
        return;
      }
      let extra = '';
      if (alsoDomain) {
        const res = await apiPost('me/eradicate-domains', {
          domain,
          confirm: 'VERNICHTEN',
        });
        extra = res?.body?.ok
          ? `\n\n✔ Domain *@${domain} auf Auto-Vernichten gesetzt.`
          : `\n\n⚠ Domain-Auto-Vernichten fehlgeschlagen: ${res?.body?.message || 'unbekannter Fehler'}`;
      }
      alert(formatEradicateResult(body, from_addr) + extra);
      reloadAll();
    } finally {
      setSenderBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const handlers = useRowHandlers(busy, setBusy, (from_addr) => {
    reloadAll();
    if (from_addr) reloadGroup(from_addr);
  });

  const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 50)));

  return (
    <>
      {error && <div className="mg-card mg-error">{error}</div>}
      {loading && <div className="mg-card">Lade …</div>}
      {!loading && data.items.length === 0 && (
        <div className="mg-card mg-muted">
          Keine Absender gefunden — noch keine Mails abgeholt oder Filter zu eng.
        </div>
      )}
      {!loading && data.items.length > 0 && (
        <div className="mg-stack">
          {data.items.map((s) => {
            const g = groups[s.from_addr] || {};
            return (
              <SenderCard
                key={s.from_addr}
                sender={s}
                group={g}
                busy={senderBusy[s.from_addr]}
                onToggle={() => toggleGroup(s.from_addr)}
                onUnsub={() => unsubSender(s.from_addr)}
                onPurgeAll={() => eradicateSender(s.from_addr, s.msg_count)}
                onBlock={() => blockSender(s.from_addr)}
                renderRow={(m) => (
                  <Row
                    key={m.id} m={m}
                    expanded={expandedMsg === m.id}
                    busy={busy[m.id]}
                    onToggle={() => setExpandedMsg(expandedMsg === m.id ? null : m.id)}
                    {...handlers.for(m, s.from_addr)}
                  />
                )}
              />
            );
          })}
        </div>
      )}
      {totalPages > 1 && (
        <div className="mg-card mg-pager">
          <button className="mg-btn" disabled={filter.page <= 1} onClick={() => setFilter({ ...filter, page: filter.page - 1 })}>‹ Zurück</button>
          {' '}Seite {filter.page} / {totalPages}{' '}
          <button className="mg-btn" disabled={filter.page >= totalPages} onClick={() => setFilter({ ...filter, page: filter.page + 1 })}>Weiter ›</button>
        </div>
      )}
    </>
  );
}

function SenderCard({ sender, group, busy, onToggle, onUnsub, onPurgeAll, onBlock, renderRow }) {
  const s = sender;
  const worst = s.worst_verdict;
  const worstClass = worst === 'dangerous' ? ' mg-sender--danger' : '';
  const canUnsub = s.has_unsub === 1 && !s.sender_unsubscribed;
  const isBlocked = !!s.sender_blocked;
  return (
    <div className={'mg-card mg-sender' + worstClass}>
      <div className="mg-sender__head" role="button" tabIndex={0} onClick={onToggle}
           onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); } }}>
        <div className="mg-sender__from">
          <strong>{s.latest_from_name || s.from_addr_display || s.from_addr}</strong>
          {s.latest_from_name && <div className="mg-muted mg-tiny">{s.from_addr_display || s.from_addr}</div>}
        </div>
        <div className="mg-sender__subject">
          <span className="mg-muted mg-tiny">Zuletzt: </span>{s.latest_subject || '(kein Subject)'}
        </div>
        <div className="mg-sender__meta">
          <VerdictBadge verdict={worst === 'pending' ? '' : worst} status={worst === 'pending' ? 'pending' : 'done'} />
          <span className="mg-sender__count" title={s.msg_count + ' Mails'}>
            {s.msg_count} {s.msg_count === 1 ? 'Mail' : 'Mails'}
          </span>
          {s.has_unsub === 1 && (s.sender_unsubscribed
            ? <span className="mg-pill mg-pill--muted" title="Sender bereits abgemeldet">✓ abgemeldet</span>
            : <span className="mg-pill mg-pill--ok">Newsletter</span>)}
          {isBlocked && <span className="mg-pill mg-pill--err" title="Blacklist-Regel aktiv">⛔ blockiert</span>}
          <span className="mg-muted mg-tiny">{fmtDate(s.latest_at)}</span>
          <span className={'mg-sender__chevron' + (group.expanded ? ' mg-sender__chevron--open' : '')} aria-hidden="true">▾</span>
        </div>
      </div>

      <div className="mg-sender__actions">
        {canUnsub && (
          <button
            className="mg-btn mg-btn--primary"
            disabled={!!busy}
            onClick={(e) => { e.stopPropagation(); onUnsub(); }}
            title={`Alle ${s.msg_count} Mails dieses Absenders in einem Rutsch abmelden`}
          >
            {busy === 'unsub' ? '…' : '✉ Newsletter abmelden'}
          </button>
        )}
        {!isBlocked && (
          <button
            className="mg-btn mg-btn--warn"
            disabled={!!busy}
            onClick={(e) => { e.stopPropagation(); onBlock(); }}
            title={`Blacklist-Regel für ${s.from_addr} anlegen — künftige Mails werden als gefährlich markiert und ggf. automatisch quarantänisiert`}
          >
            {busy === 'block' ? '…' : '⛔ Absender blockieren'}
          </button>
        )}
        <button
          className="mg-btn mg-btn--danger"
          disabled={!!busy}
          onClick={(e) => { e.stopPropagation(); onPurgeAll(); }}
          title={`Best-effort abmelden + Sender blockieren + alle ${s.msg_count} Mails ENDGÜLTIG löschen — nicht wiederherstellbar`}
        >
          {busy === 'eradicate' ? '…' : `💥 Sender vernichten (${s.msg_count})`}
        </button>
      </div>

      {group.expanded && (
        <div className="mg-sender__list">
          {group.loading && <div className="mg-muted">Lade Mails …</div>}
          {group.error && <div className="mg-error">{group.error}</div>}
          {!group.loading && group.items && group.items.length === 0 && (
            <div className="mg-muted">Keine Mails zu diesem Absender.</div>
          )}
          {!group.loading && group.items && group.items.map((m) => renderRow(m))}
        </div>
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Shared: Row + Handlers                                                     */
/* -------------------------------------------------------------------------- */

function useRowHandlers(busy, setBusy, reload) {
  // Reload akzeptiert optional from_addr, damit SenderList die Gruppe re-fetchen kann.
  const reloadRef = useRef(reload);
  reloadRef.current = reload;
  return useMemo(() => ({
    for(m, from_addr = null) {
      const finish = () => reloadRef.current && reloadRef.current(from_addr || null);
      // Whitelist/Blacklist-Regel-Anlage aus einer Nachricht heraus.
      const addRule = async (kind, matchType) => {
        const addr = (m.from_addr || '').toLowerCase().trim();
        if (!addr) return alert('Kein Absender bekannt.');
        const pattern = matchType === 'from_domain'
          ? addr.slice(addr.lastIndexOf('@') + 1)
          : addr;
        if (!pattern) return alert('Kein gueltiges Muster ableitbar.');
        const scope = matchType === 'from_domain' ? `*@${pattern}` : pattern;
        const kindLabel = kind === 'whitelist' ? 'als sicher einstufen' : 'blockieren';
        const kindNote  = kind === 'whitelist'
          ? 'Whitelist-Regel — Scan-Ergebnisse fuer diesen Absender werden uebersteuert.'
          : 'Blacklist-Regel — als gefaehrlich einstufen.';
        if (!window.confirm(`Alle kuenftigen Mails von ${scope} ${kindLabel}?\n\n${kindNote}\n\nBereits vorhandene Mails bleiben unveraendert.`)) return;
        const busyKey = (kind === 'whitelist' ? 'wl_' : 'bl_') + (matchType === 'from_domain' ? 'domain' : 'addr');
        setBusy((b) => ({ ...b, [m.id]: busyKey }));
        try {
          const { body, status } = await apiPost('rules', { kind, match_type: matchType, pattern, note: 'Aus Nachricht ' + m.id });
          if ((status === 200 || status === 201) && body && body.ok) {
            alert(kind === 'whitelist' ? `${scope} in Whitelist eingetragen.` : `${scope} in Blacklist eingetragen.`);
          } else {
            alert('Regel-Anlage fehlgeschlagen: ' + ((body && body.error) || status));
          }
          finish();
        } finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
      };
      return {
        onWhitelistAddr:   () => addRule('whitelist', 'from_addr'),
        onWhitelistDomain: () => addRule('whitelist', 'from_domain'),
        onBlacklistAddr:   () => addRule('blacklist', 'from_addr'),
        onBlacklistDomain: () => addRule('blacklist', 'from_domain'),
        onRescan: async () => {
          setBusy((b) => ({ ...b, [m.id]: 'rescan' }));
          try { await apiPost(`inbox/messages/${m.id}/rescan`); finish(); }
          finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
        },
        onUnsub: async () => {
          if (m.scan_verdict === 'dangerous' && !window.confirm('Diese Mail ist als Phishing eingestuft. Trotzdem auf den Abmelde-Link klicken? (Empfehlung: NICHT)')) return;
          setBusy((b) => ({ ...b, [m.id]: 'unsub' }));
          try {
            const { body, status } = await apiPost(`inbox/messages/${m.id}/unsubscribe`, {});
            const manualUrl = body.manual_url || (body.api && body.api.manual_url) || '';
            if (body.needs_manual && manualUrl) {
              window.open(manualUrl, '_blank', 'noopener');
            } else if (body.reason === 'endpoints_dead' && m.from_addr) {
              const cause = body.dead_cause === 'http'
                ? 'Die Abmelde-URL antwortet dauerhaft mit einem Fehler (Kampagne abgelaufen oder Endpoint zurückgezogen).'
                : 'Die Abmelde-Adressen sind im DNS nicht mehr erreichbar.';
              if (window.confirm(
                `Absender ${m.from_addr} lässt sich nicht mehr regulär abmelden.\n\n${cause}\n\n` +
                `Direkt blockieren? Es wird eine Blacklist-Regel angelegt; bestehende Mails bleiben unverändert.`
              )) {
                const { body: b2, status: s2 } = await apiPost('inbox/senders/block', { from_addr: m.from_addr });
                if (s2 !== 200 || !b2.ok)     alert('Blockieren fehlgeschlagen: ' + (b2.error || s2));
                else if (b2.existed)          alert('ℹ Sender war bereits blockiert.');
                else                          alert('✔ Sender blockiert (Regel angelegt).');
              }
            } else if (body.already) {
              alert('ℹ Bereits abgemeldet — kein neuer Versuch nötig.');
            } else if (body.ok) {
              alert(`✔ Abgemeldet (${(body.api && body.api.status) || 'ok'})`);
            } else {
              alert(formatUnsubError(body, status));
            }
            finish();
          } finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
        },
        onQuarantine: async () => {
          const score = m.scan_score;
          if ((score === null || score < 70) && !window.confirm(
            'Diese Mail ist NICHT eindeutig als gefährlich eingestuft (Score ' + (score ?? '–') + '). Trotzdem in den Quarantäne-Ordner verschieben?'
          )) return;
          setBusy((b) => ({ ...b, [m.id]: 'quarantine' }));
          try {
            const { body, status } = await apiPost(`inbox/messages/${m.id}/quarantine`);
            if (status === 200 && body.ok) {
              const undo = window.confirm('Mail in Quarantäne verschoben.\n\nOK = Aktion belassen.\nAbbrechen = sofort rückgängig machen.');
              if (!undo && body.action_id) await apiPost(`actions/${body.action_id}/undo`);
            } else {
              alert('Quarantäne fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
            }
            finish();
          } finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
        },
        onUndoQuarantine: async () => {
          if (!m.quarantine_action_id) return;
          setBusy((b) => ({ ...b, [m.id]: 'undo' }));
          try {
            const { body, status } = await apiPost(`actions/${m.quarantine_action_id}/undo`);
            if (status !== 200 || !body.ok) alert('Wiederherstellen fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
            finish();
          } finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
        },
        onPurge: async () => {
          if (!window.confirm(
            'Mail ENDGÜLTIG vom Mailserver löschen?\n\n' +
            'Nach dem Klick ist sie nicht mehr wiederherstellbar — auch nicht über den Papierkorb.'
          )) return;
          setBusy((b) => ({ ...b, [m.id]: 'purge' }));
          try {
            // Quarantänisierte Mails über den bestehenden action-basierten Purge,
            // alle anderen über den generischen Message-Purge (identisches Ergebnis:
            // IMAP-Expunge + Audit-Eintrag + mg_messages-Delete).
            const endpoint = m.quarantine_action_id
              ? `actions/${m.quarantine_action_id}/purge`
              : `inbox/messages/${m.id}/purge`;
            const { body, status } = await apiPost(endpoint);
            if (status !== 200 || !body.ok) alert('Löschen fehlgeschlagen: ' + (body.error || status) + (body.detail ? '\n' + body.detail : ''));
            finish();
          } finally { setBusy((b) => { const n = { ...b }; delete n[m.id]; return n; }); }
        },
      };
    }
  }), [busy, setBusy]);
}

function Stat({ label, value, tone }) {
  return (
    <div className={'mg-stat mg-stat--' + (tone || 'default')}>
      <div className="mg-stat__value">{value ?? 0}</div>
      <div className="mg-stat__label">{label}</div>
    </div>
  );
}

function Row({ m, expanded, busy, onToggle, onRescan, onUnsub, onQuarantine, onUndoQuarantine, onPurge, onWhitelistAddr, onWhitelistDomain, onBlacklistAddr, onBlacklistDomain }) {
  const dangerous   = m.scan_verdict === 'dangerous';
  const quarantined = !!m.quarantine_action_id;
  const attachmentCount = (m.attachment_count | 0);
  const hasAttachments  = attachmentCount > 0 || m.has_attachments === 1;
  return (
    <div className={'mg-mail' + (dangerous ? ' mg-mail--danger' : '') + (quarantined ? ' mg-mail--quarantined' : '')}>
      <div className="mg-mail__head" onClick={onToggle} role="button" tabIndex={0}>
        <div className="mg-mail__from">
          <strong>{m.from_name || m.from_addr || '(unbekannt)'}</strong>
          {m.from_name && m.from_addr && <span className="mg-muted mg-tiny"> · {m.from_addr}</span>}
        </div>
        <div className="mg-mail__subject">{m.subject || '(kein Subject)'}</div>
        <div className="mg-mail__meta">
          <VerdictBadge verdict={m.scan_verdict} score={m.scan_score} status={m.scan_status} />
          {quarantined && <span className="mg-pill mg-pill--muted" title="In Quarantäne verschoben">🛡 Quarantäne</span>}
          {hasAttachments && (
            <span
              className="mg-pill mg-pill--muted"
              title={`${attachmentCount} Anhang${attachmentCount === 1 ? '' : 'e'} — Details in der aufgeklappten Ansicht`}
            >📎 {attachmentCount || ''}</span>
          )}
          {m.has_unsub && (m.sender_unsubscribed
            ? <span className="mg-pill mg-pill--muted" title="Sender wurde bereits abgemeldet">✓ abgemeldet</span>
            : <span className="mg-pill mg-pill--ok">Newsletter</span>)}
          <span className="mg-muted mg-tiny">{fmtDate(m.date_hdr || m.fetched_at)}</span>
        </div>
      </div>
      {expanded && (
        <div className="mg-mail__body">
          <p style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{m.body_preview || <em className="mg-muted">(keine Vorschau)</em>}</p>
          {hasAttachments && <AttachmentList messageId={m.id} />}
          {Array.isArray(m.scan_reasons) && m.scan_reasons.length > 0 && (
            <>
              <p className="mg-muted mg-tiny" style={{ margin: '0.75rem 0 0.25rem' }}>Scan-Treffer:</p>
              <ReasonsList reasons={m.scan_reasons} />
            </>
          )}
          <div className="mg-mail__actions">
            <button className="mg-btn" disabled={!!busy} onClick={(e) => { e.stopPropagation(); onRescan(); }}>
              {busy === 'rescan' ? '…' : '↻ Erneut scannen'}
            </button>
            <button
              className="mg-btn" disabled={!!busy || !m.from_addr}
              onClick={(e) => { e.stopPropagation(); onWhitelistAddr && onWhitelistAddr(); }}
              title="Whitelist-Regel fuer diesen Absender — kuenftige Mails immer als sauber durchlassen"
            >
              {busy === 'wl_addr' ? '…' : '✓ Absender als sicher'}
            </button>
            <button
              className="mg-btn" disabled={!!busy || !m.from_addr}
              onClick={(e) => { e.stopPropagation(); onWhitelistDomain && onWhitelistDomain(); }}
              title="Whitelist-Regel fuer die ganze Absender-Domain"
            >
              {busy === 'wl_domain' ? '…' : '✓ Domain als sicher'}
            </button>
            <button
              className="mg-btn" disabled={!!busy || !m.from_addr}
              onClick={(e) => { e.stopPropagation(); onBlacklistAddr && onBlacklistAddr(); }}
              title="Blacklist-Regel fuer diesen Absender"
            >
              {busy === 'bl_addr' ? '…' : '⛔ Absender blocken'}
            </button>
            <button
              className="mg-btn" disabled={!!busy || !m.from_addr}
              onClick={(e) => { e.stopPropagation(); onBlacklistDomain && onBlacklistDomain(); }}
              title="Blacklist-Regel fuer die ganze Absender-Domain"
            >
              {busy === 'bl_domain' ? '…' : '⛔ Domain blocken'}
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
            <button
              className="mg-btn mg-btn--danger" disabled={!!busy}
              onClick={(e) => { e.stopPropagation(); onPurge(); }}
              title="Mail endgültig vom Server löschen — nicht wiederherstellbar"
            >
              {busy === 'purge' ? '…' : '🗑 Endgültig löschen'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function AttachmentList({ messageId }) {
  const [items, setItems] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { body, status } = await apiGet(`inbox/messages/${messageId}/attachments`);
        if (cancelled) return;
        if (status >= 400) setError('HTTP ' + status);
        else setItems(body.items || []);
      } catch (e) {
        if (!cancelled) setError(String(e));
      }
    })();
    return () => { cancelled = true; };
  }, [messageId]);

  if (error) return <p className="mg-muted mg-tiny" style={{ margin: '0.75rem 0 0' }}>Anhänge nicht ladbar: {error}</p>;
  if (items === null) return <p className="mg-muted mg-tiny" style={{ margin: '0.75rem 0 0' }}>Lade Anhänge …</p>;
  if (items.length === 0) return null;

  return (
    <div style={{ margin: '0.75rem 0 0' }}>
      <p className="mg-muted mg-tiny" style={{ margin: '0 0 0.25rem' }}>Anhänge:</p>
      <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
        {items.map((a) => (
          <li key={a.id} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <span>📎 <strong style={{ wordBreak: 'break-all' }}>{a.filename || '(ohne Namen)'}</strong></span>
            <span className="mg-muted mg-tiny">· {a.mime_type || '?'} · {fmtSize(a.size_bytes)}</span>
            {a.is_suspicious === 1 && (
              <span
                className="mg-pill mg-pill--warn"
                title={a.reasons.map((r) => r.description).join(' | ')}
              >⚠ verdächtig</span>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function fmtSize(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
}

function buildQS(filter, defaultPerPage = 25) {
  const qs = new URLSearchParams();
  if (filter.account_id) qs.set('account_id', filter.account_id);
  if (filter.unsub_only) qs.set('unsub_only', '1');
  if (filter.verdict)    qs.set('verdict', filter.verdict);
  if (filter.q)          qs.set('q', filter.q);
  qs.set('page', filter.page);
  qs.set('per_page', defaultPerPage);
  return qs;
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}

/**
 * Spiegelt Newsletters.jsx::formatEradicateResult.
 */
function formatEradicateResult(body, from_addr) {
  const lines = [`Ergebnis für ${from_addr}:`];
  const u = body.unsub || {};
  if (u.ok && u.already)         lines.push('• Abmeldung: bereits zuvor erfolgt');
  else if (u.ok)                 lines.push('• Abmeldung: erfolgreich');
  else if (u.needs_manual)       lines.push('• Abmeldung: manuell im Browser abzuschließen');
  else if (u.reason === 'endpoints_dead') {
    lines.push('• Abmeldung: Anbieter-Endpunkte tot (übersprungen)');
  } else {
    lines.push('• Abmeldung: fehlgeschlagen (' + (u.error || 'unbekannt') + ')');
  }
  const b = body.block || {};
  if (b.ok && b.existed) lines.push('• Blockiert: Regel bestand bereits');
  else if (b.ok)         lines.push('• Blockiert: neue Regel angelegt');
  else                   lines.push('• Blockieren: fehlgeschlagen (' + (b.error || 'unbekannt') + ')');
  const p = body.purge || {};
  const parts = [];
  if (p.purged)  parts.push(`${p.purged} endgültig gelöscht`);
  if (p.skipped) parts.push(`${p.skipped} übersprungen`);
  if (p.failed)  parts.push(`${p.failed} fehlgeschlagen`);
  lines.push('• Purge: ' + (parts.length ? parts.join(', ') : 'nichts zu löschen'));
  const heading = body.ok ? '✔ Sender vernichtet' : '⚠ Teilweise erledigt';
  return heading + '\n\n' + lines.join('\n');
}

/**
 * Spiegelt Newsletters.jsx::formatUnsubError — bewusst dupliziert, damit
 * beide Views ohne shared-module-Boilerplate unabhängig funktionieren.
 */
function formatUnsubError(body, status) {
  if (status === 409) return 'ℹ Es läuft bereits eine Abmeldung — bitte kurz warten und Seite neu laden.';
  if (status === 422 || body.error === 'no_options' || body.error === 'no_option_picked') {
    return '⚠ Diese Mail enthält keine gültige Abmelde-Information. Bitte manuell im Newsletter selbst abmelden oder Sender blockieren.';
  }
  if (body.detail && typeof body.detail === 'string') {
    return '⚠ Abmeldung fehlgeschlagen: ' + body.detail;
  }
  const attempts = Array.isArray(body.attempts) ? body.attempts : [];
  const last = attempts.length ? attempts[attempts.length - 1] : null;
  if (last) {
    const parts = [last.kind || '?', last.status || '?'];
    if (last.http_status) parts.push('HTTP ' + last.http_status);
    if (last.detail)      parts.push(last.detail);
    return '⚠ Abmeldung fehlgeschlagen (' + parts.join(' · ') + ')';
  }
  return '⚠ Abmeldung fehlgeschlagen: ' + (body.error || 'unbekannter Fehler');
}
