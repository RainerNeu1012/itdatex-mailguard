import React from 'react';
import { navigate } from '../router.js';

export default function Dashboard({ me }) {
  const isFirstLogin = !me.last_login_at;
  const cfg = (typeof window !== 'undefined' ? window.itdatexMailguard : null) || {};
  return (
    <div className="mg-stack">
      <div className="mg-card">
        <h2>Willkommen, {me.email}</h2>
        <p className="mg-muted">Konto seit {fmtDate(me.created_at)} {me.last_login_at ? '· Letzter Login: ' + fmtDate(me.last_login_at) : ''}</p>
      </div>

      {cfg.desktop && cfg.native && <DesktopCard native={cfg.native} />}

      {isFirstLogin && (
        <div className="mg-card" style={{ borderLeft: '3px solid #3b82f6' }}>
          <h3>Erste Schritte</h3>
          <ol style={{ paddingLeft: '1.2rem', margin: 0 }}>
            <li><strong>Postfach verbinden</strong> — unter <em>Postfächer</em> dein Konto eintragen. Microsoft/Gmail per 1-Klick-OAuth, andere Provider via IMAP (Passwort wird verschlüsselt gespeichert).</li>
            <li><strong>Ordner werden automatisch übernommen</strong> — Inbox, Junk und alle Custom-Ordner werden beim ersten Abholen erkannt und gescannt. Neue Ordner kommen automatisch dazu.</li>
            <li><strong>Inbox öffnen</strong> — chronologisch oder nach Absender gruppiert. Phishing-Mails sind rot markiert, Newsletter kannst du pro Absender in einem Klick abbestellen.</li>
            <li><strong>Optional:</strong> Regeln (Whitelist/Blacklist) pflegen und im Plan-Tab die KI-Tiefenanalyse aktivieren.</li>
          </ol>
          <p className="mg-muted mg-tiny" style={{ marginTop: '0.8rem' }}>Diese Box verschwindet nach deinem nächsten Login.</p>
        </div>
      )}

      <div className="mg-grid">
        <FeatureCard
          title="📬 Postfächer"
          desc="Microsoft- und Gmail-Konten per 1-Klick-OAuth, alle anderen Provider per IMAP mit Autoconfig. Alle Ordner deines Postfachs werden automatisch übernommen — auch neue, die du später anlegst."
          status="aktiv"
          cta={{ label: 'Postfächer verwalten →', onClick: () => navigate('accounts') }}
        />
        <FeatureCard
          title="📥 Inbox"
          desc="Zwei Ansichten: chronologisch für den klassischen Mail-Stream oder nach Absender gruppiert — mit Zähler pro Absender, Verdict-Pill und Bulk-Aktionen. Auf Mobile optimiert."
          status="aktiv"
          cta={{ label: 'Zur Inbox →', onClick: () => navigate('inbox') }}
        />
        <FeatureCard
          title="🛡 Phishing- & Spoofing-Schutz"
          desc={'Heuristik + optional KI-Tiefenanalyse. Erkennt Brand-Impersonation (PayPal, Sparkasse, DKB, N26 …), Anti-Detection-Streckungen wie „E A S Y B A N K" und verdächtige Links. Verdict + Score direkt in der Inbox.'}
          status="aktiv"
          cta={{ label: 'Verdächtige Mails →', onClick: () => navigate('inbox') }}
        />
        <FeatureCard
          title="📰 Newsletter-Abmelden"
          desc="Pro Absender ein Klick statt pro Mail. RFC-8058 One-Click-HTTP mit automatischem mailto-Fallback wenn der Provider den One-Click-Endpoint kaputt schickt. Bounce-Status wird nachverfolgt."
          status="aktiv"
          cta={{ label: 'Newsletter →', onClick: () => navigate('newsletters') }}
        />
        <FeatureCard
          title="🛡 Quarantäne & Endgültig-Löschen"
          desc="Verdächtige Mails in einen Quarantäne-Ordner verschieben — 7 Tage rückgängig-Fenster. Danach oder sofort endgültig löschen (auch für ganze Absender-Gruppen in einem Rutsch)."
          status="aktiv"
          cta={{ label: 'Aktionen ansehen →', onClick: () => navigate('actions') }}
        />
        <FeatureCard
          title="⚙ Regeln"
          desc="Whitelist- und Blacklist-Regeln pro Absender, Domain, Betreff oder Body-Muster. Blacklist übersteuert Whitelist. Kombiniert mit dem Auto-Quarantäne-Schwellwert deines Postfachs."
          status="aktiv"
          cta={{ label: 'Regeln bearbeiten →', onClick: () => navigate('rules') }}
        />
        <FeatureCard
          title="🔍 URL- & Mail-Scanner"
          desc="Verdächtige Links prüfen, ohne sie zu öffnen. Auch komplette E-Mail-Header/Body-Tests, um vor dem Klicken zu wissen ob eine Mail sauber ist."
          status="aktiv"
          cta={{ label: 'Scanner öffnen →', onClick: () => navigate('scanner') }}
        />
        <FeatureCard
          title="💳 Plan & KI-Tiefenanalyse"
          desc="Plan-Übersicht, Postfach-Quota und DSGVO-konformer Consent für die KI-Tiefenanalyse. Free/Solo/Plus/Pro — Stripe-Portal für Plan-Wechsel und Zahlungsmittel."
          status="aktiv"
          cta={{ label: 'Plan verwalten →', onClick: () => navigate('plan') }}
        />
      </div>
    </div>
  );
}

/**
 * Sichtbar nur wenn wir in der Tauri-Shell laufen (window.itdatexMailguard.desktop
 * === true). Für Web-Nutzer verschwindet die Card komplett. Aktuell: Autostart-
 * Toggle. Später hier auch Update-Check, Silent-Mode etc.
 */
function DesktopCard({ native }) {
  const [enabled, setEnabled] = React.useState(null);
  const [busy, setBusy] = React.useState(false);

  React.useEffect(() => {
    let cancelled = false;
    native.autostart.get().then((v) => { if (!cancelled) setEnabled(!!v); });
    return () => { cancelled = true; };
  }, [native]);

  const toggle = async () => {
    setBusy(true);
    try {
      const ok = enabled ? await native.autostart.disable() : await native.autostart.enable();
      if (ok) {
        setEnabled(!enabled);
      } else {
        alert('Autostart konnte nicht geändert werden. Prüfe Windows-Berechtigungen.');
      }
    } finally { setBusy(false); }
  };

  return (
    <div className="mg-card" style={{ borderLeft: '3px solid #0ea5e9' }}>
      <h3 style={{ margin: '0 0 0.25rem' }}>🖥 Desktop-Client</h3>
      <p className="mg-muted" style={{ margin: '0 0 0.75rem' }}>
        Du benutzt den MailGuard-Desktop-Client. Push-Benachrichtigungen und Autostart sind hier verfügbar.
      </p>
      <div className="mg-form__row" style={{ gap: '0.5rem', alignItems: 'center' }}>
        <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: busy ? 'wait' : 'pointer' }}>
          <input
            type="checkbox"
            checked={!!enabled}
            disabled={busy || enabled === null}
            onChange={toggle}
          />
          <span>Beim Windows-Start automatisch mit einloggen</span>
        </label>
        {enabled === null && <span className="mg-muted mg-tiny">lade …</span>}
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
