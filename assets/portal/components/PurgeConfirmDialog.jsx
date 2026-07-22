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
  ackLabel = 'Ich habe verstanden, dass diese Aktion nicht rueckgaengig zu machen ist.',
  confirmLabel = 'Vernichten',
  cancelLabel = 'Abbrechen',
  checked,
  onToggle,
  onCancel,
  onConfirm,
  busy = false,
}) {
  if (!open) return null;
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="mg-purge-title"
      style={{
        position: 'fixed', inset: 0,
        background: 'rgba(0,0,0,0.5)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000,
        padding: 20,
      }}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onCancel(); }}
    >
      <div style={{
        background: 'var(--mg-surface, #fff)',
        border: '1px solid var(--mg-border, #ddd)',
        borderRadius: 'var(--mg-radius-xl, 12px)',
        boxShadow: 'var(--mg-shadow-2, 0 12px 32px rgba(0,0,0,0.2))',
        maxWidth: 480,
        width: '100%',
        padding: 24,
      }}>
        <h2 id="mg-purge-title" className="mg-title" style={{ fontSize: 18, marginTop: 0, marginBottom: 12 }}>
          {title}
        </h2>
        <div style={{ marginTop: 0, fontSize: 14, lineHeight: 1.45 }}>
          {description}
        </div>
        {extras}
        <label className="mg-row" style={{ gap: 8, alignItems: 'flex-start', fontSize: 14, margin: '16px 0', cursor: 'pointer' }}>
          <input
            type="checkbox"
            checked={!!checked}
            onChange={(e) => onToggle(e.target.checked)}
            style={{ marginTop: 2 }}
            autoFocus
          />
          <span>{ackLabel}</span>
        </label>
        <div className="mg-row" style={{ gap: 8, justifyContent: 'flex-end' }}>
          <button className="mg-btn" onClick={onCancel} disabled={busy}>{cancelLabel}</button>
          <button
            className="mg-btn"
            onClick={onConfirm}
            disabled={!checked || busy}
            style={{ color: 'var(--mg-err, #c33)' }}
          >
            {busy ? '…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
