import React, { useState } from 'react';
import { apiGet, apiPost } from '../api.js';
import { PageShell, Section, SectionHeader } from '../components/Section.jsx';

const PLAN_LABELS = {
  free: { name: 'Free',  price: '0 €',         color: '#8b949e' },
  solo: { name: 'Solo',  price: '5 € / Monat', color: '#f0f6fc' },
  plus: { name: 'Plus',  price: '15 € / Monat', color: '#f0f6fc' },
  pro:  { name: 'Pro',   price: '39 € / Monat', color: '#f0f6fc' },
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
    <PageShell>
      <Section>
        <SectionHeader num="001" title="PLAN & ABRECHNUNG" counter={planInfo.name.toUpperCase()} />
        <div className="mg-card">
      <div style={{display:'flex',gap:'1.25rem',flexWrap:'wrap',margin:'0.5rem 0 1.5rem'}}>
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

      <div style={{background:'#0d1117',border:'1px solid #30363d',borderRadius:'8px',padding:'1.25rem 1.5rem',margin:'1.25rem 0 0'}}>
        <p style={{margin:'0 0 0.5rem',fontWeight:600}}>Zusammenfassungs-Report per E-Mail</p>
        <p style={{margin:'0 0 0.8rem',color:'#8b949e',fontSize:'0.95rem'}}>
          Regelmäßiger Report an {me.email}: wie viele Phishing-Mails wurden abgefangen, wie viele Newsletter aussortiert, Top-Absender.
        </p>
        <select
          value={me.digest_frequency || 'off'}
          onChange={async (e) => {
            const freq = e.target.value;
            await apiPost('me/digest', { frequency: freq });
            if (reloadMe) await reloadMe();
          }}
          style={{padding:'0.4rem 0.6rem',borderRadius:'4px',background:'#0d1117',border:'1px solid #30363d',color:'#f0f6fc'}}
        >
          <option value="off">Aus</option>
          <option value="daily">Täglich</option>
          <option value="weekly">Wöchentlich</option>
        </select>
      </div>

      <TwoFactorPanel me={me} reloadMe={reloadMe} />

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
      </Section>
    </PageShell>
  );
}

