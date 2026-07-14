// Minimaler History-API-Router. Spart ~30 KB ggü. react-router-dom.

import { useEffect, useState } from 'react';

const PORTAL_BASE = (((window.itdatexMailguard || {}).portalUrl || '/portal/')
  .replace(/^https?:\/\/[^/]+/, '')
  .replace(/\/$/, ''));

export function currentRoute() {
  const path = window.location.pathname;
  if (!path.startsWith(PORTAL_BASE)) return { name: 'login', params: {} };
  // Tauri startet mit `/index.html` als Frame-URL — für den Router ist das
  // aber die "home"-Route. Wir schneiden den index.html-Suffix ab, bevor
  // das Rest-Matching läuft. Web-Version unberührt (dort ist die Portal-
  // Root ohnehin per Rewrite-Rule ohne index.html erreichbar).
  const rest = path.slice(PORTAL_BASE.length)
    .replace(/^\//, '')
    .replace(/\/$/, '')
    .replace(/^index\.html$/, '');
  const search = new URLSearchParams(window.location.search);
  const params = Object.fromEntries(search.entries());
  // Flache Routen
  switch (rest) {
    case '':                return { name: 'home',             params };
    case 'login':           return { name: 'login',            params };
    case 'register':        return { name: 'register',         params };
    case 'verify-email':    return { name: 'verify-email',     params };
    case 'forgot-password': return { name: 'forgot-password',  params };
    case 'reset-password':  return { name: 'reset-password',   params };
    case 'dashboard':       return { name: 'dashboard',        params };
    case 'logout':          return { name: 'logout',           params };
    case 'accounts':        return { name: 'accounts',         params };
    case 'accounts/new':    return { name: 'account-new',      params };
    case 'inbox':           return { name: 'inbox',            params };
    case 'newsletters':     return { name: 'newsletters',      params };
    case 'scanner':         return { name: 'scanner',          params };
    case 'rules':           return { name: 'rules',            params };
    case 'eradicate-domains': return { name: 'eradicate-domains', params };
    case 'plan':            return { name: 'plan',             params };
    case 'actions':         return { name: 'actions',          params };
    case 'devices':         return { name: 'devices',          params };
    case 'llm-feedback':    return { name: 'llm-feedback',     params };
  }
  // accounts/{id}/edit
  const m = rest.match(/^accounts\/(\d+)\/edit$/);
  if (m) {
    return { name: 'account-edit', params: { ...params, id: m[1] } };
  }
  return { name: 'not-found', params };
}

export function navigate(route, opts = {}) {
  const url = PORTAL_BASE + '/' + route.replace(/^\//, '');
  if (opts.replace) {
    window.history.replaceState({}, '', url);
  } else {
    window.history.pushState({}, '', url);
  }
  window.dispatchEvent(new PopStateEvent('popstate'));
}

export function useRoute() {
  const [route, setRoute] = useState(currentRoute);
  useEffect(() => {
    const handler = () => setRoute(currentRoute());
    window.addEventListener('popstate', handler);
    return () => window.removeEventListener('popstate', handler);
  }, []);
  return route;
}

export function portalUrl(path = '') {
  return PORTAL_BASE + (path ? '/' + path.replace(/^\//, '') : '/');
}
