import React, { useEffect, useRef, useState } from 'react';
import { navigate } from '../router.js';

/**
 * Linear/Raycast-style Command-Palette.
 *
 * - Trigger: ⌘K / Ctrl+K (System-weit)
 * - Fuzzy-Match über label + synonyms
 * - Pfeiltasten ↑↓ + Enter zum Ausführen, Esc zum Schließen
 * - Kategorien im Dropdown
 *
 * Keine Backend-API — alles clientseitig. Future: /api/me/recent-actions laden.
 */

const COMMANDS = [
  // Navigation
  { kind: 'nav', label: 'Dashboard',          path: 'dashboard',         synonyms: ['home', 'übersicht', 'overview'] },
  { kind: 'nav', label: 'Inbox',              path: 'inbox',             synonyms: ['mails', 'posteingang'] },
  { kind: 'nav', label: 'Newsletter',         path: 'newsletters',       synonyms: ['abos', 'subscriptions', 'abmelden'] },
  { kind: 'nav', label: 'Scanner',            path: 'scanner',           synonyms: ['url-check', 'mail-check', 'phishing'] },
  { kind: 'nav', label: 'Postfach-Regeln',    path: 'postbox-rules',     synonyms: ['filter', 'spam-regeln', 'rules'] },
  { kind: 'nav', label: 'Auto-Vernichten',    path: 'eradicate-domains', synonyms: ['domains', 'tlds', 'blocklist', 'purge'] },
  { kind: 'nav', label: 'Aktionen',           path: 'actions',           synonyms: ['audit', 'log', 'historie', 'undo', 'quarantäne'] },
  { kind: 'nav', label: 'KI-Bewertungen',     path: 'llm-feedback',      synonyms: ['llm', 'ai', 'feedback'] },
  { kind: 'nav', label: 'Postfächer',         path: 'accounts',          synonyms: ['imap', 'oauth', 'gmail', 'outlook'] },
  { kind: 'nav', label: 'Plan & Lizenz',      path: 'plan',              synonyms: ['abo', 'rechnung', 'stripe', 'billing', 'upgrade'] },
  { kind: 'nav', label: 'Geräte',             path: 'devices',           synonyms: ['sessions', 'tokens', 'devices', 'push'] },

  // Actions
  { kind: 'action', label: 'Jetzt abholen (Pull)',     path: 'inbox',          synonyms: ['pull', 'fetch', 'refresh'] },
  { kind: 'action', label: 'Neue Postfach-Regel',       path: 'postbox-rules',  synonyms: ['new rule', 'add rule'] },
  { kind: 'action', label: 'Postfach verbinden',        path: 'accounts/new',   synonyms: ['add account', 'imap', 'connect'] },
  { kind: 'action', label: 'Mail scannen',              path: 'scanner',        synonyms: ['check', 'test'] },
  { kind: 'action', label: 'Logout',                    path: 'logout',         synonyms: ['abmelden', 'sign out'] },
];

function fuzzyScore(q, text) {
  if (!q) return 1;
  q = q.toLowerCase();
  text = text.toLowerCase();
  if (text.includes(q)) {
    return text.startsWith(q) ? 100 : 70 - (text.indexOf(q) * 0.1);
  }
  // Char-by-char fuzzy
  let qi = 0;
  for (let i = 0; i < text.length && qi < q.length; i++) {
    if (text[i] === q[qi]) qi++;
  }
  return qi === q.length ? 30 - (text.length / q.length) * 2 : 0;
}

function searchCommands(q) {
  if (!q.trim()) return COMMANDS;
  const results = COMMANDS.map((c) => {
    const all = [c.label, ...(c.synonyms || [])];
    const best = Math.max(...all.map((s) => fuzzyScore(q, s)));
    return { ...c, _score: best };
  }).filter((c) => c._score > 0);
  results.sort((a, b) => b._score - a._score);
  return results;
}

export default function CommandPalette({ open, onClose }) {
  const [query, setQuery] = useState('');
  const [index, setIndex] = useState(0);
  const inputRef = useRef(null);
  const results = searchCommands(query);

  useEffect(() => {
    if (open) {
      setQuery('');
      setIndex(0);
      setTimeout(() => inputRef.current && inputRef.current.focus(), 10);
    }
  }, [open]);

  useEffect(() => { setIndex(0); }, [query]);

  const execute = (cmd) => {
    onClose();
    if (cmd && cmd.path) {
      // Mini-defer damit das Modal zuerst schließt bevor der Route-Change kommt
      setTimeout(() => navigate(cmd.path), 0);
    }
  };

  const onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); onClose(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(i + 1, results.length - 1)); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); setIndex((i) => Math.max(i - 1, 0)); return; }
    if (e.key === 'Enter') { e.preventDefault(); if (results[index]) execute(results[index]); return; }
  };

  if (!open) return null;
  return (
    <div className="mg-palette-overlay" onClick={onClose}>
      <div
        className="mg-palette"
        role="dialog"
        aria-modal="true"
        aria-label="Command Palette"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mg-palette__input-row">
          <span className="mg-palette__input-icon" aria-hidden="true">⌕</span>
          <input
            ref={inputRef}
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={onKey}
            placeholder="Suche · Navigation · Aktionen …"
            className="mg-palette__input"
            autoComplete="off"
            spellCheck="false"
          />
          <span className="mg-palette__input-kbd mg-mono">ESC</span>
        </div>

        {results.length === 0 && (
          <div className="mg-palette__empty">Kein Treffer für „{query}"</div>
        )}

        {results.length > 0 && (
          <ul className="mg-palette__list" role="listbox">
            {results.map((cmd, i) => (
              <li
                key={cmd.path + '-' + cmd.label}
                className={'mg-palette__item' + (i === index ? ' mg-palette__item--active' : '')}
                onMouseEnter={() => setIndex(i)}
                onClick={() => execute(cmd)}
                role="option"
                aria-selected={i === index}
              >
                <span className={'mg-palette__item-kind mg-palette__item-kind--' + cmd.kind + ' mg-mono'}>
                  {cmd.kind === 'nav' ? 'GO' : 'DO'}
                </span>
                <span className="mg-palette__item-label">{cmd.label}</span>
                <span className="mg-palette__item-path mg-mono">/{cmd.path}</span>
              </li>
            ))}
          </ul>
        )}

        <div className="mg-palette__foot mg-mono">
          <span>↑↓ NAVIGATE</span>
          <span className="mg-palette__foot-dot">·</span>
          <span>↵ ÖFFNEN</span>
          <span className="mg-palette__foot-dot">·</span>
          <span>ESC ZU</span>
        </div>
      </div>
    </div>
  );
}
