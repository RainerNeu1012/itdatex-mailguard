import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost, apiDelete, apiPut } from '../api.js';

// Portal-Parity zur App-View. Selbe Semantik: multi-condition Regeln, die
// nach dem Scan aber vor Auto-Quarantaene greifen.

const FIELD_META = {
  from_addr:       { label: 'Absender-Adresse', type: 'string',  placeholder: 'name@example.com' },
  from_domain:     { label: 'Absender-Domain',  type: 'string',  placeholder: 'example.com' },
  from_name:       { label: 'Anzeigename',      type: 'string',  placeholder: 'Sparkasse' },
  subject:         { label: 'Betreff',          type: 'string',  placeholder: 'Rechnung' },
  body:            { label: 'Text (Preview)',   type: 'string',  placeholder: 'Zahlungserinnerung' },
  verdict:         { label: 'Verdict',          type: 'enum',    options: ['clean', 'suspicious', 'dangerous'] },
  score:           { label: 'Score',            type: 'number',  placeholder: '50' },
  has_unsub:       { label: 'Hat Unsubscribe',  type: 'bool' },
  has_attachments: { label: 'Hat Anhaenge',     type: 'bool' },
};
const STRING_OPS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with'];
const NUMBER_OPS = ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];
const OP_LABELS = {
  equals: 'ist gleich', not_equals: 'ist nicht', contains: 'enthält', not_contains: 'enthält nicht',
  starts_with: 'beginnt mit', ends_with: 'endet auf',
  eq: '=', ne: '!=', gt: '>', ge: '>=', lt: '<', le: '<=',
};
const ACTION_LABELS = {
  move:   'In Ordner verschieben',
  delete: 'Sofort vernichten (EXPUNGE, kein Undo)',
  flag:   'IMAP-Flag setzen (in Vorbereitung)',
};

const emptyRule = () => ({
  name: '', priority: 100, enabled: true, match_op: 'AND', stop_processing: false,
  conditions: [{ field: 'from_domain', op: 'equals', value: '' }],
  actions:    [{ type: 'move', folder: 'INBOX/Newsletter' }],
});

