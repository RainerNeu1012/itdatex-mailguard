import React from 'react';
import { navigate } from '../router.js';

export default function Dashboard({ me }) {
  const isFirstLogin = !me.last_login_at;
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2>Willkommen, {me.email}</h2>
        <p className="mg-muted">Konto seit {fmtDate(me.created_at)} {me.last_login_at ? '· Letzter Login: ' + fmtDate(me.last_login_at) : ''}</p>
      </div>

      {isFirstLogin && (
        <div className="mg-card" style={{ borderLeft: '3px solid #3b82f6' }}>
          <h3>Erste Schritte</h3>
          <ol style={{ paddingLeft: '1.2rem', margin: 0 }}>
            <li><strong>Postfach verbinden</strong> — unter <em>Postfächer</em> dein IMAP-Konto eintragen (SSL/STARTTLS). Das Passwort wird verschlüsselt gespeichert.</li>
            <li><strong>Erste Synchronisation abwarten</strong> — der Pull-Cron läuft alle 15 Minuten und holt deine Mails.</li>
            <li><strong>Inbox öffnen</strong> — Phishing-Mails sind rot markiert, Newsletter haben einen Abmelden-Button.</li>
            <li><strong>Optional:</strong> unter <em>Regeln</em> Whitelist/Blacklist-Einträge pflegen.</li>
          </ol>
          <p className="mg-muted mg-tiny" style={{ marginTop: '0.8rem' }}>Diese Box verschwindet nach deinem nächsten Login.</p>
        </div>
      )}

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
          status="aktiv"
          cta={{ label: 'Verdächtige Mails →', onClick: () => navigate('inbox') }}
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
