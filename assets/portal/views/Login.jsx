import React, { useState } from 'react';
import { apiPost } from '../api.js';
import { refreshMe } from '../auth.js';
import { navigate } from '../router.js';

export default function Login({ route }) {
  const initialEmail = (route && route.params && route.params.email) || '';
  const next         = (route && route.params && route.params.next)  || 'dashboard';
  const [email, setEmail]       = useState(initialEmail);
  const [password, setPassword] = useState('');
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setLoading(true); setError(null);
    try {
      const { status, body } = await apiPost('login', { email, password });
      if (status === 200 && body.ok) {
        await refreshMe();
        // next=accounts/new + email → Account-Form mit pre-filled Username
        if (next === 'accounts/new' && email) {
          navigate('accounts/new?email=' + encodeURIComponent(email));
        } else {
          navigate(next);
        }
        return;
      }
      setError(humanError(body, status));
    } catch (e) { setError(String(e)); }
    finally     { setLoading(false); }
  };

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>Anmelden</h2>
      <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" /></label>
      <label>Passwort<input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></label>
      {error && <div className="mg-error">{error}</div>}
      <button type="submit" className="mg-btn mg-btn--primary" disabled={loading}>
        {loading ? '…' : 'Anmelden'}
      </button>
      <div className="mg-form__links">
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('forgot-password'); }}>Passwort vergessen?</a>
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('register'); }}>Neu hier? Registrieren</a>
      </div>
    </form>
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
