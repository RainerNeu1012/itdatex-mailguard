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
    <div className="mg-stack">
      <div className="mg-card">
        <h2>Willkommen bei MailGuard</h2>
        <p>Mit deinem Konto bekommst du eine geschützte Inbox: jede eingehende Mail wird automatisch auf Phishing geprüft, Newsletter kannst du mit einem Klick abbestellen.</p>
        <p className="mg-muted">In drei Schritten:</p>
        <ol className="mg-muted" style={{ paddingLeft: '1.2rem', margin: 0 }}>
          <li>Konto anlegen und E-Mail bestätigen.</li>
          <li>IMAP-Postfach verbinden (SSL oder STARTTLS).</li>
          <li>Inbox öffnen — eingehende Mails werden im 15-Minuten-Takt geprüft.</li>
        </ol>
      </div>
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>Konto anlegen</h2>
      <label>E-Mail<input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus autoComplete="email" /></label>
      <ProviderHint cfg={discover} email={email} />
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
