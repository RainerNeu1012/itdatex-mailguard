import React from 'react';
import { navigate } from '../router.js';

export default function Dashboard({ me }) {
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2>Willkommen, {me.email}</h2>
        <p className="mg-muted">Konto seit {fmtDate(me.created_at)} {me.last_login_at ? '· Letzter Login: ' + fmtDate(me.last_login_at) : ''}</p>
      </div>

      <div className="mg-grid">
        <FeatureCard
          title="📬 Postfächer"
          desc="Verbinde deine IMAP-Konten und lass sie automatisch auf Phishing prüfen."
          status="aktiv"
          cta={{ label: 'Postfächer verwalten →', onClick: () => navigate('accounts') }}
        />
        <FeatureCard
          title="📥 Inbox"
          desc="Alle eingehenden Mails an einem Ort, mit Vorschau und Newsletter-Indikator."
          status="aktiv"
          cta={{ label: 'Zur Inbox →', onClick: () => navigate('inbox') }}
        />
        <FeatureCard
          title="🛡 Phishing-Warnungen"
          desc="Eingehende Mails werden gegen die Anti-Phishing-API geprüft, du siehst Verdict + Score direkt in der Inbox."
          status="Phase 5 in Vorbereitung"
        />
        <FeatureCard
          title="📰 Newsletter-Abmelden"
          desc="Bulk-Unsubscribe per RFC-8058 (HTTP + mailto). Bounce-Status wird nachverfolgt."
          status="aktiv"
          cta={{ label: 'Newsletter →', onClick: () => navigate('newsletters') }}
        />
        <FeatureCard
          title="🔍 URL-Scanner"
          desc="Verdächtige Links manuell prüfen, ohne sie zu öffnen."
          status="aktiv"
          cta={{ label: 'Scanner öffnen →', onClick: () => navigate('scanner') }}
        />
      </div>
    </div>
  );
}

function FeatureCard({ title, desc, status, cta }) {
  return (
    <div className="mg-card mg-card--feature">
      <h3>{title}</h3>
      <p>{desc}</p>
      <p className="mg-muted mg-tiny">{status}</p>
      {cta && (
        <p style={{ marginBottom: 0 }}>
          <button className="mg-btn mg-btn--primary" onClick={cta.onClick}>{cta.label}</button>
        </p>
      )}
    </div>
  );
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date((s.replace(' ', 'T')) + 'Z');
  return isNaN(d) ? s : d.toLocaleString();
}
