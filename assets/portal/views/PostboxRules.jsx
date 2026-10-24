import React, { useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost, apiDelete, apiPut } from '../api.js';
import { PageShell, PageTopbar, Section, SectionHeader } from '../components/Section.jsx';

const FIELD_META = {
  from_addr:       { label: 'Absender-Adresse', type: 'string',  placeholder: 'name@example.com' },
  from_domain:     { label: 'Absender-Domain',  type: 'string',  placeholder: 'example.com' },
  from_name:       { label: 'Anzeigename',      type: 'string',  placeholder: 'Sparkasse' },
  subject:         { label: 'Betreff',          type: 'string',  placeholder: 'Rechnung' },
  body:            { label: 'Text (Preview)',   type: 'string',  placeholder: 'Zahlungserinnerung' },
  folder:          { label: 'IMAP-Ordner',      type: 'string',  placeholder: 'Junk' },
  verdict:         { label: 'Verdict',          type: 'enum',    options: ['clean', 'suspicious', 'dangerous'] },
  score:           { label: 'Score',            type: 'number',  placeholder: '50' },
  has_unsub:       { label: 'Hat Unsubscribe',  type: 'bool' },
  has_attachments: { label: 'Hat Anhänge',      type: 'bool' },
};
const STRING_OPS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with'];
const NUMBER_OPS = ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];
const OP_LABELS = {
  equals: 'ist gleich', not_equals: 'ist nicht', contains: 'enthält', not_contains: 'enthält nicht',
  starts_with: 'beginnt mit', ends_with: 'endet auf',
  eq: '=', ne: '≠', gt: '>', ge: '≥', lt: '<', le: '≤',
};
const ACTION_LABELS = {
  move:   'In Ordner verschieben',
  delete: 'Sofort vernichten (EXPUNGE)',
  flag:   'IMAP-Flag setzen',
};

const emptyRule = () => ({
  name: '', priority: 100, enabled: true, match_op: 'AND', stop_processing: false,
  conditions: [{ field: 'from_domain', op: 'equals', value: '' }],
  actions:    [{ type: 'move', folder: 'INBOX/Newsletter' }],
});

