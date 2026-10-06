import React from 'react';

// Bestaetigungsmodal fuer unwiderrufliche Vernichten-Aktionen (IMAP EXPUNGE).
// Ersetzt die alte Type-in-Prompt-UX ("VERNICHTEN eintippen") durch eine
// Checkbox. Muscle-Memory-Klicks sind trotzdem blockiert, weil der
// Vernichten-Button disabled bleibt, bis die Bestaetigungs-Checkbox aktiv
// ist. Deckungsgleich zur Desktop-App-Version aus src/views/Senders.jsx.
//
// Optional koennen ueber `extras` weitere Elemente ueber der Bestaetigungs-
// Checkbox eingeblendet werden (z.B. eine zweite Checkbox fuer "auch Domain
// vernichten"), damit Folge-Entscheidungen im selben Dialog liegen und nicht
// in einem separaten confirm() nachtrudeln.

export default function PurgeConfirmDialog({
  open,
  title,
  description,
  extras,
  // Ack-Checkbox nur wenn explizit angefordert (Default aus). Fuer alltaegliche
  // Vernichten-Aktionen (Sender/Mail) genuegt der prominent rote Button plus die
  // zweite Klick-Bestaetigung aus dem Dialog-Context — Muscle-Memory ist bei
  // einem explizit roten Button nach Klick auf "Vernichten (105)" verlaesslich.
  // Fuer wirklich destruktive Multi-Domain-Aktionen koennen Call-Sites
  // ackRequired={true} setzen, dann wird die alte Checkbox-UX genutzt.
  ackRequired = false,
  ackLabel = 'Ich habe verstanden, dass diese Aktion nicht rueckgaengig zu machen ist.',
  confirmLabel = 'Vernichten',
  cancelLabel = 'Abbrechen',
  checked,
  onToggle,
  onCancel,
  onConfirm,
  busy = false,
  // Optional-Pills statt separater Checkboxes — kompakter + ein Klick-Target.
  // Shape: [{ id, label, title?, checked, onChange }]
  pills,
  // Legacy single-toggle (senderToggle) — wird intern auf pills gemappt wenn
  // kein pills[] uebergeben wurde, damit alte Call-Sites nicht brechen.
  senderToggleLabel,
  senderToggleChecked = false,
  onSenderToggle,
}) {
  if (!open) return null;

  const effectivePills = pills || (senderToggleLabel ? [{
    id: 'sender-toggle',
    label: senderToggleLabel,
    checked: senderToggleChecked,
    onChange: onSenderToggle,
  }] : []);

  const confirmDisabled = (ackRequired && !checked) || busy;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="mg-purge-title"
      style={{
        position: 'fixed', inset: 0,
        background: 'rgba(0,0,0,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000,
        padding: 20,
        backdropFilter: 'blur(4px)',
      }}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onCancel(); }}
    >
      <div style={{
        background: 'var(--mg-surface, #0e0e14)',
        border: '1px solid var(--mg-border, #ddd)',
        borderRadius: 'var(--mg-radius-xl, 14px)',
        boxShadow: 'var(--mg-shadow-2, 0 24px 48px rgba(0,0,0,0.45))',
        maxWidth: 520,
        width: '100%',
        padding: 24,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
          <span aria-hidden="true" style={{ fontSize: 24, lineHeight: 1 }}>🔥</span>
          <h2 id="mg-purge-title" className="mg-title" style={{ fontSize: 18, margin: 0 }}>
            {title}
          </h2>
        </div>

        <div style={{ fontSize: 14, lineHeight: 1.5, color: 'var(--mg-fg)', opacity: 0.9 }}>
          {description}
        </div>

        {extras && <div style={{ marginTop: 12 }}>{extras}</div>}

        {effectivePills.length > 0 && (
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 16 }}>
            {effectivePills.map((p) => (
              <label
                key={p.id}
                title={p.title || ''}
                className={'mg-purge-pill' + (p.checked ? ' mg-purge-pill--active' : '')}
              >
                <input
                  type="checkbox"
                  checked={!!p.checked}
                  onChange={(e) => p.onChange && p.onChange(e.target.checked)}
                />
                <span className="mg-purge-pill__check" aria-hidden="true">{p.checked ? '✓' : '＋'}</span>
                <span>{p.label}</span>
              </label>
            ))}
          </div>
        )}

        {ackRequired && (
          <label className="mg-row" style={{ gap: 8, alignItems: 'flex-start', fontSize: 13, margin: '14px 0 4px', cursor: 'pointer', opacity: 0.85 }}>
            <input
              type="checkbox"
              checked={!!checked}
              onChange={(e) => onToggle(e.target.checked)}
              style={{ marginTop: 2 }}
            />
            <span>{ackLabel}</span>
          </label>
        )}

        <div className="mg-row" style={{ gap: 8, justifyContent: 'flex-end', marginTop: 20 }}>
          <button className="mg-btn" onClick={onCancel} disabled={busy}>{cancelLabel}</button>
          <button
            className="mg-btn mg-purge-confirm"
            onClick={onConfirm}
            disabled={confirmDisabled}
          >
            {busy ? '…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
