# Dienstly24 Portal — End-to-End System-Audit

**Datum:** 2026-07-19 · **Branch:** `claude/system-end-to-end-audit-axr92s`
**Framework:** Laravel 13 (PHP 8.3) · **Umfang:** 242 PHP-Dateien, 89 Migrationen,
~75 Controller, ~70 Models, 99 Testdateien.

**Methodik:** Vollstaendige statische Durchsicht (Security, Datenbank, Performance,
Code/Architektur, UX/UI/a11y, E-Mail/API/Integrationen/Monitoring) durch acht
parallele, thematisch getrennte Pruefteams; anschliessende manuelle Verifikation
der kritischen Befunde am Code (`file:line`), `composer install` und Testlauf auf
frischer SQLite-DB. Aufbauend auf den Vor-Audits `AUDIT_REPORT.md` (2026-07-08),
`PRUEFBERICHT_SYSTEM_AUDIT_2026-07-11.md` und `PRODUCTION_READINESS_2026-07-11.md`.

> **Testlauf (Verifikation):** `php artisan test` auf frischer SQLite ergab
> **677 gruen / 121 rot** — **alle 121 Fehlschlaege sind umgebungsbedingt** (fehlender
> Vite-Asset-Build in dieser ephemeren Umgebung: 118× `ViteManifestNotFoundException`,
> 3× Folgefehler „response is not a view" durch denselben 500). **Kein einziger
> Fehlschlag ist ein Code-Defekt.** Die CI (`deploy.yml`) baut Assets vor dem Testlauf
> (`npm ci && npm run build`), dort laeuft die Suite gruen.

> **Darstellung der Befunde:** Fuer **Critical/High**-Befunde ist die vollstaendige
> Vorlage ausgefuellt (Titel, Beschreibung, Schweregrad, Ort, Reproduktion,
> Auswirkung, Ursache, Loesung, Best Practice, Aufwand, Prioritaet). Fuer
> **Medium/Low** werden kompakte Tabellenzeilen verwendet (Ort, Auswirkung,
> Loesung, Aufwand), um den Bericht navigierbar zu halten. Aufwand:
> S = < 0,5 Tag, M = 0,5–2 Tage, L = > 2 Tage. Konfidenz ist angegeben, wo relevant.

---

## 1. Management-Zusammenfassung

Das System ist **deutlich reifer als ein typisches CRM dieser Groesse** und traegt
die Handschrift von zwei vorangegangenen Audits: die frueher gemeldeten kritischen
Luecken (IDOR M1, fail-open Inquiry-Token C5, Session-Invalidierung deaktivierter
Konten M2, kaputte Migrationen) sind **verifiziert behoben**. Die Architektur der
Service-Schicht (AI-Dokumentenpipeline, Workflow-Engine, Mailbox-Subsystem) ist
produktionsreif: sauberer Strategy/Registry-Einsatz, Verschluesselung ruhender
Daten, idempotente Jobs, PII-bewusstes Logging.

**Es wurden keine unmittelbar aus dem Netz ausnutzbaren Sicherheitsluecken
gefunden** — kein SQL-/Command-Injection, kein Path-Traversal, kein XSS, keine
CSRF-Luecke, keine Broken-Access-Control in den Kundenpfaden. Die Sicherheitsbefunde
sind ueberwiegend **Haertungsluecken** (fehlende CSP, User-Enumeration, ein
Nebenpfad-IDOR), nicht offene Tueren.

Die groessten Risiken liegen **nicht im Web-Layer, sondern im Betrieb und in der
Datenhaltung**:

1. **Keine Datenbank-Backup-/Restore-Strategie** im gesamten Repo — bei mehreren
   bewusst irreversiblen Datenmigrationen und einem `migrate --force` im Deploy ohne
   Vorab-Dump ist ein Fehlschlag nicht wiederherstellbar. **(Critical)**
2. **Zwei komplett kaputte Lexoffice-Funktionen** (Rechnung senden/herunterladen) →
   garantierter 500-Fehler; **CSV-Export ist anfaellig fuer Formel-Injection**
   (Excel/DDE) und enthaelt IBANs. **(High)**
3. **DSGVO-Exposition**: Spezialkategorien-Daten (Gesundheits-/Ausweisdaten) gehen
   ungefiltert an einen US-KI-Dienstleister; auf `customer_family` liegen
   Steuer-/KV-Nummern **doppelt und im Klartext**. **(High, Compliance)**
4. **Stiller Datenverlust im Mailbox-Sync** (Nachrichten jenseits von 50/Batch werden
   nie nachgeladen) und **write-amplification** durch Aktivitaets-Tracking auf jedem
   Request. **(High)**

Der Rest sind gezielte Verbesserungen: fehlende Indizes auf Filterspalten,
synchroner E-Mail-Versand, Cascade-Deletes die Audit-Historie loeschen, eine
1518-Zeilen-`AdminController`-Gottklasse, sicherheitskritische Scoping-Logik in
8 Controllern kopiert, sowie eine breite Schicht von UX-/UI-/a11y-Politur (kaputte
CSS-Klassen, versteckter Rollen-Selektor, nicht tastaturbedienbare Modals/Uploads,
Palette-Verstoesse „Petrol-Gruen", nicht-lokalisierte Kundenmails).

**Gesamturteil:** Solides, sicheres Fundament. **Vor breiterem Roll-out** sollten die
Betriebs- und Compliance-Punkte (Backup, DSGVO-KI, Klartext-PII) und die zwei
kaputten Lexoffice-Funktionen adressiert werden — die meisten davon sind
Quick Wins (S/M).

### Befund-Statistik

| Schweregrad | Anzahl (ca.) | Kernthemen |
|---|---|---|
| Critical | 1 | Kein DB-Backup/Restore |
| High | ~22 | Lexoffice-Bug, CSV-Injection, DSGVO-KI, Klartext-PII, Mail-Verlust, Indizes, FK-Integritaet, Perf-Amplifikation, a11y-Blocker, i18n Kundenmails |
| Medium | ~35 | CSP, Enumeration, Logging/Monitoring, Audit-Log-Luecken, FormRequests, Palette, Responsive, Enum-Konsistenz |
| Low | ~40 | Politur, Dead Code, Kosmetik, Konsistenz |

---

## 2. Sicherheits-Audit

### Positiv verifiziert (nicht anfassen — nicht regressieren)

- **Zugriffskontrolle konsistent stark.** Alle kundenseitigen Loader sind hart auf den
  eigenen Datensatz gescoped (`PortalController`, `SelfServiceController`,
  `PartnerPortalController` via `$partner->customers()`, `PortalMessageController::findOwnAttachment`).
  Staff-Seite erzwingt Portfolio-+Vertretungs-Scoping (`visibleCustomerIds()`/`authorizeCustomerAccess()`).
- **Loesch-Guards entsprechen CLAUDE.md exakt:** Web-Bulk `max:30` (`AdminController.php:1444`),
  Loeschrouten nur `role:admin`, `CustomerDeletionService.php:73` entfernt Login nur bei
  `role === 'customer'`, redigiert `AiDecision`-PII.
- **Keine Privilege-Escalation ueber Mass-Assignment:** `role` ist in Registrierung/Anlage
  hart gesetzt; Staff-Update klemmt `role` auf `employee|manager` (kein `admin`).
- **Keine Injection jeglicher Art:** alle `whereRaw/selectRaw/orderByRaw` sind Literale oder
  parametrisiert; Binaeraufrufe (tesseract/pdftotext/pdftoppm) via Symfony `Process` Array-Form,
  Original-Dateinamen erreichen nie die Shell; Uploads mit Framework-Hash-Namen.
- **Kein XSS:** alle 18 `{!! !!}`-Sinks sind upstream `e()`-escaped oder statisch/JSON.
- **CSRF:** nur `api/website-inquiry` ausgenommen — abgesichert durch fail-closed
  `hash_equals`-Token (`WebsiteInquiryController.php:15`, **C5 verifiziert behoben**).
- **Krypto:** bcrypt Rounds 12, `password => hashed`; `SafeEncrypted` fuer IBAN/Steuer/Gesundheit;
  OAuth-Tokens/IMAP-Passwoerter `encrypted:array`.
- **Session-Invalidierung deaktivierter Konten** bei jedem Request (`EnsureUserRole.php:21`, **M2 behoben**).
- **`laravel/pao`** ist eine offizielle Laravel-Org-Abhaengigkeit (github.com/laravel/pao) —
  **kein Typosquat** (Vor-Verdacht ausgeraeumt).

### SEC-1 — Kein Content-Security-Policy-Header · **Medium**
- **Beschreibung:** `SecurityHeaders` setzt `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy`, `Permissions-Policy`, bedingtes HSTS — **aber kein `Content-Security-Policy`**.
  Die Admin-Blades nutzen durchgaengig inline `<style>` und `on*`-Handler, es fehlt also die
  Defense-in-Depth-Schicht gegen XSS.
- **Ort:** `app/Http/Middleware/SecurityHeaders.php:19-33` (verifiziert).
- **Reproduktion:** Response-Header pruefen (`curl -I`) — kein CSP vorhanden.
- **Auswirkung:** Bei einer kuenftigen Escaping-Luecke gaebe es keine CSP-Eindaemmung; kein
  `frame-ancestors`-Fallback fuer Browser, die `X-Frame-Options` ignorieren.
- **Ursache:** CSP war bei Einfuehrung der Header nicht Teil des Umfangs.
- **Loesung:** Moderate Policy einfuehren, zunaechst `Content-Security-Policy-Report-Only`:
  `default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'`
  (Vite/Nonces nach Bedarf). Langfristig inline-Styles/Handler in Klassen/Dateien auslagern → strikte Nonce-CSP.
- **Best Practice:** OWASP Secure Headers, CSP Level 3 mit Nonces.
- **Aufwand:** M (Report-Only S, strikte CSP L) · **Prioritaet:** Mittel · **Konfidenz:** Hoch.

### SEC-2 — Nebenpfad-IDOR: Inbox-Dokumente fuer portfolio-begrenzte Mitarbeiter · **Low→Medium**
- **Beschreibung:** `authorizeDocumentAccess()` erzwingt Scoping nur bei `customer_id !== null`.
  Fuer **noch nicht zugeordnete Inbox-Dokumente** (`customer_id === null`) kann jede Staff-Rolle
  (auch begrenzter `employee`) per ID herunterladen — inkonsistent mit
  `SmartDocumentUploadController::authorizeDocument()`, der Inbox-Dokumente korrekt auf den
  Uploader beschraenkt.
- **Ort:** `AdminController.php:973-990` → Guard `:39-43`.
- **Reproduktion:** Als begrenzter `employee` `/admin/documents/{id}/download` fuer eine fremde,
  unzugeordnete Dokument-UUID aufrufen.
- **Auswirkung:** Lesen extrahierter PII (Ausweise, Bankkarten, Protokolle) von Kollegen vor der
  Zuordnung. Niedrig, da authentifizierter Staff-Account + UUID (nicht erratbar) noetig.
- **Ursache:** Uploader-Check im Nicht-Smart-Pfad nicht gespiegelt.
- **Loesung:** In `authorizeDocumentAccess()` fuer `customer_id === null`
  `abort_unless(canSeeAllCustomers() || uploaded_by === auth()->id())`.
- **Aufwand:** S · **Prioritaet:** Mittel · **Konfidenz:** Hoch.

### SEC-3 — User-/Account-Enumeration in Auth-Flows · **Low–Medium**
- **Beschreibung:** Passwort-Reset (`PasswordResetLinkController.php:59`, `NewPasswordController.php:64`)
  liefert distinkt `INVALID_USER` („kein Konto gefunden"); deaktivierte Konten liefern beim Login
  eine eigene Meldung („Dieses Konto wurde deaktiviert"). Beides verraet Existenz/Status eines Kontos.
- **Ort:** siehe oben; `LoginRequest.php:52-56`.
- **Auswirkung:** Angreifer kann gueltige Adressen (und deaktivierte) ermitteln → gezieltes
  Phishing/Credential-Stuffing gegen bekannte Kundenbasis.
- **Ursache:** Bewusster UX-Trade-off (freundlichere deutsche Meldungen).
- **Loesung:** Einheitliche „Falls ein Konto existiert, wurde ein Link gesendet"-Meldung beim Reset;
  generische Ungueltig-Meldung fuer deaktivierte Konten. **Betreiber-Entscheidung** (UX vs. Haertung).
- **Aufwand:** S · **Prioritaet:** Niedrig–Mittel · **Konfidenz:** Hoch.

### Weitere Sicherheitsbefunde (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| SEC-4 | Low | `routes/auth.php:28` (`password.email` ohne `throttle`) | Per-IP-Probing vieler Adressen / Mail-Bombing (verstaerkt SEC-3) | `->middleware('throttle:6,1')` | S |
| SEC-5 | Low | `LoginRequest.php:133-157` (Throttle je email+IP) | Password-Spraying (1 Passwort × viele Konten) je IP nicht global gedrosselt | Zusaetzlicher per-IP-Limiter auf Login-Route | S |
| SEC-6 | Low | `EmployeeController.php:34-64` | Klartext-Passwort per E-Mail an neue Mitarbeiter (bleibt im Postfach) | Invite-/Passwort-Setzen-Link statt Passwortversand | M |
| SEC-7 | Low | `PortalController.php:66` (Banner `link_url`) | Unvalidierter Open-Redirect (admin-kontrolliert) → Phishing von der Portal-Domain | `link_url` als `url` validieren, Schema-Allowlist | S |
| SEC-8 | Info | `SecurityHeaders.php:29`; `.env.example` | HSTS ohne `preload`; `.env.example` mit `APP_DEBUG=true` | `preload` ergaenzen (nach Domain-Registrierung); Prod-`.env` = `APP_DEBUG=false` bestaetigen | S |
| SEC-9 | Info | `config/cors.php` fehlt | Keine CORS-Config publiziert (Default: nur `api/*`); da Session-Cookie-basiert unkritisch | Belassen; bewusst dokumentieren | — |

**Beobachtung (nicht ausnutzbar, aber halten):** `ChangeRequestReviewController::document()` (`:61-66`)
laedt `new_data['document_path']`/`document_disk`. Aktuell nur aus vertrauenswuerdigen Server-Ergebnissen
befuellt → **niemals** Client-Input direkt hineinfliessen lassen (sonst Arbitrary-File-Read).

---

## 3. Datenbank & Datenintegritaet

### Positiv: idempotente, gut dokumentierte Migrationen; starke Unique-Constraints
(`external_references`, `email_messages`, `commissions`, `contract_switch_reminders` fuer idempotente
Sends), `SafeEncrypted` mit Self-Healing von Alt-Klartext, retrofit FK-Index auf `documents.contract_id`,
`CustomerMergeService` mit Schema-Introspektion (neue `customer_id`-Tabellen automatisch abgedeckt).

### DB-1 — Keine Backup-/Restore-Strategie · **CRITICAL**
- **Beschreibung:** Kein Backup-Paket (`spatie/laravel-backup` o. ae.), kein `mysqldump`/Snapshot in
  `scripts/`, kein Cron/Artisan-Command, keine dokumentierte Restore-Prozedur. `scripts/deploy.sh`
  laeuft `php artisan migrate --force` in Produktion **ohne Vorab-Dump**. Mehrere Migrationen sind
  bewusst irreversibel (leeres `down()`, siehe DB-8).
- **Ort:** gesamtes Repo — `composer.json`, `scripts/deploy.sh`, `docs/`.
- **Reproduktion:** Repo nach `backup`/`mysqldump`/`spatie` durchsuchen → keine Treffer.
- **Auswirkung:** Ein fehlgeschlagenes Deploy, ein versehentliches `customers:purge --force` oder
  VPS-Plattenverlust ist **nicht wiederherstellbar**. DSGVO-Verfuegbarkeit/Integritaet + Business-Continuity.
- **Ursache:** Backup nie Teil des Deploy-Prozesses.
- **Loesung:** (1) Automatische Dumps (Hostinger-DB-Snapshot oder Cron `mysqldump | gzip` off-box mit
  Retention); (2) In `deploy.sh` **direkt vor** `migrate --force` einen Dump ziehen; (3) getesteten
  Restore dokumentieren. `spatie/laravel-backup` buendelt App+DB+Storage.
- **Best Practice:** 3-2-1-Backup-Regel, regelmaessige Restore-Drills.
- **Aufwand:** M · **Prioritaet:** **Hoechste** · **Konfidenz:** Hoch.

### DB-2 — Sensible Nummern doppelt & im Klartext auf `customer_family` · **High (DSGVO)**
- **Beschreibung:** `customer_family.krankenversicherung_nr` und `.steuer_nr` sind **Klartext-`string`**
  (Migration `2026_07_07_110001`), waehrend die spaeteren `.health_insurance_number`/`.tax_id`
  verschluesselt sind (`encrypted`-Cast, `CustomerFamily.php:18` verifiziert). Beide Paare stehen in
  `$fillable` und werden vom Admin-Pfad noch geschrieben (`AdminController` referenziert die
  Klartextfelder).
- **Ort:** Migration `2026_07_07_110001_add_fields_to_customer_family.php:8-9`; `app/Models/CustomerFamily.php:8-18`.
- **Reproduktion:** `SELECT krankenversicherung_nr, steuer_nr FROM customer_family` → Klartext.
- **Auswirkung:** Steuer-ID/KV-Nummer eines Familienmitglieds liegt **unverschluesselt** in der DB und in
  Backups, obwohl das neuere Feld verschluesselt. Write-Skew (welches Feld ist fuehrend?).
- **Ursache:** Inkrementelle Feature-Erweiterung ohne Retirement der Altspalten.
- **Loesung:** Daten in die verschluesselten Spalten migrieren, Admin-Pfad auf diese umstellen, dann
  `krankenversicherung_nr`/`steuer_nr` droppen (Vorlage: `2026_07_10_100000` fuer `salutation`).
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### DB-3 — `partners.user_id`/`customers.partner_id`: `string`, keine FK, Typ-Mismatch · **High**
- **Beschreibung:** `partners.user_id` ist `string` (aber `users.id` ist `bigint`) **ohne FK**;
  `customers.partner_id` ist `string`-UUID auf `partners.id` **ohne FK-Constraint**.
- **Ort:** `2026_07_11_160000_add_partner_portal_columns.php`.
- **Auswirkung:** Verwaiste Referenzen frei anlegbar; Loeschen von Partner/User hinterlaesst Dangling-Pointer
  ohne `ON DELETE`; `bigint`-in-`string` = Join-/Compare-Ueberraschungen.
- **Loesung:** Typisierte FKs (`foreignId('user_id')->nullable()->constrained()->nullOnDelete()`;
  `foreignUuid('partner_id')->nullable()->constrained()->nullOnDelete()`). Falls fuer das noch nicht
  gebaute Partner-Portal bewusst lose → dokumentieren + App-seitige Integritaetspruefung.
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### DB-4 — `CASCADE` Richtung `users` loescht Audit-/Historie-Zeilen · **High**
- **Beschreibung:** `tasks.assigned_to/created_by`, `appointments.assigned_to`, `announcements.created_by`,
  `customer_notes.created_by`, `email_campaigns.created_by`, `email_logs.user_id` sind `cascadeOnDelete`.
  Loeschen eines Mitarbeiters loescht dessen Tasks, Termine, Notizen, Kampagnen und **E-Mail-Sende-Logs**.
  Das Muster wurde fuer `ticket_messages.sender_id` bereits korrekt auf `SET NULL` gefixt
  (`2026_07_13_100000_...sender_null_on_delete`), die anderen Tabellen nicht.
- **Ort:** Migrationen `2026_07_06_150001`, `2026_07_07_200001` u. a.
- **Auswirkung:** Verlust von Betriebs-/Audit-Historie bei Personalwechsel; inkonsistente Loeschsemantik.
- **Loesung:** Auf `nullable()` + `nullOnDelete()` umstellen (analog `ticket_messages`) oder Hard-Delete von
  Usern mit abhaengigen Zeilen blockieren.
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### DB-5 — Fehlende Indizes auf haeufig gefilterten Nicht-FK-Spalten · **High**
- **Beschreibung:** `contracts.status`, `contracts.type`, `tickets.status` werden in Dashboards/Reports/Portal
  gefiltert und ge`COUNT`et, haben aber keinen Index (FK-Spalten sind unter MySQL/InnoDB abgedeckt, plain
  Status/Type nicht).
- **Ort:** u. a. `AdminController.php:62/86`, `ReportController.php:24-27`, `PortalController.php:23`.
- **Auswirkung:** Full-Table-Scans, die mit dem Datenvolumen wachsen → Dashboard-/Report-Latenz.
- **Loesung:** Composite-Indizes passend zum Zugriff: `contracts (customer_id, status)`, `contracts (type)`,
  `tickets (status, due_at)`.
- **Aufwand:** S · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### DB-6 — Committed Default `DB_CONNECTION=sqlite`; keine MySQL-CI · **High**
- **Beschreibung:** `config/database.php` → `env('DB_CONNECTION','sqlite')`; Tests laufen auf SQLite und
  verbergen MySQL-only-Fehler. Genau diese Klasse hat das Team schon zweimal getroffen (dokumentiert in
  `2026_07_13_140000_widen_encrypted_iban_columns` „Data too long" und `2026_07_18_140000_fix_documents_ai_extracted_text` „Invalid JSON text").
- **Auswirkung:** Gruene CI bei kaputter Produktion; stille Daten-Divergenz bei fehlendem/fehlerhaftem `.env`.
- **Loesung:** MySQL-CI-Leg ergaenzen; Boot-Guard, der in `production` bei Nicht-`mysql`-Treiber hart fehlschlaegt.
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### Weitere DB-Befunde (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| DB-7 | Medium | `customers.address/address2` vs. `address_*` vs. `customer_addresses` | 3 ueberlappende Adressquellen, Sync-Bugs, Backfill-Migration noetig | Strukturierte Spalten als kanonisch; Freitext nur Anzeige | M |
| DB-8 | Medium | Enum vs. String uneinheitlich (viele Enums bleiben, neue Status ohne Constraint) | Inkonsistente Garantien; Enum-„value not allowed"-Risiko | Standardisieren (App-Whitelist wie `Contract::TYPES`) oder CHECK-Constraints | M |
| DB-9 | Medium | `CustomerNumberGenerator::generate()` (max()+1 ohne Lock) | Race → seltener 500 bei paralleler Anlage (Unique faengt ab) | Transaktion + `FOR UPDATE` oder Unique-Violation-Retry | S |
| DB-10 | Medium | Verschluesselte Spalten unindexierbar → `DuplicateDetectionService` laedt alle Kunden in PHP (`MAX_SCAN=20000`) | IBAN-Dedup skaliert nicht ueber Cap | Optional Blind-Index (HMAC normalisierte IBAN) fuer DB-Lookup | M |
| DB-11 | Medium | Keine DB-Unique gegen Kundendubletten; `users.email` jetzt nullable + Import-Platzhalter | Kein Backstop gegen exakte Dubletten bei App-Bug | Blind-Index-Unique auf normalisierte E-Mail/IBAN oder dokumentieren „advisory only" | M |
| DB-12 | Low/Med | 2 parallele Fahrzeugspeicher (`customer_vehicles` vs. `contract_vehicle_details`) | Dublette/inkonsistente Fahrzeugdaten | Legacy pruefen, ggf. deprecaten/migrieren | M |
| DB-13 | Low/Med | Heavy Data-Migrationen mit Row-Loops in `migrate --force` (`2026_07_12_100000`, `2026_07_17_100000`) | Verlaengerte Downtime; bei Abbruch teilmigrierte Daten ohne Dump | Schwere Data-Migr. in idempotente, gechunkte Jobs ausserhalb Schema-Schritt | M |
| DB-14 | Low | `external_references` ohne `Relation::morphMap()` | Namespace-Umbenennung bricht gespeicherte Referenzen | Morph-Map registrieren | S |
| DB-15 | Low | `activity_logs` waechst 1 Zeile/Request; `email_messages.body_*` longText | Tabellenwachstum; `SELECT *` zieht grosse Bodies | Retention-Policy (Prune existiert); explizite Spaltenauswahl in Mailbox-Listen | S |

---

## 4. Performance

### Positiv: Hauptlisten (Kunden, Tickets) paginiert & eager-loaded; Jobs mit Timeout/Tries/Backoff/Chunking; Deploy cached config/routes/views; „free-first" AI-Pipeline.

### PERF-1 — Aktivitaets-Tracking schreibt bei jedem Request in die DB · **High**
- **Beschreibung:** Jeder nicht-ignorierte Staff-Request: SELECT der offenen `WorkSession` + 1–2
  `WorkSession`-UPDATE + **`ActivityLog::create()`-INSERT**. Ein Seitenaufruf ~ 1 SELECT + 1–2 UPDATE + 1 INSERT
  vor den eigentlichen Seiten-Queries.
- **Ort:** `TrackStaffActivity.php:26` → `ActivityTracker.php:80,159,198`.
- **Auswirkung:** Write-Amplification auf dem Hot-Path; mit DB-Session/-Cache 5–8 reine Infra-Roundtrips pro Load;
  `activity_logs` wird die groesste Tabelle.
- **Loesung:** (a) `ActivityLog`-Insert per `->afterResponse()`/Queue aus dem kritischen Pfad nehmen;
  (b) `last_seen_at`-Heartbeat auf max. 1×/N Sekunden je Session drosseln; (c) Index auf `work_session_id` pruefen.
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### PERF-2 — Transaktionsmails werden synchron im Request versendet · **High**
- **Beschreibung:** Nur `DocumentRequestMail` implementiert `ShouldQueue`; die uebrigen 13 Mailables werden per
  `Mail::to()->send()` synchron verschickt (Welcome, Ticket-Reply, Support, Mention, …). SMTP-Latenz (300 ms–mehrere s,
  schlimmer bei Timeout) blockiert die HTTP-Antwort.
- **Ort:** `app/Mail/*`; Call-Sites u. a. `PortalAccessService.php:62`, `TicketController.php:561`, `ComposeEmailController.php:249`.
- **Loesung:** Mailables auf `implements ShouldQueue` (oder `->queue()`); DB-Queue-Worker laeuft bereits.
- **Aufwand:** S · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### PERF-3 — Gesamte Infra-State auf einer MySQL (Session+Cache+Queue+Activity) · **High**
- **Beschreibung:** `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` = `database`. Jeder Request = Session-Read/Write
  + Activity + Cache-Lookups; Worker pollt dieselbe DB. Einziger Bottleneck/Lock-Contention-Punkt.
- **Ort:** `config/queue.php`, `config/cache.php`, `.env.example`.
- **Loesung:** `CACHE_STORE` + `SESSION_DRIVER` (ideal auch `QUEUE_CONNECTION`) auf **Redis**. Groesster Einzelhebel;
  entsperrt echtes Query-/Response-Caching. **Konfidenz:** Hoch (bitte Prod-`.env` bestaetigen — evtl. bereits Redis).
- **Aufwand:** M · **Prioritaet:** Hoch.

### Weitere Performance-Befunde (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| PERF-4 | Medium | `SystemSetting::get()` uncached; `ActivityCatalog` Thresholds nicht memoized; `LegalPageController` ~10 Settings-Calls | Query-Strom je Request/oeffentliche Seite | `Cache::rememberForever` + Invalidierung in `set()`; memoize | S |
| PERF-5 | Medium | `User::visibleCustomerIdsWithSubstitution()` (`User.php:51`) N+1 via `find()`-Loop, nicht memoized | Mehrfach je Request neu berechnet | Memoize je Instanz; Absentee-Assignments in einem `whereIn` | S |
| PERF-6 | Medium | Unbounded `->get()`: `contracts()` (`AdminController.php:117`), `contractNew()` (alle Kunden im `<select>`), Inbox/Commission-Listen, `tasks`-Modal (`tasks.blade.php:156`) | Speicher/Latenz waechst mit Daten; riesige HTML-Payloads | Paginieren; Server-Autocomplete/Typeahead statt Voll-Select | M |
| PERF-7 | Medium | `Cache::flush()` in `DuplicateDetectionService.php:140` | Merge leert **gesamten** App-Cache | `Cache::forget` gezielter Keys / Tagged Cache | S |
| PERF-8 | Med-High | `CommissionController::book()` ruft `Lexoffice::createVoucher()` synchron im Request | Latenz an Dritt-API gekoppelt; Timeout haengt Request | Voucher-Erstellung in Queue-Job | M |
| PERF-9 | Medium | `customerShow` tiefe Eager-Load (alle Claims/Mileage/Documents) `AdminController.php:99` | Speicher/CPU + Entschluesselung bei grossen Akten | Mileage/Claims lazy (Tab/AJAX) oder `latest()->take(n)` | M |
| PERF-10 | Low | `SendCampaignJob` `sent_count`-Update + `EmailLog::create()` je Empfaenger | 2 Writes/Mail | Counter je Chunk batchen | S |
| PERF-11 | Low | `chart.umd.min.js` ausserhalb Vite (`admin.blade.php:9`) | Schwaecheres Cache-Busting | Ueber `@vite` bundeln | S |
| PERF-12 | Low | Scheduler-Closures (`console.php:82,113`) Voll-`get()` + inline `Mail::send` | Blockiert Scheduler-Tick bei SMTP-Slowness | Queued Mailables; `withoutOverlapping` | S |

---

## 5. Code-Qualitaet & Architektur

### Positiv: provider-swappbare AI/OCR/Mailbox-Architektur (Interfaces + Container-`match()`); `AnalyzeDocumentJob`/`WorkflowEngine` produktionsreif (atomarer Claim, „bezahltes Ergebnis zuerst"); starke domaenenbewusste Validierung; 99 Testdateien inkl. Security-Guards.

### ARCH-1 — `AdminController` ist eine Gottklasse · **High (Wartbarkeit)**
- **Beschreibung:** 1518 Zeilen, ~40 Methoden: Kunden-CRUD, Vertrags-CRUD + KFZ/Energie/Internet-Detail-Sync,
  SF-Historie, Dokumente, Familie, Fahrzeuge, Notizen, Dubletten-Merge (Union-Find inline), Bulk-Assign, Loeschung,
  Timeline, globale Suche. `syncVehicleDetail()` ~110 Z., `validateContract()` ~90 Z. Inline-Regeln.
- **Ort:** `app/Http/Controllers/AdminController.php`.
- **Auswirkung:** Schwer isoliert testbar, hohe Merge-Konflikt-Flaeche, kognitive Last.
- **Loesung:** `ContractService` extrahieren (create/update/`syncContractDetails`/`syncVehicleDetail`/`syncSfHistory`);
  Union-Find-Clustering nach `CustomerMergeService`; Dubletten/Relationen in eigenen Controller;
  `validateContract`-Regelwerk in `ContractRequest`.
- **Aufwand:** L · **Prioritaet:** Mittel-Hoch · **Konfidenz:** Hoch.

### ARCH-2 — Sicherheitskritisches Customer-Scoping in 8 Controllern kopiert · **High**
- **Beschreibung:** `visibleCustomerIds()`/`authorizeCustomerAccess()`/`authorizeTicketAccess()` sind unabhaengig
  in `AdminController`, `TicketController`, `AppointmentController`, `ImportExportController`, `EmailMarketingController`,
  `ComposeEmailController`, `ReportController` u. a. redefiniert. Basis-`Controller.php` ist leer; kein
  `Concerns`/`Traits`-Verzeichnis. Bereits leicht divergent (Admin hat Guest-Lead-Handling, andere nicht).
- **Auswirkung:** **Autorisierungslogik** — eine kuenftige Verschaerfung muss an 8+ Stellen erfolgen; Drift =
  Datenleck-Risiko.
- **Loesung:** Trait `ScopesCustomerAccess` (oder Query-Scopes `->visibleTo($user)` auf Models) als Single Source of Truth.
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### ARCH-3 — `LexofficeService` verschluckt jeden Fehler still (Geld-Pfad) · **High**
- **Beschreibung:** Jede Methode gibt bei Fehler `[]`/`null` zurueck — **ohne Logging**. Eine fehlgeschlagene
  Rechnungs-/Voucher-Erstellung (echtes Geld, Provisionsbuchung) ist ununterscheidbar von „leeres Ergebnis".
  `uploadVoucher` liest `file_get_contents($filePath)` ohne Existenz-/IO-Guard.
- **Ort:** `app/Services/LexofficeService.php` (alle Methoden).
- **Auswirkung:** Ops hat null Sichtbarkeit, wenn Lexoffice down ist/rate-limitet/ablehnt.
- **Loesung:** `Log::warning` mit Status/Body bei `!successful()`; Schreiboperationen werfen typisierte Exception
  → Controller informiert Nutzer. File-Read guarden.
- **Aufwand:** S · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### Weitere Code-/Architektur-Befunde (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| ARCH-4 | Medium | Inkonsistente Service-Adoption (Vertrag/Familie/Fahrzeug direkt im Controller) | Logik unvorhersehbar verortet | Konvention: Controller validiert + delegiert; Multi-Step-Writes via Service | M |
| ARCH-5 | Medium | Stiller `catch` im Merge-Loop `AdminController.php:1322`; leere `catch{}` bei Storage-Delete `:890,:958` | Integritaetsfehler unsichtbar | Exceptions loggen (Vorbild: `CustomerDeletionService:53`) | S |
| ARCH-6 | Medium | Nur 1 FormRequest (`LoginRequest`); 83 inline `$request->validate()` | Regeln nicht wiederverwendbar/testbar | `ContractRequest`/`CustomerRequest`/`DocumentUploadRequest` | M |
| ARCH-7 | Medium | Kein statisches Analyse-Tool (kein PHPStan/Larastan/`pint.json`) | Typ-Luecken/`mixed`-Flows unentdeckt | Larastan Level 5+ + CI-Step | M |
| ARCH-8 | Medium | Uneinheitliches Logging (Level/Struktur); Money-Pfad loggt nichts | Schwache Forensik | Konvention: `error` fuer fehlgeschlagene Ext-Writes, strukturierter Kontext | S |
| ARCH-9 | Medium | `ActivityLog::create([...json_encode...])` ~10× dupliziert | Boilerplate/Drift | `ActivityLog::record()`-Helper (Vorbild `AiActionLog::record()`) | S |
| ARCH-10 | Low | Config-Drift `ocr.ai_text_max_chars` 16000 (config) vs. 12000 (Code/Doku) | Stale Doku | Auf einen Wert konsolidieren | S |
| ARCH-11 | Low | `.env.example` unvollstaendig (`OCR_TEXT_LAYER`, `OCR_AI_TEXT_MAX_CHARS`, `ANTHROPIC_DOCUMENT_MAX_TOKENS` fehlen) | Betreiber findet Knoepfe nicht | Alle konsumierten Keys mit Default dokumentieren | S |
| ARCH-12 | Low | `Schema::getColumnListing()` je Kunden-Update `AdminController:668`; `mergeSummary($auto)` toter Parameter | Code-Smell/Runtime-Introspektion | `$fillable` vertrauen; toten Parameter entfernen | S |
| ARCH-13 | Low | Fehlende Return-Types auf Model-Relationen & aelteren Controllern | Schwaechere Typsicherheit | Return-Types ergaenzen (Larastan erzwingt) | M |

**Test-Luecken (namensbasiert, nicht ausgefuehrt):** Lexoffice `createVoucher`/`createInvoice`-**Fehlerpfade**;
expliziter Assert, dass `CustomerDeletionService` einen an Staff-`User` gekoppelten Kundendatensatz **nicht** den
Staff-Account loescht; Per-Controller-Scoping-Tests (wegen ARCH-2-Drift); `MailboxSyncService`-Fehlerbranch.

---

## 6. E-Mail, API, Integrationen & Monitoring

### API-/Webhook-Inventar (Auth-Mechanismus)

| Endpoint | Auth | Validierung | Rate-Limit |
|---|---|---|---|
| `POST /api/website-inquiry` | Static-Token `hash_equals`, **fail-closed**, CSRF-exempt | ja | `throttle:30,1` |
| `POST /leistungen/{slug}/anfrage` | oeffentlich + Honeypot + SpamFilter | ja | `throttle:8,1` |
| `GET/POST /hilfe` | oeffentlich + verschluesselter Kunden-Token | ja + Honeypot | `throttle:8,1` |
| `GET /abmelden/{token}` | Per-Kunde-Token | — | `throttle:30,1` |
| `GET /magic-login/{user}` | Laravel `signed` (90 T), nur aktive `customer` | — | `throttle:10,1` |
| Portal-/Admin-JSON (AJAX) | `auth` + Rolle + Portfolio-Scope | ja | per-Route |

Kein versioniertes REST-API / keine API-Tokens/Sanctum — alles Session+CSRF-Web-Routen ausser dem einen
tokenisierten Inquiry-Webhook. Fuer diese App **angemessen**.

### INT-1 — CSV-Export anfaellig fuer Formel-/CSV-Injection · **High**
- **Beschreibung:** `export()` schreibt kunden-kontrollierte Felder (`name`, `company_name`, `address`, `iban`,
  Telefon) **ohne `EscapeFormula`** in den CSV-Writer. Der Codebase kennt den Fix bereits
  (`ActivityReportController.php:101,140` nutzt `EscapeFormula`). Werte mit `= + - @` werden beim Oeffnen in
  Excel/LibreOffice als Formel ausgefuehrt (DDE → Datenexfiltration/Command-Execution).
- **Ort:** `app/Http/Controllers/ImportExportController.php:90-124` (verifiziert).
- **Reproduktion:** Kunde mit Name `=cmd|'/C calc'!A1` anlegen (via Self-Service/Website/Import) → Betreiber
  exportiert → Formel feuert. Export enthaelt **IBANs** (hochwertiges Ziel).
- **Auswirkung:** Angriff auf die Maschine des Betreibers beim Export.
- **Loesung:** `$csv->addFormatter(new League\Csv\EscapeFormula());` in `export()` (und `template()`).
- **Aufwand:** S · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### INT-2 — Lexoffice: Rechnung senden & herunterladen rufen nicht existierende Methoden → 500 · **High**
- **Beschreibung:** `LexofficeController.php:81` ruft `sendInvoice($id,$email)`, `:86` ruft `getInvoicePdf($id)`.
  `LexofficeService` definiert **nur** `renderInvoicePdf()` (`:67`) — **kein `sendInvoice`, kein `getInvoicePdf`**.
- **Ort:** `LexofficeController.php:81,86` vs. `LexofficeService.php:67` (verifiziert per grep).
- **Reproduktion:** `/admin/lexoffice/invoices/{id}/send` oder `/download` aufrufen → „Call to undefined method" → HTTP 500.
- **Auswirkung:** Zwei beworbene Admin-Integrationsfunktionen komplett kaputt. Kein Test deckt sie ab.
- **Loesung:** `getInvoicePdf`/`sendInvoice` implementieren oder Controller auf `renderInvoicePdf` umbiegen + Send bauen.
- **Aufwand:** S · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### INT-3 — Spezialkategorien-PII an US-KI-Dienstleister ohne sichtbare DSGVO-Schutzmassnahmen · **High (Compliance)**
- **Beschreibung:** Die Dokumentanalyse sendet **ganze Dokumentbilder/-PDFs** (Ausweise inkl. `id_number`/
  `nationality`, IBANs, KV-Nummern, VINs, Familiendaten) an `api.anthropic.com` (US). `EmailDraftService` sendet
  Name/Nummer/Anrede/Interaktions-Snippets. Gute Prompt-Injection-Haertung und Hash-only-Logging vorhanden, aber
  **keine Datenminimierung am Bild**, keine Region-Pinning, kein Code-Bezug zu AVV/DPA/Einwilligung. Gesundheitsdaten =
  Art. 9 DSGVO.
- **Ort:** `ClaudeDocumentAiProvider.php:41-120` (via `AnalyzeDocumentJob`); `EmailDraftService::draft`.
- **Auswirkung:** Potenziell unrechtmaessige Uebermittlung besonderer Kategorien an einen Drittland-Verarbeiter —
  **groesste Compliance-Exposition** im Code.
- **Loesung (Prozess, Betreiber-Entscheidung):** AVV/DPA mit Anthropic sicherstellen, TIA dokumentieren,
  EU-Endpoint/Zero-Retention-Terms pruefen, auto-gesendete Dokumenttypen einschraenken, Einwilligungsgrundlage klaeren.
- **Aufwand:** M (Prozess) · **Prioritaet:** Hoch · **Konfidenz:** Hoch (Datenfluss); Rechtslage ausserhalb Code.

### INT-4 — Mailbox-Provider verlieren Nachrichten jenseits des Fetch-Limits · **High (Datenintegritaet)**
- **Beschreibung:** `fetchNewMessages` cappt bei 50 (Gmail `maxResults=50` ohne `nextPageToken`, Graph `$top` ohne
  `@odata.nextLink`, IMAP `->limit()`); danach setzt `MailboxSyncService::syncAccount:96` `last_synced_at = now()`
  bedingungslos. Nachrichten jenseits von 50 in einem 2-Min-Fenster werden **nie wieder** geholt.
- **Ort:** `GmailApiMailboxProvider.php:38`, `GraphApiMailboxProvider.php:38`, `ImapMailboxProvider.php:46`,
  `MailboxSyncService.php:96`.
- **Auswirkung:** Bei Mail-Bursts (z. B. Fonds-Finanz-Batch) gehen Kundenmails/Anhaenge **dauerhaft** verloren —
  stille Luecke in der Kundenakte.
- **Loesung:** Bis zur Erschoepfung paginieren **oder** `last_synced_at` nur bis zum tatsaechlich verarbeiteten
  Max-`received_at` vorruecken (1-h-Overlap-Sicherheitsmarge halten).
- **Aufwand:** M · **Prioritaet:** Hoch · **Konfidenz:** Hoch.

### Weitere E-Mail/Integration/Monitoring-Befunde (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| INT-5 | Medium | `CampaignMail`/`ContractSwitchMail` ohne `List-Unsubscribe`-Header | Schlechtere Zustellung (Outlook/Gmail), UWG-Signal | RFC-8058 `List-Unsubscribe` + `-Post: One-Click` | S |
| INT-6 | Medium | Kein Bounce-/Complaint-Handling; `EmailLog` nur Handoff | Bad-Addresses weiter bemailt → Reputationsschaden (Outlook-Thema aus CLAUDE.md) | Provider mit Event-Webhooks (Postmark/SES, in `config/mail.php` gestubbt) + Suppression-Liste | M |
| INT-7 | Medium | `config/logging.php`: single non-rotating, `LOG_LEVEL=debug`; kein Sentry; PII in Logs (`SendCampaignJob:54`, `OAuthTokenService:119`) | Unbounded Disk, PII, stille Prod-Fehler | `daily` + Retention, `LOG_LEVEL=info/warning`, Error-Tracker/Slack-`critical` | S |
| INT-8 | Medium | Security-Events kaum im Audit-Trail (kein Login/Logout/Failed-Login/CSV-Export/Rollenaenderung) | Schwache Forensik/DSGVO-Rechenschaft | `ActivityLog` fuer Login(-fail)/Logout/Export/Rollen-/Aktiv-Aenderung | S |
| INT-9 | Medium | Bulk-Send = 1 langer synchroner Job, kein SMTP-Pacing, Counter/Empfaenger | Provider-Throttle; Timeout laesst `sending` haengen | Queued per-Chunk-Mailable, Counter batchen, idempotent `sent` | M |
| INT-10 | Medium | Queue-Worker nicht vom Deploy verwaltet/ueberwacht (`deploy.sh:51` nur `queue:restart`) | Bei Worker-Tod stauen Jobs still (Kampagnen, Import, Analyse) | systemd-Units sicherstellen; Alert auf Queue-Depth/`failed_jobs`; `/up` erweitern | M |
| INT-11 | Low | Scheduler-Closures ohne `withoutOverlapping`, inline `Mail::send` (`console.php:69-121`) | Slow SMTP blockiert Tick | Queued Mailables + Overlap-Guard | S |
| INT-12 | Low | `LexofficeController::importContact` umgeht Matching/Nummerngenerator/ExternalReference/Throttle | Dubletten, inkonsistente Nummerierung, keine Provenienz | Ueber `CustomerAutoCreationService`/Matcher leiten | M |
| INT-13 | Low | Import-Preview-Datei (Kunden-PII) ohne TTL-Cleanup wenn nie bestaetigt | PII bleibt in `storage/app/private/imports` liegen | Scheduled Cleanup stale `imports/*.csv` (> 24 h) | S |
| INT-14 | Low | `DirectEmailMail` vertraut Client-MIME; kein Reply-To pro Sender | Antworten gehen an globales `info@` | Reply-To = sendender Mitarbeiter; MIME serverseitig | S |

---

## 7. UX / UI / Accessibility

> **Palette-Hinweis (reduziert Falschmeldungen):** In `layouts/admin.blade.php:11` haelt die CSS-Var `--petrol`
> tatsaechlich Graphit `#17191d` und `--gold` = Smaragd `#17A65B` — `var(--petrol)`/`.btn-gold` sind also
> **on-palette**, nur legacy-benannt. Echte Verstoesse sind **hartkodierte** Hex-Werte
> (`#3B7A57`, `#1F3A33`, `#185FA5`, `#1e3a8a`, `#0F4C4C`).

### Funktionale Bugs (High) — im UI, aber echte Defekte

| ID | Ort | Defekt | Loesung | Aufw. |
|---|---|---|---|---|
| UX-1 | `employee_edit.blade.php:42-50` | **Rollen-Select ist fuer Voll-Zugriff-Mitarbeiter versteckt** (im `display:none`-Block „Begrenzte Kunden"). Rollenwechsel unmoeglich fuer eine ganze Nutzerklasse. | Rollen-Select in eigenen, immer sichtbaren Abschnitt | S |
| UX-2 | `employee_create.blade.php:48-53`, `employee_edit.blade.php:140-145` | **`can_import_export` hat kein UI-Control** — Controller liest es (`:45,109`), Badge existiert, aber keine Checkbox submitet es → Recht nie ueber UI vergebbar | Checkbox ergaenzen | S |
| UX-3 | `employee_create.blade.php:77,106-110` | **„Alle auswaehlen" wirft JS-TypeError** (`getElementById('can_import_export')` = null) → Loop bricht ab | Element ergaenzen oder null-Guard | S |
| UX-4 | `commissions.blade.php:63`, `partner_show.blade.php:61` | **`.badge-danger` nicht definiert** → abgelehnte Provisionen ohne Farbe/Status | `.badge-rejected` nutzen | S |
| UX-5 | `import_preview.blade.php:36` | **`.alert-warning` nicht definiert** → „N Kunden ohne E-Mail" als unstyled Text | Klasse definieren | S |
| UX-6 | `customer_edit.blade.php` (ganze Datei) | **Keine `$errors`-Anzeige, kein `old()`** → bei Validierungsfehler wirkt Edit als „tut nichts", Eingaben verloren | `$errors`-Panel + `old('feld',$customer->...)` wie `customer_create` | S |
| UX-7 | `email_marketing.blade.php:82` | **Massenversand ohne Bestaetigung/Empfaengerzahl/Undo** — gefaehrlichste Aktion der App, einziger destruktiver Button ohne `confirm()` | `onsubmit`-Confirm mit aufgeloester Empfaengerzahl; Pflicht-Preview/Test | S |

### UX (weitere, kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| UX-8 | High | `auth/forgot-password/reset/verify.blade.php` | **Passwort-Reset-Trio = englisches Breeze-Leftover** in grauer Karte; DE/AR-Nutzer sehen Englisch, off-brand | Auf Glas-Karten-Layout mit DE-Quelle + `ar.json` neu bauen | M |
| UX-9 | Medium | `email_marketing.blade.php:54-64` | Empfaengerzahl nur fuer „Alle", nicht fuer Segmente | Per-Segment-Count (AJAX/precompute) | S |
| UX-10 | Medium | Validierungsfehler unsichtbar auf `email_marketing`/`compose_email`/Template-/Chat-/Announcement-Modals | „Button tut nichts"-Dead-Ends | Shared `$errors`-Partial; Modal bei Fehlern re-oeffnen | M |
| UX-11 | Medium | `internal_chat` (`index/show`) | Ungelesen-Status getrackt (`last_read_at`) aber nie angezeigt | Ungelesen-Badge/Bold via `last_read_at` vs. `last_message_at` | S |
| UX-12 | Medium | `_new_modal.blade.php:8-27` | „Team einladen" erfuellt `participants|required|min:1` nicht → stiller Fehler | Participants optional bei Team-Wahl oder Members auto-checken | S |
| UX-13 | Medium | `settings.blade.php:80-82` | **Hartkodierte Statistik „1031 Kontakte importiert"** unabhaengig vom echten Zustand (streift CLAUDE.md „keine erfundenen Statistiken") | Aus Live-State ableiten oder entfernen | S |
| UX-14 | Medium | `employee_create.blade.php:36,63` | Neue Mitarbeiter defaulten auf **Voll-Zugriff + alle Rechte** (unsicherster Default) | Default „Begrenzt" + keine Rechte | S |
| UX-15 | Medium | `commissions.blade.php:36,37` | Finanz-Buchung (Lexoffice) & Ablehnung **ohne Confirm** | `confirm()` ergaenzen | S |
| UX-16 | Medium | `contract_new.blade.php` | JS-only Kundenauswahl (leeres `action`), Suche nur nach Name trotz „Kundendaten durchsuchen" | Kunde serverseitig in URL/validieren; Suche um E-Mail/Nummer erweitern | M |
| UX-17 | Low | `tasks.blade.php:156` vs `appointments.blade.php:83` | Tasks laedt **alle** Kunden ins Select; Appointments cappt bei 100 (aeltere unerreichbar) | Gemeinsames AJAX-Suchwidget | M |
| UX-18 | Low | Diverse | Duplizierte Flash-Meldungen (Layout rendert global + inline), leere Empty-States ohne CTA, inkonsistente Primary-CTA-Farbe (graphit vs. smaragd) | Inline-Flash entfernen; Empty-State-CTAs; Konvention Smaragd=Primary | S |

### UI / Palette (kompakt)

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| UI-1 | High | `layouts/admin.blade.php:77,90,94,115`, `layouts/portal.blade.php:35,40,51`, `layouts/partner.blade.php:41` + viele Inline | **Petrol-Gruen `#3B7A57`** in Shared-Klassen (`.badge-active/-approved`, `.alert-success`, `.icon-green`) → auf fast jeder Seite; CLAUDE.md verbietet es explizit | Auf Smaragd `#17A65B` umstellen; Erfolgs-/Aktiv-Token zentralisieren | M |
| UI-2 | High | `emails/campaign.blade.php:7`, `ticket_reply/guest_reply/document_request` (`#1e3a8a`), `support_inquiry` (`#0F4C4C`) | **Kundenmails off-brand blau/petrol** — Welcome-Mail ist der on-palette Ausreisser | Alle Kundenmails: Graphit-Band `#17191d` + Smaragd-CTA `#17A65B` | M |
| UI-3 | Medium | `customers.blade.php:61,114`, `customer_merge`, `documents_inbox.blade.php:177,206` | Weitere hartkodierte Petrol-Gruens (`#1F3A33`/`#3B7A57`) inkonsistent zwischen aehnlichen Screens | Vereinheitlichen auf Graphit/Smaragd | M |
| UI-4 | Medium | `customer_create/edit`, `contract_form_fields`, `contract_kfz_fields` | Feste `grid-template-columns:1fr 1fr/1fr 1fr 1fr` **ohne Media-Query** → Formularfelder auf Mobil unbrauchbar | `minmax()`/`auto-fit` oder `@media(max-width:640px){1fr}` | M |
| UI-5 | Medium | `customer_show.blade.php:330`, `contracts.blade.php` (`overflow:hidden`), `tickets.blade.php` | Breite Tabellen ohne `overflow-x:auto` (bzw. clippen) → Spaltenverlust/Body-Scroll auf Mobil | In `overflow-x:auto` wrappen | S |
| UI-6 | Medium | `dashboard.blade.php:116`, `reports.blade.php:172` | Off-palette Chart-Farben (`#0F3D3D`, `#C9963E`, Blau-Ramp) vs. `#17A65B` in Activity/Banner-Stats | Palette-abgeleitete kategoriale Skala (Skill „dataviz") | M |
| UI-7 | Low | pervasive | Alles inline-styled; `.field select` mehrfach redeklariert; kein `.badge-*`/`.alert-*`-Utility-Set | Wiederholte Muster in Shared-Klassen; redundante Inline-Styles entfernen | M |
| UI-8 | Low | `customer_timeline.blade.php:30,40` | `background:var(--petrol)20` = ungueltiges CSS-Alpha (nur bei 6-stelligem Hex) | `color-mix()`/rgba statt Alpha-Concat auf CSS-Var | S |
| UI-9 | Low | `layouts/admin.blade.php:11` (`--canvas:#DCDEE3`, `--line:#CDD1D8`) vs. CLAUDE.md (`#F4F5F7`/`#E4E6EA`) | Admin-Canvas dunkler als Spec; Partner-Layout nutzt Spec-Werte | Auf Spec vereinheitlichen | S |
| UI-10 | Low | `email_connection.blade.php:15` | **Weisser Text auf hellem Grund** — `--surface` (= `#ECEEF1`) gewinnt gegen Fallback `#101216` → Weiterleitungsadresse unsichtbar | Dunklen BG hartkodieren | S |
| UI-11 | Low | `lexoffice_invoices.blade.php:42` | Toter Zweig `@if(... || true)` (Debug-Rest) | Entfernen | S |

### Accessibility (a11y)

Durchgaengiges Muster ueber Admin **und** Portal: interaktive Widgets sind `<div onclick>` statt semantischer
Elemente, Modals ohne Dialog-Semantik, Uploads/Autocompletes nicht tastaturbedienbar, Labels ohne `for`/`id`.

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| A11Y-1 | High | Berechtigungs-/Zugriffs-„Cards" `employee_create/edit` (`display:none`-Checkbox in `<div onclick>`) | **Tastatur-/SR-Nutzer koennen Rechte nicht setzen** | Echte fokussierbare Checkbox/Radio als Card stylen | M |
| A11Y-2 | High | Drag&Drop-Uploads `documents_inbox.blade.php:19`, `import_export.blade.php:54` (`<div onclick>`, Input `display:none`) | **Kernfunktion ohne Maus unbedienbar** | Echtes `<button>`/`<label for>`, Input visually-hidden aber fokussierbar | S |
| A11Y-3 | High | Kunden-Autocompletes (Email-Inbox/Compose/Contract-New) `<input>`+`<div onclick>` | Keine Combobox/Listbox-Semantik, keine Pfeiltasten → SR/Tastatur koennen keinen Kunden waehlen | ARIA-Combobox-Pattern oder natives `<datalist>` | M |
| A11Y-4 | High | Alle Modals (Admin+Portal) `<div style=display:none>` | Kein `role="dialog"`/`aria-modal`, kein Focus-Trap/-Move, kein ESC | Dialog-Rollen, Focus on open + trap + return, ESC/Backdrop-close (`<dialog>`) | M |
| A11Y-5 | High | Async-Status (Upload/Analyse/KI-Entwurf) ohne `aria-live` | Blinde Nutzer erhalten kein Feedback | `aria-live="polite"`/`role="status"` auf Status-/Flash-Container | S |
| A11Y-6 | High | Formular-Labels ohne `for`/`id` (systemisch Admin+Portal) | SR kuendigt Feldnamen nicht an; Label-Klick fokussiert nicht | `for`/`id`-Paare oder Input in `<label>` nesten | M |
| A11Y-7 | Medium | Row-Click-Navigation (`customers`/`tickets`) ohne Tastatur-Aequivalent | Zeilen nicht per Enter oeffenbar | Namens-/Subject-Zelle als echten `<a>` | S |
| A11Y-8 | Medium | Icon-only-Buttons nur mit `title`/Emoji (Announcements-Delete ohne beides) | Mehrdeutiger „Button" fuer SR/Touch | `aria-label` + `aria-hidden` auf Deko-Emoji | S |
| A11Y-9 | Medium | Chart-Canvases (`dashboard`/`reports`) ohne Text-Alternative | Daten fuer SR unzugaenglich | `role="img"`+Label oder Daten-Tabelle (Vorbild: Banner/Activity-Stats) | S |
| A11Y-10 | Medium | Tabs ohne `role="tab/tablist/tabpanel"`/`aria-selected`; Status teils nur per Farbe (Doc-Prioritaets-Punkt) | AT erkennt Tab-/Status-Beziehungen nicht | ARIA-Tab-Pattern; Text/Shape-Alternative fuer Farb-Status | M |

### RTL / i18n

| ID | Schwere | Ort | Auswirkung | Loesung | Aufw. |
|---|---|---|---|---|---|
| I18N-1 | High | `emails/customer_welcome.blade.php` | **Willkommens-Mail nur Deutsch** (kein `__()`/`$lang`), obwohl Kundschaft ueberwiegend arabischsprachig; Ticket-Mails lokalisieren bereits | AR-Variante/`$lang`-Branch analog Ticket-Mails | M |
| I18N-2 | High | Self-Service-Views (`profile/family/addresses/contacts/bank/contract_show/change_requests`) | Grossteils hartkodiertes Deutsch → AR-Nutzer sieht arabische Huelle um deutschen Inhalt | Strings in `__()` wrappen (viele Keys existieren bereits in `ar.json`); Model-Label-Maps uebersetzen | L |
| I18N-3 | Medium | `emails/document_request.blade.php` | Kundenmail nur Deutsch | Lokalisieren wie Ticket-Reply | S |
| I18N-4 | Medium | `legal/page.blade.php` | Rechtsseiten hartkodiert Deutsch, kein `dir=rtl`/Switch (oeffentlich, aus AR-faehigem Footer verlinkt) | `__()`/`dir` wie `services/*` oder Sprach-Switch | M |
| I18N-5 | Medium | Partner-Portal `partner/*`, `layouts/partner.blade.php` | Rohe DB-Enums an Partner (`booked`/`pending_review`/`kfz`); kein i18n; **keine Mobil-Navigation** (Sidebar off-screen ohne Hamburger) | Enum→DE-Label-Map; Portal-Topbar/Drawer uebernehmen | M |
| I18N-6 | Medium | Physische `left/right`-CSS statt logischer Properties (Modal-Close, Border-Akzente, `→`-Pfeile) | Unter `dir=rtl` falsche Kante/Richtung | `inset-inline-end`/`border-inline-start`/`padding-inline-start`; Pfeile via `$rtl` | M |
| I18N-7 | Low | `welcome.blade.php` (Laravel-Starter) verwaist im Repo | Peinlich falls verlinkt | Loeschen/ersetzen | S |

---

## 8. Konsolidierte Verbesserungsvorschlaege (auch ohne aktuelle „Fehler")

- **UX:** Empfaenger-Vorschau + Test-Send-Pflicht vor Massenversand; Undo-Fenster (Soft-Delete-Trash) fuer Kunden;
  Ungelesen-Indikatoren im internen Chat; Spalten-Mapping-Schritt beim CSV-Import; einheitliche Empty-States mit CTA;
  Kalender-Ansicht statt Terminliste; Ende>Beginn-Validierung.
- **UI:** Zentrales Utility-/Token-System (`.badge-*`, `.alert-*`, Status-Pills, Info-Boxen) statt Inline-Styles;
  einheitliche Kundenmail-Vorlage (Skill „dataviz" fuer Chart-Palette); Primary-CTA-Konvention (Smaragd).
- **Performance:** Redis fuer Cache/Session/Queue; `SystemSetting`-Cache; Activity-Insert deferred; Mailables queuen;
  Typeahead statt Voll-Selects; gezielte Indizes.
- **Security:** CSP (Report-Only → strikt), einheitliche Auth-Meldungen, Login-per-IP-Limiter, Passwort-Setzen-Link
  statt Klartextversand, Banner-URL-Allowlist, Audit-Log fuer Login/Export/Rollen.
- **Architektur:** `ContractService`/`CustomerRequest`/`ContractRequest`; `ScopesCustomerAccess`-Trait als Single
  Source of Truth; `ActivityLog::record()`-Helper; Larastan + Pint in CI; FormRequests statt inline-`validate`.
- **Neue Features mit Wert:** Vollstaendiges Partner-Portal (Mobil-Nav, i18n, echte Labels); Bounce-/Suppression-Management;
  Provider mit Zustell-Webhooks; PDF-Analyse fuer Fonds-Finanz/Provisionen (aktuell nur Mail-Text); Task↔Contract-Verknuepfung.
- **Automatisierung:** Automatische Backups + Pre-Migration-Dump; Cleanup stale Import-Dateien; Alerting auf
  Queue-Depth/`failed_jobs`/Mailbox-Sync-Fehler; Mail-Tester-Automat fuer Zustellbarkeit.
- **Skalierbarkeit:** Infra-State von der einen MySQL loesen (Redis); Suche via FULLTEXT/Scout statt `LIKE '%...%'`;
  schwere Data-Migrationen als gechunkte Jobs.
- **Reliability:** MySQL-CI-Leg; getesteter Restore-Drill; systemd fuer Worker/Scheduler; Mailbox-Watermark-Fix
  gegen Datenverlust.
- **Maintainability:** Statische Analyse, Return-Types, FormRequests, Entzerrung der `AdminController`-Gottklasse,
  `.env.example` vervollstaendigen, Config/Doku-Drift beseitigen.

---

## 9. Roadmap (nach Prioritaet & Aufwand)

### Phase 0 — Quick Wins (Tage, hoher Hebel)
1. **INT-2** Lexoffice `sendInvoice`/`getInvoicePdf` fixen (500 → funktioniert). *(S)*
2. **INT-1** `EscapeFormula` im CSV-Export. *(S)*
3. **UX-1/2/3/4/5/6** Mitarbeiter-/Provisions-/Import-/Edit-UI-Bugs (versteckte Rolle, fehlende Checkbox,
   JS-Error, kaputte Badge/Alert-Klassen, fehlende Fehleranzeige). *(je S)*
4. **UX-7** Confirm + Empfaengerzahl vor Massenversand. *(S)*
5. **PERF-2** Transaktionsmails `ShouldQueue`. *(S)*
6. **ARCH-3** Lexoffice-Fehler loggen + werfen; **ARCH-5** stille Catches loggen. *(S)*
7. **SEC-2** Inbox-Dokument-IDOR schliessen; **SEC-4** Reset-Throttle. *(S)*
8. **UI-10** Kontrast-Bug Weiterleitungsadresse; **UX-13** hartkodierte „1031 Kontakte" entfernen. *(S)*
9. **INT-7/8** Log-Level/-Rotation + Audit-Log fuer Login/Export/Rollen. *(S)*

### Phase 1 — Betrieb & Compliance (1–2 Wochen)
10. **DB-1** Backup-Strategie + Pre-Migration-Dump in `deploy.sh`. *(M, hoechste Prioritaet)*
11. **INT-3** DSGVO-Klaerung KI-Versand (AVV/TIA/EU-Endpoint/Minimierung). *(M, Betreiber)*
12. **DB-2** Klartext-`customer_family`-Nummern migrieren + droppen. *(M)*
13. **INT-4** Mailbox-Watermark-Fix (Datenverlust). *(M)*
14. **DB-5** Indizes auf Status/Type; **DB-6** MySQL-CI-Leg. *(S/M)*
15. **PERF-1/3** Activity-Insert deferred; Redis fuer Cache/Session/Queue. *(M)*
16. **INT-5/6/9/10** List-Unsubscribe, Bounce-/Suppression, Bulk-Send queuen, Worker-Monitoring. *(M)*
17. **UX-8** Passwort-Reset-Trio neu bauen (DE/AR, on-brand). *(M)*

### Phase 2 — Mittelfristig (Wochen)
18. **ARCH-2** `ScopesCustomerAccess`-Trait (Single Source of Truth). *(M)*
19. **DB-3/4** FK-Integritaet Partner; Cascade→`users` auf `SET NULL`. *(M)*
20. **A11Y-1..6** Tastatur/SR: Modals, Uploads, Autocompletes, Permission-Editor, Labels, Live-Regions. *(M–L)*
21. **UI-1/2/3** Palette-Bereinigung (Petrol-Gruen → Smaragd) in Layouts, Mails, Views. *(M)*
22. **I18N-1/2/3** Kundenmails + Self-Service-Views lokalisieren. *(M–L)*
23. **ARCH-6/7** FormRequests + Larastan/Pint in CI. *(M)*
24. **UI-4/5** Responsive-Grids/Tabellen fuer Mobil. *(M)*

### Phase 3 — Langfristig / strategisch
25. **ARCH-1** `AdminController`-Gottklasse entzerren (`ContractService` etc.). *(L)*
26. **DB-7/8/10/12** Datenmodell-Konsolidierung (Adressen, Enum-Politik, Blind-Index, Fahrzeuge). *(L)*
27. **Partner-Portal** vollstaendig (Mobil-Nav, i18n, Labels) + **PDF-Analyse** Fonds-Finanz/Provisionen. *(L)*
28. **Suche** FULLTEXT/Scout; **Monitoring/Alerting**-Ausbau (Sentry, Queue-Depth, Sync-Fehler). *(L)*

---

*Erstellt durch statische Analyse + manuelle Verifikation am 2026-07-19. Laufzeit-abhaengige Punkte
(Prod-`.env`-Treiber, echte Zeilenzahlen, Browser-Fokusverhalten) sind als solche markiert und sollten am
VPS/live bestaetigt werden. Nichts wurde am Code geaendert — dieser Bericht ist reine Dokumentation.*