export default function PostboxRules({ me }) {
  const [items, setItems]     = useState(null);
  const [sharedItems, setSharedItems] = useState([]);
  const [isOperator, setIsOperator]   = useState(false);
  const [err, setErr]         = useState(null);
  const [flash, setFlash]     = useState(null);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving]   = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [sharedBusy, setSharedBusy]     = useState({});

  const load = useCallback(async () => {
    setErr(null);
    const r = await apiGet('postbox-rules');
    if (r.status >= 400) { setErr('HTTP ' + r.status); return; }
    setItems(r.body?.items || []);
  }, []);
  const loadShared = useCallback(async () => {
    const r = await apiGet('shared-rules');
    if (r.status < 400 && r.body?.ok) {
      setSharedItems(r.body.items || []);
      setIsOperator(!!r.body.is_operator);
    }
  }, []);
  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadShared(); }, [loadShared]);

  const toggleOptOut = async (r) => {
    setSharedBusy((b) => ({ ...b, [r.id]: true }));
    const path = `shared-rules/${r.id}/opt-out`;
    const res = r.opted_out ? await apiDelete(path) : await apiPost(path, {});
    setSharedBusy((b) => { const n = { ...b }; delete n[r.id]; return n; });
    if (res.status < 400) { await loadShared(); }
    else setErr(res.body?.error || ('HTTP ' + res.status));
  };

  const save = async () => {
    setErr(null); setFlash(null);
    for (const c of editing.conditions) {
      const meta = FIELD_META[c.field] || FIELD_META.from_addr;
      if (meta.type !== 'bool' && meta.type !== 'enum' && !String(c.value ?? '').trim()) {
        setErr(`Bedingung „${meta.label}": Wert darf nicht leer sein.`);
        return;
      }
    }
    for (const a of editing.actions) {
      if (a.type === 'move' && !String(a.folder ?? '').trim()) {
        setErr('Aktion „Verschieben": Zielordner darf nicht leer sein.');
        return;
      }
    }
    setSaving(true);
    const payload = {
      name: editing.name, priority: editing.priority, enabled: editing.enabled,
      match_op: editing.match_op, stop_processing: editing.stop_processing,
      conditions: editing.conditions, actions: editing.actions,
    };
    const shared = !!editing.__shared;
    if (shared) { payload.description = editing.description || ''; }
    const base = shared ? 'admin/shared-rules' : 'postbox-rules';
    const r = editing.id
      ? await apiPut(`${base}/${editing.id}`, payload)
      : await apiPost(base, payload);
    setSaving(false);
    if (r.status < 400 && r.body?.ok) {
      setFlash(editing.id ? 'Regel aktualisiert.' : 'Regel angelegt.');
      setEditing(null);
      if (shared) { await loadShared(); } else { await load(); }
    } else {
      setErr(r.body?.message || r.body?.error || ('HTTP ' + r.status));
    }
  };

  const remove = async (r) => {
    const shared = !!r.__shared;
    const base = shared ? 'admin/shared-rules' : 'postbox-rules';
    const res = await apiDelete(`${base}/${r.id}`);
    setDeleteTarget(null);
    if (res.status < 400) {
      setFlash('Regel gelöscht.');
      if (shared) { await loadShared(); } else { await load(); }
    } else setErr(res.body?.error || ('HTTP ' + res.status));
  };

  const toggleEnabled = async (r) => {
    await apiPut(`postbox-rules/${r.id}`, { ...r, enabled: !r.enabled });
    await load();
  };

  const toggleSharedEnabled = async (r) => {
    const payload = { ...r, enabled: !r.enabled, description: r.description || '' };
    await apiPut(`admin/shared-rules/${r.id}`, payload);
    await loadShared();
  };

  return (
    <PageShell>
      <PageTopbar kicker="MAILGUARD · POSTFACH-REGELN" user={me?.email} />
      <Section>
        <SectionHeader
          num="001"
          title="EIGENE REGELN"
          counter={items ? `${items.length} ANGELEGT` : null}
          right={
            <button className="mg-btn mg-btn--primary" style={{ fontSize: '0.75rem', letterSpacing: '0.08em' }} onClick={() => { setEditing(emptyRule()); setFlash(null); setErr(null); }}>
              + NEUE REGEL
            </button>
          }
        />

      {flash && (
        <div style={{ padding: '0.85rem 1rem', background: 'rgba(63,185,80,0.1)', border: '1px solid var(--mg-ok)', borderRadius: 6, color: 'var(--mg-ok)', fontSize: '0.95rem' }}>
          ✓ {flash}
        </div>
      )}
      {err && <div className="mg-error">{err}</div>}

      {items === null && (
        <div className="mg-card mg-muted" style={{ textAlign: 'center', padding: '2rem' }}>Lade …</div>
      )}
      {items !== null && items.length === 0 && !editing && (
        <div className="mg-card mg-muted" style={{ textAlign: 'center', padding: '2rem', fontSize: '0.95rem' }}>
          Noch keine Regeln angelegt.<br />
          <span style={{ fontSize: '0.85rem', opacity: 0.7 }}>Beispiel: Mails mit Newsletter-Header in den Ordner „Newsletter" verschieben.</span>
        </div>
      )}

      {items !== null && items.length > 0 && (
        <ul className="mg-rules-list">
          {items.map((r) => (
            <RuleRow
              key={r.id}
              r={r}
              onToggle={() => toggleEnabled(r)}
              onEdit={() => { setEditing({ ...r }); setFlash(null); setErr(null); }}
              onDelete={() => setDeleteTarget(r)}
            />
          ))}
        </ul>
      )}

      <SectionHeader
        num="002"
        title="SPAM-DB · GLOBALE REGELN"
        counter={`${sharedItems.length} AKTIV`}
        right={isOperator && (
          <button
            className="mg-btn"
            style={{ fontSize: '0.75rem', letterSpacing: '0.08em' }}
            onClick={() => { setEditing({ ...emptyRule(), __shared: true, description: '' }); setFlash(null); setErr(null); }}
          >
            + GLOBALE REGEL
          </button>
        )}
      />
      <div className="mg-dash__shared-intro mg-mono">
        VOM BETREIBER GEPFLEGTE SPAM-DB · PRO REGEL OPT-OUT MÖGLICH
      </div>
      <div>
        {sharedItems.length === 0 && (
          <div className="mg-muted" style={{ textAlign: 'center', padding: '1rem', fontSize: '0.9rem' }}>
            Noch keine globalen Regeln angelegt.
          </div>
        )}
        {sharedItems.length > 0 && (
          <ul className="mg-rules-list">
            {sharedItems.map((r) => (
              <RuleRow
                key={r.id}
                r={r}
                shared
                isOperator={isOperator}
                busy={sharedBusy[r.id]}
                onOptOut={() => toggleOptOut(r)}
                onToggle={() => toggleSharedEnabled(r)}
                onEdit={() => { setEditing({ ...r, __shared: true }); setFlash(null); setErr(null); }}
                onDelete={() => setDeleteTarget({ ...r, __shared: true })}
              />
            ))}
          </ul>
        )}
      </div>

      {editing && (
        <div className="mg-card">
          <h3 style={{ margin: '0 0 1.25rem', fontSize: '1.1rem' }}>
            {editing.id
              ? (editing.__shared ? 'Globale Regel bearbeiten' : 'Regel bearbeiten')
              : (editing.__shared ? 'Neue globale Regel' : 'Neue Postfach-Regel')}
          </h3>
          <div className="mg-form">
            <label>
              <span>Name</span>
              <input
                type="text"
                value={editing.name}
                onChange={(e) => setEditing({ ...editing, name: e.target.value })}
                placeholder="z.B. Newsletter in Newsletter-Ordner verschieben"
                autoFocus
              />
            </label>
            {editing.__shared && (
              <label>
                <span>Beschreibung (optional)</span>
                <input
                  type="text"
                  value={editing.description || ''}
                  onChange={(e) => setEditing({ ...editing, description: e.target.value })}
                  placeholder="Kurze Erklärung — wird den Kunden angezeigt"
                />
              </label>
            )}

            <div className="mg-form__row" style={{ alignItems: 'flex-end', gap: 12 }}>
              <label style={{ flex: '0 0 120px' }}>
                <span>Priorität</span>
                <input
                  type="number"
                  value={editing.priority}
                  onChange={(e) => setEditing({ ...editing, priority: parseInt(e.target.value, 10) || 100 })}
                  min="0" max="9999"
                />
              </label>
              <label className="mg-form__checkbox" style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingBottom: '0.4rem' }}>
                <input
                  type="checkbox"
                  checked={editing.enabled}
                  onChange={(e) => setEditing({ ...editing, enabled: e.target.checked })}
                  style={{ width: 16, height: 16 }}
                />
                <span style={{ color: 'var(--mg-fg)' }}>Aktiv</span>
              </label>
              <label className="mg-form__checkbox" style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingBottom: '0.4rem' }}>
                <input
                  type="checkbox"
                  checked={editing.stop_processing}
                  onChange={(e) => setEditing({ ...editing, stop_processing: e.target.checked })}
                  style={{ width: 16, height: 16 }}
                />
                <span style={{ color: 'var(--mg-fg)' }}>Weitere Regeln danach überspringen</span>
              </label>
            </div>

            <div style={{ borderTop: '1px solid var(--mg-border)', paddingTop: '1rem' }}>
              <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 10 }}>
                <strong style={{ whiteSpace: 'nowrap' }}>Wenn</strong>
                <select
                  value={editing.match_op}
                  onChange={(e) => setEditing({ ...editing, match_op: e.target.value })}
                  style={{ padding: '0.4rem 0.6rem', border: '1px solid var(--mg-border)', borderRadius: 6, background: 'var(--mg-surface-2)', color: 'var(--mg-fg)', colorScheme: 'dark', font: 'inherit' }}
                >
                  <option value="AND">alle Bedingungen</option>
                  <option value="OR">mindestens eine Bedingung</option>
                </select>
                <span style={{ color: 'var(--mg-muted)', whiteSpace: 'nowrap' }}>zutreffen:</span>
              </div>
              {editing.conditions.map((c, idx) => (
                <ConditionRow
                  key={idx} c={c} canRemove={editing.conditions.length > 1}
                  onChange={(patch) => {
                    const conditions = editing.conditions.map((x, i) => i === idx ? { ...x, ...patch } : x);
                    setEditing({ ...editing, conditions });
                  }}
                  onRemove={() => setEditing({ ...editing, conditions: editing.conditions.filter((_, i) => i !== idx) })}
                />
              ))}
              <button
                className="mg-btn"
                style={{ fontSize: '0.85rem' }}
                onClick={() => setEditing({ ...editing, conditions: [...editing.conditions, { field: 'from_addr', op: 'equals', value: '' }] })}
              >
                + Bedingung
              </button>
            </div>

            <div style={{ borderTop: '1px solid var(--mg-border)', paddingTop: '1rem' }}>
              <strong style={{ display: 'block', marginBottom: 10 }}>Dann:</strong>
              {editing.actions.map((a, idx) => (
                <ActionRow
                  key={idx} a={a} canRemove={editing.actions.length > 1}
                  onChange={(patch) => {
                    const actions = editing.actions.map((x, i) => i === idx ? { ...x, ...patch } : x);
                    setEditing({ ...editing, actions });
                  }}
                  onRemove={() => setEditing({ ...editing, actions: editing.actions.filter((_, i) => i !== idx) })}
                />
              ))}
              <button
                className="mg-btn"
                style={{ fontSize: '0.85rem' }}
                onClick={() => setEditing({ ...editing, actions: [...editing.actions, { type: 'move', folder: '' }] })}
              >
                + Aktion
              </button>
            </div>

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', paddingTop: '0.5rem' }}>
              <button className="mg-btn" onClick={() => setEditing(null)} disabled={saving}>Abbrechen</button>
              <button
                className="mg-btn mg-btn--primary"
                onClick={save}
                disabled={saving || !editing.name.trim() || editing.conditions.length === 0 || editing.actions.length === 0}
              >
                {saving ? '…' : 'Speichern'}
              </button>
            </div>
          </div>
        </div>
      )}

      {deleteTarget && (
        <div
          role="dialog" aria-modal="true"
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: 20, backdropFilter: 'blur(4px)' }}
          onClick={(e) => { if (e.target === e.currentTarget) setDeleteTarget(null); }}
        >
          <div style={{ background: 'var(--mg-surface)', border: '1px solid var(--mg-border)', borderRadius: 10, boxShadow: '0 24px 48px rgba(0,0,0,0.45)', maxWidth: 440, width: '100%', padding: 24 }}>
            <h3 style={{ margin: '0 0 10px', fontSize: '1.05rem' }}>Regel löschen?</h3>
            <p style={{ margin: '0 0 20px', color: 'var(--mg-muted)', fontSize: '0.9rem' }}>
              <strong style={{ color: 'var(--mg-fg)' }}>{deleteTarget.name}</strong> wird unwiderruflich entfernt.
            </p>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button className="mg-btn" onClick={() => setDeleteTarget(null)}>Abbrechen</button>
              <button
                className="mg-btn"
                onClick={() => remove(deleteTarget)}
                style={{ color: 'var(--mg-err)', borderColor: 'color-mix(in srgb, var(--mg-err) 40%, transparent)' }}
              >
                Löschen
              </button>
            </div>
          </div>
        </div>
      )}
      </Section>
    </PageShell>
  );
}

