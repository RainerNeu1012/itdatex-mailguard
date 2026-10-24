import React, { useEffect, useState } from 'react';

// Theme-Toggle: dark / light / auto. Default = auto (folgt prefers-color-scheme).
// Explizite Wahl kommt in localStorage und wird via <html data-theme> gesetzt.

const KEY = 'mg_theme';

function readPref() {
  try { return localStorage.getItem(KEY) || 'auto'; } catch { return 'auto'; }
}
function writePref(v) {
  try {
    if (v === 'auto') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, v);
  } catch { /* ignore */ }
}
function applyTheme(pref) {
  const root = document.documentElement;
  if (pref === 'auto') root.removeAttribute('data-theme');
  else root.setAttribute('data-theme', pref);
}

// Boot-time: lies die Preference und wende sie an, damit der Theme-Switch
// nicht nach dem React-Mount sichtbar wird.
if (typeof document !== 'undefined') {
  applyTheme(readPref());
}

export default function ThemeToggle() {
  const [pref, setPref] = useState(readPref);

  useEffect(() => { applyTheme(pref); writePref(pref); }, [pref]);

  const cycle = () => {
    setPref((p) => (p === 'auto' ? 'light' : p === 'light' ? 'dark' : 'auto'));
  };

  const icon = pref === 'light' ? '☀' : pref === 'dark' ? '☾' : '◐';
  const label = pref === 'light' ? 'LIGHT' : pref === 'dark' ? 'DARK' : 'AUTO';

  return (
    <button
      type="button"
      className="mg-nav__btn mg-theme-toggle"
      onClick={cycle}
      title={`Theme: ${label} (klick zum Wechseln)`}
    >
      <span className="mg-nav__kbd" aria-hidden="true">{icon}</span>
      <span className="mg-nav__label">{label}</span>
    </button>
  );
}
