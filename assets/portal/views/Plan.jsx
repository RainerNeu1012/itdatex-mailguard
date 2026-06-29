import React, { useState } from 'react';
import { apiGet, apiPost } from '../api.js';

const PLAN_LABELS = {
  free: { name: 'Free',  price: '0 €',         color: '#8b949e' },
  solo: { name: 'Solo',  price: '5 € / Monat', color: '#58a6ff' },
  plus: { name: 'Plus',  price: '15 € / Monat', color: '#58a6ff' },
  pro:  { name: 'Pro',   price: '39 € / Monat', color: '#58a6ff' },
};

const STATUS_LABELS = {
  active:   { label: 'Aktiv',         color: '#3fb950' },
  past_due: { label: 'Zahlung offen', color: '#d29922' },
  canceled: { label: 'Gekündigt',     color: '#f85149' },
};

const PLAN_HAS_LLM = { solo: true, plus: true, pro: true, test: true };

export default function Plan({ me, reloadMe }) {
  const [busy, setBusy] = useState(false);
  const [consentBusy, setConsentBusy] = useState(false);
  const [error, setError] = useState('');
  if (!me) return null;

  const planInfo   = PLAN_LABELS[me.plan_slug] || PLAN_LABELS.free;
  const statusInfo = STATUS_LABELS[me.plan_status] || STATUS_LABELS.active;
  const planHasLlm = !!PLAN_HAS_LLM[me.plan_slug];
  const inUseHint  = `Postfach-Limit: ${me.imap_quota} · KI-Tiefenanalyse: ${me.llm_enabled ? 'an' : (planHasLlm ? 'aus (Einwilligung fehlt)' : 'nicht im Plan enthalten')}`;

  const onManageBilling = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await apiPost('saas/billing-portal', {});
      if (res.ok && res.url) {
        window.location.href = res.url;
      } else if (res.error === 'no_stripe_customer') {
        setError(res.hint || 'Im Free-Plan gibt es keine Stripe-Abrechnung.');
      } else {
        setError(res.error || 'Stripe-Portal konnte nicht geöffnet werden.');
      }
    } catch (e) {
      setError(String(e.message || e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mg-card">
      <h2>Plan &amp; Abrechnung</h2>

      <div style={{display:'flex',gap:'1.25rem',flexWrap:'wrap',margin:'1.5rem 0'}}>
        <div style={{background:'#0d1117',border:'1px solid #30363d',borderRadius:'8px',padding:'1.25rem 1.5rem',flex:'1 1 220px'}}>
          <p style={{margin:0,fontFamily:'ui-monospace,Menlo,monospace',fontSize:'1rem',color:'#6e7681',letterSpacing:'0.08em',textTransform:'uppercase'}}>// plan</p>
          <p style={{margin:'0.4rem 0 0',fontSize:'1.6rem',fontWeight:600,color:planInfo.color}}>{planInfo.name}</p>
          <p style={{margin:'0.2rem 0 0',color:'#8b949e'}}>{planInfo.price}</p>
        </div>
        <div style={{background:'#0d1117',border:'1px solid #30363d',borderRadius:'8px',padding:'1.25rem 1.5rem',flex:'1 1 220px'}}>
          <p style={{margin:0,fontFamily:'ui-monospace,Menlo,monospace',fontSize:'1rem',color:'#6e7681',letterSpacing:'0.08em',textTransform:'uppercase'}}>// status</p>
          <p style={{margin:'0.4rem 0 0',fontSize:'1.6rem',fontWeight:600,color:statusInfo.color}}>{statusInfo.label}</p>
          {me.plan_grace_until && (
            <p style={{margin:'0.2rem 0 0',color:'#8b949e',fontSize:'1rem'}}>Daten gelöscht am {new Date(me.plan_grace_until + 'Z').toLocaleDateString('de-DE')}</p>
          )}
        </div>
      </div>

      <p style={{color:'#8b949e',marginBottom:'1.25rem'}}>{inUseHint}</p>

      {planHasLlm && (
        <div style={{background:'#0d1117',border:'1px solid #30363d',borderRadius:'8px',padding:'1.25rem 1.5rem',marginBottom:'1.25rem'}}>
          <p style={{margin:'0 0 0.4rem',fontWeight:600}}>KI-Tiefenanalyse via Ollama Cloud</p>
          <p style={{margin:'0 0 0.75rem',color:'#8b949e',fontSize:'1rem'}}>
            Subject + Body verdächtiger Mails (Heuristik-Score 30–69, typisch &lt;10 % der Mails) gehen
            zur Bewertung an Ollama Inc., USA. Drittlandtransfer auf Grundlage der EU-Standardvertragsklauseln.
            Details: <a href="https://wp.itdatex.support/datenschutz/#sec-10-2" target="_blank" rel="noopener">Datenschutzerklärung 10.2</a>.
          </p>
          <label style={{display:'flex',gap:'0.5rem',alignItems:'center',cursor:'pointer'}}>
            <input
              type="checkbox"
              checked={!!me.cloud_consent_at}
              disabled={consentBusy}
              onChange={async (e) => {
                const accept = e.target.checked;
                if (!accept && !window.confirm('Wirklich widerrufen? Die KI-Tiefenanalyse wird sofort deaktiviert — der Scanner läuft dann nur mit lokaler Heuristik weiter.')) {
                  return;
                }
                setConsentBusy(true);
                try {
                  await apiPost('me/cloud-consent', { accept });
                  if (reloadMe) await reloadMe();
                } finally {
                  setConsentBusy(false);
                }
              }}
            />
            <span>{me.cloud_consent_at
              ? `Eingewilligt am ${new Date(me.cloud_consent_at + 'Z').toLocaleString('de-DE')} — Häkchen entfernen, um zu widerrufen`
              : 'Ich willige in die KI-Tiefenanalyse (Ollama Cloud, USA) ein'}</span>
          </label>
        </div>
      )}

      <div style={{display:'flex',gap:'0.75rem',flexWrap:'wrap'}}>
        {me.has_stripe_sub ? (
          <button className="mg-btn" disabled={busy} onClick={onManageBilling}>
            {busy ? 'Stripe wird geöffnet…' : 'Plan wechseln / kündigen →'}
          </button>
        ) : (
          <a className="mg-btn" href="https://guard.itdatex.support/#plans" target="_blank" rel="noopener">
            Plan upgraden →
          </a>
        )}
      </div>

      {error && (
        <div style={{marginTop:'1rem',padding:'0.85rem 1rem',background:'rgba(218,54,51,0.08)',border:'1px solid #da3633',borderRadius:'6px',color:'#ff7b72'}}>
          {error}
        </div>
      )}

      <p style={{color:'#6e7681',fontSize:'1rem',marginTop:'1.5rem'}}>
        Plan-Wechsel und Kündigung laufen über das Stripe-Customer-Portal. Du kannst dort
        Zahlungsmittel ändern, Rechnungen herunterladen, Plan wechseln oder dein Abo
        zum Monatsende kündigen.
      </p>
    </div>
  );
}
