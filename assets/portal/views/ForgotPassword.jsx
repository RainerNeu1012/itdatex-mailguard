import React, { useState } from 'react';
import { apiPost } from '../api.js';
import { navigate } from '../router.js';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [done, setDone]       = useState(false);
  const [error, setError]     = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setLoading(true); setError(null);
    try {
      const { status, body } = await apiPost('forgot-password', { email });
      if (status === 200 && body.ok) setDone(true);
      else setError(humanError(body, status));
    } catch (e) { setError(String(e)); }
    finally     { setLoading(false); }
  };

  if (done) {
    return (
      <div className="mg-card">
        <h2>Mail unterwegs</h2>
        <p>Falls die Adresse <strong>{email}</strong> bei uns registriert ist, haben wir gerade einen Reset-Link an dich geschickt (gültig 60 Minuten).</p>
        <button className="mg-btn" onClick={() => navigate('login')}>Zur Anmeldung</button>
      </div>
    );
  }

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>Passwort vergessen?</h2>
      <p className="mg-muted">Trag deine E-Mail-Adresse ein, wir schicken dir einen Reset-Link.</p>
      <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" /></label>
      {error && <div className="mg-error">{error}</div>}
      <button type="submit" className="mg-btn mg-btn--primary" disabled={loading}>{loading ? '…' : 'Reset-Link anfordern'}</button>
      <div className="mg-form__links">
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>Zurück zur Anmeldung</a>
      </div>
    </form>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'rate_limited') return 'Zu viele Versuche. Bitte später erneut.';
  return 'Fehler beim Anfordern (HTTP ' + status + ').';
}
