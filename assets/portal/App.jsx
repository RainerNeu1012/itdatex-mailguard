import React, { useEffect, useState } from 'react';
import { useRoute, navigate, portalUrl } from './router.js';
import { useMe, logoutCurrent } from './auth.js';
import { apiPost } from './api.js';

import Login          from './views/Login.jsx';
import Register       from './views/Register.jsx';
import VerifyEmail    from './views/VerifyEmail.jsx';
import ForgotPassword from './views/ForgotPassword.jsx';
import ResetPassword  from './views/ResetPassword.jsx';
import Dashboard      from './views/Dashboard.jsx';
import Accounts       from './views/Accounts.jsx';
import AccountForm    from './views/AccountForm.jsx';
import Inbox          from './views/Inbox.jsx';
import Newsletters    from './views/Newsletters.jsx';
import Scanner        from './views/Scanner.jsx';
import Rules          from './views/Rules.jsx';
import Plan           from './views/Plan.jsx';
import Actions        from './views/Actions.jsx';
import Devices        from './views/Devices.jsx';

export default function App() {
  const route = useRoute();
  const { me, loading, refresh } = useMe();
  const [menuOpen, setMenuOpen] = useState(false);

  // Menu schließt sich automatisch nach Route-Wechsel.
  useEffect(() => { setMenuOpen(false); }, [route.name]);

  // Home → automatisches Routing je nach Auth-State.
  useEffect(() => {
    if (loading) return;
    if (route.name === 'home') {
      navigate(me ? 'dashboard' : 'login', { replace: true });
    }
    if (route.name === 'logout') {
      logoutCurrent().then(() => navigate('login', { replace: true }));
    }
  }, [route.name, me, loading]);

  if (loading) {
    return <div className="mg-shell"><div className="mg-card">Lade …</div></div>;
  }

  const requiresAuth   = [ 'dashboard', 'accounts', 'account-new', 'account-edit', 'inbox', 'newsletters', 'scanner', 'rules', 'plan', 'actions', 'devices' ];
  const requiresAnon   = [ 'login', 'register', 'forgot-password' ];

  if (requiresAuth.includes(route.name) && !me) {
    navigate('login', { replace: true });
    return null;
  }
  if (requiresAnon.includes(route.name) && me) {
    navigate('dashboard', { replace: true });
    return null;
  }

  const Page = pickView(route.name);

  // Pre-Login-Pages bekommen die SaaS-Marketing-Shell (Brand "MAILGUARD // saas"
  // + Pläne/Features/FAQ-Nav + Legal-Footer) — exakt wie guard.itdatex.support und
  // /saas-onboard/*. Post-Login = klassischer App-Shell mit Tab-Nav.
  const isMarketing = !me;

  return (
    <div className={isMarketing ? 'mg-shell mg-shell--marketing' : 'mg-shell'}>
      <header className="mg-header">
        <a href={isMarketing ? 'https://guard.itdatex.support/' : portalUrl()}
           className="mg-brand"
           onClick={(e) => { if (!isMarketing) { e.preventDefault(); navigate('dashboard'); } }}>
          <img src={`${(window.itdatexMailguard||{}).pluginUrl || ''}assets/img/mark-white.svg`} alt="!tdatex" className="mg-brand__logo" />
          <span className="mg-brand__txt">
            <span className="mg-brand__name">MAIL<span className="mg-brand__accent">GUARD</span></span>
            <span className="mg-brand__sub">// {isMarketing ? 'saas' : 'portal'}</span>
          </span>
        </a>

        {isMarketing ? (
          <nav className="mg-nav mg-nav--marketing">
            <a href="https://guard.itdatex.support/#plans">Pläne</a>
            <a href="https://guard.itdatex.support/#features">Features</a>
            <a href="https://guard.itdatex.support/#faq">FAQ</a>
            {route.name !== 'login' && (
              <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>Login</a>
            )}
          </nav>
        ) : (
          <>
            <button
              type="button"
              className="mg-burger"
              aria-label="Menü"
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen((v) => !v)}
            >
              <span></span><span></span><span></span>
            </button>
            <nav className={menuOpen ? 'mg-nav mg-nav--open' : 'mg-nav'}>
              <button className="mg-nav__btn" onClick={() => navigate('dashboard')}>Dashboard</button>
              <button className="mg-nav__btn" onClick={() => navigate('inbox')}>Inbox</button>
              <button className="mg-nav__btn" onClick={() => navigate('newsletters')}>Newsletter</button>
              <button className="mg-nav__btn" onClick={() => navigate('scanner')}>Scanner</button>
              <button className="mg-nav__btn" onClick={() => navigate('rules')}>Regeln</button>
              <button className="mg-nav__btn" onClick={() => navigate('actions')}>Aktionen</button>
              <button className="mg-nav__btn" onClick={() => navigate('accounts')}>Postfächer</button>
              <button className="mg-nav__btn" onClick={() => navigate('plan')}>Plan</button>
              <button className="mg-nav__btn" onClick={() => navigate('devices')}>Geräte</button>
              <span className="mg-nav__email">{me.email}</span>
              <button className="mg-nav__btn" onClick={() => navigate('logout')}>Logout</button>
            </nav>
          </>
        )}
      </header>

      <main className="mg-main">
        <Page route={route} me={me} reloadMe={refresh} />
        {me && me.cloud_consent_required && <CloudConsentModal onDone={refresh} />}
      </main>

      {isMarketing ? (
        <footer className="mg-footer mg-footer--marketing">
          <span>© !tdatex 2026 · <a href="https://itdatex.support/">itdatex.support</a></span>
          <span>
            <a href="https://wp.itdatex.support/impressum/">Impressum</a> ·{' '}
            <a href="https://wp.itdatex.support/datenschutz/">Datenschutz</a> ·{' '}
            <a href="https://wp.itdatex.support/agb/">AGB</a> ·{' '}
            <a href="https://wp.itdatex.support/widerruf/">Widerruf</a> ·{' '}
            <a href="https://wp.itdatex.support/kuendigen/">Kündigen</a>
          </span>
        </footer>
      ) : (
        <footer className="mg-footer">
          <span>itdatex MailGuard · v{(window.itdatexMailguard || {}).version || '?'}</span>
        </footer>
      )}
    </div>
  );
}

