import React, { useEffect, useState } from 'react';
import { navigate } from '../router.js';
import { apiGet } from '../api.js';
import BrandMark from './BrandMark.jsx';

/**
 * Linear/Raycast-style Sidebar: 4 Gruppen, collapsible auf 56px icon-only,
 * sticky, mit Keyboard-Shortcuts rechts in jeder Row.
 *
 * Props: route (aktuelle Route), me (eingeloggter User), onOpenPalette (⌘K-Trigger)
 */

// Icons: minimalistische Unicode-Glyphen, monochrom, passen zum
// Linear/Raycast-Aesthetic. Groesser gerendert im collapsed-State
// (statt generischem Dot der nichts unterscheidet).
const GROUPS = [
  { label: 'OVERVIEW', items: [
    { r: 'dashboard',  label: 'Dashboard',       kbd: '1', icon: '▣' },
  ]},
  { label: 'MAIL', items: [
    { r: 'inbox',       label: 'Inbox',          kbd: '2', icon: '✉' },
    { r: 'newsletters', label: 'Newsletter',     kbd: '3', icon: '◱' },
    { r: 'scanner',     label: 'Scanner',        kbd: '4', icon: '⌕' },
  ]},
  { label: 'FILTER & REGELN', items: [
    { r: 'postbox-rules',     label: 'Postfach-Regeln', kbd: '5', icon: '⚙' },
    { r: 'eradicate-domains', label: 'Auto-Vernichten', kbd: '6', icon: '✖' },
  ]},
  { label: 'SICHERHEIT', items: [
    { r: 'breaches',     label: 'Breach-Monitor',          icon: '⚠', badgeKey: 'breaches' },
    { r: 'activity',     label: 'Kontoaktivitaet',         icon: '◉' },
  ]},
  { label: 'SYSTEM', items: [
    { r: 'actions',      label: 'Aktionen',       kbd: '7', icon: '⚡' },
    { r: 'llm-feedback', label: 'KI-Bewertungen', kbd: '8', icon: '◈' },
    { r: 'accounts',     label: 'Postfächer',     kbd: '9', icon: '▦' },
    { r: 'plan',         label: 'Plan & Lizenz',           icon: '◇' },
    { r: 'devices',      label: 'Geräte',                  icon: '▭' },
  ]},
];

export default function Sidebar({ route, me, onOpenPalette, onOpenHelp }) {
  // Sidebar ist immer expanded. Collapse-Feature war visuell unprofessionell
  // (schmale Leiste mit Mini-Icons wirkt amateurhaft) und ohne echten Nutzen —
  // 240px Sidebar auf 1280-4K-Screens ist kein Platzproblem. Mobile klappt eh
  // komplett ein via Burger-Button.
  const collapsed = false;
  const [mobileOpen, setMobileOpen] = useState(false);
  const [badges, setBadges] = useState({});

  useEffect(() => { setMobileOpen(false); }, [route.name]);

  // Unread-Badges: alle 60s + bei Route-Wechsel refreshen. Aktuell nur Breach-Monitor.
  useEffect(() => {
    if (!me) return;
    let alive = true;
    const load = async () => {
      try {
        const { status, body } = await apiGet('me/breaches');
        if (!alive) return;
        if (status === 200 && body?.ok) {
          setBadges((b) => ({ ...b, breaches: Number(body.unread_cnt || 0) }));
        }
      } catch (_) {}
    };
    load();
    const t = setInterval(load, 60000);
    return () => { alive = false; clearInterval(t); };
  }, [me?.customer_id, route.name]);

  return (
    <>
      <button
        type="button"
        className="mg-sidebar-burger"
        aria-label="Menü öffnen"
        aria-expanded={mobileOpen}
        onClick={() => setMobileOpen((v) => !v)}
      >
        <span /><span /><span />
      </button>

      <aside className={'mg-sidebar' + (collapsed ? ' mg-sidebar--collapsed' : '') + (mobileOpen ? ' mg-sidebar--mobile-open' : '')} aria-label="Hauptnavigation">
        <div className="mg-sidebar__brand" onClick={() => navigate('dashboard')} role="link" tabIndex={0}>
          <BrandMark size={28} />
          {!collapsed && (
            <div className="mg-sidebar__brand-txt">
              <span className="mg-sidebar__brand-name">MAIL<span className="mg-sidebar__brand-accent">GUARD</span></span>
              <span className="mg-sidebar__brand-sub">// portal</span>
            </div>
          )}
        </div>

        <button
          type="button"
          className="mg-sidebar__palette-btn"
          onClick={onOpenPalette}
          title="Suche &amp; Quick-Actions (⌘K)"
        >
          <span className="mg-sidebar__palette-icon" aria-hidden="true">⌕</span>
          {!collapsed && (
            <>
              <span className="mg-sidebar__palette-label">Suche</span>
              <span className="mg-sidebar__palette-kbd mg-mono">⌘K</span>
            </>
          )}
        </button>

        <nav className="mg-sidebar__groups">
          {GROUPS.map((g) => (
            <div key={g.label} className="mg-sidebar__group">
              {!collapsed && <div className="mg-sidebar__group-label mg-mono">{g.label}</div>}
              <ul className="mg-sidebar__list">
                {g.items.map((item) => {
                  const active = route.name === item.r;
                  return (
                    <li key={item.r}>
                      <button
                        type="button"
                        className={'mg-sidebar__item' + (active ? ' mg-sidebar__item--active' : '')}
                        onClick={() => navigate(item.r)}
                        title={collapsed ? item.label + (item.kbd ? ' (⌥' + item.kbd + ')' : '') : undefined}
                      >
                        <span className="mg-sidebar__item-icon" aria-hidden="true">{item.icon || '•'}</span>
                        {!collapsed && (
                          <>
                            <span className="mg-sidebar__item-label">{item.label}</span>
                            {item.badgeKey && badges[item.badgeKey] > 0 && (
                              <span className="mg-sidebar__item-badge mg-mono" style={{
                                background:'var(--mg-err, #f85149)',color:'#fff',
                                fontSize:'0.7rem',padding:'1px 6px',borderRadius:'10px',
                                marginLeft:'auto',minWidth:18,textAlign:'center'
                              }}>{badges[item.badgeKey]}</span>
                            )}
                            {item.kbd && <span className="mg-sidebar__item-kbd mg-mono">⌥{item.kbd}</span>}
                          </>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </nav>

        <div className="mg-sidebar__foot">
          {!collapsed && me && (
            <div className="mg-sidebar__user">
              <div className="mg-sidebar__user-email mg-mono">{me.email}</div>
              <button
                type="button"
                className="mg-sidebar__logout"
                onClick={() => navigate('logout')}
              >LOGOUT ↗</button>
            </div>
          )}
          <div className="mg-sidebar__foot-row">
            {onOpenHelp && (
              <button
                type="button"
                className="mg-sidebar__help-btn"
                onClick={onOpenHelp}
                title="Shortcut-Hilfe (?)"
                aria-label="Hilfe"
              >
                <span>?</span>
                <span className="mg-sidebar__help-label mg-mono">SHORTCUTS</span>
              </button>
            )}
          </div>
        </div>
      </aside>

      {mobileOpen && <div className="mg-sidebar-backdrop" onClick={() => setMobileOpen(false)} />}
    </>
  );
}
