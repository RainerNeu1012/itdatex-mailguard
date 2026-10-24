import React, { useCallback, useEffect, useState } from 'react';
import { apiGet, apiPost } from '../api.js';
import { PageShell, PageTopbar, Section, SectionHeader } from '../components/Section.jsx';

// Batch-View fuer LLM-Bewertungen. Zeigt die letzten gescannten Mails mit
// LLM-Reasoning, Filter unbewertet/👍/👎, ein Klick pro Zeile speichert
// Feedback in mg_llm_feedback (Trainingsdaten fuer spaeteres Feintuning).
const FILTERS = [
  { key: '',        label: 'Alle' },
  { key: 'unrated', label: 'Noch offen' },
  { key: 'up',      label: '👍 Passt' },
  { key: 'down',    label: '👎 Daneben' },
];

export default function LlmFeedback({ me }) {
  const [filter, setFilter] = useState('unrated');
  const [items, setItems]   = useState(null);
  const [busy, setBusy]     = useState({});
  const [error, setError]   = useState(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const qs = filter ? `?filter=${encodeURIComponent(filter)}` : '';
      const { status, body } = await apiGet('llm-feedback/recent' + qs);
      if (status === 200 && body?.ok) setItems(body.items || []);
      else setError('HTTP ' + status);
    } catch (e) { setError(String(e)); }
  }, [filter]);

  useEffect(() => { load(); }, [load]);

  const rate = async (id, thumbs) => {
    setBusy((b) => ({ ...b, [id]: thumbs }));
    try {
      await apiPost(`inbox/messages/${id}/llm-feedback`, { thumbs });
      // Optimistic: Row lokal updaten, statt komplett zu reloaden — sonst
      // scrollt die Liste jedes Mal zurueck an den Anfang.
      setItems((prev) => prev.map((it) => it.id === id ? { ...it, feedback_thumbs: thumbs } : it));
    } finally {
      setBusy((b) => { const n = { ...b }; delete n[id]; return n; });
    }
  };

  return (
    <PageShell>
      <PageTopbar kicker="MAILGUARD · KI-BEWERTUNGEN" user={me?.email} />
      <Section>
        <SectionHeader
          num="001"
          title="LLM-FEEDBACK"
          right={
            <div className="mg-tabs-mono" role="tablist">
              {FILTERS.map((f) => (
                <button key={f.key || 'all'}
                        className={'mg-tabs-mono__btn' + (filter === f.key ? ' mg-tabs-mono__btn--active' : '')}
                        onClick={() => setFilter(f.key)}>
                  <span className="mg-tabs-mono__label">{(f.label || '').toUpperCase()}</span>
                </button>
              ))}
            </div>
          }
        />
        <div className="mg-dash__shared-intro mg-mono">
          👍/👎 PRO MAIL HILFT DEM LLM — ANONYMISIERTES FEINTUNING
        </div>

      {error && <div className="mg-card mg-error">{error}</div>}
      {items === null && <div className="mg-card">Lade …</div>}
      {items && items.length === 0 && (
        <div className="mg-card mg-muted">
          Keine Mails auf diesem Filter. Wechsel den Filter oben.
        </div>
      )}
      {items && items.length > 0 && (
        <div className="mg-stack">
          {items.map((it) => <FeedbackRow key={it.id} it={it} busy={busy[it.id]} onRate={rate} />)}
        </div>
      )}
      </Section>
    </PageShell>
  );
}

function FeedbackRow({ it, busy, onRate }) {
  const done = it.feedback_thumbs;
  const verdictTone = it.scan_verdict === 'dangerous' ? 'mg-pill--err'
                     : it.scan_verdict === 'suspicious' ? 'mg-pill--warn'
                     : 'mg-pill--ok';
  return (
    <div className="mg-card" style={{ padding: '12px 14px', display: 'flex', gap: 12, alignItems: 'flex-start', flexDirection: 'column' }}>
      <div style={{ width: '100%', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'baseline' }}>
        <strong style={{ fontSize: 13 }}>{it.from_name || it.from_addr || '(unbekannt)'}</strong>
        {it.from_name && it.from_addr && <span className="mg-muted mg-tiny">{it.from_addr}</span>}
        <span className={'mg-pill ' + verdictTone}>{it.scan_verdict} · {it.scan_score ?? '?'}</span>
        <span className="mg-muted mg-tiny" style={{ marginLeft: 'auto' }}>{fmt(it.date_hdr)}</span>
      </div>
      <div style={{ fontSize: 14 }}>{it.subject || <em className="mg-muted">(kein Betreff)</em>}</div>
      {it.llm_reasoning && (
        <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--mg-fg)', background: 'var(--mg-surface-2)', padding: '8px 10px', borderRadius: 4, borderLeft: '3px solid var(--mg-accent, #999)' }}>
          🧠 {it.llm_reasoning}
        </div>
      )}
      <div className="mg-row" style={{ gap: 6, marginLeft: 'auto' }}>
        <button
          className={'mg-btn ' + (done === 'up' ? 'mg-btn--primary' : '')}
          disabled={busy === 'up'}
          onClick={() => onRate(it.id, 'up')}
        >{busy === 'up' ? '…' : '👍 Passt'}</button>
        <button
          className={'mg-btn ' + (done === 'down' ? 'mg-btn--danger' : '')}
          disabled={busy === 'down'}
          onClick={() => onRate(it.id, 'down')}
        >{busy === 'down' ? '…' : '👎 Daneben'}</button>
      </div>
    </div>
  );
}

function fmt(iso) {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
  } catch { return String(iso); }
}
