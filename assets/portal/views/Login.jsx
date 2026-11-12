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
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setLoading(true); setError(null);
    try {
      const payload = { email, password };
      if (twoFaRequired && twoFaCode) payload.two_fa_code = twoFaCode;
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
      // 2FA-Challenge: Password war korrekt, braucht noch Code
      if (body?.error === 'two_fa_required') {
        setTwoFaRequired(true);
        setError('Zwei-Faktor-Code eingeben (6-stellig aus deiner Authenticator-App oder 8-stelliger Backup-Code).');
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
        {twoFaRequired && (
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
        )}
        {error && <div className="mg-error">{error}</div>}
        <button type="submit" className="mg-btn mg-btn--primary mg-auth__submit" disabled={loading || (twoFaRequired && twoFaCode.length < 6)}>
          {loading ? '…' : (twoFaRequired ? 'Code bestätigen' : 'Anmelden')}
        </button>
        <div className="mg-auth__links">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('forgot-password'); }}>Passwort vergessen?</a>
          <span className="mg-auth__dot" aria-hidden="true">·</span>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('register'); }}>Neu hier? Registrieren</a>
        </div>
      </form>
    </div>
  );
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
