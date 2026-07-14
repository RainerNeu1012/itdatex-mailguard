import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost, apiDelete } from '../api.js';

/**
 * Verwaltet die Auto-Vernichten-Liste. Jede eingetragene Domain sorgt dafür,
 * dass PullService jede neue Mail dieser Domain direkt beim IMAP-Fetch
 * verwirft, bevor sie in mg_messages landet.
 *
 * Zwei Wege, wie ein Eintrag hierher kommt:
 *  1. Über das "Vernichten"-Häkchen im Inbox/Newsletters-Flow — dort setzt
 *     der User Sender-Eradicate + Domain-Häkchen in einem Rutsch.
 *  2. Direkt hier, für Domains, die schon lange nerven, aber gerade keine
 *     aktuelle Mail zum Rechtsklicken haben.
 */
export default function EradicateDomains() {
  const [tab, setTab] = useState('domains');
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Auto-Vernichten</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          Filter die vor dem Ingest greifen — die Mail landet nie in deiner Inbox,
          wird direkt am IMAP-Server verworfen. Zwei Achsen: einzelne Absender-Domains
          oder ganze Top-Level-Endungen (Geo-/TLD-Block).
        </p>
        <div className="mg-form__row" style={{ marginTop: '0.75rem', gap: '0.4rem' }}>
          <button
            className={'mg-btn ' + (tab === 'domains' ? 'mg-btn--primary' : '')}
            onClick={() => setTab('domains')}
          >Absender-Domains</button>
          <button
            className={'mg-btn ' + (tab === 'tlds' ? 'mg-btn--primary' : '')}
            onClick={() => setTab('tlds')}
          >TLD-Sperre</button>
        </div>
      </div>
      {tab === 'domains' ? <DomainsList /> : <TldsList />}
    </div>
  );
}

