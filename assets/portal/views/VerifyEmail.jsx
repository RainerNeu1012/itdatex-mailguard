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

  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  if (state.loading) {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">Prüfe Token</div>
            <div className="mg-auth__sub">…</div>
          </div>
        </div>
      </div>
    );
  }

  if (state.ok) {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">✓ Bestätigt</div>
            <div className="mg-auth__sub">E-Mail verifiziert · Weiterleitung …</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center', fontSize: '0.9rem' }}>
            Dein Konto ist aktiv. Du wirst in 3 Sekunden zur Anmeldung weitergeleitet.
          </p>
          <button
            className="mg-btn mg-btn--primary mg-auth__submit"
            style={{ marginTop: '1rem' }}
            onClick={() => navigate(state.email ? `login?email=${encodeURIComponent(state.email)}&next=accounts/new` : 'login')}
          >
            Jetzt anmelden
          </button>
        </div>
      </div>
    );
  }
  return (
    <div className="mg-auth">
      <div className="mg-auth__card">
        <div className="mg-auth__brand">
          <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
          <div className="mg-auth__title">✘ Fehlgeschlagen</div>
          <div className="mg-auth__sub">Token ungültig</div>
        </div>
        <div className="mg-error" style={{ marginBottom: '0.75rem' }}>{state.error}</div>
        <p className="mg-muted" style={{ textAlign: 'center', fontSize: '0.9rem' }}>
          Der Link ist möglicherweise abgelaufen (24h). Lass dir bei der Anmeldung eine neue Mail schicken oder registriere dich neu.
        </p>
        <button className="mg-btn mg-btn--primary mg-auth__submit" style={{ marginTop: '1rem' }} onClick={() => navigate('login')}>
          Zur Anmeldung
        </button>
      </div>
    </div>
  );
}

function humanError(body) {
  const e = body && body.error;
  if (e === 'invalid_or_expired_token') return 'Der Bestätigungs-Link ist ungültig oder abgelaufen.';
  return 'Unbekannter Fehler beim Bestätigen.';
}
