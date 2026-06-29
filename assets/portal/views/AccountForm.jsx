import React, { useEffect, useState, useRef } from 'react';
import { apiGet, apiPost } from '../api.js';
import { navigate } from '../router.js';

const DEFAULTS = {
  label: '', host: '', port: 993, encryption: 'ssl',
  username: '', password: '', folder: 'INBOX', status: 'active',
  auto_quarantine_min_score: null,
};

// Auswahlwerte für Auto-Quarantäne. Untergrenze 70 entspricht der internen
// "dangerous"-Schwelle aus dem Phishing-Tuning — alles darunter wäre zu aggressiv.
const AUTO_Q_OPTIONS = [
  { value: '',   label: 'aus (nur manuell)' },
  { value: '70', label: 'ab Score 70 (alle „gefährlich") — empfohlen' },
  { value: '80', label: 'ab Score 80 (deutlich gefährlich)' },
  { value: '90', label: 'ab Score 90 (nahezu sicher Phishing)' },
];

const EMAIL_RE = /^[^\s@]+@([^\s@]+\.[^\s@]+)$/;

export default function AccountForm({ route }) {
  const id          = route.params.id ? parseInt(route.params.id, 10) : null;
  const mode        = id ? 'edit' : 'create';
  const presetEmail = (mode === 'create' && route.params.email) || '';

  const [form, setForm]       = useState({ ...DEFAULTS, username: presetEmail });
  const [loading, setLoading] = useState(mode === 'edit');
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState(null);
  const [hasPwd, setHasPwd]   = useState(false);
  const [discover, setDiscover] = useState(null);
  const lastQuery = useRef('');

  useEffect(() => {
    if (mode !== 'edit') return;
    apiGet(`accounts/${id}`).then(({ status, body }) => {
      if (status === 200 && body.ok) {
        setForm({ ...DEFAULTS, ...body.item, password: '' });
        setHasPwd(!!body.item.has_password);
      } else {
        setError('Konto nicht gefunden.');
      }
      setLoading(false);
    });
  }, [mode, id]);

  // Auto-Discover, sobald der Username eine valide Email ist (debounced 800ms).
  useEffect(() => {
    if (mode !== 'create') return;
    const m = form.username.match(EMAIL_RE);
    if (!m) { setDiscover(null); return; }
    const email = form.username;
    if (email === lastQuery.current) return;
    const t = setTimeout(async () => {
      lastQuery.current = email;
      try {
        const { status, body } = await apiGet('imap/discover?email=' + encodeURIComponent(email));
        if (status === 200 && body.ok && body.found) {
          setDiscover(body.config);
          // Felder nur autofillen, wenn User nichts Eigenes eingegeben hat
          setForm((f) => ({
            ...f,
            host:       f.host       || body.config.host       || '',
            port:       body.config.port       || f.port,
            encryption: body.config.encryption || f.encryption,
            label:      f.label      || providerLabel(body.config, email),
          }));
        } else {
          setDiscover({ none: true });
        }
      } catch (e) {
        setDiscover(null);
      }
    }, 800);
    return () => clearTimeout(t);
  }, [form.username, mode]);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const startOauthConnect = async (provider) => {
    try {
      const { status, body } = await apiPost(`oauth/${provider}/start`, { login_hint: form.username });
      if (status === 200 && body.ok && body.authorize_url) {
        const w = 520, h = 720;
        const left = window.screenX + (window.outerWidth - w) / 2;
        const top  = window.screenY + (window.outerHeight - h) / 2;
        window.open(body.authorize_url, 'mg-oauth-' + provider, `width=${w},height=${h},left=${left},top=${top}`);
        const onMsg = (ev) => {
          if (ev && ev.data && ev.data.type === 'mg-oauth') {
            window.removeEventListener('message', onMsg);
            navigate('accounts');
          }
        };
        window.addEventListener('message', onMsg);
      } else {
        alert(body.message || body.error || ('HTTP ' + status));
      }
    } catch (e) {
      alert(String(e));
    }
  };

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const payload = { ...form, port: parseInt(form.port, 10) };
      if (mode === 'edit' && !payload.password) {
        delete payload.password;  // leer = bestehendes nicht ueberschreiben
      }
      const path = mode === 'edit' ? `accounts/${id}` : 'accounts';
      const method = mode === 'edit' ? 'PATCH' : 'POST';
      const res = await fetch(
        ((window.itdatexMailguard || {}).restUrl || '') + path,
        {
          method,
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }
      );
      const body = await res.json().catch(() => ({}));
      if (res.status >= 200 && res.status < 300 && body.ok) {
        navigate('accounts');
        return;
      }
      setError(body.message || body.error || ('HTTP ' + res.status));
    } catch (err) {
      setError(String(err));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="mg-card">Lade …</div>;

  const cfg = discover && !discover.none ? discover : null;
  const noImap   = cfg && cfg.no_imap;
  const wantsOauth = cfg && (cfg.oauth_provider === 'microsoft' || cfg.oauth_provider === 'google');

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>{mode === 'edit' ? 'Konto bearbeiten' : 'Neues IMAP-Konto'}</h2>

      {cfg && (
        <div className={'mg-banner ' + (noImap ? 'mg-banner--err' : (wantsOauth ? 'mg-banner--info' : 'mg-banner--ok'))}>
          {noImap ? (
            <>
              <strong>{cfg.domain} unterstützt kein IMAP.</strong>
              {cfg.note && <div className="mg-tiny">{cfg.note}</div>}
            </>
          ) : wantsOauth ? (
            <>
              <strong>{cfg.oauth_provider === 'google' ? 'Gmail/Google' : 'Microsoft'}-Konto erkannt ({cfg.domain}).</strong>
              <div className="mg-tiny">{cfg.note || 'Empfohlen: per OAuth verbinden statt mit Passwort.'}</div>
              <button type="button" className="mg-btn mg-btn--primary" style={{ marginTop: 8 }} onClick={() => startOauthConnect(cfg.oauth_provider)}>
                🔑 Mit {cfg.oauth_provider === 'google' ? 'Google' : 'Microsoft'} verbinden
              </button>
            </>
          ) : (
            <>
              <strong>Provider erkannt:</strong> {cfg.host}:{cfg.port} ({cfg.encryption.toUpperCase()}) <span className="mg-tiny">via {cfg.source}</span>
              {cfg.note && <div className="mg-tiny" style={{ marginTop: 4 }}>{cfg.note}</div>}
            </>
          )}
        </div>
      )}
      {discover && discover.none && form.username.match(EMAIL_RE) && (
        <div className="mg-banner mg-banner--muted">
          Kein Auto-Setup für diese Domain — bitte Host/Port unten manuell eintragen.
        </div>
      )}

      <label><strong>E-Mail-Adresse</strong> <span className="mg-muted mg-tiny">— gib zuerst hier deine Adresse ein, wir füllen Host und Port automatisch aus</span>
        <input
          type="email"
          required
          autoFocus={mode === 'create'}
          value={form.username}
          onChange={set('username')}
          autoComplete="username"
          placeholder="z.B. dein-name@gmx.de"
        />
      </label>

      <label>Passwort {mode === 'edit' && hasPwd && <span className="mg-muted mg-tiny">(leer lassen, um beizubehalten)</span>}
        <input type="password" {...(mode === 'create' ? { required: true } : {})}
          value={form.password} onChange={set('password')} autoComplete="new-password" />
      </label>

      <div className="mg-form__section-label">Server-Einstellungen {cfg && !cfg.no_imap && !wantsOauth && <span className="mg-muted mg-tiny">(automatisch erkannt — anpassbar)</span>}</div>

      <label>Bezeichnung <span className="mg-muted mg-tiny">(frei wählbar)</span>
        <input type="text" value={form.label} onChange={set('label')} placeholder="z.B. Privat Gmail" />
      </label>

      <div className="mg-form__row">
        <label style={{ flex: 2 }}>Host
          <input type="text" required value={form.host} onChange={set('host')} placeholder="imap.example.com" />
        </label>
        <label style={{ flex: 1 }}>Port
          <input type="number" required min={1} max={65535} value={form.port} onChange={set('port')} />
        </label>
      </div>

      <label>Verschlüsselung
        <select value={form.encryption} onChange={set('encryption')}>
          <option value="ssl">SSL (Port 993)</option>
          <option value="tls">STARTTLS (Port 143)</option>
          <option value="none">Keine</option>
        </select>
      </label>

      <label>IMAP-Ordner
        <input type="text" value={form.folder} onChange={set('folder')} placeholder="INBOX" />
      </label>

      {mode === 'edit' && (
        <label>Status
          <select value={form.status} onChange={set('status')}>
            <option value="active">aktiv</option>
            <option value="disabled">pausiert</option>
          </select>
        </label>
      )}

      <div className="mg-form__section-label">Quarantäne</div>
      <label>Auto-Quarantäne <span className="mg-muted mg-tiny">— gefährliche Mails automatisch in den Quarantäne-Ordner verschieben (Undo bleibt 7 Tage möglich)</span>
        <select
          value={form.auto_quarantine_min_score == null ? '' : String(form.auto_quarantine_min_score)}
          onChange={(e) => setForm({ ...form, auto_quarantine_min_score: e.target.value === '' ? null : parseInt(e.target.value, 10) })}
        >
          {AUTO_Q_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </label>

      {error && <div className="mg-error">{error}</div>}

      <div className="mg-form__row" style={{ justifyContent: 'flex-end' }}>
        <button type="button" className="mg-btn" onClick={() => navigate('accounts')}>Abbrechen</button>
        <button type="submit" className="mg-btn mg-btn--primary" disabled={saving || (cfg && cfg.no_imap)}>
          {saving ? '…' : (mode === 'edit' ? 'Speichern' : 'Anlegen')}
        </button>
      </div>
    </form>
  );
}

function providerLabel(cfg, email) {
  if (!cfg || !cfg.domain) return '';
  const local = (email.split('@')[0] || '').slice(0, 24);
  const map = {
    'gmail.com': 'Gmail', 'googlemail.com': 'Gmail',
    'outlook.com': 'Outlook', 'hotmail.com': 'Outlook', 'live.com': 'Outlook', 'live.de': 'Outlook', 'outlook.de': 'Outlook', 'hotmail.de': 'Outlook',
    'gmx.de': 'GMX', 'gmx.net': 'GMX', 'gmx.com': 'GMX',
    'web.de': 'Web.de',
    't-online.de': 'T-Online', 'magenta.de': 'T-Online',
    'icloud.com': 'iCloud', 'me.com': 'iCloud',
    'yahoo.com': 'Yahoo', 'yahoo.de': 'Yahoo',
    'mailbox.org': 'Mailbox.org',
    'posteo.de': 'Posteo', 'posteo.net': 'Posteo',
    'fastmail.com': 'FastMail',
  };
  const brand = map[cfg.domain] || cfg.domain;
  return `${brand} · ${local}`;
}
