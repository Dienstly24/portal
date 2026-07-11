# Konzeptstudie: Einwilligungsbasierte E-Mail-Verbindung (DSGVO)

> Status: **Entwurf zur Diskussion – NICHT implementiert, NICHT deployed.**
> Grundlage: Vorgabe des Auftraggebers (explizite, getrennte, widerrufbare
> Einwilligung statt AGB-Kopplung). Diese Studie bestätigt den rechtlichen
> Rahmen und ergänzt Architektur- und Verbesserungsvorschläge.

## 1. Rechtlicher Rahmen (bestätigt)

Der Zugriff auf E-Mail-Korrespondenz ist Verarbeitung personenbezogener
Daten. Eine Kopplung an die AGB ist **unzulässig** (Kopplungsverbot,
Art. 7 Abs. 4 DSGVO). Die Einwilligung muss sein:

- **freiwillig & getrennt** von den AGB (eigene Checkbox, nicht vorausgewählt),
- **spezifisch & informiert** (klarer Zweck: nur vertragsbezogene Nachrichten),
- **nachweisbar** (Art. 7 Abs. 1 – wer, wann, welchem Text zugestimmt),
- **jederzeit widerrufbar**, so einfach wie die Erteilung (Art. 7 Abs. 3).

Rechtsgrundlage: Art. 6 Abs. 1 lit. a (Einwilligung). Da das Auslesen von
Postfächern eine risikoreiche Verarbeitung ist, wird eine **DSFA
(Datenschutz-Folgenabschätzung, Art. 35)** empfohlen.

## 2. Zwei mögliche Ausbaustufen – Empfehlung

**Variante A – Verarbeitung eingehender Korrespondenz an UNSERE Postfächer**
(info@, kv@ …): Es wird nur Post verarbeitet, die ohnehin bei uns eingeht
und den Kunden betrifft. Kein Zugriff auf das Kundenpostfach. → **geringes
Risiko, schnell umsetzbar.** (Deckt bereits die heutige Mailbox-Pipeline ab.)

**Variante B – Anbindung des EIGENEN Kundenpostfachs per OAuth**
(Gmail/Microsoft 365 des Kunden): mächtiger, aber deutlich datenschutz-
intensiver, da theoretisch das ganze Postfach zugänglich wäre. Erfordert
strikte Zweckbindung + Data Minimization.

> **Empfehlung:** Mit **Variante A** starten (rechtlich robust, schnell),
> **Variante B** als optionale Erweiterung mit DSFA und den unten genannten
> Schutzmaßnahmen. Beide nutzen dieselbe Einwilligungs-/Audit-Infrastruktur.

## 3. Datenmodell (Vorschlag)

`customer_consents`
- id, customer_id
- type (`email_processing`, `marketing`, …)
- granted_at, revoked_at (nullable)
- consent_text_version (welche Fassung wurde akzeptiert)
- ip_address, user_agent (Nachweis)
- source (`portal_registration`, `portal_settings`)

`customer_mailbox_connections` (nur Variante B)
- id, customer_id, provider (gmail|graph)
- access/refresh Token **verschlüsselt** (wie EmailAccount)
- status (active|revoked), connected_at, revoked_at
- filter_rules (JSON – z. B. erlaubte Absenderdomains)

Wiederverwendung: OAuthTokenService + Mailbox-Provider bestehen bereits
(heute für Admin-Konten). Für B werden sie kundengebunden + einwilligungs-
gesteuert gekapselt.

## 4. Ablauf

**Einwilligung (Registrierung ODER später im Portal):**
1. Getrennte Checkbox (nicht vorausgewählt) mit klarem Text.
2. Speicherung als `customer_consents`-Datensatz inkl. Textversion.
3. **Einwilligungsbeleg** per E-Mail an den Kunden (Nachweis + Widerrufsweg).
4. Erst danach beginnt die Verarbeitung.

**Verarbeitung (Privacy by Design / Data Minimization):**
- Nur vertragsbezogene Nachrichten (Absender aus Partner-/Versicherer-Domains,
  Bezug zu bekannten Vertrags-/Kundennummern).
- Nicht zuordenbare/private Nachrichten werden **nicht gespeichert**, sofort
  verworfen. Serverseitige Filter schon beim Abruf.
- Verschlüsselte Übertragung (TLS) und verschlüsselte Ablage.
- Jede menschliche/maschinelle Verarbeitung → Audit Log (bereits vorhanden:
  `activity_logs`, `ai_decisions` als Freigabe-Gateway).

**Widerruf (Portalbereich „Datenschutz & Einwilligungen"):**
- Button „E-Mail-Verbindung trennen".
- Variante B: Access-/Refresh-Token sofort **widerrufen**, Sync gestoppt.
- Auswahl: (a) importierte Dokumente behalten oder (b) alle importierten
  Daten löschen (nutzt die bestehende DSGVO-Löschlogik).
- `revoked_at` gesetzt, Ereignis im Audit Log.

## 5. Verbesserungsvorschläge (über die Vorgabe hinaus)

1. **Consent-Versionierung**: exakten Zustimmungstext speichern → belastbarer Nachweis.
2. **Double-Opt-in** für die E-Mail-Anbindung (Bestätigungslink) als stärkerer Nachweis.
3. **Granulare Zwecke**: getrennte Einwilligung für Vertragspost vs. Marketing.
4. **Sofort-Purge** nicht vertragsbezogener Mails; keine Vorratsspeicherung.
5. **Erneuerung/Erinnerung**: optionale periodische Bestätigung der Einwilligung.
6. **Transparenz-Dashboard** im Portal: „Welche E-Mails wurden wann verarbeitet?"
7. **Verzeichnis von Verarbeitungstätigkeiten** (Art. 30) intern dokumentieren.
8. **Kill-Switch**: globaler Schalter, der die gesamte Postfach-Verarbeitung
   stoppt (Betriebssicherheit/Vorfallreaktion).

## 6. Offene Punkte für die Diskussion

- Variante A, B oder beide? (Empfehlung: A zuerst.)
- Soll die Einwilligung schon bei der Registrierung ODER erst im Portal möglich sein?
- Welche Absenderdomains gelten als „vertragsbezogen" (Whitelist pflegen)?
- Aufbewahrungsfristen für importierte Vertragsdokumente?
- Wird eine DSFA vor Variante B durchgeführt?
