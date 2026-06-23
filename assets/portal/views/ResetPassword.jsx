import React, { useState } from 'react';
import { apiPost } from '../api.js';
import { navigate } from '../router.js';

export default function ResetPassword({ route }) {
  const token = route.params.token || '';
  const [password, setPassword] = useState('');
  const [pass2, setPass2]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);
  const [done, setDone]         = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError(null);
    if (password !== pass2) { setError('Die Passwörter stimmen nicht überein.'); return; }
    if (password.length < 10) { setError('Passwort muss mindestens 10 Zeichen lang sein.'); return; }
    setLoading(true);
    try {
      const { status, body } = await apiPost('reset-password', { token, password });
      if (status === 200 && body.ok) setDone(true);
      else setError(humanError(body, status));
    } catch (e) { setError(String(e)); }
    finally     { setLoading(false); }
  };

  if (!token) {
    return (
      <div className="mg-card">
        <h2>✘ Kein Token</h2>
        <p>Der Reset-Link ist unvollständig. Bitte erneut anfordern.</p>
        <button className="mg-btn" onClick={() => navigate('forgot-password')}>Neuen Link anfordern</button>
      </div>
    );
  }

  if (done) {
    return (
      <div className="mg-card">
        <h2>✔ Passwort gesetzt</h2>
        <p>Du kannst dich jetzt mit dem neuen Passwort anmelden.</p>
        <button className="mg-btn mg-btn--primary" onClick={() => navigate('login')}>Zur Anmeldung</button>
      </div>
    );
  }

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>Neues Passwort setzen</h2>
      <label>Neues Passwort (min. 10 Zeichen)<input type="password" required minLength={10} value={password} onChange={(e) => setPassword(e.target.value)} autoFocus autoComplete="new-password" /></label>
      <label>Passwort wiederholen<input type="password" required minLength={10} value={pass2} onChange={(e) => setPass2(e.target.value)} autoComplete="new-password" /></label>
      {error && <div className="mg-error">{error}</div>}
      <button type="submit" className="mg-btn mg-btn--primary" disabled={loading}>{loading ? '…' : 'Passwort speichern'}</button>
    </form>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'invalid_or_expired_token') return 'Der Reset-Link ist ungültig oder abgelaufen.';
  if (e === 'bad_input')                return 'Eingabe ungültig.';
  return 'Fehler beim Speichern (HTTP ' + status + ').';
}
