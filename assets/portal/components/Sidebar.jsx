import React, { useEffect, useState } from 'react';
import { navigate } from '../router.js';
import BrandMark from './BrandMark.jsx';

/**
 * Linear/Raycast-style Sidebar: 4 Gruppen, collapsible auf 56px icon-only,
 * sticky, mit Keyboard-Shortcuts rechts in jeder Row.
 *
 * Props: route (aktuelle Route), me (eingeloggter User), onOpenPalette (⌘K-Trigger)
 */

const GROUPS = [
  { label: 'OVERVIEW', items: [
    { r: 'dashboard',  label: 'Dashboard',       kbd: '1' },
  ]},
  { label: 'MAIL', items: [
    { r: 'inbox',       label: 'Inbox',          kbd: '2' },
    { r: 'newsletters', label: 'Newsletter',     kbd: '3' },
    { r: 'scanner',     label: 'Scanner',        kbd: '4' },
  ]},
  { label: 'FILTER & REGELN', items: [
    { r: 'postbox-rules',     label: 'Postfach-Regeln', kbd: '5' },
    { r: 'eradicate-domains', label: 'Auto-Vernichten', kbd: '6' },
  ]},
  { label: 'SYSTEM', items: [
    { r: 'actions',      label: 'Aktionen',       kbd: '7' },
    { r: 'llm-feedback', label: 'KI-Bewertungen', kbd: '8' },
    { r: 'accounts',     label: 'Postfächer',     kbd: '9' },
    { r: 'plan',         label: 'Plan & Lizenz' },
    { r: 'devices',      label: 'Geräte' },
  ]},
];

const COLLAPSED_KEY = 'mg_sidebar_collapsed';
function loadCollapsed() {
  try { return localStorage.getItem(COLLAPSED_KEY) === '1'; } catch { return false; }
}
function saveCollapsed(v) {
  try { localStorage.setItem(COLLAPSED_KEY, v ? '1' : '0'); } catch { /* ignore */ }
}

export default function Sidebar({ route, me, onOpenPalette, onOpenHelp }) {
  const [collapsed, setCollapsed] = useState(loadCollapsed);
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => { saveCollapsed(collapsed); }, [collapsed]);
  useEffect(() => { setMobileOpen(false); }, [route.name]);

  const toggle = () => setCollapsed((c) => !c);

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
                        title={collapsed ? item.label : undefined}
                      >
                        <span className="mg-sidebar__item-dot" aria-hidden="true" />
                        {!collapsed && (
                          <>
                            <span className="mg-sidebar__item-label">{item.label}</span>
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
                {!collapsed && <span className="mg-sidebar__help-label mg-mono">SHORTCUTS</span>}
              </button>
            )}
            <button
              type="button"
              className="mg-sidebar__collapse-btn"
              onClick={toggle}
              title={collapsed ? 'Ausklappen' : 'Zusammenklappen'}
              aria-label={collapsed ? 'Ausklappen' : 'Zusammenklappen'}
            >
              {collapsed ? '›' : '‹'}
            </button>
          </div>
        </div>
      </aside>

      {mobileOpen && <div className="mg-sidebar-backdrop" onClick={() => setMobileOpen(false)} />}
    </>
  );
}
