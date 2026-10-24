import React, { useEffect, useState } from 'react';
import { apiGet } from '../api.js';
import { navigate } from '../router.js';
import { PageShell, PageTopbar, Section, SectionHeader, HairlineList, HairlineRow } from '../components/Section.jsx';

// Precision Monochrome Security — monumentale Numerals, mono labels,
// hairline dividers, live-pulse. Zero cards, pure information hierarchy.
export default function Dashboard({ me }) {
  const [data, setData]   = useState(null);
  const [err, setErr]     = useState(null);
  const [now, setNow]     = useState(() => new Date());

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        const { status, body } = await apiGet('dashboard');
        if (cancelled) return;
        if (status < 400 && body?.ok) setData(body);
        else setErr(body?.error || ('HTTP ' + status));
      } catch (e) { if (!cancelled) setErr(String(e)); }
    };
    load();
    const t = setInterval(load, 60_000); // refresh every 60s
    return () => { cancelled = true; clearInterval(t); };
  }, []);

  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  if (err) {
    return <div className="mg-dash"><div className="mg-dash__err">{err}</div></div>;
  }

  const s = data?.stats || {};
  return (
    <div className="mg-dash">
      {/* STATUS-BAR */}
      <header className="mg-dash__topbar">
        <div className="mg-dash__topbar-left">
          <span className="mg-dash__kicker">MAILGUARD · DASHBOARD</span>
          <span className="mg-dash__sep" aria-hidden>/</span>
          <span className="mg-dash__user">{me?.email}</span>
        </div>
        <div className="mg-dash__topbar-right">
          <span className="mg-dash__clock">{fmtClock(now)}</span>
          <span className="mg-dash__dot mg-dash__dot--live" aria-hidden />
          <span className="mg-dash__status">LIVE</span>
        </div>
      </header>

      {/* HERO METRICS — monumentale Numerals */}
      <section className="mg-dash__hero">
        <HeroStat
          value={data ? s.total : null}
          label="MAILS GESCANNT"
          detail={data ? `heute · ${num(data.today)}` : null}
        />
        <HeroStat
          value={data ? (s.dangerous + s.suspicious) : null}
          label="BEDROHUNGEN"
          detail={data ? `24h · ${num(data.purges_24h)} vernichtet` : null}
          tone="warn"
        />
        <HeroStat
          value={data ? data.quarantined_open : null}
          label="QUARANTÄNE"
          detail={data ? `7-Tage · rückrufbar` : null}
        />
        <HeroStat
          value={data ? s.clean : null}
          label="SAUBER"
          detail={data ? `${pct(s.clean, s.total)}%` : null}
          tone="ok"
        />
      </section>

      {/* VERDICT-BAR */}
      {data && (
        <section className="mg-dash__section">
          <SectionHeader num="001" title="SCAN-VERDICT · VERTEILUNG" />
          <VerdictBar
            clean={s.clean}
            suspicious={s.suspicious}
            dangerous={s.dangerous}
            pending={s.pending_scan}
            total={s.total}
          />
        </section>
      )}

      {/* ACCOUNTS */}
      <section className="mg-dash__section">
        <SectionHeader
          num="002"
          title="POSTFÄCHER"
          right={
            <button className="mg-dash__link" onClick={() => navigate('accounts')}>
              VERWALTEN →
            </button>
          }
        />
        {data?.accounts?.length ? (
          <ul className="mg-dash__list">
            {data.accounts.map((a) => (
              <li key={a.id} className="mg-dash__row">
                <span className={'mg-dash__dot mg-dash__dot--' + accountDotTone(a)} aria-hidden />
                <span className="mg-dash__row-label">{a.label}</span>
                <span className="mg-dash__row-sub mg-mono">{a.username}</span>
                <span className="mg-dash__row-val mg-mono">{num(a.mail_count)} MAILS</span>
                <span className="mg-dash__row-tag mg-mono">{accountTag(a)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <div className="mg-dash__empty">
            Noch kein Postfach verbunden.
            <button className="mg-btn mg-btn--primary" style={{ marginLeft: '1rem' }} onClick={() => navigate('accounts/new')}>
              + Postfach anlegen
            </button>
          </div>
        )}
      </section>

      {/* SPAM-DB */}
      <section className="mg-dash__section">
        <SectionHeader
          num="003"
          title="SPAM-DB · GLOBALE REGELN"
          right={
            <button className="mg-dash__link" onClick={() => navigate('postbox-rules')}>
              {data?.is_operator ? 'VERWALTEN' : 'ANSEHEN'} →
            </button>
          }
          counter={data ? `${num(data.rules?.shared_active)} AKTIV` : null}
        />
        {data?.rules?.shared_top?.length ? (
          <ul className="mg-dash__list">
            {data.rules.shared_top.map((r, i) => (
              <li key={r.id} className="mg-dash__row">
                <span className="mg-dash__row-num mg-mono">{String(i + 1).padStart(2, '0')}</span>
                <span className="mg-dash__row-label">{r.name}</span>
                <span className="mg-dash__row-val mg-mono">
                  {r.hit_count}× HITS
                </span>
                <span className="mg-dash__row-tag mg-mono">
                  {r.last_hit_at ? fmtRelative(r.last_hit_at) : 'IDLE'}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <div className="mg-dash__empty">Noch keine globalen Regeln.</div>
        )}
      </section>

      {/* RECENT ACTIONS — Activity-Log */}
      <section className="mg-dash__section">
        <SectionHeader
          num="004"
          title="LETZTE AKTIVITÄT"
          right={
            <button className="mg-dash__link" onClick={() => navigate('actions')}>
              ALLE →
            </button>
          }
        />
        {data?.recent_actions?.length ? (
          <ul className="mg-dash__list mg-dash__list--log">
            {data.recent_actions.map((a, i) => (
              <li key={i} className="mg-dash__row mg-dash__row--log">
                <span className="mg-dash__row-time mg-mono">{fmtShortTime(a.created_at)}</span>
                <span className={'mg-dash__action-tag mg-dash__action-tag--' + a.action}>
                  {a.action.toUpperCase()}
                </span>
                <span className="mg-dash__row-label mg-mono mg-dash__row-label--mono">
                  {a.from_addr || '—'}
                </span>
                <span className="mg-dash__row-sub">{a.subject || ''}</span>
                {a.score !== null && (
                  <span className={'mg-dash__row-tag mg-mono mg-dash__verdict-' + (a.verdict || 'clean')}>
                    {(a.verdict || '').toUpperCase()} · {a.score}
                  </span>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <div className="mg-dash__empty">Noch keine Aktionen protokolliert.</div>
        )}
      </section>

      {/* QUICK-ACTIONS */}
      <section className="mg-dash__quickbar">
        <button className="mg-btn mg-btn--primary" onClick={() => navigate('inbox')}>
          📥 Inbox öffnen
        </button>
        <button className="mg-btn" onClick={() => navigate('postbox-rules')}>Regeln</button>
        <button className="mg-btn" onClick={() => navigate('scanner')}>Scanner</button>
        <button className="mg-btn" onClick={() => navigate('newsletters')}>Newsletter</button>
        <button className="mg-btn" onClick={() => navigate('plan')}>Plan & Lizenz</button>
      </section>

      <footer className="mg-dash__foot">
        <span className="mg-mono">MAILGUARD v{(window.itdatexMailguard || {}).version || '?'}</span>
        <span className="mg-dash__sep" aria-hidden>·</span>
        <span className="mg-mono">LAST SYNC {fmtClock(now)}</span>
      </footer>
    </div>
  );
}

/* ------------------------- Components ------------------------- */

function HeroStat({ value, label, detail, tone }) {
  const display = value === null || value === undefined ? '—' : num(value);
  return (
    <div className={'mg-dash__hero-stat' + (tone ? ' mg-dash__hero-stat--' + tone : '')}>
      <div className="mg-dash__hero-num">{display}</div>
      <div className="mg-dash__hero-label mg-mono">{label}</div>
      {detail && <div className="mg-dash__hero-detail mg-mono">{detail}</div>}
    </div>
  );
}

function VerdictBar({ clean, suspicious, dangerous, pending, total }) {
  if (!total) return null;
  const seg = (n) => (n / total) * 100;
  return (
    <div className="mg-dash__verdictbar">
      <div className="mg-dash__verdictbar-track">
        {clean      > 0 && <div className="mg-dash__verdictbar-seg mg-dash__verdictbar-seg--clean"      style={{ width: seg(clean) + '%' }}      title={`sauber: ${clean}`} />}
        {suspicious > 0 && <div className="mg-dash__verdictbar-seg mg-dash__verdictbar-seg--suspicious" style={{ width: seg(suspicious) + '%' }} title={`verdächtig: ${suspicious}`} />}
        {dangerous  > 0 && <div className="mg-dash__verdictbar-seg mg-dash__verdictbar-seg--dangerous"  style={{ width: seg(dangerous) + '%' }}  title={`gefährlich: ${dangerous}`} />}
        {pending    > 0 && <div className="mg-dash__verdictbar-seg mg-dash__verdictbar-seg--pending"    style={{ width: seg(pending) + '%' }}    title={`ausstehend: ${pending}`} />}
      </div>
      <div className="mg-dash__verdictbar-legend mg-mono">
        <LegendItem tone="clean"      count={clean}      label="SAUBER" />
        <LegendItem tone="suspicious" count={suspicious} label="VERDÄCHTIG" />
        <LegendItem tone="dangerous"  count={dangerous}  label="GEFÄHRLICH" />
        <LegendItem tone="pending"    count={pending}    label="PENDING" />
      </div>
    </div>
  );
}

function LegendItem({ tone, count, label }) {
  return (
    <span className="mg-dash__legend">
      <span className={'mg-dash__legend-dot mg-dash__legend-dot--' + tone} aria-hidden />
      <span className="mg-dash__legend-count">{num(count || 0)}</span>
      <span className="mg-dash__legend-label">{label}</span>
    </span>
  );
}

/* ------------------------- Helpers ------------------------- */

function num(n) {
  if (n === null || n === undefined) return '—';
  return Number(n).toLocaleString('de-DE');
}

function pct(part, total) {
  if (!total) return 0;
  return Math.round((part / total) * 100);
}

function fmtClock(d) {
  const h = String(d.getUTCHours()).padStart(2, '0');
  const m = String(d.getUTCMinutes()).padStart(2, '0');
  const s = String(d.getUTCSeconds()).padStart(2, '0');
  return `${h}:${m}:${s} UTC`;
}

function fmtShortTime(s) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return s;
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return 'JUST NOW';
  if (diff < 3600) return Math.floor(diff / 60) + 'M AGO';
  const h = String(d.getUTCHours()).padStart(2, '0');
  const m = String(d.getUTCMinutes()).padStart(2, '0');
  const sameDay = (new Date().toISOString().slice(0, 10)) === d.toISOString().slice(0, 10);
  return sameDay ? `${h}:${m}` : `${d.toISOString().slice(5, 10).replace('-', '/')} ${h}:${m}`;
}

function fmtRelative(s) {
  if (!s) return 'IDLE';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return s;
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return 'EBEN';
  if (diff < 3600) return Math.floor(diff / 60) + 'MIN';
  if (diff < 86400) return Math.floor(diff / 3600) + 'H';
  return Math.floor(diff / 86400) + 'D';
}

function accountDotTone(a) {
  if (a.status !== 'active') return 'muted';
  if (a.last_test_ok === false) return 'err';
  return 'live';
}
function accountTag(a) {
  if (a.status !== 'active') return a.status.toUpperCase();
  if (a.last_test_ok === false) return 'ERR';
  return 'LIVE';
}
