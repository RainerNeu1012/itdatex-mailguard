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
import PostboxRules   from './views/PostboxRules.jsx';
import EradicateDomainsView from './views/EradicateDomains.jsx';
import Plan           from './views/Plan.jsx';
import Actions        from './views/Actions.jsx';
import Devices        from './views/Devices.jsx';
import LlmFeedback    from './views/LlmFeedback.jsx';
import NotificationsBell from './components/NotificationsBell.jsx';
import ThemeToggle from './components/ThemeToggle.jsx';
import BrandMark from './components/BrandMark.jsx';
import Sidebar from './components/Sidebar.jsx';
import CommandPalette from './components/CommandPalette.jsx';

const ROUTE_TITLES = {
  dashboard: 'Dashboard',
  inbox: 'Inbox',
  newsletters: 'Newsletter',
  scanner: 'Scanner',
  rules: 'Spam-Regeln',
  'postbox-rules': 'Postfach-Regeln',
  'eradicate-domains': 'Auto-Vernichten',
  actions: 'Aktionen',
  'llm-feedback': 'KI-Bewertungen',
  accounts: 'Postfächer',
  'account-new': 'Postfach anlegen',
  'account-edit': 'Postfach bearbeiten',
  plan: 'Plan & Lizenz',
  devices: 'Geräte',
};
function routeTitle(route) {
  return ROUTE_TITLES[route?.name] || (route?.name || '').replace(/-/g, ' ');
}

function LiveClock() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  const h = String(now.getUTCHours()).padStart(2, '0');
  const m = String(now.getUTCMinutes()).padStart(2, '0');
  const s = String(now.getUTCSeconds()).padStart(2, '0');
  return <span className="mg-topbar__clock mg-mono">{h}:{m}:{s} UTC</span>;
}

const KBD_ROUTES = {
  '1': 'dashboard',
  '2': 'inbox',
  '3': 'newsletters',
  '4': 'scanner',
  '5': 'postbox-rules',
  '6': 'eradicate-domains',
  '7': 'actions',
  '8': 'llm-feedback',
  '9': 'accounts',
};

export default function App() {
  const route = useRoute();
  const { me, loading, refresh } = useMe();
  const [paletteOpen, setPaletteOpen] = useState(false);

  // Global Keyboard-Shortcuts
  useEffect(() => {
    const onKey = (e) => {
      const t = e.target;
      const isInput = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
      // ⌘K / Ctrl+K oeffnet Palette (auch in Inputs)
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setPaletteOpen(true);
        return;
      }
      if (isInput) return;
      // Alt+1..9 wechselt Views
      if (e.altKey && KBD_ROUTES[e.key]) {
        e.preventDefault();
        navigate(KBD_ROUTES[e.key]);
        return;
      }
      // / oeffnet Palette als Spotlight-Shortcut
      if (e.key === '/' && !e.altKey && !e.metaKey && !e.ctrlKey) {
        e.preventDefault();
        setPaletteOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

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

  const requiresAuth   = [ 'dashboard', 'accounts', 'account-new', 'account-edit', 'inbox', 'newsletters', 'scanner', 'rules', 'postbox-rules', 'eradicate-domains', 'plan', 'actions', 'devices' ];
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

  if (isMarketing) {
    return (
      <div className="mg-shell mg-shell--marketing">
        <header className="mg-header">
          <a href="https://guard.itdatex.support/" className="mg-brand">
            <BrandMark className="mg-brand__logo" size={28} />
            <span className="mg-brand__txt">
              <span className="mg-brand__name">MAIL<span className="mg-brand__accent">GUARD</span></span>
              <span className="mg-brand__sub">// saas</span>
            </span>
          </a>
          <nav className="mg-nav mg-nav--marketing">
            <a href="https://guard.itdatex.support/#plans">Pläne</a>
            <a href="https://guard.itdatex.support/#features">Features</a>
            <a href="https://guard.itdatex.support/#faq">FAQ</a>
            {route.name !== 'login' && (
              <a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>Login</a>
            )}
            <ThemeToggle />
          </nav>
        </header>
        <main className="mg-main">
          <Page route={route} me={me} reloadMe={refresh} />
        </main>
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
      </div>
    );
  }

  return (
    <div className="mg-shell mg-shell--app">
      <Sidebar route={route} me={me} onOpenPalette={() => setPaletteOpen(true)} />

      <div className="mg-shell__main">
        <header className="mg-topbar">
          <div className="mg-topbar__crumbs">
            <span className="mg-topbar__crumb mg-topbar__crumb--kicker mg-mono">MAILGUARD</span>
            <span className="mg-topbar__crumb-sep" aria-hidden="true">›</span>
            <span className="mg-topbar__crumb mg-topbar__crumb--active">{routeTitle(route)}</span>
          </div>
          <div className="mg-topbar__status">
            <LiveClock />
            <span className="mg-dash__dot mg-dash__dot--live" aria-hidden="true" />
            <span className="mg-topbar__status-label mg-mono">LIVE</span>
          </div>
          <button
            type="button"
            className="mg-topbar__search"
            onClick={() => setPaletteOpen(true)}
            title="Suche (⌘K)"
          >
            <span className="mg-topbar__search-icon" aria-hidden="true">⌕</span>
            <span className="mg-topbar__search-label">Suche</span>
            <span className="mg-topbar__search-kbd mg-mono">⌘K</span>
          </button>
          <NotificationsBell />
          <ThemeToggle />
        </header>

        <main className="mg-main">
          <Page route={route} me={me} reloadMe={refresh} />
          {me && me.cloud_consent_required && <CloudConsentModal onDone={refresh} />}
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
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
    case 'postbox-rules':   return PostboxRules;
    case 'eradicate-domains': return EradicateDomainsView;
    case 'plan':            return Plan;
    case 'actions':         return Actions;
    case 'llm-feedback':    return LlmFeedback;
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
