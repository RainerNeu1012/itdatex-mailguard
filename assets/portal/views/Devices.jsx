import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiDelete } from '../api.js';

export default function Devices() {
  const [sessions, setSessions] = useState(null);
  const [tokens,   setTokens]   = useState(null);
  const [devices,  setDevices]  = useState(null);
  const [error,    setError]    = useState(null);
  const [busy,     setBusy]     = useState({});

  const load = useCallback(async () => {
    setError(null);
    try {
      const [s, t, d] = await Promise.all([
        apiGet('me/web-sessions'),
        apiGet('me/tokens'),
        apiGet('me/push-devices'),
      ]);
      if (s.status < 400) setSessions(s.body?.items || []);
      if (t.status < 400) setTokens(t.body?.items || []);
      else setError('HTTP ' + t.status);
      if (d.status < 400) setDevices(d.body?.items || []);
    } catch (e) { setError(String(e)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const revokeSession = async (id, isCurrent) => {
    const msg = isCurrent
      ? 'Das ist deine aktuelle Browser-Session. Nach dem Widerruf wirst du sofort ausgeloggt. Fortfahren?'
      : 'Diese Browser-Session widerrufen? Am entsprechenden Gerät wirst du danach automatisch ausgeloggt.';
    if (!window.confirm(msg)) return;
    setBusy((b) => ({ ...b, ['s' + id]: true }));
    try {
      const res = await apiDelete('me/web-sessions/' + id);
      if (res.body?.is_current) {
        window.location.href = 'login';
        return;
      }
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n['s' + id]; return n; });
    }
  };

  const revokeToken = async (id) => {
    if (!window.confirm('Diesen App-Zugang widerrufen? Die App muss sich danach neu anmelden.')) return;
    setBusy((b) => ({ ...b, ['t' + id]: true }));
    try { await apiDelete('me/tokens/' + id); load(); }
    finally { setBusy((b) => { const n = { ...b }; delete n['t' + id]; return n; }); }
  };

  const removeDevice = async (id) => {
    if (!window.confirm('Push-Benachrichtigungen für dieses Gerät deaktivieren?')) return;
    setBusy((b) => ({ ...b, ['d' + id]: true }));
    try { await apiDelete('me/push-devices/' + id); load(); }
    finally { setBusy((b) => { const n = { ...b }; delete n['d' + id]; return n; }); }
  };

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Angemeldete Geräte</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          App-Anmeldungen mit Bearer-Token und Push-Registrierungen. Bei Verlust eines Geräts hier widerrufen.
        </p>
      </div>

      {error && <div className="mg-card mg-error">{error}</div>}

      <div className="mg-card">
        <h3>Browser-Sessions</h3>
        {sessions === null && <p className="mg-muted">Lade …</p>}
        {sessions && sessions.length === 0 && (
          <p className="mg-muted">
            Noch keine Session-Tracking-Einträge. Wenn du dich seit dem letzten Plugin-Update noch nicht neu eingeloggt hast, ist die Liste leer — logg dich einmal aus und wieder ein, damit dein Browser hier auftaucht.
          </p>
        )}
        {sessions && sessions.length > 0 && (
          <table className="mg-table">
            <thead>
              <tr><th>Browser / Gerät</th><th>IP</th><th>Zuletzt aktiv</th><th></th><th></th></tr>
            </thead>
            <tbody>
              {sessions.map((s) => (
                <tr key={s.id}>
                  <td>{shortUa(s.ua)}</td>
                  <td className="mg-mono mg-tiny">{s.ip || '—'}</td>
                  <td className="mg-muted mg-tiny">{fmtDate(s.last_seen_at) || fmtDate(s.created_at)}</td>
                  <td>{s.is_current && <span className="mg-pill mg-pill--ok">dieser Browser</span>}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button className="mg-btn mg-btn--danger" disabled={!!busy['s' + s.id]} onClick={() => revokeSession(s.id, s.is_current)}>
                      {busy['s' + s.id] ? '…' : 'Widerrufen'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="mg-card">
        <h3>App-Zugänge</h3>
        {tokens === null && <p className="mg-muted">Lade …</p>}
        {tokens && tokens.length === 0 && (
          <p className="mg-muted">Kein App-Login. Sobald du dich in der Mobile-App anmeldest, taucht das Gerät hier auf.</p>
        )}
        {tokens && tokens.length > 0 && (
          <table className="mg-table">
            <thead>
              <tr><th>Name</th><th>Platform</th><th>Zuletzt aktiv</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              {tokens.map((t) => (
                <tr key={t.id}>
                  <td>{t.name || <em className="mg-muted">(ohne Name)</em>}</td>
                  <td className="mg-mono">{t.platform}</td>
                  <td className="mg-muted mg-tiny">{fmtDate(t.last_used_at) || fmtDate(t.created_at)}</td>
                  <td>{t.revoked_at ? <span className="mg-pill mg-pill--muted">widerrufen</span> : <span className="mg-pill mg-pill--ok">aktiv</span>}</td>
                  <td style={{ textAlign: 'right' }}>
                    {!t.revoked_at && (
                      <button className="mg-btn mg-btn--danger" disabled={!!busy['t' + t.id]} onClick={() => revokeToken(t.id)}>
                        {busy['t' + t.id] ? '…' : 'Widerrufen'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="mg-card">
        <h3>Push-Benachrichtigungen</h3>
        {devices === null && <p className="mg-muted">Lade …</p>}
        {devices && devices.length === 0 && (
          <p className="mg-muted">Kein Gerät für Push registriert. Ein App-Login mit erteilten Benachrichtigungs-Rechten erscheint hier automatisch.</p>
        )}
        {devices && devices.length > 0 && (
          <table className="mg-table">
            <thead>
              <tr><th>Label</th><th>Platform</th><th>Events</th><th>Zuletzt</th><th></th></tr>
            </thead>
            <tbody>
              {devices.map((d) => (
                <tr key={d.id}>
                  <td>{d.device_label || <em className="mg-muted">(ohne Label)</em>}</td>
                  <td className="mg-mono">{d.platform}</td>
                  <td className="mg-tiny">{formatMask(d.events_mask)}</td>
                  <td className="mg-muted mg-tiny">{fmtDate(d.last_seen_at) || fmtDate(d.created_at)}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button className="mg-btn" disabled={!!busy['d' + d.id]} onClick={() => removeDevice(d.id)}>
                      {busy['d' + d.id] ? '…' : 'Entfernen'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

const EVENT_LABELS = [
  { bit: 1, label: 'Phishing' },
  { bit: 2, label: 'Auto-Quarantäne' },
  { bit: 4, label: 'Undo-Ablauf' },
  { bit: 8, label: 'Unsub-Bounce' },
];

function formatMask(mask) {
  const active = EVENT_LABELS.filter((e) => (mask & e.bit) !== 0).map((e) => e.label);
  return active.length === EVENT_LABELS.length ? 'alle' : (active.join(', ') || 'keine');
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}

// Aus einem User-Agent-String die groben Bestandteile (Browser + OS) ziehen.
// Absichtlich naiv — reicht fuer die Anzeige "wo bist du eingeloggt".
function shortUa(ua) {
  if (!ua) return <em className="mg-muted">(unbekannt)</em>;
  let browser = 'Browser';
  if (/Edg\//.test(ua))      browser = 'Edge';
  else if (/OPR\//.test(ua)) browser = 'Opera';
  else if (/Chrome\//.test(ua))  browser = 'Chrome';
  else if (/Firefox\//.test(ua)) browser = 'Firefox';
  else if (/Safari\//.test(ua))  browser = 'Safari';
  else if (/curl\//.test(ua))    browser = 'curl';
  let os = '';
  if (/Windows NT/.test(ua))       os = 'Windows';
  else if (/Mac OS X|Macintosh/.test(ua)) os = 'macOS';
  else if (/iPhone|iPad|iOS/.test(ua))    os = 'iOS';
  else if (/Android/.test(ua))            os = 'Android';
  else if (/Linux/.test(ua))              os = 'Linux';
  return os ? `${browser} · ${os}` : browser;
}
