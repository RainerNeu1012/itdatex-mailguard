import React, { useEffect, useState } from 'react';
import { apiGet, apiPost } from '../api.js';
import { navigate } from '../router.js';

const DEFAULTS = {
  label: '', host: '', port: 993, encryption: 'ssl',
  username: '', password: '', folder: 'INBOX', status: 'active',
};

export default function AccountForm({ route }) {
  const id   = route.params.id ? parseInt(route.params.id, 10) : null;
  const mode = id ? 'edit' : 'create';

  const [form, setForm]       = useState(DEFAULTS);
  const [loading, setLoading] = useState(mode === 'edit');
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState(null);
  const [hasPwd, setHasPwd]   = useState(false);

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

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

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

  return (
    <form className="mg-card mg-form" onSubmit={submit}>
      <h2>{mode === 'edit' ? 'Konto bearbeiten' : 'Neues IMAP-Konto'}</h2>

      <label>Bezeichnung (frei wählbar)
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

      <label>Benutzername
        <input type="text" required value={form.username} onChange={set('username')} autoComplete="username" />
      </label>

      <label>Passwort {mode === 'edit' && hasPwd && <span className="mg-muted mg-tiny">(leer lassen, um beizubehalten)</span>}
        <input type="password" {...(mode === 'create' ? { required: true } : {})}
          value={form.password} onChange={set('password')} autoComplete="new-password" />
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

      {error && <div className="mg-error">{error}</div>}

      <div className="mg-form__row" style={{ justifyContent: 'flex-end' }}>
        <button type="button" className="mg-btn" onClick={() => navigate('accounts')}>Abbrechen</button>
        <button type="submit" className="mg-btn mg-btn--primary" disabled={saving}>
          {saving ? '…' : (mode === 'edit' ? 'Speichern' : 'Anlegen')}
        </button>
      </div>
    </form>
  );
}
