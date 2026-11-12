import React, { useCallback, useEffect, useState } from 'react';
import { apiGet, apiPost } from '../api.js';
import { PageShell, PageTopbar, Section, SectionHeader } from '../components/Section.jsx';

/**
 * Zeigt Treffer aus XposedOrNot (Credential-Breach-DB) fuer die vom Customer
 * eingetragenen Postfach-Adressen. Hourly-Cron fuellt mg_breach_alerts,
 * dieses UI zeigt die Liste + Mark-All-Read Button.
 */
export default function Breaches({ me }) {
  const [items, setItems] = useState(null);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const { status, body } = await apiGet('me/breaches');
      if (status === 200 && body?.ok) {
        setItems(body.items || []);
        setUnread(body.unread_cnt || 0);
      } else {
        setError('HTTP ' + status);
      }
    } catch (e) { setError(String(e)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const markAllRead = async () => {
    setBusy(true);
    try {
      await apiPost('me/breaches/mark-read', {});
      setItems((prev) => prev.map((it) => ({ ...it, read_at: it.read_at || new Date().toISOString() })));
      setUnread(0);
    } finally { setBusy(false); }
  };

  return (
    <PageShell>
      <PageTopbar kicker="MAILGUARD · BREACH-MONITOR" user={me?.email} />
      <Section>
        <SectionHeader
          num="001"
          title="CREDENTIAL-BREACHES"
          right={unread > 0 ? (
            <button className="mg-btn" disabled={busy} onClick={markAllRead}>
              {busy ? '…' : `Alle ${unread} als gelesen markieren`}
            </button>
          ) : null}
        />
        <div className="mg-dash__shared-intro mg-mono">
          QUELLE: XPOSEDORNOT.COM · STUENDLICHE PRUEFUNG · JEDE REGISTRIERTE POSTFACH-ADRESSE
        </div>

        {error && <div className="mg-card mg-error">{error}</div>}
        {items === null && <div className="mg-card">Lade …</div>}
        {items && items.length === 0 && (
          <div className="mg-card mg-muted">
            Keine Breach-Treffer. Deine Adressen tauchen aktuell in keiner bekannten Credential-Leak-Datenbank auf.
            Die naechste Pruefung laeuft stuendlich im Hintergrund.
          </div>
        )}
        {items && items.length > 0 && (
          <div className="mg-stack">
            {items.map((it) => <BreachRow key={it.id} it={it} />)}
          </div>
        )}
      </Section>
    </PageShell>
  );
}

function BreachRow({ it }) {
  const unread = !it.read_at;
  const classes = Array.isArray(it.data_classes)
    ? it.data_classes
    : (typeof it.data_classes === 'string' && it.data_classes
        ? it.data_classes.split(';').map(s => s.trim()).filter(Boolean)
        : []);
  const count = Number(it.pwned_count || 0);
  return (
    <div className="mg-card" style={{
      borderLeft: unread ? '4px solid var(--mg-err, #f85149)' : '4px solid transparent',
      paddingLeft: '0.9rem',
    }}>
      <div style={{display:'flex',alignItems:'baseline',justifyContent:'space-between',gap:12,flexWrap:'wrap'}}>
        <div>
          <strong style={{fontSize:'1.1rem'}}>{it.breach_name || '(unbekannter Leak)'}</strong>
          {unread && <span className="mg-pill mg-pill--err" style={{marginLeft:8}}>NEU</span>}
        </div>
        <span className="mg-mono" style={{color:'var(--mg-muted)',fontSize:'0.9rem'}}>
          {it.breach_date ? String(it.breach_date).slice(0,10) : '—'}
        </span>
      </div>
      <div style={{marginTop:6,color:'var(--mg-muted)',fontSize:'0.95rem'}}>
        Betroffene Adresse: <code>{it.email}</code>
        {count > 0 && <> · <strong>{count.toLocaleString('de-DE')}</strong> Datensaetze geleakt</>}
      </div>
      {classes.length > 0 && (
        <div style={{marginTop:8,display:'flex',gap:6,flexWrap:'wrap'}}>
          {classes.map((c, i) => (
            <span key={i} className="mg-pill" style={{fontSize:'0.75rem'}}>{c}</span>
          ))}
        </div>
      )}
      <div style={{marginTop:10,fontSize:'0.9rem',color:'var(--mg-muted)'}}>
        <strong style={{color:'var(--mg-fg)'}}>Handlungsempfehlung:</strong>{' '}
        Passwort fuer <code>{it.email}</code> aendern, ueberall wo es ggf. wiederverwendet wurde.
        2FA aktivieren (siehe Plan-Seite) falls noch nicht geschehen.
      </div>
    </div>
  );
}
