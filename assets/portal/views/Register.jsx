import React, { useState } from 'react';
import { apiPost } from '../api.js';
import { navigate } from '../router.js';

export default function Register() {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [pass2, setPass2]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);
  const [done, setDone]         = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setError(null);
    if (password !== pass2) {
      setError('Die Passwörter stimmen nicht überein.');
      return;
    }
    if (password.length < 10) {
      setError('Passwort muss mindestens 10 Zeichen lang sein.');
      return;
    }
    setLoading(true);
    try {
      const { status, body } = await apiPost('register', { email, password });
      if (status === 200 && body.ok) {
        setDone(body.verification_sent ? 'verify' : 'login');
        return;
      }
      setError(humanError(body, status));
    } catch (e) { setError(String(e)); }
    finally     { setLoading(false); }
  };

  if (done === 'verify') {
    return (
      <div className="mg-card">
        <h2>Fast geschafft</h2>
        <p>Wir haben dir gerade eine Bestätigungs-Mail an <strong>{email}</strong> geschickt.</p>
        <p>Klicke den Link in der Mail, um dein Konto zu aktivieren.</p>
        <p className="mg-muted">Keine Mail bekommen? Spam-Ordner checken, sonst Support kontaktieren.</p>
      </div>
    );
  }
  if (done === 'login') {
    return (
      <div className="mg-card">
        <h2>Konto angelegt</h2>
        <p>Du kannst dich jetzt anmelden.</p>
        <button className="mg-btn mg-btn--primary" onClick={() => navigate('login')}>Zur Anmeldung</button>
      </div>
    );
  }

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>Konto anlegen</h2>
      <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" /></label>
      <label>Passwort (min. 10 Zeichen)<input type="password" required minLength={10} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" /></label>
      <label>Passwort wiederholen<input type="password" required minLength={10} value={pass2} onChange={(e) => setPass2(e.target.value)} autoComplete="new-password" /></label>
      {error && <div className="mg-error">{error}</div>}
      <button type="submit" className="mg-btn mg-btn--primary" disabled={loading}>
        {loading ? '…' : 'Registrieren'}
      </button>
      <div className="mg-form__links">
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>Bereits Konto? Anmelden</a>
      </div>
    </form>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'email_already_registered') return 'Diese E-Mail-Adresse ist bereits registriert.';
  if (e === 'registration_disabled')    return 'Registrierung ist auf dieser Seite nicht freigegeben.';
  if (e === 'bad_input')                return body.field === 'email' ? 'Bitte gültige E-Mail-Adresse eingeben.' : 'Eingabe ungültig.';
  if (e === 'rate_limited')             return 'Zu viele Versuche. Bitte später erneut.';
  return 'Fehler bei der Registrierung (HTTP ' + status + ').';
}
