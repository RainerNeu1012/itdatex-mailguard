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

const mount = document.getElementById('mg-portal');
if (mount) {
  createRoot(mount).render(<App />);
}
