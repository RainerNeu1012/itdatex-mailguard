# Onboarding-Consent für Cloud-LLM (Plus/Pro)

**Status: Draft — vor Live-Stellung anwaltlich review.**
**Stand:** 2026-06-29.

## Kontext

Aktuell wird beim Plan-Wechsel auf Plus/Pro/Enterprise automatisch
`mg_customers.llm_enabled = 1` gesetzt
(`src/Saas/Onboard.php:222`, `src/Saas/Webhook.php:232`). Damit fließen
Subject + Body von Grauzonen-Mails (Heuristik-Score 30–69) automatisch an
**Ollama Inc., San Francisco, USA**.

Die Datenschutzerklärung auf `wp.itdatex.support/datenschutz/` Abschnitt 10.2
beschreibt das transparent. **Eine explizite Einwilligung gem. Art. 6 Abs. 1
lit. a DSGVO ist im aktuellen Onboarding-Flow nicht vorgesehen.** Das ist die
größte verbleibende rechtliche Lücke.

## Vorgeschlagener Consent-Text (Checkbox-Label vor Plan-Auswahl)

> ☐ **Ich willige ein, dass für die KI-gestützte Phishing-Erkennung Subject und
> Body verdächtiger E-Mails (Heuristik-Score 30–69, typisch < 10 % der Mails)
> an Ollama Inc., 410 Townsend St., San Francisco, CA 94107, USA, übermittelt
> und dort durch ein KI-Modell bewertet werden. Diese Übermittlung in ein
> Drittland (USA) erfolgt auf Grundlage der EU-Standardvertragsklauseln (SCC,
> Modul 2) gemäß Art. 46 Abs. 2 lit. c DSGVO. Ich wurde in der
> [Datenschutzerklärung Abschnitt 10.2](https://wp.itdatex.support/datenschutz/#sec-10-2)
> über die Einzelheiten informiert. Ich kann diese Einwilligung jederzeit
> mit Wirkung für die Zukunft widerrufen (Plan-Wechsel auf Free oder Deaktivierung
> im Portal unter „Plan → KI-Tiefenanalyse ausschalten"). Die Rechtmäßigkeit
> der bis zum Widerruf erfolgten Verarbeitung bleibt davon unberührt.**

## Begleittext oberhalb der Checkbox (informativ)

> Der von dir gewählte Plan (Plus / Pro / Enterprise) enthält die KI-Tiefenanalyse
> über Ollama Cloud. Wenn du das nicht möchtest, wähle den **Free**- oder
> **Solo**-Plan — dort findet ausschließlich die lokale heuristische Prüfung
> auf unserem Server (Strato, Berlin) statt; keine Mail-Inhalte verlassen das
> EU-Hoheitsgebiet.

## Implementierungs-Hinweise (für später, NICHT Teil dieses Drafts)

1. **DB-Migration v11:** neue Spalte `mg_customers.cloud_consent_at DATETIME NULL`.
   Bei NULL gilt: keine Einwilligung erteilt; deep-Scan darf nicht aktiv sein.
2. **`src/Customer/Auth::register`** + **`src/Saas/Onboard::handle_checkout_start`**
   erweitern: Pflicht-Checkbox-Param `cloud_consent: true`, vor Stripe-Checkout
   validieren. Bei `false` → kein Plus/Pro-Plan möglich (Auto-Downgrade auf Solo).
3. **`src/Saas/Webhook::set_plan_for_customer`**: `llm_enabled` nur dann auf 1
   setzen, wenn `cloud_consent_at IS NOT NULL`. Sonst auf 0 zwingen, auch
   wenn der Plan technisch `llm_enabled => true` sagt.
4. **Plan.jsx Toggle:** statt nur Anzeige „LLM-Deep-Scan: an" einen echten
   On/Off-Schalter, der via neuen Endpunkt `POST
   /itdatex-mailguard/v1/me/cloud-consent` `cloud_consent_at`
   setzt/zurückzieht. Aus = `llm_enabled` synchron auf 0.
5. **Bestands-Customer:** beim ersten Login nach Deploy einen einmaligen Modal
   einblenden, der den oben genannten Consent-Text zeigt und die Checkbox
   verlangt. Bis dahin `llm_enabled` im Code auf 0 zwingen (Backwards-Safety).
6. **Auditierbarkeit:** zusätzliche Spalte `cloud_consent_text_version
   VARCHAR(20)` mitloggen, damit bei späteren Text-Änderungen klar bleibt,
   welcher Wortlaut zustimmungsgrundlage war.

## Was nicht in dieses Draft gehört (separat)

- AVV mit Ollama Inc. — laufende Aufgabe, externe Anwaltskanzlei kontaktieren.
- Falls Ollama Inc. EU-US-Data-Privacy-Framework-zertifiziert ist, das in der
  Datenschutzerklärung Abschnitt 10.2 explizit machen (statt „sofern
  zertifiziert").
- Daten-Subject-Rights (Auskunft, Löschung) — gehört in
  `wp.itdatex.support/datenschutz/` als eigenen Abschnitt; wahrscheinlich
  bereits vorhanden.

## Review-Checkliste vor Implementation

- [ ] Anwaltliche Prüfung des Checkbox-Texts (Wortlaut + Verlinkung)
- [ ] Anchor-ID `#sec-10-2` in der Datenschutzerklärung existiert
- [ ] AVV-Status mit Ollama geklärt (zumindest „in Anbahnung" auf live)
- [ ] Free/Solo-Plan-Verfügbarkeit als echte Alternative kommunikativ stark machen
