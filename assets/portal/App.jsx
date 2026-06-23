import React, { useEffect } from 'react';
import { useRoute, navigate, portalUrl } from './router.js';
import { useMe, logoutCurrent } from './auth.js';

import Login          from './views/Login.jsx';
import Register       from './views/Register.jsx';
import VerifyEmail    from './views/VerifyEmail.jsx';
import ForgotPassword from './views/ForgotPassword.jsx';
import ResetPassword  from './views/ResetPassword.jsx';
import Dashboard      from './views/Dashboard.jsx';

export default function App() {
  const route = useRoute();
  const { me, loading } = useMe();

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

  const requiresAuth   = [ 'dashboard' ];
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

  return (
    <div className="mg-shell">
      <header className="mg-header">
        <a href={portalUrl()} className="mg-brand" onClick={(e) => { e.preventDefault(); navigate(me ? 'dashboard' : 'login'); }}>
          <span className="mg-brand__mark">M</span>
          <span>MailGuard</span>
        </a>
        {me && (
          <nav className="mg-nav">
            <span className="mg-nav__email">{me.email}</span>
            <button className="mg-nav__btn" onClick={() => navigate('logout')}>Logout</button>
          </nav>
        )}
      </header>
      <main className="mg-main">
        <Page route={route} me={me} />
      </main>
      <footer className="mg-footer">
        <span>itdatex MailGuard · v{(window.itdatexMailguard || {}).version || '?'}</span>
      </footer>
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
    case 'logout':          return Empty;
    default:                return NotFound;
  }
}

function Empty() { return null; }

function NotFound() {
  return (
    <div className="mg-card">
      <h2>Seite nicht gefunden</h2>
      <p><a href="#" onClick={(e) => { e.preventDefault(); navigate('login'); }}>← Zurück zur Anmeldung</a></p>
    </div>
  );
}
