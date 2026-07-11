# Änderungsbericht: E2E-Test + Entwicklungsphasen 1–3

**Datum:** 2026-07-11 · **Branch:** `claude/dienstly-system-audit-akcmkd` · **Commits:** `e02309a` (Phase 1), `db756fe` (Phase 2), `ec67cf7` (Phase 3)
**Umfang:** 38 geänderte Dateien, +2 212 / −80 Zeilen · **Testsuite: 205/205 grün** (643 Assertions; vor den Phasen: 178)

---

## 0. Vorgelagerter praktischer E2E-Test (nur Dokumentation, keine Änderungen)

24 + 2 Prüfpunkte gegen die reale Dev-Datenbank über die echten Service-/Controller-Pfade (nur die IMAP-Socket-Schicht wurde ersetzt – kein echtes Postfach in dieser Umgebung verfügbar). **Ergebnis: 23 bestanden**, 3 gezielte Nachweise der bekannten Audit-Befunde:
- **H1 bestätigt** (Anhang bei Score 90/suggested sofort in der Akte, blieb nach Ablehnung)
- **H2 bestätigt** (Support ohne Portfolio konnte fremden Vorschlag bestätigen)
- **T1 neu gefunden:** Kundenname im Mail-Text („Kunde: …") wurde nicht fürs Matching genutzt → Versicherungs-Mails erreichten die Vorschlagsstufe nie (Score 52 statt 90)

Nicht praktisch testbar blieb die echte Hostinger-IMAP-Verbindung (keine Zugangsdaten/kein ausgehender IMAP-Port in der Sandbox) – der Fehlerpfad ist verifiziert, der Erfolgspfad braucht einen Test auf dem Produktivserver.

---

## 1. Phase 1 – Audit-Fixes (Commit `e02309a`)

| Fix | Umsetzung |
|---|---|
| **H1** Anhänge erst nach Bestätigung | Neuer `EmailAttachmentService`: Dateien landen zunächst nur unter `email_attachments/<message_id>/` mit Meta am E-Mail-Datensatz (`attachments_meta`); Documents entstehen erst bei `match_status=confirmed` (Auto >90 beim Sync, Bestätigung oder manuelle Zuweisung im Posteingang), idempotent. Ablehnung hinterlässt nichts in fremden Akten. |
| **H2** Zugriffscheck | `canAccessCustomer`-Prüfung in `EmailInboxController::confirm()` und `reject()`. |
| **H3** Datei-Löschung | Kundenlöschung entfernt Dokumentdateien (beide Disks), das `customers/<id>`-Verzeichnis und die Anhang-Dateien der Kunden-Mails; der Prune-Command löscht Anhang-Dateien mit. |
| **T1** Matching aus Mail-Text | „Kunde:/Kundenname:/Versicherungsnehmer:"-Label im Text schlägt den Absendernamen; die Absenderadresse Dritter zählt dann nicht mehr als E-Mail-Signal. |
| Zusatz | Index auf `email_messages.match_status` (Audit M7). |

## 2. Phase 2 – E-Mail-Ausbau (Commit `db756fe`)

- **Gmail-OAuth + Microsoft-365-OAuth vollständig:** `OAuthTokenService` (Consent-URL, Code-Tausch, Refresh; nur der Refresh-Token wird dauerhaft und verschlüsselt gespeichert; fehlgeschlagener Refresh wird am Konto sichtbar), `GmailApiMailboxProvider` (messages.list/get, MIME-Baum, attachments.get) und `GraphApiMailboxProvider` (inbox/messages, fileAttachments) mit Minimal-Scopes `gmail.readonly`/`Mail.Read`; „Verbinden"-Button, Redirect-/Callback-Routen. Konfiguration über `GOOGLE_CLIENT_ID/SECRET`, `MICROSOFT_CLIENT_ID/SECRET/TENANT`.
- **PDF-Anhang-Analyse:** `PdfTextExtractor` (smalot/pdfparser, neue Composer-Abhängigkeit) mit Zeilenrekonstruktion über Textpositionen; defensives Scheitern bei Scans/defekten PDFs.
- **Dokumentenerkennung:** `AttachmentAnalysisService` kategorisiert übernommene Anhänge (police/invoice/contract/claim/identity) auf den **bestehenden** Kategorien.
- **Automatische Vorgangs-Zuordnung:** Fonds-Finanz- und Provisions-Workflow lesen PDF-Anhänge als Fallback; der FF-Import verknüpft übernommene Dokumente direkt mit dem Vertrag – über die **bereits vorhandene, nie genutzte** Spalte `documents.contract_id` (eine versehentlich doppelt angelegte Migration wurde vor dem Push entdeckt und entfernt – keine Schema-Duplikate).

## 3. Phase 3 – Intelligente Automatisierung (Commit `ec67cf7`)

- **Fristen-Watchdog** `document-requests:remind` (täglich 08:15): Kunden-Erinnerung ≤2 Tage vor Frist, interner Überfälligkeits-Hinweis an Betreuer/Admins – jede Stufe genau einmal (`reminder_sent_at`/`overdue_notified_at`). Schließt Prüfbericht M3.
- **KI-Auswertung mit Freigabe-Gateway:** neue Tabelle `ai_decisions` (Skill, Modell, Input-**Hash**, validiertes Output-JSON, Konfidenz, Status, Entscheider). `AiEmailClassifier` läuft nur bei Kategorie „sonstige" und nur mit konfiguriertem `ANTHROPIC_API_KEY`; E-Mail-Inhalt wird als nicht vertrauenswürdige Datenquelle übergeben, die Antwort hart gegen die Kategorienliste validiert (Injection-/Halluzinations-Ausgaben werden verworfen – testabgedeckt) und **nie automatisch angewendet**: Übernehmen/Verwerfen im Posteingang ist die Freigabestufe; erst die Übernahme löst die Standard-Aktion der Kategorie aus. API-Ausfall/fehlender Key beeinträchtigen die Verarbeitung nicht.
- **Gast-Ticket-Nachverknüpfung** (Prüfbericht M4): Bestätigte Zuordnung verknüpft frühere E-Mail-Gast-Tickets desselben Absenders mit dem Kunden.
- **Queue-Versand** (Prüfbericht M2): `DocumentRequestMail` ist queued – ein hängender SMTP-Server blockiert keinen Mitarbeiter-Request mehr.

---

## 4. Geänderte Dateien (38)

**Neue Services (9):** `Mailbox/EmailAttachmentService`, `Mailbox/PdfTextExtractor`, `Mailbox/AttachmentAnalysisService`, `Mailbox/OAuthTokenService`, `Mailbox/GmailApiMailboxProvider`, `Mailbox/GraphApiMailboxProvider`, `Ai/AiEmailClassifier` · entfernt: `Mailbox/OAuthMailboxProvider` (Platzhalter)
**Neue Models (1):** `AiDecision` · **Neue Commands (1):** `RemindDocumentRequests`
**Geänderte Kernklassen:** `MailboxSyncService` (Dateien vor Workflow, Documents nur bei confirmed), `MailboxProviderFactory` (OAuth-Routing), `EmailWorkflowService` (T1-Extraktion, KI-Anbindung, `applyCategory`), `FondsFinanzImportService` (PDF-Fallback, Dokument→Vertrag), `CommissionWorkflowService` (PDF-Fallback), `EmailInboxController` (H2-Checks, Anhang-Übernahme, KI-Freigabe, Ticket-Relink), `AdminController::destroyCustomer` (H3), `PruneUnmatchedEmails` (Datei-Löschung), `DocumentRequestMail` (queued), Models `EmailMessage`/`DocumentRequest`/`Document`/`Contract`, `config/services.php`, Routen, Posteingang-View, Konten-View
**Abhängigkeit:** + `smalot/pdfparser ^2.12`

## 5. Migrationen (3 neue, alle additiv und rückrollbar)

1. `2026_07_11_100000_add_attachments_meta_to_email_messages` – `attachments_meta` JSON + Index `match_status`
2. `2026_07_11_120000_create_ai_decisions_and_reminders` – Tabelle `ai_decisions`; `document_requests` + `reminder_sent_at`, `overdue_notified_at`
3. *(eine geplante `documents.contract_id`-Migration wurde verworfen – Spalte existierte bereits im Bestand)*

## 6. Tests

**205 Tests / 643 Assertions, alle grün** (+38 in den Phasen: `AuditFixesPhase1Test` 10, `OAuthMailboxTest` 12, `AttachmentAnalysisTest` 5, `Phase3AutomationTest` 11, angepasste Bestandstests). Abgedeckt u. a.: H1-Lebenszyklus inkl. Ablehnung/Neu-Zuweisung, H2-Verbote, H3-Dateilöschung, OAuth-Refresh-Fehlerpfad, Gmail-/Graph-Parsing, PDF-Fallbacks inkl. Scan-ohne-Text, KI-Injection-Verwurf, KI-Ausfall-Toleranz, Erinnerungs-Einmaligkeit. `route:cache` weiterhin möglich.

## 7. Verbleibende Risiken / offene Punkte

| Risiko | Einordnung |
|---|---|
| **Echte IMAP-/OAuth-/Lexoffice-Verbindungen ungetestet gegen Produktivsysteme** – alle externen APIs sind nur gegen Fakes verifiziert; Erstinbetriebnahme braucht je einen manuellen Test (Hostinger-Login, Google-/MS-App-Registrierung mit korrekter Redirect-URI, ein Lexoffice-Testbeleg) | Hoch (Betriebsrisiko, kein Code-Risiko) |
| **Betriebsvoraussetzungen:** `php artisan schedule:work` (Sync/Prune/Reminder) und neu `php artisan queue:work` (queued Mails) müssen laufen; ohne Worker bleiben Dokumentenanfrage-Mails in der Queue liegen | Hoch |
| OAuth-Apps erfordern externe Registrierung (Google Cloud Console / MS Entra) inkl. Verifizierung des `gmail.readonly`-Scopes durch Google | Mittel |
| Gescannte PDFs ohne Textebene werden nicht ausgewertet (kein OCR) → manuelle Prüfaufgabe (defensiv, aber Handarbeit) | Mittel |
| KI-Stufe: Modell-/Prompt-Drift kann Vorschlagsqualität ändern – `ai_decisions` protokolliert alles, aber Regressions-Fälle gegen das echte Modell existieren noch nicht; AVV mit Anthropic vor Produktivnutzung prüfen (DSGVO) | Mittel |
| Gast-Ticket-Relink verknüpft über die Absenderadresse – teilt sich eine Familie eine Adresse, werden deren E-Mail-Gast-Tickets demselben Kunden zugeordnet | Niedrig |
| Alt-Befunde unverändert offen: Klartext-Passwörter in Willkommens-Mails (H4), Lexoffice-Key im Settings-Formular, `AdminController`-Größe, fehlende Mandantenfähigkeit | siehe Prüfbericht |

---

*Alle drei Phasen wurden ohne Zwischenfragen nacheinander umgesetzt, jeweils mit grüner Gesamtsuite committet und gepusht.*