const selectStyle = {
  padding: '0.55rem 0.7rem',
  border: '1px solid var(--mg-border)',
  borderRadius: 6,
  background: 'var(--mg-surface-2)',
  color: 'var(--mg-fg)',
  colorScheme: 'dark',
  font: 'inherit',
  fontSize: '0.9rem',
};
const inputStyle = {
  ...selectStyle,
  flex: '1 1 160px',
};

function ConditionRow({ c, canRemove, onChange, onRemove }) {
  const meta = FIELD_META[c.field] || FIELD_META.from_addr;
  const opList = meta.type === 'number' ? NUMBER_OPS : STRING_OPS;
  const currentOp = opList.includes(c.op) ? c.op : opList[0];
  const onFieldChange = (field) => {
    const newMeta = FIELD_META[field] || FIELD_META.from_addr;
    const newOpList = newMeta.type === 'number' ? NUMBER_OPS : STRING_OPS;
    const newOp = newOpList.includes(c.op) ? c.op : newOpList[0];
    let value = c.value;
    if (newMeta.type === 'bool') value = false;
    else if (newMeta.type === 'enum') value = (newMeta.options && newMeta.options[0]) || '';
    onChange({ field, op: newOp, value });
  };
  return (
    <div style={{ display: 'flex', gap: 6, marginBottom: 8, flexWrap: 'wrap', alignItems: 'center' }}>
      <select style={selectStyle} value={c.field} onChange={(e) => onFieldChange(e.target.value)}>
        {Object.entries(FIELD_META).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
      </select>
      <select style={selectStyle} value={currentOp} onChange={(e) => onChange({ op: e.target.value })}>
        {opList.map((o) => <option key={o} value={o}>{OP_LABELS[o] || o}</option>)}
      </select>
      {meta.type === 'bool' ? (
        <select style={selectStyle} value={c.value ? '1' : '0'} onChange={(e) => onChange({ value: e.target.value === '1' })}>
          <option value="1">Ja</option><option value="0">Nein</option>
        </select>
      ) : meta.type === 'enum' ? (
        <select style={selectStyle} value={c.value} onChange={(e) => onChange({ value: e.target.value })}>
          {meta.options.map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      ) : meta.type === 'number' ? (
        <input style={inputStyle} type="number" value={c.value} onChange={(e) => onChange({ value: parseInt(e.target.value, 10) || 0 })} />
      ) : (
        <input style={inputStyle} type="text" value={c.value} onChange={(e) => onChange({ value: e.target.value })} placeholder={meta.placeholder} />
      )}
      <button className="mg-btn" onClick={onRemove} disabled={!canRemove} style={{ fontSize: '1rem', padding: '0.5rem 0.85rem' }}>−</button>
    </div>
  );
}

function ActionRow({ a, canRemove, onChange, onRemove }) {
  return (
    <div style={{ display: 'flex', gap: 6, marginBottom: 8, flexWrap: 'wrap', alignItems: 'center' }}>
      <select style={selectStyle} value={a.type} onChange={(e) => {
        const type = e.target.value;
        if (type === 'move')      onChange({ type, folder: a.folder || '' });
        else if (type === 'flag') onChange({ type, flag: a.flag || '\\Seen' });
        else                      onChange({ type });
      }}>
        {Object.entries(ACTION_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
      </select>
      {a.type === 'move' && (
        <input
          style={inputStyle}
          type="text"
          value={a.folder || ''}
          onChange={(e) => onChange({ folder: e.target.value })}
          placeholder="z.B. INBOX/Newsletter"
        />
      )}
      {a.type === 'flag' && (
        <select style={selectStyle} value={a.flag || '\\Seen'} onChange={(e) => onChange({ flag: e.target.value })}>
          <option value="\\Seen">Gelesen</option>
          <option value="\\Flagged">Markiert</option>
          <option value="\\Answered">Beantwortet</option>
        </select>
      )}
      <button className="mg-btn" onClick={onRemove} disabled={!canRemove} style={{ fontSize: '1rem', padding: '0.5rem 0.85rem' }}>−</button>
    </div>
  );
}

function RuleRow({ r, shared, isOperator, busy, onToggle, onEdit, onDelete, onOptOut }) {
  const dimmed = (!r.enabled) || (shared && r.opted_out);
  return (
    <li className={'mg-rules-row' + (dimmed ? ' mg-rules-row--dimmed' : '')}>
      <div className="mg-rules-row__head">
        <strong className="mg-rules-row__name">{r.name || '(ohne Name)'}</strong>
        <span className="mg-rules-row__prio mg-mono">P{String(r.priority).padStart(3, '0')}</span>
        {!r.enabled && <span className="mg-rules-row__flag mg-mono">OFF</span>}
        {shared && r.opted_out && <span className="mg-rules-row__flag mg-rules-row__flag--err mg-mono">OPT-OUT</span>}
        <span className="mg-rules-row__hits mg-mono">
          {r.hit_count}× HIT{r.last_hit_at && ` · ${fmtRel(r.last_hit_at)}`}
        </span>
      </div>
      {shared && r.description && <div className="mg-rules-row__desc">{r.description}</div>}
      <div className="mg-rules-row__body mg-mono">
        <span className="mg-rules-row__op">{r.match_op}</span>
        {' '}
        {r.conditions.map((c, i) => (
          <span key={i} className="mg-rules-row__cond">
            {i > 0 && <span className="mg-rules-row__sep">{r.match_op === 'AND' ? '∧' : '∨'}</span>}
            <code>{FIELD_META[c.field]?.label || c.field}</code>
            <span className="mg-rules-row__opc">{OP_LABELS[c.op] || c.op}</span>
            <code>{String(c.value)}</code>
          </span>
        ))}
        <span className="mg-rules-row__arrow">⇒</span>
        {r.actions.map((a, i) => (
          <code key={i} className={'mg-rules-row__action' + (a.type === 'delete' ? ' mg-rules-row__action--danger' : '')}>
            {a.type === 'move' ? `MOVE → ${a.folder}` : a.type === 'delete' ? 'DELETE' : `FLAG ${a.flag}`}
          </code>
        ))}
      </div>
      <div className="mg-rules-row__actions">
        {shared ? (
          <>
            <button className="mg-btn" disabled={busy} onClick={onOptOut} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>
              {busy ? '…' : (r.opted_out ? '✓ FÜR MICH AN' : '🚫 FÜR MICH AUS')}
            </button>
            {isOperator && (
              <>
                <button className="mg-btn" onClick={onToggle} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>{r.enabled ? 'GLOBAL AUS' : 'GLOBAL AN'}</button>
                <button className="mg-btn" onClick={onEdit} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>EDIT</button>
                <button className="mg-btn mg-btn--danger-ghost" onClick={onDelete} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>DEL</button>
              </>
            )}
          </>
        ) : (
          <>
            <button className="mg-btn" onClick={onToggle} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>{r.enabled ? 'DEAKTIVIEREN' : 'AKTIVIEREN'}</button>
            <button className="mg-btn" onClick={onEdit} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>EDIT</button>
            <button className="mg-btn mg-btn--danger-ghost" onClick={onDelete} style={{ fontSize: '0.7rem', letterSpacing: '0.08em' }}>DEL</button>
          </>
        )}
      </div>
    </li>
  );
}

function fmtRel(s) {
  if (!s) return 'IDLE';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return s;
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return 'EBEN';
  if (diff < 3600) return Math.floor(diff / 60) + 'MIN';
  if (diff < 86400) return Math.floor(diff / 3600) + 'H';
  return Math.floor(diff / 86400) + 'D';
}
