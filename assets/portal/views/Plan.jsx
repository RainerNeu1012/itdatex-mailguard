import React, { useState, useEffect, useCallback } from 'react';
import { apiGet, apiPost, apiDelete } from '../api.js';
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
        <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',flex:'1 1 220px'}}>
          <p style={{margin:0,fontFamily:'ui-monospace,Menlo,monospace',fontSize:'1rem',color:'#6e7681',letterSpacing:'0.08em',textTransform:'uppercase'}}>// plan</p>
          <p style={{margin:'0.4rem 0 0',fontSize:'1.6rem',fontWeight:600,color:planInfo.color}}>{planInfo.name}</p>
          <p style={{margin:'0.2rem 0 0',color:'var(--mg-muted)'}}>{planInfo.price}</p>
        </div>
        <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',flex:'1 1 220px'}}>
          <p style={{margin:0,fontFamily:'ui-monospace,Menlo,monospace',fontSize:'1rem',color:'#6e7681',letterSpacing:'0.08em',textTransform:'uppercase'}}>// status</p>
          <p style={{margin:'0.4rem 0 0',fontSize:'1.6rem',fontWeight:600,color:statusInfo.color}}>{statusInfo.label}</p>
          {me.plan_grace_until && (
            <p style={{margin:'0.2rem 0 0',color:'var(--mg-muted)',fontSize:'1rem'}}>Daten gelöscht am {new Date(me.plan_grace_until + 'Z').toLocaleDateString('de-DE')}</p>
          )}
        </div>
      </div>

      <p style={{color:'var(--mg-muted)',marginBottom:'1.25rem'}}>{inUseHint}</p>

      {planHasLlm && (
        <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',marginBottom:'1.25rem'}}>
          <p style={{margin:'0 0 0.4rem',fontWeight:600}}>KI-Tiefenanalyse via Ollama Cloud</p>
          <p style={{margin:'0 0 0.75rem',color:'var(--mg-muted)',fontSize:'1rem'}}>
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

      <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',margin:'1.25rem 0 0'}}>
        <p style={{margin:'0 0 0.5rem',fontWeight:600}}>Zusammenfassungs-Report per E-Mail</p>
        <p style={{margin:'0 0 0.8rem',color:'var(--mg-muted)',fontSize:'0.95rem'}}>
          Regelmäßiger Report an {me.email}: wie viele Phishing-Mails wurden abgefangen, wie viele Newsletter aussortiert, Top-Absender.
        </p>
        <select
          value={me.digest_frequency || 'off'}
          onChange={async (e) => {
            const freq = e.target.value;
            await apiPost('me/digest', { frequency: freq });
            if (reloadMe) await reloadMe();
          }}
          style={{padding:'0.4rem 0.6rem',borderRadius:'4px',background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',color:'var(--mg-fg)'}}
        >
          <option value="off">Aus</option>
          <option value="daily">Täglich</option>
          <option value="weekly">Wöchentlich</option>
        </select>
      </div>

      <TwoFactorPanel me={me} reloadMe={reloadMe} />
      <PasskeysPanel me={me} reloadMe={reloadMe} />

      {error && (
        <div style={{marginTop:'1rem',padding:'0.85rem 1rem',background:'rgba(248,81,73,0.08)',border:'1px solid var(--mg-err)',borderRadius:'6px',color:'var(--mg-err)'}}>
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
    <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',margin:'1.25rem 0 0'}}>
      <p style={{margin:'0 0 0.5rem',fontWeight:600}}>
        Zwei-Faktor-Authentifizierung (TOTP)
        {me.two_fa_enabled && <span className="mg-pill mg-pill--ok" style={{marginLeft:8}}>✓ aktiv</span>}
      </p>
      <p style={{margin:'0 0 0.8rem',color:'var(--mg-muted)',fontSize:'0.95rem'}}>
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
          <p style={{margin:0,fontSize:'0.85rem',color:'var(--mg-muted)'}}>
            Alternativ Secret eintippen: <code style={{background:'var(--mg-surface)',padding:'2px 6px',borderRadius:'3px',fontSize:'0.9rem'}}>{setupData.secret}</code>
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
              style={{display:'block',marginTop:4,padding:'0.5rem 0.75rem',fontFamily:'var(--mg-mono)',letterSpacing:'0.2em',fontSize:'1.2rem',textAlign:'center',width:'180px',background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',color:'var(--mg-fg)',borderRadius:4}}
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
        <div style={{marginTop:'1rem',padding:'1rem',background:'rgba(63,185,80,0.08)',border:'1px solid var(--mg-ok)',borderRadius:'6px'}}>
          <p style={{margin:'0 0 0.5rem',fontWeight:600,color:'var(--mg-ok)'}}>✓ 2FA aktiviert — bitte Backup-Codes sichern!</p>
          <p style={{margin:'0 0 0.6rem',fontSize:'0.9rem',color:'var(--mg-muted)'}}>
            Diese Codes werden dir NUR EINMAL angezeigt. Speichere sie in deinem Passwort-Manager. Jeder Code ist einmal verwendbar als Ersatz für den App-Code wenn du dein Handy nicht hast.
          </p>
          <div style={{display:'grid',gridTemplateColumns:'repeat(2,1fr)',gap:6,fontFamily:'var(--mg-mono)',fontSize:'1rem',letterSpacing:'0.08em',background:'var(--mg-surface)',padding:12,borderRadius:4}}>
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
            style={{padding:'0.4rem 0.6rem',fontFamily:'var(--mg-mono)',background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',color:'var(--mg-fg)',borderRadius:4,width:200}}
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

function PasskeysPanel({ me, reloadMe }) {
  const [items, setItems] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const supported = typeof window !== 'undefined' && !!window.PublicKeyCredential;

  const load = useCallback(async () => {
    const { status, body } = await apiGet('me/webauthn/credentials');
    if (status === 200 && body?.ok) setItems(body.items || []);
  }, []);
  useEffect(() => { load(); }, [load]);

  const registerKey = async () => {
    setBusy(true); setErr(null);
    try {
      const { status, body } = await apiPost('me/webauthn/register-options', {});
      if (status !== 200 || !body?.ok) { setErr('Setup fehlgeschlagen'); return; }
      const opts = body.options;
      // lbuchs liefert bereits publicKey — der Browser erwartet nested: { publicKey }
      const pk = opts.publicKey || opts;
      // Challenge + user.id + excludeCredentials[].id sind base64url-Strings — nach ArrayBuffer wandeln
      pk.challenge = b64urlToBuf(pk.challenge);
      if (pk.user?.id) pk.user.id = b64urlToBuf(pk.user.id);
      if (Array.isArray(pk.excludeCredentials)) {
        pk.excludeCredentials = pk.excludeCredentials.map(c => ({ ...c, id: b64urlToBuf(c.id) }));
      }
      const cred = await navigator.credentials.create({ publicKey: pk });
      if (!cred) { setErr('Abgebrochen'); return; }
      const name = prompt('Name fuer den Passkey (z.B. "MacBook Touch ID"):') || '';
      const payload = {
        name,
        client_data_json:    bufToB64url(cred.response.clientDataJSON),
        attestation_object:  bufToB64url(cred.response.attestationObject),
      };
      const { status: s2, body: b2 } = await apiPost('me/webauthn/register', payload);
      if (s2 !== 200 || !b2?.ok) { setErr('Registrierung fehlgeschlagen'); return; }
      await load();
      if (reloadMe) await reloadMe();
    } catch (e) {
      setErr(String(e.message || e));
    } finally { setBusy(false); }
  };

  const revokeKey = async (id) => {
    if (!confirm('Diesen Passkey entfernen? Danach koennen User sich mit diesem Hardware-Key nicht mehr anmelden.')) return;
    await apiDelete(`me/webauthn/credentials/${id}`);
    await load();
    if (reloadMe) await reloadMe();
  };

  return (
    <div style={{background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'8px',padding:'1.25rem 1.5rem',margin:'1.25rem 0 0'}}>
      <p style={{margin:'0 0 0.5rem',fontWeight:600}}>
        Passkeys / Hardware-Keys
        {items && items.length > 0 && <span className="mg-pill mg-pill--ok" style={{marginLeft:8}}>✓ {items.length} aktiv</span>}
      </p>
      <p style={{margin:'0 0 0.8rem',color:'var(--mg-muted)',fontSize:'0.95rem'}}>
        WebAuthn-basierte Anmeldung mit YubiKey, Touch-ID, Windows-Hello oder Platform-Authenticator. Ersetzt den TOTP-Code beim Login. Mehrere Keys moeglich (Backup auf zweitem Geraet).
      </p>
      {!supported && (
        <div className="mg-error">Dein Browser unterstuetzt WebAuthn nicht (benoetigt Chrome/Edge/Safari/Firefox ab 2019).</div>
      )}
      {supported && (
        <>
          <button className="mg-btn mg-btn--primary" disabled={busy} onClick={registerKey}>
            {busy ? '…' : '+ Neuen Passkey hinzufuegen'}
          </button>
          {items && items.length > 0 && (
            <div style={{marginTop:'0.75rem',display:'flex',flexDirection:'column',gap:6}}>
              {items.map(k => (
                <div key={k.id} style={{display:'flex',justifyContent:'space-between',alignItems:'center',padding:'6px 0',borderTop:'1px solid var(--mg-border)'}}>
                  <div>
                    <div style={{fontSize:'0.95rem'}}>{k.name || 'Passkey'}</div>
                    <div style={{fontSize:'0.8rem',color:'var(--mg-muted)'}}>
                      erstellt {String(k.created_at).slice(0,10)}
                      {k.last_used_at && <> · zuletzt {String(k.last_used_at).slice(0,10)}</>}
                    </div>
                  </div>
                  <button className="mg-btn" onClick={() => revokeKey(k.id)} style={{fontSize:'0.8rem',padding:'4px 8px'}}>
                    Entfernen
                  </button>
                </div>
              ))}
            </div>
          )}
        </>
      )}
      {err && <div className="mg-error" style={{marginTop:8}}>{err}</div>}
    </div>
  );
}

function b64urlToBuf(s) {
  if (typeof s !== 'string') return s;
  const pad = '='.repeat((4 - s.length % 4) % 4);
  const b64 = (s + pad).replace(/-/g, '+').replace(/_/g, '/');
  const bin = atob(b64);
  const buf = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
  return buf.buffer;
}
function bufToB64url(buf) {
  const bytes = new Uint8Array(buf);
  let bin = '';
  for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
