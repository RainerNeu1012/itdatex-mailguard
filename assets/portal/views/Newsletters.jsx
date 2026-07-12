import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';
import AccountTabs, { useCurrentAccount } from '../components/AccountTabs.jsx';

const STATUS_TONE = {
  unsubscribed: { label: 'abgemeldet', cls: 'mg-pill--ok' },
  needs_manual: { label: 'im Browser abschließen', cls: 'mg-pill--warn' },
  blocked:      { label: 'blockiert',  cls: 'mg-pill--err' },
  failed:       { label: 'fehler',     cls: 'mg-pill--err' },
  unknown:      { label: 'unbekannt',  cls: 'mg-pill--muted' },
  smtp_not_configured: { label: 'SMTP fehlt', cls: 'mg-pill--err' },
};

export default function Newsletters() {
  const [tab, setTab] = useState('subscriptions');
  const [accounts, accountId, switchAccount] = useCurrentAccount();
  const activeAccount = accounts.find((a) => a.id === accountId) || null;
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Newsletter</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          {activeAccount
            ? <>Newsletter aus <strong>{activeAccount.label || activeAccount.host}</strong>. Pro Absender abmelden statt pro Mail — einmal klicken reicht für alle aktuellen und künftigen Nachrichten dieses Senders.</>
            : 'Pro Absender abmelden statt pro Mail — einmal klicken reicht für alle aktuellen und künftigen Nachrichten dieses Senders.'}
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

      <AccountTabs accounts={accounts} activeId={accountId} onSwitch={switchAccount} />

      {tab === 'subscriptions' ? <Subscriptions accountId={accountId} /> : <History accountId={accountId} />}
    </div>
  );
}

