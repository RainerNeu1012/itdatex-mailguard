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

  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  if (!token) {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">Kein Token</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center' }}>Der Reset-Link ist unvollständig. Bitte erneut anfordern.</p>
          <button className="mg-btn mg-btn--primary mg-auth__submit" onClick={() => navigate('forgot-password')} style={{ marginTop: '1rem' }}>Neuen Link anfordern</button>
        </div>
      </div>
    );
  }

  if (done) {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">✔ Erledigt</div>
            <div className="mg-auth__sub">Passwort gesetzt</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center' }}>Du kannst dich jetzt mit dem neuen Passwort anmelden.</p>
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
          <div className="mg-auth__title">Neues Passwort</div>
          <div className="mg-auth__sub">min. 10 Zeichen</div>
        </div>
        <label>Neues Passwort<input type="password" required minLength={10} value={password} onChange={(e) => setPassword(e.target.value)} autoFocus autoComplete="new-password" placeholder="••••••••••" /></label>
        <label>Passwort wiederholen<input type="password" required minLength={10} value={pass2} onChange={(e) => setPass2(e.target.value)} autoComplete="new-password" placeholder="••••••••••" /></label>
        {error && <div className="mg-error">{error}</div>}
        <button type="submit" className="mg-btn mg-btn--primary mg-auth__submit" disabled={loading}>{loading ? '…' : 'Passwort speichern'}</button>
      </form>
    </div>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'invalid_or_expired_token') return 'Der Reset-Link ist ungültig oder abgelaufen.';
  if (e === 'bad_input')                return 'Eingabe ungültig.';
  return 'Fehler beim Speichern (HTTP ' + status + ').';
}
