import React, { useState, useEffect, useRef } from 'react';
import { apiGet, apiPost } from '../api.js';
import { navigate } from '../router.js';

const EMAIL_RE = /^[^\s@]+@([^\s@]+\.[^\s@]+)$/;

export default function Register() {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [pass2, setPass2]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);
  const [done, setDone]         = useState(null);
  const [discover, setDiscover] = useState(null);
  const lastQuery = useRef('');

  useEffect(() => {
    if (!email.match(EMAIL_RE)) { setDiscover(null); return; }
    if (email === lastQuery.current) return;
    const t = setTimeout(async () => {
      lastQuery.current = email;
      try {
        const { status, body } = await apiGet('imap/discover-public?email=' + encodeURIComponent(email));
        if (status === 200 && body.ok && body.found) setDiscover(body.config);
        else setDiscover({ none: true });
      } catch (e) {
        setDiscover(null);
      }
    }, 800);
    return () => clearTimeout(t);
  }, [email]);

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

  const pluginUrl = (window.itdatexMailguard || {}).pluginUrl || '';
  if (done === 'verify') {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">Fast geschafft</div>
            <div className="mg-auth__sub">Verification-Mail unterwegs</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center', fontSize: '0.9rem' }}>
            Wir haben dir eine Bestätigungs-Mail an <strong className="mg-mono">{email}</strong> geschickt. Klick den Link um dein Konto zu aktivieren.
          </p>
          <p className="mg-muted mg-tiny" style={{ textAlign: 'center', marginTop: '1rem' }}>
            Keine Mail? Spam-Ordner checken oder Support kontaktieren.
          </p>
        </div>
      </div>
    );
  }
  if (done === 'login') {
    return (
      <div className="mg-auth">
        <div className="mg-auth__card">
          <div className="mg-auth__brand">
            <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
            <div className="mg-auth__title">✓ Konto angelegt</div>
          </div>
          <p className="mg-muted" style={{ textAlign: 'center' }}>Du kannst dich jetzt anmelden.</p>
          <button className="mg-btn mg-btn--primary mg-auth__submit" onClick={() => navigate('login')} style={{ marginTop: '1rem' }}>
            Zur Anmeldung
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="mg-auth mg-auth--wide">
      <form className="mg-auth__card mg-auth__card--wide mg-form" onSubmit={submit}>
        <div className="mg-auth__brand">
          <img src={`${pluginUrl}assets/img/mark-white.svg`} alt="!tdatex" />
          <div className="mg-auth__title">
            MAIL<span className="mg-auth__title-accent">GUARD</span>
          </div>
          <div className="mg-auth__sub">Konto anlegen · 2 Minuten</div>
        </div>

        <ol className="mg-auth__steps mg-mono">
          <li><span>01</span>Konto anlegen · E-Mail bestätigen</li>
          <li><span>02</span>Postfach verbinden · Microsoft/Gmail per OAuth oder IMAP mit Autoconfig</li>
          <li><span>03</span>Scan läuft automatisch · Pattern · Spam-DB · Phishing + Google Safe Browsing</li>
        </ol>

        <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" placeholder="dein@mailpostfach.de" /></label>
        <ProviderHint cfg={discover} email={email} />
        <label>Passwort <span className="mg-muted mg-tiny">(min. 10 Zeichen)</span><input type="password" required minLength={10} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" placeholder="••••••••••" /></label>
        <label>Passwort wiederholen<input type="password" required minLength={10} value={pass2} onChange={(e) => setPass2(e.target.value)} autoComplete="new-password" placeholder="••••••••••" /></label>
        {error && <div className="mg-error">{error}</div>}
        <button type="submit" className="mg-btn mg-btn--primary mg-auth__submit" disabled={loading}>
          {loading ? '…' : 'Konto anlegen'}
        </button>
        <div className="mg-auth__links">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>← Bereits Konto? Anmelden</a>
        </div>
      </form>
    </div>
  );
}

function ProviderHint({ cfg, email }) {
  if (!cfg || !email.match(EMAIL_RE)) return null;
  if (cfg.none) return null; // unbekannter Provider — kein Hinweis, kein Druck

  if (cfg.no_imap) {
    return (
      <div className="mg-banner mg-banner--err">
        <strong>{cfg.domain} unterstützt kein IMAP.</strong>
        {cfg.note && <div className="mg-tiny">{cfg.note}</div>}
        <div className="mg-tiny" style={{ marginTop: 4 }}>Du kannst dich trotzdem registrieren, brauchst aber später eine andere E-Mail-Adresse zum Postfach-Verbinden.</div>
      </div>
    );
  }

  if (cfg.oauth_provider === 'microsoft') {
    return (
      <div className="mg-banner mg-banner--info">
        <strong>Microsoft-Konto erkannt.</strong>
        <div className="mg-tiny">Nach der Bestätigung kannst du dein Postfach mit einem Klick „Mit Microsoft verbinden" einbinden — kein App-Passwort nötig.</div>
      </div>
    );
  }
  if (cfg.oauth_provider === 'google') {
    return (
      <div className="mg-banner mg-banner--info">
        <strong>Gmail/Google-Konto erkannt.</strong>
        <div className="mg-tiny">Nach der Bestätigung kannst du dein Postfach mit einem Klick „Mit Google verbinden" einbinden — kein App-Passwort nötig.</div>
      </div>
    );
  }

  // Normales IMAP — Provider bekannt
  return (
    <div className="mg-banner mg-banner--ok">
      <strong>Provider erkannt:</strong> {cfg.host}:{cfg.port} ({cfg.encryption.toUpperCase()})
      {cfg.note && <div className="mg-tiny" style={{ marginTop: 4 }}>{cfg.note}</div>}
    </div>
  );
}

function humanError(body, status) {
  const e = body && body.error;
  if (e === 'email_already_registered') return 'Diese E-Mail-Adresse ist bereits registriert.';
  if (e === 'registration_disabled')    return 'Registrierung ist auf dieser Seite nicht freigegeben.';
  if (e === 'license_required')         return 'Der Site-Betreiber muss erst seine MailGuard-Lizenz aktivieren. Bitte später erneut versuchen.';
  if (e === 'bad_input')                return body.field === 'email' ? 'Bitte gültige E-Mail-Adresse eingeben.' : 'Eingabe ungültig.';
  if (e === 'rate_limited')             return 'Zu viele Versuche. Bitte später erneut.';
  return 'Fehler bei der Registrierung (HTTP ' + status + ').';
}