function pickView(name) {
  switch (name) {
    case 'login':           return Login;
    case 'register':        return Register;
    case 'verify-email':    return VerifyEmail;
    case 'forgot-password': return ForgotPassword;
    case 'reset-password':  return ResetPassword;
    case 'dashboard':       return Dashboard;
    case 'accounts':        return Accounts;
    case 'account-new':     return AccountForm;
    case 'account-edit':    return AccountForm;
    case 'inbox':           return Inbox;
    case 'newsletters':     return Newsletters;
    case 'scanner':         return Scanner;
    case 'rules':           return Rules;
    case 'plan':            return Plan;
    case 'actions':         return Actions;
    case 'devices':         return Devices;
    case 'logout':          return Empty;
    default:                return NotFound;
  }
}

function Empty() { return null; }

function CloudConsentModal({ onDone }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const submit = async (accept) => {
    setBusy(true); setErr(null);
    try {
      const { status, body } = await apiPost('me/cloud-consent', { accept });
      if (status !== 200 || !body.ok) {
        setErr(body.error || ('HTTP ' + status));
        setBusy(false);
        return;
      }
      if (onDone) await onDone();
    } catch (e) {
      setErr(String(e));
      setBusy(false);
    }
  };
  return (
    <div style={{position:'fixed',inset:0,background:'rgba(0,0,0,0.75)',display:'flex',alignItems:'center',justifyContent:'center',zIndex:9999,padding:'1rem'}}>
      <div className="mg-card" style={{maxWidth:640,width:'100%',margin:0}}>
        <h2 style={{marginTop:0}}>Einwilligung KI-Tiefenanalyse erforderlich</h2>
        <p style={{color:'#8b949e'}}>
          Dein Plan enthält die <strong>KI-Tiefenanalyse</strong>. Für Mails mit Heuristik-Score
          30–69 (typisch &lt; 10 % der eingehenden Mails) brauchen wir deine Einwilligung,
          Subject und Body an <strong>Ollama Inc., 410 Townsend St., San Francisco, CA 94107, USA</strong>
          zur Bewertung zu übermitteln. Die Übermittlung in das Drittland USA erfolgt auf Grundlage der
          EU-Standardvertragsklauseln (SCC, Modul 2) gemäß Art.&nbsp;46 Abs.&nbsp;2 lit.&nbsp;c DSGVO.
        </p>
        <p style={{color:'#8b949e'}}>
          Details siehe <a href="https://wp.itdatex.support/datenschutz/#sec-10-2" target="_blank" rel="noopener">Datenschutzerklärung Abschnitt 10.2</a>.
          Du kannst die Einwilligung jederzeit unter <em>Plan</em> widerrufen — der Scanner läuft dann
          ausschließlich mit lokaler Heuristik weiter.
        </p>
        {err && <div className="mg-error" style={{margin:'0.75rem 0'}}>{err}</div>}
        <div style={{display:'flex',gap:'0.5rem',flexWrap:'wrap',marginTop:'1rem'}}>
          <button className="mg-btn mg-btn--primary" disabled={busy} onClick={() => submit(true)}>
            {busy ? '…' : 'Einwilligung erteilen'}
          </button>
          <button className="mg-btn" disabled={busy} onClick={() => submit(false)}>
            Ablehnen — nur Heuristik nutzen
          </button>
        </div>
        <p className="mg-tiny mg-muted" style={{marginTop:'1rem'}}>
          Die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung bleibt unberührt (Art.&nbsp;7 Abs.&nbsp;3 DSGVO).
        </p>
      </div>
    </div>
  );
}

function NotFound() {
  return (
    <div className="mg-card">
      <h2>Seite nicht gefunden</h2>
      <p><a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>← Zurück zur Anmeldung</a></p>
    </div>
  );
}