function Subscriptions({ accountId }) {
  const [items, setItems] = useState(null);
  const [busy, setBusy]   = useState({});
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const qs = accountId ? `?account_id=${accountId}` : '';
      const { status, body } = await apiGet('subscriptions' + qs);
      if (status >= 400) setError('HTTP ' + status);
      else setItems(body.items);
    } catch (e) { setError(String(e)); }
  }, [accountId]);

  useEffect(() => { load(); }, [load]);

  const unsub = async (from_addr) => {
    setBusy((b) => ({ ...b, [from_addr]: 'unsub' }));
    try {
      const { body, status } = await apiPost('subscriptions/unsubscribe', { from_addr });
      const manualUrl = body.manual_url || (body.api && body.api.manual_url) || '';
      if (body.needs_manual && manualUrl) {
        // Direkt neuen Tab aufmachen — der User hat gerade explizit auf 'Abmelden' geklickt,
        // der Popup-Blocker haelt das durch. Der Reload zeigt danach den persistenten Button.
        window.open(manualUrl, '_blank', 'noopener');
      } else if (body.reason === 'endpoints_dead') {
        // Anbieter hat die Abmelde-URL stillgelegt. Anbieten, direkt zu blockieren —
        // sonst hat der User keine Handhabe außer resigniert wegzuklicken.
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
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const block = async (from_addr) => {
    if (!window.confirm(
      `Absender ${from_addr} blockieren?\n\n` +
      `Es wird eine Blacklist-Regel angelegt. Künftige Mails dieses Senders werden als gefährlich eingestuft ` +
      `und — bei aktivierter Auto-Quarantäne-Schwelle — automatisch in Quarantäne verschoben.\n\n` +
      `Diese Aktion löscht keine bereits vorhandenen Mails.`
    )) return;
    setBusy((b) => ({ ...b, [from_addr]: 'block' }));
    try {
      const { body, status } = await apiPost('inbox/senders/block', { from_addr });
      if (status !== 200 || !body.ok) alert('Blockieren fehlgeschlagen: ' + (body.error || status));
      else if (body.existed)          alert('ℹ Sender war bereits blockiert.');
      else                            alert('✔ Sender blockiert (Regel angelegt).');
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const blockDomain = async (from_addr) => {
    const at = String(from_addr || '').lastIndexOf('@');
    const domain = at >= 0 ? String(from_addr).slice(at + 1).toLowerCase().trim() : '';
    if (!domain) { alert('Kein gueltiges Muster ableitbar.'); return; }
    if (!window.confirm(
      `Ganze Domain *@${domain} blockieren?\n\n` +
      `Es wird eine Blacklist-Regel fuer die Domain angelegt. Kuenftige Mails jedes Absenders unter dieser Domain ` +
      `werden als gefaehrlich markiert und ggf. automatisch quarantaeniert.\n\n` +
      `Bereits vorhandene Mails bleiben unveraendert.`
    )) return;
    setBusy((b) => ({ ...b, [from_addr]: 'bl_domain' }));
    try {
      const { body, status } = await apiPost('rules', {
        kind: 'blacklist', match_type: 'from_domain', pattern: domain,
        note: 'Aus Newsletter-Uebersicht',
      });
      if ((status === 200 || status === 201) && body && body.ok) alert(`✔ *@${domain} in Blacklist eingetragen.`);
      else alert('Regel-Anlage fehlgeschlagen: ' + ((body && body.error) || status));
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const purge = async (from_addr, msg_count) => {
    const ok = window.confirm(
      `${msg_count} Newsletter-Mail${msg_count === 1 ? '' : 's'} von ${from_addr} in den Papierkorb verschieben ` +
      `und künftige Mails dieses Senders automatisch aussortieren?\n\n` +
      `Die Mails bleiben im Papierkorb wiederherstellbar.`
    );
    if (!ok) return;
    setBusy((b) => ({ ...b, [from_addr]: 'purge' }));
    try {
      const { body } = await apiPost('subscriptions/purge', { from_addr, auto_rule: true });
      const parts = [];
      if (body.moved)   parts.push(`${body.moved} verschoben`);
      if (body.skipped) parts.push(`${body.skipped} übersprungen`);
      if (body.failed)  parts.push(`${body.failed} fehlgeschlagen`);
      const ruleTxt = body.rule_id ? ' · Auto-Regel aktiv' : '';
      alert((body.ok ? '✔ ' : '⚠ ') + (parts.join(', ') || 'keine Änderung') + ruleTxt);
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  const eradicate = async (from_addr, msg_count) => {
    // Type-in-Confirmation. Muscle-Memory-Klicks werden hier bewusst blockiert,
    // weil hard_purge_sender ohne Undo läuft (IMAP-EXPUNGE, kein Papierkorb).
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

    // Zweiter Schritt: Domain-Auto-Vernichten anbieten. Der Blacklist-Rule
    // von eradicate_sender greift nur pro from_addr — Absender wechseln oft
    // die Sub-Adresse (news@, angebote@, service@…). Ein Häkchen auf die
    // Domain fängt die alle mit ab, ohne dass der User jedesmal wieder klicken
    // muss. Wenn abgelehnt: normaler Sender-only-Eradicate-Flow.
    const domain = extractDomain(from_addr);
    const alsoDomain = !!domain && window.confirm(
      `Auch alle zukünftigen Mails von *@${domain} automatisch vernichten?\n\n` +
      `Sobald aktiv, filtert MailGuard jede neue Mail dieser Domain beim` +
      ` Abrufen direkt vom Server — sie landet nie in deiner Inbox.\n\n` +
      `Kann jederzeit unter "Auto-Vernichten-Domains" wieder aufgehoben werden.`
    );

    setBusy((b) => ({ ...b, [from_addr]: 'eradicate' }));
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
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[from_addr]; return n; });
    }
  };

  function extractDomain(addr) {
    const at = String(addr || '').lastIndexOf('@');
    if (at < 0) return '';
    return String(addr).slice(at + 1).toLowerCase().trim();
  }

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
        const needsManual  = lu && lu.api_status === 'needs_manual' && lu.manual_url;
        const lastFailed   = lu && lu.api_status === 'failed';
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
            <div className="mg-account__actions" style={{ marginTop: '0.5rem', display: 'flex', gap: '0.4rem', flexWrap: 'wrap' }}>
              {unsubscribed ? (
                <button className="mg-btn" disabled>✔ Bereits abgemeldet</button>
              ) : needsManual ? (
                <a
                  className="mg-btn mg-btn--primary"
                  href={lu.manual_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  title="Anbieter akzeptiert keine 1-Klick-Abmeldung — Formular im Browser abschließen."
                >
                  ↗ Abmelde-Seite öffnen
                </a>
              ) : (
                <button
                  className="mg-btn mg-btn--primary"
                  disabled={!!busy[s.from_addr]}
                  onClick={() => unsub(s.from_addr)}
                >
                  {busy[s.from_addr] === 'unsub' ? '…' : '✉ Vom Newsletter abmelden'}
                </button>
              )}
              <button
                className="mg-btn mg-btn--warn"
                disabled={!!busy[s.from_addr]}
                onClick={() => block(s.from_addr)}
                title="Blacklist-Regel anlegen — künftige Mails dieses Absenders werden als gefährlich markiert und ggf. automatisch quarantänisiert"
              >
                {busy[s.from_addr] === 'block' ? '…' : '⛔ Sender blockieren'}
              </button>
              <button
                className="mg-btn mg-btn--warn"
                disabled={!!busy[s.from_addr]}
                onClick={() => blockDomain(s.from_addr)}
                title="Ganze Absender-Domain in Blacklist — faengt auch andere Absender unter dieser Domain ab"
              >
                {busy[s.from_addr] === 'bl_domain' ? '…' : '⛔ Domain blockieren'}
              </button>
              {unsubscribed && s.msg_count > 0 && (
                <button
                  className="mg-btn"
                  disabled={!!busy[s.from_addr]}
                  onClick={() => purge(s.from_addr, s.msg_count)}
                  title="Alle Mails dieses Absenders in den Papierkorb verschieben und künftige automatisch aussortieren"
                >
                  {busy[s.from_addr] === 'purge'
                    ? '…'
                    : `🗑 ${s.msg_count} aufräumen + blockieren`}
                </button>
              )}
              {s.msg_count > 0 && (
                <button
                  className="mg-btn mg-btn--danger"
                  disabled={!!busy[s.from_addr]}
                  onClick={() => eradicate(s.from_addr, s.msg_count)}
                  title="Best-effort abmelden + Sender blockieren + ALLE bestehenden Mails ENDGÜLTIG löschen (kein Undo)"
                >
                  {busy[s.from_addr] === 'eradicate'
                    ? '…'
                    : `💥 Sender vernichten (${s.msg_count})`}
                </button>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function History({ accountId }) {
  const [items, setItems]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [error, setError]   = useState(null);
  const [page, setPage]     = useState(1);
  const [total, setTotal]   = useState(0);

  // Beim Postfach-Wechsel: zurueck auf Seite 1, sonst zeigt der Pager unpassende
  // Seiten fuer das neu gewaehlte Konto.
  useEffect(() => { setPage(1); }, [accountId]);

  const load = useCallback(async () => {
    setError(null);
    try {
      const acc = accountId ? `&account_id=${accountId}` : '';
      const { status, body } = await apiGet(`unsubs?page=${page}&per_page=25${acc}`);
      if (status >= 400) setError('HTTP ' + status);
      else { setItems(body.items); setTotal(body.total); }
    } catch (e) { setError(String(e)); }
  }, [page, accountId]);

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
        const manualUrl = u.api_detail && typeof u.api_detail.manual_url === 'string' ? u.api_detail.manual_url : '';
        const needsManual = u.api_status === 'needs_manual' && manualUrl;
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
            {(needsManual || u.kind === 'mailto' || u.api_message_id) && (
              <div style={{ marginTop: '0.5rem', display: 'flex', gap: '0.4rem', flexWrap: 'wrap' }}>
                {needsManual && (
                  <a
                    className="mg-btn mg-btn--primary"
                    href={manualUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Anbieter akzeptiert keine 1-Klick-Abmeldung — Formular im Browser abschließen."
                  >
                    ↗ Abmelde-Seite öffnen
                  </a>
                )}
                {(u.kind === 'mailto' || u.api_message_id) && (
                  <button className="mg-btn" disabled={!!busy[u.id]} onClick={() => refresh(u.id)}>
                    {busy[u.id] === 'status' ? '…' : '↻ Status'}
                  </button>
                )}
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

/**
 * Fasst die drei Teilergebnisse einer "Sender vernichten"-Aktion in einem
 * User-lesbaren Text zusammen. Zeigt jede Teil-Aktion als eigene Zeile, damit
 * der User sieht, was durchging und was nicht (z.B. Abmeldung tot, Purge ok).
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
 * Baut aus der Unsub-API-Antwort eine für den User lesbare Fehlermeldung.
 * Priorisiert `detail` (Backend-Erklärung) über den letzten attempt-Status
 * über den generischen error-Code. HTTP 409 = Doppelklick-Lock, 422 = keine
 * Optionen im List-Unsubscribe-Header — beide brauchen eigene Texte, weil
 * der User sonst denkt, das System sei kaputt.
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

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}
