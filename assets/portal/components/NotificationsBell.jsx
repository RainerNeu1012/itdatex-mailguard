import React, { useState, useRef, useEffect } from 'react';
import { useNotifications } from '../notifications.js';
import { navigate } from '../router.js';

/**
 * Header-Bell mit Unread-Count-Badge + Dropdown der letzten 10 Notifications.
 *
 * Klick auf einen Eintrag: navigiert zur zugehörigen Route und markiert alle
 * bis inklusive dieses Eintrags als gelesen (Server-seitig). "Alle gelesen"
 * am Fuß macht dasselbe für die ganze Liste.
 *
 * Wird nur bei eingeloggtem User im Header gemounted (siehe App.jsx).
 */
export default function NotificationsBell() {
  const { items, unreadCount, markSeen } = useNotifications(true);
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  // Klick außerhalb → Dropdown schließen. Klassisch für ein Header-Menü.
  useEffect(() => {
    if (!open) return;
    const onDocClick = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  const handleClick = async (n) => {
    setOpen(false);
    if (n.route) navigate(n.route);
    // Markieren als gelesen läuft im Hintergrund — der User soll auf das
    // Navigations-Feedback nicht auf einen Server-Roundtrip warten.
    markSeen();
  };

  return (
    <div ref={wrapRef} style={{ position: 'relative' }}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="mg-nav__btn"
        aria-label="Benachrichtigungen"
        style={{ position: 'relative' }}
      >
        🔔
        {unreadCount > 0 && (
          <span
            aria-label={`${unreadCount} ungelesen`}
            style={{
              position: 'absolute',
              top: '0.1rem',
              right: '0.1rem',
              background: '#ef4444',
              color: 'white',
              borderRadius: '999px',
              minWidth: '1.1rem',
              height: '1.1rem',
              fontSize: '0.65rem',
              lineHeight: '1.1rem',
              padding: '0 0.25rem',
              fontWeight: 700,
            }}
          >
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="mg-card" style={dropdownStyle}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <strong>Benachrichtigungen</strong>
            {unreadCount > 0 && (
              <button className="mg-btn mg-btn--ghost mg-tiny" onClick={markSeen}>
                Alle gelesen
              </button>
            )}
          </div>
          {items.length === 0 && (
            <p className="mg-muted mg-tiny" style={{ margin: 0 }}>Keine Benachrichtigungen.</p>
          )}
          {items.slice(0, 10).map((n) => (
            <button
              key={n.id}
              onClick={() => handleClick(n)}
              style={itemStyle(!!n.read_at)}
            >
              <div style={{ fontWeight: n.read_at ? 400 : 600, fontSize: '0.85rem' }}>{n.title}</div>
              {n.body && <div className="mg-muted mg-tiny" style={{ marginTop: '0.15rem' }}>{n.body}</div>}
              <div className="mg-muted mg-tiny" style={{ marginTop: '0.15rem' }}>{fmtDate(n.created_at)}</div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

const dropdownStyle = {
  position: 'absolute',
  right: 0,
  top: 'calc(100% + 0.35rem)',
  minWidth: 320,
  maxWidth: 400,
  maxHeight: '70vh',
  overflowY: 'auto',
  zIndex: 200,
  padding: '0.75rem',
  boxShadow: '0 8px 24px rgba(0,0,0,0.5)',
};

const itemStyle = (isRead) => ({
  display: 'block',
  width: '100%',
  textAlign: 'left',
  background: 'transparent',
  border: 0,
  borderTop: '1px solid rgba(255,255,255,0.08)',
  padding: '0.5rem 0.25rem',
  cursor: 'pointer',
  color: 'inherit',
  opacity: isRead ? 0.7 : 1,
});

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}
