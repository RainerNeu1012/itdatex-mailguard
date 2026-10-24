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

  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  if (done) {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">Mail unterwegs</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center' }}>Falls die Adresse <strong>{email}</strong> bei uns registriert ist, haben wir gerade einen Reset-Link an dich geschickt (gültig 60 Minuten).</p>
          <button className="mg-btn mg-btn--primary mg-auth__submit" onClick={() => navigate('login')} style={{ marginTop: '1rem' }}>Zur Anmeldung</button>
        </div>
      </div>
    );
  }

  return (
    <div className="mg-auth">
      <form className="mg-auth__card mg-form" onSubmit={submit}>
        <div className="mg-auth__brand">
          <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
          <div className="mg-auth__title">Passwort reset</div>
          <div className="mg-auth__sub">E-Mail-Adresse eingeben</div>
        </div>
        <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" placeholder="dein@mailpostfach.de" /></label>
        {error && <div className="mg-error">{error}</div>}
        <button type="submit" className="mg-btn mg-btn--primary mg-auth__submit" disabled={loading}>{loading ? '…' : 'Reset-Link anfordern'}</button>
        <div className="mg-auth__links">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>← Zurück zur Anmeldung</a>
        </div>
      </form>
    </div>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'rate_limited') return 'Zu viele Versuche. Bitte später erneut.';
  return 'Fehler beim Anfordern (HTTP ' + status + ').';
}
