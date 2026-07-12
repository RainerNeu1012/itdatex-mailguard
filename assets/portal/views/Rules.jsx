import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost } from '../api.js';

const TYPE_LABEL = {
  from_addr:        'Absender (exakt)',
  from_domain:      'Domain',
  subject_contains: 'Subject enthält',
};

export default function Rules() {
  const [items, setItems]   = useState(null);
  const [error, setError]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [form, setForm]     = useState({ kind: 'whitelist', match_type: 'from_addr', pattern: '', note: '' });
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const { status, body } = await apiGet('rules');
    if (status >= 400) setError('HTTP ' + status);
    else setItems(body.items);
  }, []);

  useEffect(() => { load(); }, [load]);

  const add = async (e) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const { status, body } = await apiPost('rules', form);
      if (status === 402 && body && body.error === 'plan_limit_reached') {
        setError('⚠ Free-Plan-Limit erreicht: ' + body.used + ' von ' + body.limit + ' Regeln in Nutzung. Upgrade unter „Plan" für unbegrenzt.');
      } else if (status >= 400) {
        setError((body && (body.message || body.error)) || ('HTTP ' + status));
      } else {
        setForm({ kind: form.kind, match_type: form.match_type, pattern: '', note: '' });
        load();
      }
    } finally {
      setSaving(false);
    }
  };

  const remove = async (id) => {
    if (!window.confirm('Regel wirklich löschen?')) return;
    setBusy((b) => ({ ...b, [id]: 'del' }));
    try {
      await fetch(((window.itdatexMailguard || {}).restUrl || '') + `rules/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
      });
      load();
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  const wl = (items || []).filter((i) => i.kind === 'whitelist');
  const bl = (items || []).filter((i) => i.kind === 'blacklist');

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Spam-Regeln</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          Eigene Regeln, die das automatische Verdict überschreiben. <strong>Blacklist gewinnt vor Whitelist.</strong> Wirken erst auf neue Scans — alte Mails bei Bedarf in der Inbox via „↻ Erneut scannen" auffrischen.
        </p>
      </div>

      <form className="mg-card mg-form" onSubmit={add}>
        <h3 style={{ margin: '0 0 0.5rem' }}>Neue Regel</h3>
        <div className="mg-form__row">
          <label style={{ flex: 1 }}>Art
            <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              <option value="whitelist">Whitelist (immer „sauber")</option>
              <option value="blacklist">Blacklist (immer „gefährlich")</option>
            </select>
          </label>
          <label style={{ flex: 1 }}>Match
            <select value={form.match_type} onChange={(e) => setForm({ ...form, match_type: e.target.value })}>
              <option value="from_addr">Absender (exakt)</option>
              <option value="from_domain">Domain</option>
              <option value="subject_contains">Subject enthält</option>
            </select>
          </label>
        </div>
        <label>Muster
          <input type="text" required value={form.pattern} onChange={(e) => setForm({ ...form, pattern: e.target.value })}
            placeholder={form.match_type === 'from_addr' ? 'name@example.com' : form.match_type === 'from_domain' ? 'example.com  oder  .example.com (mit Sub)' : 'Stichwort'} />
        </label>
        <label>Notiz (optional)
          <input type="text" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} placeholder="z.B. Kollege, immer durchlassen" />
        </label>
        {error && <div className="mg-error">{error}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <button type="submit" className="mg-btn mg-btn--primary" disabled={saving}>{saving ? '…' : '+ Hinzufügen'}</button>
        </div>
      </form>

      {items === null && <div className="mg-card">Lade …</div>}
      {items !== null && (
        <>
          <RuleList title="Whitelist" items={wl} busy={busy} onDelete={remove} emptyLabel="Keine Whitelist-Regeln." />
          <RuleList title="Blacklist" items={bl} busy={busy} onDelete={remove} emptyLabel="Keine Blacklist-Regeln." />
        </>
      )}
    </div>
  );
}

function RuleList({ title, items, busy, onDelete, emptyLabel }) {
  return (
    <div className="mg-card">
      <h3 style={{ margin: '0 0 0.5rem' }}>{title}</h3>
      {items.length === 0 ? (
        <p className="mg-muted">{emptyLabel}</p>
      ) : (
        <table className="mg-rules">
          <thead><tr><th>Match</th><th>Muster</th><th>Notiz</th><th></th></tr></thead>
          <tbody>
            {items.map((r) => (
              <tr key={r.id}>
                <td className="mg-tiny">{TYPE_LABEL[r.match_type] || r.match_type}</td>
                <td><code>{r.pattern}</code></td>
                <td className="mg-muted mg-tiny">{r.note}</td>
                <td><button className="mg-btn" disabled={!!busy[r.id]} onClick={() => onDelete(r.id)}>{busy[r.id] === 'del' ? '…' : '×'}</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