function TwoFactorPanel({ me, reloadMe }) {
  const [setupData, setSetupData] = useState(null); // {secret, qr_url, otpauth}
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [backupCodes, setBackupCodes] = useState(null);
  const [disablingCode, setDisablingCode] = useState('');

  const startSetup = async () => {
    setBusy(true); setErr(null);
    try {
      const { body } = await apiPost('me/2fa/setup', {});
      if (body?.ok) setSetupData(body);
      else setErr(body?.message || 'Setup fehlgeschlagen');
    } finally { setBusy(false); }
  };

  const activate = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      const { body } = await apiPost('me/2fa/activate', { code });
      if (body?.ok) {
        setBackupCodes(body.backup_codes || []);
        setSetupData(null);
        setCode('');
        if (reloadMe) await reloadMe();
      } else {
        setErr(body?.message || 'Code falsch');
      }
    } finally { setBusy(false); }
  };

  const disable = async () => {
    if (!disablingCode) return;
    setBusy(true); setErr(null);
    try {
      const { body } = await apiPost('me/2fa/disable', { code: disablingCode });
      if (body?.ok) {
        setDisablingCode('');
        if (reloadMe) await reloadMe();
      } else {
        setErr(body?.message || 'Deaktivierung fehlgeschlagen');
      }
    } finally { setBusy(false); }
  };

  return (
    <div style={{background:'#0d1117',border:'1px solid #30363d',borderRadius:'8px',padding:'1.25rem 1.5rem',margin:'1.25rem 0 0'}}>
      <p style={{margin:'0 0 0.5rem',fontWeight:600}}>
        Zwei-Faktor-Authentifizierung (TOTP)
        {me.two_fa_enabled && <span className="mg-pill mg-pill--ok" style={{marginLeft:8}}>✓ aktiv</span>}
      </p>
      <p style={{margin:'0 0 0.8rem',color:'#8b949e',fontSize:'0.95rem'}}>
        Zusätzlicher 6-stelliger Code aus deiner Authenticator-App (Google Authenticator, Authy, 1Password, Bitwarden) bei jedem Login. Schützt vor Account-Takeover auch bei Passwort-Leak.
      </p>

      {!me.two_fa_enabled && !setupData && !backupCodes && (
        <button className="mg-btn mg-btn--primary" disabled={busy} onClick={startSetup}>
          {busy ? '…' : '2FA einrichten →'}
        </button>
      )}

      {setupData && (
        <form onSubmit={activate} style={{marginTop:'1rem',display:'flex',flexDirection:'column',gap:'0.75rem',alignItems:'flex-start'}}>
          <p style={{margin:0,fontSize:'0.95rem'}}>1. Scanne den QR mit deiner Authenticator-App:</p>
          <img src={setupData.qr_url} alt="2FA-QR" width={200} height={200} style={{background:'#fff',padding:'6px',borderRadius:'6px'}} />
          <p style={{margin:0,fontSize:'0.85rem',color:'#8b949e'}}>
            Alternativ Secret eintippen: <code style={{background:'#161b22',padding:'2px 6px',borderRadius:'3px',fontSize:'0.9rem'}}>{setupData.secret}</code>
          </p>
          <label style={{width:'100%'}}>2. Gib den aktuellen 6-stelligen Code ein:
            <input
              type="text"
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              required
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
              placeholder="123 456"
              style={{display:'block',marginTop:4,padding:'0.5rem 0.75rem',fontFamily:'var(--mg-mono)',letterSpacing:'0.2em',fontSize:'1.2rem',textAlign:'center',width:'180px',background:'#0d1117',border:'1px solid #30363d',color:'#f0f6fc',borderRadius:4}}
            />
          </label>
          <div style={{display:'flex',gap:8}}>
            <button type="submit" className="mg-btn mg-btn--primary" disabled={busy || code.length !== 6}>
              {busy ? '…' : 'Aktivieren'}
            </button>
            <button type="button" className="mg-btn" onClick={() => { setSetupData(null); setCode(''); }}>Abbrechen</button>
          </div>
        </form>
      )}

      {backupCodes && (
        <div style={{marginTop:'1rem',padding:'1rem',background:'rgba(63,185,80,0.08)',border:'1px solid #3fb950',borderRadius:'6px'}}>
          <p style={{margin:'0 0 0.5rem',fontWeight:600,color:'#3fb950'}}>✓ 2FA aktiviert — bitte Backup-Codes sichern!</p>
          <p style={{margin:'0 0 0.6rem',fontSize:'0.9rem',color:'#8b949e'}}>
            Diese Codes werden dir NUR EINMAL angezeigt. Speichere sie in deinem Passwort-Manager. Jeder Code ist einmal verwendbar als Ersatz für den App-Code wenn du dein Handy nicht hast.
          </p>
          <div style={{display:'grid',gridTemplateColumns:'repeat(2,1fr)',gap:6,fontFamily:'var(--mg-mono)',fontSize:'1rem',letterSpacing:'0.08em',background:'#0d1117',padding:12,borderRadius:4}}>
            {backupCodes.map((c, i) => <div key={i}>{c}</div>)}
          </div>
          <button className="mg-btn" style={{marginTop:8}} onClick={() => setBackupCodes(null)}>Verstanden</button>
        </div>
      )}

      {me.two_fa_enabled && !setupData && !backupCodes && (
        <div style={{marginTop:'0.75rem',display:'flex',gap:8,alignItems:'center',flexWrap:'wrap'}}>
          <input
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            maxLength={8}
            value={disablingCode}
            onChange={(e) => setDisablingCode(e.target.value.replace(/\D/g, ''))}
            placeholder="Code für Deaktivierung"
            style={{padding:'0.4rem 0.6rem',fontFamily:'var(--mg-mono)',background:'#0d1117',border:'1px solid #30363d',color:'#f0f6fc',borderRadius:4,width:200}}
          />
          <button className="mg-btn" disabled={busy || !disablingCode} onClick={disable} style={{color:'var(--mg-err)'}}>
            {busy ? '…' : '2FA deaktivieren'}
          </button>
        </div>
      )}

      {err && <div className="mg-error" style={{marginTop:8}}>{err}</div>}
    </div>
  );
}
