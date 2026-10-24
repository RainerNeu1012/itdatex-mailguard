import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';

// Theme-Vorapplikation vor dem Mount — verhindert FOUC beim Reload.
try {
  const saved = localStorage.getItem('mg_theme');
  if (saved === 'light' || saved === 'dark') {
    document.documentElement.setAttribute('data-theme', saved);
  }
} catch { /* ignore */ }

// PWA: Service-Worker registrieren. Portal-Rewrite serviert /portal/sw.js
// mit korrektem Content-Type (siehe src/Portal/Rewrite.php route __sw).
if ('serviceWorker' in navigator) {
  const portalUrl = (window.itdatexMailguard || {}).portalUrl || '/portal/';
  const swUrl = portalUrl.replace(/\/$/, '') + '/sw.js';
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(swUrl, { scope: portalUrl })
      .catch(() => { /* kein SW = kein PWA, kein Problem */ });
  });
}

const mount = document.getElementById('mg-portal');
if (mount) {
  createRoot(mount).render(<App />);
}
