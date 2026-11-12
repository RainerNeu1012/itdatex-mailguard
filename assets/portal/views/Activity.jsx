import React, { useCallback, useEffect, useState } from 'react';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { PageShell, PageTopbar, Section, SectionHeader } from '../components/Section.jsx';

const EVENT_LABELS = {
  'auth.login_success':     ['Login erfolgreich',     'ok'],
  'auth.login_failed':      ['Login fehlgeschlagen',  'warn'],
  'auth.two_fa_failed':     ['2FA-Code falsch',       'warn'],
  'auth.logout':            ['Logout',                'info'],
  'two_fa.enable':          ['2FA aktiviert',         'warn'],
  'two_fa.disable':         ['2FA deaktiviert',       'warn'],
  'two_fa.reset_request':   ['2FA-Reset angefordert', 'warn'],
  'two_fa.reset_confirm':   ['2FA per Email-Reset entfernt', 'err'],
  'two_fa.reset_failed':    ['2FA-Reset-Code falsch', 'warn'],
  'password.change':        ['Passwort geaendert',    'warn'],
  'password.reset_request': ['Passwort-Reset angefordert', 'info'],
  'password.reset_confirm': ['Passwort per Reset gesetzt', 'warn'],
  'sessions.revoked_all':   ['Alle Sessions beendet', 'warn'],
  'sessions.revoked_others':['Andere Sessions beendet','info'],
  'device.trusted':         ['Geraet vertraut',       'info'],
};

export default function Activity({ me }) {
  const [items, setItems] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    const { status, body } = await apiGet('me/activity');
    if (status === 200 && body?.ok) setItems(body.items || []);
    else setError('HTTP ' + status);
  }, []);
  useEffect(() => { load(); }, [load]);

  const revokeOthers = async () => {
    if (!confirm('Alle anderen Sessions (Web + Mobile + Desktop) jetzt beenden?')) return;
    setBusy(true);
    const { body } = await apiPost('me/sessions/revoke-all-others', {});
    setBusy(false);
    if (body?.ok) {
      alert(`Beendet: ${body.web_revoked} Web-Sessions, ${body.api_revoked} App-Tokens.`);
      await load();
    }
  };

  return (
    <PageShell>
      <PageTopbar kicker="MAILGUARD · KONTOAKTIVITAET" user={me?.email} />
      <Section>
        <SectionHeader
          num="001"
          title="LETZTE KONTOAKTIVITAET"
          right={
            <button className="mg-btn" disabled={busy} onClick={revokeOthers}>
              {busy ? '…' : 'Alle anderen Sessions beenden'}
            </button>
          }
        />
        <div className="mg-dash__shared-intro mg-mono">
          SICHERHEITSRELEVANTE EREIGNISSE DER LETZTEN TAGE · IP + USER-AGENT PRO EVENT
        </div>

        <TrustedDevicesPanel />

        {error && <div className="mg-card mg-error">{error}</div>}
        {items === null && <div className="mg-card">Lade …</div>}
        {items && items.length === 0 && (
          <div className="mg-card mg-muted">Keine Aktivitaet protokolliert.</div>
        )}
        {items && items.length > 0 && (
          <div className="mg-stack">
            {items.map((it) => <Row key={it.id} it={it} />)}
          </div>
        )}
      </Section>
    </PageShell>
  );
}

function Row({ it }) {
  const [label, tone] = EVENT_LABELS[it.event] || [it.event, 'info'];
  const color =
    tone === 'ok'   ? 'var(--mg-ok)' :
    tone === 'warn' ? 'var(--mg-warn)' :
    tone === 'err'  ? 'var(--mg-err)' : 'var(--mg-muted)';
  return (
    <div className="mg-card" style={{
      borderLeft: `4px solid ${color}`,
      paddingLeft: '0.9rem',
    }}>
      <div style={{display:'flex',justifyContent:'space-between',alignItems:'baseline',gap:12,flexWrap:'wrap'}}>
        <strong style={{fontSize:'1.02rem'}}>{label}</strong>
        <span className="mg-mono" style={{color:'var(--mg-muted)',fontSize:'0.85rem'}}>
          {formatDate(it.created_at)}
        </span>
      </div>
      <div style={{marginTop:4,color:'var(--mg-muted)',fontSize:'0.9rem'}}>
        {it.actor_ip && <code>{it.actor_ip}</code>}
        {it.actor_ip && it.actor_ua ? ' · ' : ''}
        {it.actor_ua && <span style={{fontSize:'0.82rem'}}>{shortenUA(it.actor_ua)}</span>}
      </div>
      {it.meta && Object.keys(it.meta).length > 0 && (
        <div style={{marginTop:4,fontSize:'0.82rem',color:'var(--mg-muted)'}}>
          {Object.entries(it.meta).map(([k,v]) => (
            <span key={k} style={{marginRight:8}}><code>{k}</code>={String(v)}</span>
          ))}
        </div>
      )}
    </div>
  );
}

function TrustedDevicesPanel() {
  const [items, setItems] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const { status, body } = await apiGet('me/trusted-devices');
    if (status === 200 && body?.ok) setItems(body.items || []);
  }, []);
  useEffect(() => { load(); }, [load]);

  const revoke = async (id) => {
    if (!confirm('Dieses Geraet nicht mehr vertrauen? Beim naechsten Login wird dort wieder 2FA abgefragt.')) return;
    setBusy(true);
    await apiDelete(`me/trusted-devices/${id}`);
    setBusy(false);
    await load();
  };

  const activeCount = items?.filter(it => !it.revoked_at).length || 0;
  if (!items || items.length === 0) return null;
  return (
    <div className="mg-card" style={{marginBottom:16,padding:'1rem 1.25rem'}}>
      <div style={{display:'flex',justifyContent:'space-between',alignItems:'baseline',marginBottom:8}}>
        <strong>Vertraute Geraete ({activeCount} aktiv)</strong>
        <span className="mg-mono" style={{fontSize:'0.8rem',color:'var(--mg-muted)'}}>2FA uebersprungen</span>
      </div>
      <div style={{display:'flex',flexDirection:'column',gap:6}}>
        {items.map(d => (
          <div key={d.id} style={{display:'flex',justifyContent:'space-between',alignItems:'center',gap:8,padding:'6px 0',borderTop:'1px solid var(--mg-border)'}}>
            <div>
              <div style={{fontSize:'0.95rem'}}>{d.label || 'Unbekanntes Geraet'}</div>
              <div style={{fontSize:'0.8rem',color:'var(--mg-muted)'}}>
                {d.ip && <code>{d.ip}</code>} · bis {String(d.expires_at).slice(0,10)}
                {d.revoked_at && <> · <span style={{color:'var(--mg-err)'}}>revokt</span></>}
              </div>
            </div>
            {!d.revoked_at && (
              <button className="mg-btn" disabled={busy} onClick={() => revoke(d.id)} style={{fontSize:'0.8rem',padding:'4px 8px'}}>
                Nicht mehr vertrauen
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

function formatDate(s) {
  if (!s) return '—';
  const d = new Date(s + 'Z');
  return d.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
}
function shortenUA(ua) {
  if (!ua) return '';
  const m = ua.match(/(Firefox|Chrome|Safari|Edge|Edg)\/[\d.]+/);
  const os = ua.match(/\((Windows[^)]*|Macintosh[^)]*|Linux[^)]*|iPhone[^)]*|Android[^)]*)\)/);
  return [m?.[0], os?.[1]].filter(Boolean).join(' · ') || ua.slice(0, 60);
}
