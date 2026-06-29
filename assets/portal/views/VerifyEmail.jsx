import React, { useEffect, useState } from 'react';
import { apiPost } from '../api.js';
import { navigate } from '../router.js';

export default function VerifyEmail({ route }) {
  const token = route.params.token || '';
  const [state, setState] = useState({ loading: true, ok: false, email: '', error: null });

  useEffect(() => {
    if (!token) { setState({ loading: false, ok: false, email: '', error: 'Kein Token in der URL.' }); return; }
    apiPost('verify-email', { token })
      .then(({ status, body }) => {
        if (status === 200 && body.ok) setState({ loading: false, ok: true, email: body.email || '', error: null });
        else setState({ loading: false, ok: false, email: '', error: humanError(body) });
      })
      .catch((e) => setState({ loading: false, ok: false, email: '', error: String(e) }));
  }, [token]);

  // Auto-Redirect zu Login mit pre-filled Email nach 3 s
  useEffect(() => {
    if (!state.ok) return;
    const t = setTimeout(() => {
      const target = state.email ? `login?email=${encodeURIComponent(state.email)}&next=accounts/new` : 'login';
      navigate(target);
    }, 3000);
    return () => clearTimeout(t);
  }, [state.ok, state.email]);

  if (state.loading) return <div className="mg-card">Prüfe Token …</div>;

  if (state.ok) {
    return (
      <div className="mg-card">
        <h2>✔ E-Mail bestätigt</h2>
        <p>Dein Konto ist aktiv. Du wirst gleich zur Anmeldung weitergeleitet …</p>
        <button className="mg-btn mg-btn--primary" onClick={() => navigate(state.email ? `login?email=${encodeURIComponent(state.email)}&next=accounts/new` : 'login')}>Jetzt anmelden</button>
      </div>
    );
  }
  return (
    <div className="mg-card">
      <h2>✘ Bestätigung fehlgeschlagen</h2>
      <p className="mg-error">{state.error}</p>
      <p>Der Link ist möglicherweise abgelaufen (24h). Lass dir bei der Anmeldung eine neue Mail schicken oder registriere dich neu.</p>
      <button className="mg-btn" onClick={() => navigate('login')}>Zur Anmeldung</button>
    </div>
  );
}

function humanError(body) {
  const e = body && body.error;
  if (e === 'invalid_or_expired_token') return 'Der Bestätigungs-Link ist ungültig oder abgelaufen.';
  return 'Unbekannter Fehler beim Bestätigen.';
}
