import React, { useEffect } from 'react';

const SHORTCUTS = [
  { label: 'NAVIGATION', items: [
    { kbd: ['⌘', 'K'],  desc: 'Command-Palette öffnen (auch Strg+K oder /)' },
    { kbd: ['Alt', '1'], desc: 'Dashboard' },
    { kbd: ['Alt', '2'], desc: 'Inbox' },
    { kbd: ['Alt', '3'], desc: 'Newsletter' },
    { kbd: ['Alt', '4'], desc: 'Scanner' },
    { kbd: ['Alt', '5'], desc: 'Postfach-Regeln' },
    { kbd: ['Alt', '6'], desc: 'Auto-Vernichten' },
    { kbd: ['Alt', '7'], desc: 'Aktionen' },
    { kbd: ['Alt', '8'], desc: 'KI-Bewertungen' },
    { kbd: ['Alt', '9'], desc: 'Postfächer' },
  ]},
  { label: 'IN PALETTE', items: [
    { kbd: ['↑', '↓'],   desc: 'Durch Treffer navigieren' },
    { kbd: ['↵'],         desc: 'Ausgewählten Eintrag öffnen' },
    { kbd: ['ESC'],       desc: 'Schließen' },
  ]},
  { label: 'ALLGEMEIN', items: [
    { kbd: ['?'],         desc: 'Diese Hilfe anzeigen' },
  ]},
];

export default function ShortcutHelp({ open, onClose }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="mg-help-overlay" onClick={onClose}>
      <div className="mg-help" role="dialog" aria-modal="true" aria-labelledby="mg-help-title" onClick={(e) => e.stopPropagation()}>
        <div className="mg-help__head">
          <h2 id="mg-help-title" className="mg-help__title">Keyboard-Shortcuts</h2>
          <button type="button" className="mg-help__close" onClick={onClose} aria-label="Schließen">✕</button>
        </div>
        <div className="mg-help__groups">
          {SHORTCUTS.map((g) => (
            <section key={g.label} className="mg-help__group">
              <div className="mg-help__group-label mg-mono">{g.label}</div>
              <ul className="mg-help__list">
                {g.items.map((s, i) => (
                  <li key={i} className="mg-help__row">
                    <span className="mg-help__kbd-row">
                      {s.kbd.map((k, j) => (
                        <React.Fragment key={j}>
                          {j > 0 && <span className="mg-help__plus">+</span>}
                          <kbd className="mg-help__kbd">{k}</kbd>
                        </React.Fragment>
                      ))}
                    </span>
                    <span className="mg-help__desc">{s.desc}</span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
        <div className="mg-help__foot mg-mono">ESC ZU</div>
      </div>
    </div>
  );
}