function DomainsList() {
  const [items, setItems]   = useState(null);
  const [error, setError]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [form, setForm]     = useState({ domain: '', purge_history: false });
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    const { status, body } = await apiGet('me/eradicate-domains');
    if (status >= 400) setError('HTTP ' + status);
    else setItems(body.items || []);
  }, []);

  useEffect(() => { load(); }, [load]);

  const add = async (e) => {
    e.preventDefault();
    const domain = (form.domain || '').trim().toLowerCase();
    if (!domain) return;

    // Confirm-Zwang analog zum Backend-Guard. Der User tippt "VERNICHTEN"
    // ins Prompt — sowohl UX-Bremsklotz gegen Muscle-Memory als auch API-
    // Vorgabe (Server 422't ohne).
    const answer = window.prompt(
      `Alle zukünftigen Mails von *@${domain} automatisch vernichten?\n\n` +
      (form.purge_history
        ? `Zusätzlich werden alle bereits eingegangenen Mails der Domain ENDGÜLTIG gelöscht (kein Papierkorb, kein Undo).\n\n`
        : `Bereits in der Inbox liegende Mails bleiben unangetastet — nur neue Zustellungen ab jetzt.\n\n`
      ) +
      `Zur Bestätigung "VERNICHTEN" eintippen:`
    );
    if (answer === null) return;
    if (answer.trim().toUpperCase() !== 'VERNICHTEN') {
      alert('Abgebrochen — Bestätigung stimmt nicht.');
      return;
    }

    setSaving(true); setError(null);
    try {
      const { status, body } = await apiPost('me/eradicate-domains', {
        domain,
        purge_history: !!form.purge_history,
        confirm: 'VERNICHTEN',
      });
      if (status >= 400 || !body?.ok) {
        setError(body?.message || body?.error || ('HTTP ' + status));
        return;
      }
      if (body.purge) {
        const p = body.purge;
        alert(
          (body.existed ? 'Domain war bereits aktiv.' : `Domain ${body.domain} aktiviert.`) +
          `\n\nHistorien-Löschung: ${p.purged} gelöscht` +
          (p.skipped ? ` · ${p.skipped} übersprungen` : '') +
          (p.failed  ? ` · ${p.failed} fehlgeschlagen`  : '')
        );
      }
      setForm({ domain: '', purge_history: false });
      load();
    } finally {
      setSaving(false);
    }
  };

  const remove = async (id, domain) => {
    if (!window.confirm(`Auto-Vernichten für *@${domain} wieder aufheben?\n\nZukünftige Mails der Domain landen dann wieder ganz normal in deiner Inbox.`)) return;
    setBusy((b) => ({ ...b, [id]: 'del' }));
    try {
      const { status, body } = await apiDelete(`me/eradicate-domains/${id}`);
      if (status >= 400) {
        alert('Löschen fehlgeschlagen: ' + (body?.message || body?.error || ('HTTP ' + status)));
        return;
      }
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  return (
    <>
      <form className="mg-card mg-form" onSubmit={add}>
        <h3 style={{ margin: '0 0 0.5rem' }}>Neue Domain</h3>
        <label>Domain
          <input
            type="text"
            required
            value={form.domain}
            onChange={(e) => setForm({ ...form, domain: e.target.value })}
            placeholder="example.com"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
          />
        </label>
        <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexDirection: 'row' }}>
          <input
            type="checkbox"
            checked={form.purge_history}
            onChange={(e) => setForm({ ...form, purge_history: e.target.checked })}
          />
          <span>Auch alle bereits eingegangenen Mails dieser Domain endgültig löschen</span>
        </label>
        {error && <div className="mg-error">{error}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <button type="submit" className="mg-btn mg-btn--danger" disabled={saving}>
            {saving ? '…' : '+ Hinzufügen'}
          </button>
        </div>
      </form>

      {items === null && <div className="mg-card">Lade …</div>}
      {items !== null && items.length === 0 && (
        <div className="mg-card mg-muted">
          Noch keine Domain auf Auto-Vernichten. Sobald du eine hinzufügst, filtert
          MailGuard passende Mails direkt am IMAP-Server, bevor sie in deine
          Inbox laufen.
        </div>
      )}
      {items !== null && items.length > 0 && (
        <div className="mg-card">
          <table className="mg-rules">
            <thead>
              <tr>
                <th>Domain</th>
                <th>Aktiv seit</th>
                <th>Zuletzt gefangen</th>
                <th>Treffer</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((r) => (
                <tr key={r.id}>
                  <td><code>*@{r.domain}</code></td>
                  <td className="mg-muted mg-tiny">{fmtDate(r.created_at)}</td>
                  <td className="mg-muted mg-tiny">{r.last_hit_at ? fmtDate(r.last_hit_at) : '—'}</td>
                  <td>{r.hit_count}</td>
                  <td>
                    <button
                      className="mg-btn"
                      disabled={!!busy[r.id]}
                      onClick={() => remove(r.id, r.domain)}
                    >
                      {busy[r.id] === 'del' ? '…' : '×'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

// TLD-Sperre: gleiche Wirkung wie Absender-Domain, aber matched per
// Endung. `.tm`, `.icu`, `co.uk` — was auf `.<tld>` endet wird beim
// naechsten Pull direkt am IMAP-Server verworfen.
function TldsList() {
  const [items, setItems] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy]   = useState({});
  const [tld, setTld]     = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    const { status, body } = await apiGet('me/blocked-tlds');
    if (status >= 400) setError('HTTP ' + status);
    else setItems(body.items || []);
  }, []);

  useEffect(() => { load(); }, [load]);

  const add = async (e) => {
    e.preventDefault();
    const v = (tld || '').trim().toLowerCase().replace(/^\.+/, '');
    if (!v) return;
    if (!window.confirm(`Alle Mails deren Absender auf ".${v}" endet automatisch verwerfen?\n\nSchon in der Inbox liegende Mails bleiben. Nur neue Zustellungen ab jetzt.`)) return;
    setSaving(true); setError(null);
    try {
      const { status, body } = await apiPost('me/blocked-tlds', { tld: v });
      if (status >= 400 || !body?.ok) {
        setError(body?.error || ('HTTP ' + status));
        return;
      }
      setTld('');
      load();
    } finally { setSaving(false); }
  };

  const remove = async (id, v) => {
    if (!window.confirm(`TLD-Sperre für ".${v}" wieder aufheben? Mails der Endung landen dann wieder in deiner Inbox.`)) return;
    setBusy((b) => ({ ...b, [id]: 'del' }));
    try {
      const { status, body } = await apiDelete(`me/blocked-tlds/${id}`);
      if (status >= 400) { alert('Löschen fehlgeschlagen: HTTP ' + status); return; }
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const SUGGESTIONS = ['tm', 'tk', 'ml', 'ga', 'cf', 'icu', 'top', 'xyz', 'rest', 'zip'];

  return (
    <>
      <form className="mg-card mg-form" onSubmit={add}>
        <h3 style={{ margin: '0 0 0.5rem' }}>Neue TLD sperren</h3>
        <label>Endung (ohne führenden Punkt)
          <input
            type="text"
            required
            value={tld}
            onChange={(e) => setTld(e.target.value)}
            placeholder="tm  oder  co.uk"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
          />
        </label>
        <div className="mg-muted mg-tiny">
          Schnellauswahl:{' '}
          {SUGGESTIONS.map((s) => (
            <button
              key={s}
              type="button"
              className="mg-btn mg-tiny"
              style={{ padding: '2px 8px', fontSize: 11, marginRight: 4 }}
              onClick={() => setTld(s)}
            >.{s}</button>
          ))}
        </div>
        {error && <div className="mg-error">{error}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <button type="submit" className="mg-btn mg-btn--danger" disabled={saving}>
            {saving ? '…' : '+ Hinzufügen'}
          </button>
        </div>
      </form>

      {items === null && <div className="mg-card">Lade …</div>}
      {items !== null && items.length === 0 && (
        <div className="mg-card mg-muted">
          Noch keine TLD gesperrt. Beispiele wie <code>.tm</code>, <code>.icu</code>,{' '}
          <code>.tk</code> sind statistisch fast ausschliesslich Spam-Quellen — ein
          Klick oben blockiert sie komplett.
        </div>
      )}
      {items !== null && items.length > 0 && (
        <div className="mg-card">
          <table className="mg-rules">
            <thead>
              <tr>
                <th>Endung</th>
                <th>Aktiv seit</th>
                <th>Zuletzt gefangen</th>
                <th>Treffer</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((r) => (
                <tr key={r.id}>
                  <td><code>*.{r.tld}</code></td>
                  <td className="mg-muted mg-tiny">{fmtDate(r.created_at)}</td>
                  <td className="mg-muted mg-tiny">{r.last_hit_at ? fmtDate(r.last_hit_at) : '—'}</td>
                  <td>{r.hit_count}</td>
                  <td>
                    <button
                      className="mg-btn"
                      disabled={!!busy[r.id]}
                      onClick={() => remove(r.id, r.tld)}
                    >
                      {busy[r.id] === 'del' ? '…' : '×'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
}
