import React, { useEffect, useState } from 'react';
import { apiGet, apiPost } from '../api.js';
import { VerdictBadge, ReasonsList } from '../components/Verdict.jsx';

const MODES = [
  { id: 'url',   label: 'URL' },
  { id: 'email', label: 'E-Mail' },
];

export default function Scanner() {
  const [mode, setMode]     = useState('url');
  const [url, setUrl]       = useState('');
  const [subject, setSubject]   = useState('');
  const [fromAddr, setFromAddr] = useState('');
  const [body, setBody]         = useState('');
  const [headersRaw, setHeadersRaw] = useState('');
  const [deep, setDeep]     = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError]   = useState(null);
  const [quota, setQuota]   = useState(null);

  const loadQuota = async () => {
    const { body } = await apiGet('scan/quota');
    if (body && body.ok) setQuota({ used: body.used, limit: body.limit, remaining: body.remaining });
  };

  useEffect(() => { loadQuota(); }, []);

  const reset = () => { setResult(null); setError(null); };

  const submit = async (e) => {
    e.preventDefault();
    reset(); setLoading(true);
    try {
      if (mode === 'url') {
        if (!/^https?:\/\/\S+/.test(url)) { setError('Bitte eine vollständige URL (https://…) eingeben.'); return; }
        const { status, body: b } = await apiPost('scan/url', { url: url.trim() });
        if (status === 429) { setError('Tages-Quota verbraucht. Bitte morgen erneut.'); return; }
        if (status >= 400)  { setError('HTTP ' + status); return; }
        setResult(b.result);
        if (typeof b.remaining === 'number') setQuota({ used: b.limit - b.remaining, limit: b.limit, remaining: b.remaining });
      } else {
        const payload = {
          subject, body, from_addr: fromAddr || null,
          headers: parseHeaders(headersRaw),
          deep,
        };
        const { status, body: b } = await apiPost('scan/email', payload);
        if (status === 429) { setError('Tages-Quota verbraucht. Bitte morgen erneut.'); return; }
        if (status >= 400)  { setError('HTTP ' + status); return; }
        setResult(b.result);
        if (typeof b.remaining === 'number') setQuota({ used: b.limit - b.remaining, limit: b.limit, remaining: b.remaining });
      }
    } catch (err) {
      setError(String(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2 style={{ margin: '0 0 0.25rem' }}>Scanner</h2>
        <p className="mg-muted" style={{ margin: 0 }}>
          URL oder Mail-Snippet prüfen, ohne sie zu öffnen.
          {quota && <> · <strong>{quota.remaining}</strong> von {quota.limit} Scans heute übrig.</>}
        </p>
      </div>

      <form className="mg-card mg-form" onSubmit={submit}>
        <div className="mg-modes">
          {MODES.map((m) => (
            <label key={m.id} className={'mg-mode' + (mode === m.id ? ' is-active' : '')}>
              <input type="radio" name="scan-mode" value={m.id} checked={mode === m.id} onChange={() => { setMode(m.id); reset(); }} />
              {m.label}
            </label>
          ))}
        </div>

        {mode === 'url' && (
          <label>URL
            <input type="text" required value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://..." autoFocus />
          </label>
        )}

        {mode === 'email' && (
          <>
            <label>Subject<input type="text" value={subject} onChange={(e) => setSubject(e.target.value)} /></label>
            <label>Absender (From)<input type="text" value={fromAddr} onChange={(e) => setFromAddr(e.target.value)} placeholder="name@example.com" /></label>
            <label>Body<textarea rows={6} value={body} onChange={(e) => setBody(e.target.value)} /></label>
            <label>Header (optional, eine pro Zeile <code>Header: Wert</code>)
              <textarea rows={3} value={headersRaw} onChange={(e) => setHeadersRaw(e.target.value)} placeholder={'List-Unsubscribe: <https://...>\nList-Unsubscribe-Post: List-Unsubscribe=One-Click'} />
            </label>
            <label>
              <input type="checkbox" checked={deep} onChange={(e) => setDeep(e.target.checked)} />
              {' '}LLM-Deep-Mode (dauert 15–25 s)
            </label>
          </>
        )}

        {error && <div className="mg-error">{error}</div>}

        <button type="submit" className="mg-btn mg-btn--primary" disabled={loading}>
          {loading ? 'Scanne …' : 'Scannen'}
        </button>
      </form>

      {result && (
        <div className="mg-card">
          <h3 style={{ margin: '0 0 0.5rem' }}>Ergebnis</h3>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.5rem' }}>
            <VerdictBadge verdict={result.verdict} score={result.score} status="done" />
            <span className="mg-muted mg-tiny">Score {result.score} / 100</span>
          </div>
          <ReasonsList reasons={result.reasons} />
        </div>
      )}
    </div>
  );
}

function parseHeaders(raw) {
  const out = {};
  if (!raw) return out;
  for (const line of raw.split(/\r?\n/)) {
    const m = line.match(/^([A-Za-z0-9-]+)\s*:\s*(.*)$/);
    if (m) out[m[1]] = out[m[1]] ? out[m[1]] + ', ' + m[2] : m[2];
  }
  return out;
}
