import React, { useState } from 'react';
import { apiPost } from '../api.js';
import { refreshMe } from '../auth.js';
import { navigate } from '../router.js';

export default function Login({ route }) {
  const initialEmail = (route && route.params && route.params.email) || '';
  const next         = (route && route.params && route.params.next)  || 'dashboard';
  const [email, setEmail]       = useState(initialEmail);
  const [password, setPassword] = useState('');
  const [twoFaCode, setTwoFaCode] = useState('');
  const [twoFaRequired, setTwoFaRequired] = useState(false);
  const [rememberDevice, setRememberDevice] = useState(true);
  const [methods, setMethods] = useState({ totp: false, webauthn: false });
  const [waBusy, setWaBusy] = useState(false);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);
  // Reset-Flow: 'request' (Code anfordern) | 'confirm' (Code aus Mail eingeben) | 'done'
  const [resetMode, setResetMode] = useState(null);
  const [resetCode, setResetCode] = useState('');
  const [resetBusy, setResetBusy] = useState(false);
  const [resetInfo, setResetInfo] = useState(null);
  const [resetError, setResetError] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setLoading(true); setError(null);
    try {
      const payload = { email, password };
      if (twoFaRequired && twoFaCode) payload.two_fa_code = twoFaCode;
      if (twoFaRequired && rememberDevice) payload.remember_device = true;
      const { status, body } = await apiPost('login', payload);
      if (status === 200 && body.ok) {
        await refreshMe();
        if (next === 'accounts/new' && email) {
          navigate('accounts/new?email=' + encodeURIComponent(email));
        } else {
          navigate(next);
        }
        return;
      }
      // 2FA-Challenge: Password war korrekt, braucht noch Code (TOTP) oder Passkey
      if (body?.error === 'two_fa_required') {
        setTwoFaRequired(true);
        setMethods(body.methods || { totp: true, webauthn: false });
        const hint = body.methods?.webauthn
          ? (body.methods?.totp ? 'Mit Passkey anmelden ODER 6-stelligen Code eingeben.' : 'Mit Passkey anmelden.')
          : 'Zwei-Faktor-Code eingeben (6-stellig aus deiner Authenticator-App oder 8-stelliger Backup-Code).';
        setError(hint);
        return;
      }
      if (body?.error === 'webauthn_invalid') {
        setError('Passkey-Verifikation fehlgeschlagen.');
        return;
      }
      if (body?.error === 'two_fa_invalid') {
        setError('Zwei-Faktor-Code falsch oder abgelaufen.');
        setTwoFaCode('');
        return;
      }
      setError(humanError(body, status));
      setTwoFaRequired(false);
      setTwoFaCode('');
    } catch (e) { setError(String(e)); }
    finally     { setLoading(false); }
  };

  const useWebAuthn = async () => {
    if (!window.PublicKeyCredential) { setError('Dein Browser unterstuetzt WebAuthn nicht.'); return; }
    setWaBusy(true); setError(null);
    try {
      const { status, body } = await apiPost('webauthn/login-options', { email });
      if (status !== 200 || !body?.ok) { setError('Passkey-Anmeldung nicht verfuegbar.'); return; }
      const opts = body.options;
      const pk = opts.publicKey || opts;
      pk.challenge = b64urlToBuf(pk.challenge);
      if (Array.isArray(pk.allowCredentials)) {
        pk.allowCredentials = pk.allowCredentials.map(c => ({ ...c, id: b64urlToBuf(c.id) }));
      }
      const cred = await navigator.credentials.get({ publicKey: pk });
      if (!cred) { setError('Abgebrochen.'); return; }
      const payload = {
        email, password,
        remember_device: rememberDevice,
        webauthn: {
          credential_id:      bufToB64url(cred.rawId),
          client_data_json:   bufToB64url(cred.response.clientDataJSON),
          authenticator_data: bufToB64url(cred.response.authenticatorData),
          signature:          bufToB64url(cred.response.signature),
        },
      };
      const { status: s2, body: b2 } = await apiPost('login', payload);
      if (s2 === 200 && b2.ok) {
        await refreshMe();
        navigate(next === 'accounts/new' && email ? ('accounts/new?email=' + encodeURIComponent(email)) : next);
        return;
      }
      setError(b2?.error === 'webauthn_invalid' ? 'Passkey-Verifikation fehlgeschlagen.' : 'Login fehlgeschlagen.');
    } catch (e) {
      setError(String(e.message || e));
    } finally { setWaBusy(false); }
  };

  const requestReset = async () => {
    setResetBusy(true); setResetError(null);
    try {
      await apiPost('2fa/reset-request', { email });
      setResetMode('confirm');
      setResetInfo('Wir haben dir einen 6-stelligen Code an ' + email + ' geschickt. Code gueltig 15 Minuten.');
    } catch (e) {
      setResetError(String(e));
    } finally { setResetBusy(false); }
  };

  const confirmReset = async () => {
    setResetBusy(true); setResetError(null);
    try {
      const { status, body } = await apiPost('2fa/reset-confirm', { email, code: resetCode });
      if (status === 200 && body?.ok) {
        setResetMode('done');
        setResetInfo('2FA wurde entfernt. Melde dich jetzt mit E-Mail + Passwort an.');
        setTwoFaRequired(false);
        setTwoFaCode('');
        setError(null);
      } else {
        setResetError('Code falsch oder abgelaufen. Neuen Code anfordern?');
      }
    } catch (e) {
      setResetError(String(e));
    } finally { setResetBusy(false); }
  };

  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  return (
    <div className="mg-auth">
      <form className="mg-auth__card mg-form" onSubmit={submit}>
        <div className="mg-auth__brand">
          <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
          <div className="mg-auth__title">
            MAIL<span className="mg-auth__title-accent">GUARD</span>
          </div>
          <div className="mg-auth__sub">Anti-Phishing · Multi-Mailbox · Monochrom</div>
        </div>
        <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" placeholder="dein@mailpostfach.de" disabled={twoFaRequired} /></label>
        <label>Passwort<input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" placeholder="••••••••" disabled={twoFaRequired} /></label>
        {twoFaRequired && methods.webauthn && (
          <button type="button" className="mg-btn mg-btn--primary" disabled={waBusy} onClick={useWebAuthn}
            style={{marginTop:6}}>
            {waBusy ? '…' : '🔐 Mit Passkey anmelden'}
          </button>
        )}
        {twoFaRequired && methods.totp && (
          <>
            <label>Zwei-Faktor-Code
              <input
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                pattern="[0-9]*"
                maxLength={8}
                required
                value={twoFaCode}
                onChange={(e) => setTwoFaCode(e.target.value.replace(/\D/g, ''))}
                autoFocus
                placeholder="123 456"
                style={{ fontFamily: 'var(--mg-mono)', letterSpacing: '0.2em', fontSize: '1.3rem', textAlign: 'center' }}
              />
            </label>
            <label style={{display:'flex',alignItems:'center',gap:8,fontSize:'0.9rem',color:'var(--mg-muted)',flexDirection:'row'}}>
              <input type="checkbox" checked={rememberDevice} onChange={(e)=>setRememberDevice(e.target.checked)}
                style={{width:'auto',margin:0}} />
              Dieses Geraet 30 Tage merken (keine 2FA-Abfrage mehr auf diesem Browser)
            </label>
          </>
        )}
        {error && <div className="mg-error">{error}</div>}
        <button type="submit" className="mg-btn mg-btn--primary mg-auth__submit" disabled={loading || (twoFaRequired && twoFaCode.length < 6)}>
          {loading ? '…' : (twoFaRequired ? 'Code bestätigen' : 'Anmelden')}
        </button>

        {twoFaRequired && !resetMode && (
          <div style={{marginTop:'0.75rem',fontSize:'0.9rem',textAlign:'center'}}>
            <a href="#" onClick={(e) => { e.preventDefault(); setResetMode('request'); setResetInfo(null); setResetError(null); }}
               style={{color:'var(--mg-muted)'}}>
              2FA verloren? Code per E-Mail anfordern
            </a>
          </div>
        )}

        {resetMode === 'request' && (
          <div style={{marginTop:'0.75rem',padding:'0.85rem 1rem',background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'6px'}}>
            <p style={{margin:'0 0 0.6rem',fontSize:'0.9rem',color:'var(--mg-muted)'}}>
              Wir schicken einen 6-stelligen Reset-Code an <strong>{email || 'deine E-Mail'}</strong>. Nach Bestaetigung wird die Zwei-Faktor-Authentifizierung entfernt.
            </p>
            <div style={{display:'flex',gap:8}}>
              <button type="button" className="mg-btn mg-btn--primary" disabled={resetBusy || !email} onClick={requestReset}>
                {resetBusy ? '…' : 'Code anfordern'}
              </button>
              <button type="button" className="mg-btn" onClick={() => { setResetMode(null); setResetError(null); }}>Abbrechen</button>
            </div>
            {resetError && <div className="mg-error" style={{marginTop:8}}>{resetError}</div>}
          </div>
        )}

        {resetMode === 'confirm' && (
          <div style={{marginTop:'0.75rem',padding:'0.85rem 1rem',background:'var(--mg-surface-2)',border:'1px solid var(--mg-border)',borderRadius:'6px'}}>
            {resetInfo && <p style={{margin:'0 0 0.6rem',fontSize:'0.9rem',color:'var(--mg-muted)'}}>{resetInfo}</p>}
            <input
              type="text"
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              value={resetCode}
              onChange={(e) => setResetCode(e.target.value.replace(/\D/g, ''))}
              placeholder="123 456"
              style={{ width:'100%',padding:'0.5rem 0.75rem',fontFamily:'var(--mg-mono)',letterSpacing:'0.2em',fontSize:'1.2rem',textAlign:'center',background:'var(--mg-surface)',border:'1px solid var(--mg-border)',color:'var(--mg-fg)',borderRadius:4 }}
            />
            <div style={{display:'flex',gap:8,marginTop:8}}>
              <button type="button" className="mg-btn mg-btn--primary" disabled={resetBusy || resetCode.length !== 6} onClick={confirmReset}>
                {resetBusy ? '…' : '2FA entfernen'}
              </button>
              <button type="button" className="mg-btn" onClick={() => { setResetMode(null); setResetCode(''); setResetError(null); }}>Abbrechen</button>
            </div>
            {resetError && <div className="mg-error" style={{marginTop:8}}>{resetError}</div>}
          </div>
        )}

        {resetMode === 'done' && (
          <div style={{marginTop:'0.75rem',padding:'0.85rem 1rem',background:'rgba(63,185,80,0.08)',border:'1px solid var(--mg-ok)',borderRadius:'6px'}}>
            <p style={{margin:0,color:'var(--mg-ok)',fontSize:'0.95rem'}}>✓ {resetInfo}</p>
          </div>
        )}

        <div className="mg-auth__links">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('forgot-password'); }}>Passwort vergessen?</a>
          <span className="mg-auth__dot" aria-hidden="true">·</span>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('register'); }}>Neu hier? Registrieren</a>
        </div>
      </form>
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

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'invalid_credentials')   return 'E-Mail oder Passwort falsch.';
  if (e === 'email_not_verified')    return 'Bitte zuerst die E-Mail-Adresse bestätigen (Link in der Verification-Mail).';
  if (e === 'account_suspended')     return 'Konto ist gesperrt.';
  if (e === 'rate_limited')          return 'Zu viele Versuche. Bitte einen Moment warten.';
  if (status === 0)                  return 'Verbindung zum Server fehlgeschlagen.';
  return 'Fehler bei der Anmeldung (HTTP ' + status + ').';
}