export default function PostboxRules() {
  const [items, setItems] = useState(null);
  const [err, setErr]     = useState(null);
  const [flash, setFlash] = useState(null);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setErr(null);
    const r = await apiGet('postbox-rules');
    if (r.status >= 400) { setErr('HTTP ' + r.status); return; }
    setItems(r.body?.items || []);
  }, []);
  useEffect(() => { load(); }, [load]);

  const save = async () => {
    setSaving(true); setErr(null); setFlash(null);
    const payload = {
      name: editing.name, priority: editing.priority, enabled: editing.enabled,
      match_op: editing.match_op, stop_processing: editing.stop_processing,
      conditions: editing.conditions, actions: editing.actions,
    };
    const r = editing.id ? await apiPut(`postbox-rules/${editing.id}`, payload) : await apiPost('postbox-rules', payload);
    setSaving(false);
    if (r.status < 400 && r.body?.ok) {
      setFlash(editing.id ? 'Regel aktualisiert.' : 'Regel angelegt.');
      setEditing(null);
      await load();
    } else {
      setErr(r.body?.message || r.body?.error || ('HTTP ' + r.status));
    }
  };

  const remove = async (r) => {
    if (!window.confirm(`Regel "${r.name}" loeschen?`)) return;
    const res = await apiDelete(`postbox-rules/${r.id}`);
    if (res.status < 400) { setFlash('Regel geloescht.'); await load(); }
    else setErr(res.body?.error || ('HTTP ' + res.status));
  };

  const toggleEnabled = async (r) => {
    await apiPut(`postbox-rules/${r.id}`, { ...r, enabled: !r.enabled });
    await load();
  };

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Postfach-Regeln</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          Multi-condition Filter, die nach dem Scan aber vor Auto-Quarantaene ausgewertet werden.
          Gefährliche Mails umgehen die Regeln bewusst — Sicherheit vor Sortier-Komfort.
        </p>
        <div style={{ marginTop: 12 }}>
          <button className="mg-btn mg-btn--primary" onClick={() => setEditing(emptyRule())}>+ Neue Regel</button>
        </div>
      </div>

      {flash && <div className="mg-card mg-ok">{flash}</div>}
      {err && <div className="mg-card mg-error">{err}</div>}
      {items === null && <div className="mg-card">Lade …</div>}
      {items !== null && items.length === 0 && !editing && (
        <div className="mg-card mg-muted">Noch keine Regeln. Beispiel: "Verschiebe alle Mails mit Unsubscribe-Header in den Newsletter-Ordner".</div>
      )}
      {items !== null && items.length > 0 && (
        <div className="mg-stack">
          {items.map((r) => (
            <div key={r.id} className="mg-card" style={{ opacity: r.enabled ? 1 : 0.55 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <strong>{r.name || '(ohne Name)'}</strong>
                <span style={{ fontSize: 11, padding: '2px 6px', border: '1px solid var(--mg-border)', borderRadius: 999 }}>Priorität {r.priority}</span>
                <span style={{ fontSize: 12, opacity: 0.7 }}>{r.match_op === 'AND' ? 'alle' : 'mind. eine'} · {r.conditions.length} Bed. · {r.actions.length} Aktion(en)</span>
                {r.hit_count > 0 && <span style={{ fontSize: 11, opacity: 0.7 }}>{r.hit_count}× getroffen</span>}
              </div>
              <div style={{ fontSize: 12, marginTop: 6, opacity: 0.85 }}>
                {r.conditions.map((c, i) => (
                  <span key={i}>
                    {i > 0 && <em style={{ opacity: 0.5 }}> {r.match_op === 'AND' ? ' und ' : ' oder '} </em>}
                    <code>{FIELD_META[c.field]?.label || c.field}</code>
                    {' '}{OP_LABELS[c.op] || c.op}{' '}
                    <code>{String(c.value)}</code>
                  </span>
                ))}
                <br />
                → {r.actions.map((a, i) => (
                  <span key={i}>{i > 0 && ', '}<code>{a.type === 'move' ? `verschiebe → ${a.folder}` : a.type === 'delete' ? 'vernichte' : `flag ${a.flag}`}</code></span>
                ))}
              </div>
              <div className="mg-form__row" style={{ gap: 6, marginTop: 8 }}>
                <button className="mg-btn" onClick={() => toggleEnabled(r)}>{r.enabled ? 'Aus' : 'Ein'}</button>
                <button className="mg-btn" onClick={() => setEditing({ ...r })}>Bearbeiten</button>
                <button className="mg-btn mg-btn--danger" onClick={() => remove(r)}>Löschen</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {editing && (
        <div className="mg-card">
          <h3 style={{ marginTop: 0 }}>{editing.id ? 'Regel bearbeiten' : 'Neue Postfach-Regel'}</h3>
          <label style={{ display: 'block', marginBottom: 8 }}>
            <span style={{ fontSize: 12, opacity: 0.8, display: 'block' }}>Name</span>
            <input type="text" style={{ width: '100%' }} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} placeholder="z.B. Newsletter in Newsletter-Ordner" autoFocus />
          </label>
          <div style={{ display: 'flex', gap: 10, marginBottom: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <label>
              <span style={{ fontSize: 12, opacity: 0.8, display: 'block' }}>Priorität</span>
              <input type="number" style={{ width: 100 }} value={editing.priority} onChange={(e) => setEditing({ ...editing, priority: parseInt(e.target.value, 10) || 100 })} min="0" max="9999" />
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <input type="checkbox" checked={editing.enabled} onChange={(e) => setEditing({ ...editing, enabled: e.target.checked })} />
              Aktiv
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <input type="checkbox" checked={editing.stop_processing} onChange={(e) => setEditing({ ...editing, stop_processing: e.target.checked })} />
              Weitere Regeln danach überspringen
            </label>
          </div>

          <div style={{ borderTop: '1px solid var(--mg-border)', paddingTop: 8, marginTop: 8 }}>
            <div style={{ marginBottom: 8, display: 'flex', gap: 8, alignItems: 'center' }}>
              <strong>Wenn</strong>
              <select value={editing.match_op} onChange={(e) => setEditing({ ...editing, match_op: e.target.value })}>
                <option value="AND">alle Bedingungen</option>
                <option value="OR">mindestens eine</option>
              </select>
              <span style={{ opacity: 0.7 }}>zutreffen:</span>
            </div>
            {editing.conditions.map((c, idx) => (
              <ConditionRow key={idx} c={c} canRemove={editing.conditions.length > 1}
                onChange={(patch) => {
                  const conditions = editing.conditions.map((x, i) => i === idx ? { ...x, ...patch } : x);
                  setEditing({ ...editing, conditions });
                }}
                onRemove={() => setEditing({ ...editing, conditions: editing.conditions.filter((_, i) => i !== idx) })}
              />
            ))}
            <button className="mg-btn" onClick={() => setEditing({ ...editing, conditions: [...editing.conditions, { field: 'from_addr', op: 'equals', value: '' }] })}>+ Bedingung</button>
          </div>

          <div style={{ borderTop: '1px solid var(--mg-border)', paddingTop: 8, marginTop: 8 }}>
            <div style={{ marginBottom: 8 }}><strong>Dann:</strong></div>
            {editing.actions.map((a, idx) => (
              <ActionRow key={idx} a={a} canRemove={editing.actions.length > 1}
                onChange={(patch) => {
                  const actions = editing.actions.map((x, i) => i === idx ? { ...x, ...patch } : x);
                  setEditing({ ...editing, actions });
                }}
                onRemove={() => setEditing({ ...editing, actions: editing.actions.filter((_, i) => i !== idx) })}
              />
            ))}
            <button className="mg-btn" onClick={() => setEditing({ ...editing, actions: [...editing.actions, { type: 'move', folder: '' }] })}>+ Aktion</button>
          </div>

          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="mg-btn" onClick={() => setEditing(null)} disabled={saving}>Abbrechen</button>
            <button className="mg-btn mg-btn--primary" onClick={save} disabled={saving || !editing.name.trim() || editing.conditions.length === 0 || editing.actions.length === 0}>
              {saving ? '…' : 'Speichern'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function ConditionRow({ c, canRemove, onChange, onRemove }) {
  const meta = FIELD_META[c.field] || FIELD_META.from_addr;
  const opList = meta.type === 'number' ? NUMBER_OPS : STRING_OPS;
  const currentOp = opList.includes(c.op) ? c.op : opList[0];
  const onFieldChange = (field) => {
    const newMeta = FIELD_META[field] || FIELD_META.from_addr;
    const newOpList = newMeta.type === 'number' ? NUMBER_OPS : STRING_OPS;
    const newOp = newOpList.includes(c.op) ? c.op : newOpList[0];
    let value = c.value;
    if (newMeta.type === 'bool') value = !!value;
    else if (newMeta.type === 'enum') value = (newMeta.options && newMeta.options[0]) || '';
    onChange({ field, op: newOp, value });
  };
  return (
    <div style={{ display: 'flex', gap: 6, marginBottom: 6, flexWrap: 'wrap' }}>
      <select value={c.field} onChange={(e) => onFieldChange(e.target.value)}>
        {Object.entries(FIELD_META).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
      </select>
      <select value={currentOp} onChange={(e) => onChange({ op: e.target.value })}>
        {opList.map((o) => <option key={o} value={o}>{OP_LABELS[o] || o}</option>)}
      </select>
      {meta.type === 'bool' ? (
        <select value={c.value ? '1' : '0'} onChange={(e) => onChange({ value: e.target.value === '1' })}><option value="1">Ja</option><option value="0">Nein</option></select>
      ) : meta.type === 'enum' ? (
        <select value={c.value} onChange={(e) => onChange({ value: e.target.value })}>{meta.options.map((o) => <option key={o} value={o}>{o}</option>)}</select>
      ) : meta.type === 'number' ? (
        <input type="number" value={c.value} onChange={(e) => onChange({ value: parseInt(e.target.value, 10) || 0 })} />
      ) : (
        <input type="text" value={c.value} onChange={(e) => onChange({ value: e.target.value })} placeholder={meta.placeholder} />
      )}
      <button className="mg-btn" onClick={onRemove} disabled={!canRemove}>−</button>
    </div>
  );
}

function ActionRow({ a, canRemove, onChange, onRemove }) {
  return (
    <div style={{ display: 'flex', gap: 6, marginBottom: 6, flexWrap: 'wrap' }}>
      <select value={a.type} onChange={(e) => {
        const type = e.target.value;
        if (type === 'move')   onChange({ type, folder: a.folder || '' });
        else if (type === 'flag') onChange({ type, flag: a.flag || '\\Seen' });
        else onChange({ type });
      }}>
        {Object.entries(ACTION_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
      </select>
      {a.type === 'move' && (
        <input type="text" style={{ flex: '1 1 200px' }} value={a.folder || ''} onChange={(e) => onChange({ folder: e.target.value })} placeholder="z.B. Newsletter oder Ordner/Unterordner" />
      )}
      {a.type === 'flag' && (
        <select value={a.flag || '\\Seen'} onChange={(e) => onChange({ flag: e.target.value })}>
          <option value="\\Seen">Gelesen</option>
          <option value="\\Flagged">Markiert</option>
          <option value="\\Answered">Beantwortet</option>
        </select>
      )}
      <button className="mg-btn" onClick={onRemove} disabled={!canRemove}>−</button>
    </div>
  );
}
