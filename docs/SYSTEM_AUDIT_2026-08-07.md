# System-Audit Dienstly24 Portal - 07.08.2026

Vollstaendiger, tiefer Funktions-, Sicherheits- und Betriebs-Check des
gesamten Systems (Beraterwelt, Kundenportal, Website, CLI/Jobs, Datenmodell).
Durchgefuehrt auf Branch `claude/system-comprehensive-audit-narr4l`
(Basis `origin/main` @ `0a6c40d`).

## Vorgehen (was wirklich geprueft wurde)

- **Testsuite**: `php artisan test` komplett (1484 Tests). Einmalig `npm run
  build`, weil ohne Vite-Manifest jede View-rendernde Route 500 wirft.
- **Statik**: `php -l` ueber alle PHP-Dateien (0 Syntaxfehler),
  `php artisan view:cache` (alle 182 Blades kompilieren), `route:list`
  (347 Routen), `migrate:fresh` auf SQLite, `schedule:list` (23 Jobs).
- **Laufzeit-UI**: Headless-Chromium (`/opt/pw-browsers`) besucht alle
  parameterlosen GET-Routen als Admin UND als Kunde - Status, Konsolenfehler,
  fehlgeschlagene Requests und externe Ressourcen protokolliert; Screenshots
  der Schluesselseiten (Login, Website DE/AR, Dashboard, Portal DE/AR-RTL,
  Medien, Provisionen, Werbung).
- **Tiefe Code-Analyse** in vier parallelen Straengen: Sicherheit/Autorisierung,
  Geschaeftsregeln gegen `CLAUDE.md`, Datenschicht/Jobs/Scheduler, Frontend/
  i18n/E-Mail - jeweils gegen das real migrierte Schema (80 Tabellen).

## Gesamturteil

Das System ist **ueberdurchschnittlich sauber gebaut** und in weiten Teilen
produktionsreif: kein `$request->all()`, keine interpolierten SQL-Strings,
konsistentes Portfolio-Scoping, private Disks fuer Belege, Policy-Schicht,
durchdachte Job-Idempotenz. Die Testsuite ist gruen. Die 13 dokumentierten
Geschaeftsregel-Gruppen aus `CLAUDE.md` sind im Code tatsaechlich erzwungen.

Der Audit hat dennoch **eine kritische und mehrere hohe Schwachstellen**
gefunden. Die eindeutig belegten, risikoarmen davon sind in DIESEM PR
behoben (mit Regressionstests); die groesseren, verhaltensaendernden oder
Migrations-pflichtigen Punkte sind unten als priorisierte Liste fuer den
Betreiber dokumentiert.

---

## In diesem PR behoben (mit Tests, Suite bleibt gruen)

### KRITISCH - Mitarbeiter-Deaktivierung war wirkungslos
`app/Http/Controllers/EmployeeController.php` (`toggleActive`)

`is_active` steht bewusst nicht in `User::$fillable`, `update(['is_active'=>...])`
verwarf die Aenderung daher **still**. Folge: der "Deaktivieren"-Button tat
nichts - das Konto behielt vollen Zugriff -, und Activity-Log + Erfolgsmeldung
behaupteten sogar das Gegenteil ("aktiviert" nach Klick auf Deaktivieren).
43 Stellen im Code filtern auf `is_active = true` (Ticket-Zuweisung,
Benachrichtigungen, Chat, Aufgaben) und behandelten das "deaktivierte" Konto
weiter als aktiv. Laufzeitbeleg: `update` liess DB-Wert auf `1`.
**Fix**: `forceFill(['is_active' => ...])->save()` (Konvention wie bei den
uebrigen System-Spalten, vgl. `PortalAccessService`). Damit stimmen auch
Log/Meldung. Test: `AuthorizationHardeningTest::test_deactivating_employee_actually_persists`.

### HOCH - Portfolio-weite Kundensuche fuer jede Staff-Rolle erreichbar
`routes/web.php:529`

`/admin/employees/customer-search` war die EINZIGE Route im `employees`-Block
ohne `role:admin,manager`. Ueber die Basis-Middleware der Admin-Gruppe konnte
jeder `employee`/`support` damit Name, Login-E-Mail, Kundennummer und volle
Anschrift JEDES Kunden abfragen - komplette Umgehung des Portfolio-Modells
(DSGVO Art. 32). **Fix**: `->middleware('role:admin,manager')` ergaenzt.
Tests: `AuthorizationHardeningTest` (employee/support 302, manager 200).

### HOCH - Stored XSS im Mitarbeiter-Bearbeiten-Formular
`resources/views/admin/employee_edit.blade.php:60`

`{!! json_encode($preselectedCustomers) !!}` ohne Flags: ein Kundenname mit
`</script>...` (Name kommt aus der oeffentlichen `/register`-Route bzw. OCR)
bricht aus dem `<script>`-Block aus und fuehrt Code in der Admin-Session aus;
die CSP mit `'unsafe-inline'` schuetzt hier nicht. **Fix**: `@json(...)`
(setzt `JSON_HEX_TAG|HEX_APOS|HEX_AMP|HEX_QUOT`, wie alle anderen 12 Stellen
im Repo).

### HOCH - Termine ohne Portfolio-Scope (Scope-Leak + IDOR)
`app/Http/Controllers/AppointmentController.php`

- Vergangenheitsliste (`index`) ohne `visibleCustomerIds()` -> fremde
  Termin-Titel/Zeiten/Kundennamen sichtbar.
- `store()`: `customer_id` nur `required` -> Termin + Timeline-Eintrag liess
  sich in eine FREMDE Kundenakte schreiben.
- `update()`: `findOrFail->update` ohne Eigentumspruefung -> jeder konnte
  jeden Termin per ID stornieren/abschliessen.
**Fix**: Scope auf `$past`, `exists:customers,id` + `canAccessCustomer` in
`store`, `canAccessCustomer` in `update`. 3 Tests.

### HOCH - Aufgaben: IDOR bei update/destroy + Namensleck
`app/Http/Controllers/TaskController.php`

- `destroy()` war ein blankes `findOrFail->delete()` -> jeder Staff konnte
  jede Aufgabe loeschen.
- `update()` (Verschieben/Schnell-Status) ohne Pruefung des bestehenden
  Datensatzes -> fremde Aufgaben umbuchbar/schliessbar.
- `$filterCustomer` (Deep-Link `?customer=<uuid>`) ohne `canAccessCustomer`
  -> fremder Kundenname im Chip sichtbar.
**Fix**: neue `authorizeTask()`-Pruefung (admin/manager immer; sonst nur
eigene - zugewiesen/erstellt - oder Kunde im eigenen Portfolio; deckungsgleich
mit der Sichtbarkeit in `index()`); `$filterCustomer` mit `canAccessCustomer`
gefiltert. 4 Tests.

### HOCH - Lexoffice-Seiten warfen 500 bei API-Fehler/ungueltigem Key
`app/Services/LexofficeService.php`

`->retry(2, 500)` nutzt den Default `throw: true`: nach dem letzten
Fehlversuch flog eine `RequestException`, BEVOR die Methoden `$r->successful()`
pruefen konnten. Jede Lexoffice-Seite (`/admin/lexoffice/contacts`,
`/invoices`) und die Provisions-Buchung antworteten damit bei ungueltigem Key
oder API-Ausfall mit HTTP 500 statt des vorgesehenen leeren Fallbacks. Im
Laufzeit-UI-Check reproduziert. **Fix**: `->retry(2, 500, throw: false)`.
Test: `LexofficeServiceFallbackTest`.

### MITTEL - Oeffentliche deutsche Seiten lieferten `<html lang="en">`
`app/Http/Middleware/SetLocale.php`

Der Docblock verspricht "Fallback Deutsch", der Code rief `setLocale()` aber
nur bei aktiver de/ar-Wahl. Erstbesucher blieben auf `APP_LOCALE=en`; neun
oeffentliche, indexierte Seiten (Login, Leistungsseiten, Support) gaben
`lang="en"` aus -> WCAG 3.1.1-Verstoss und ein dem `hreflang="de"`
widersprechendes Google-Signal. **Fix**: immer eine unterstuetzte Sprache
setzen, Default `de`.

### MITTEL - Deploy legt keinen storage-Symlink an
`scripts/deploy.sh`

`php artisan storage:link` fehlte. Auf frisch ausgechecktem `public/` liefern
die relativen `/storage/...`-URLs (Medienbibliothek, Leistungsseiten-Bilder,
Marken-Assets) einen 404 - ohne Spur im Deploy-Log. **Fix**: idempotenter
`storage:link || true`-Schritt ergaenzt. (Von zwei Audit-Straengen unabhaengig
gemeldet.)

### MITTEL - Unvalidierte Eingabe in MySQL-ENUM-Spalten
`EmployeeController::store` (`access_level`), `TarifrechnerController::storeAnnouncement`
(`priority`)

Genau das Muster, das schon einmal die `tasks.type`-Umstellung erzwang: ohne
Whitelist loest ein abweichender Wert unter MySQL strict einen 500 aus (die
jeweiligen update-Pfade validierten bereits). **Fix**: `in:full,limited` bzw.
`in:normal,important,urgent` ergaenzt.

---

## Offen - Empfehlung fuer den Betreiber (nicht in diesem PR)

Bewusst NICHT in diesem PR, weil verhaltensaendernd, Migrations-pflichtig oder
eine Produktentscheidung. Nach Prioritaet:

### Hoch

1. **Scheduler laeuft auf UTC statt Europe/Berlin** (`config/app.php`).
   Alle Zeiten in `routes/console.php` sind als deutsche Ortszeit KOMMENTIERT,
   feuern im Sommer aber +2h spaeter (z.B. `contracts:apply-endings` 05:15 ->
   07:15; Kunden-Einladungen/Geburtstagsmails entsprechend). Ausserdem zeigt
   die UI (ausser `BannerSocialPost`) rohe UTC-Zeiten an - Arbeitszeiten
   (`WorkSession`), DSGVO-Einwilligung (`tickets.consent_given_at`) usw.
   Empfehlung: `app.timezone` bzw. gezielt `->timezone('Europe/Berlin')` +
   Anzeige-Konvertierung vereinheitlichen. Verhaltensaendernd - bewusst
   Betreiber-Entscheidung.

2. **Fehlende Indizes auf heissen Foreign Keys**. `employee_customers`
   (`user_id`/`customer_id`) hat KEINEN Index, wird aber bei fast jedem
   Admin-Request ueber das Scoping gelesen; ebenso `users.role`
   (43 Rollen-Filter ueber eine Tabelle, die auch alle Kunden haelt),
   `documents.customer_id`, `tickets.customer_id`, `tasks.assigned_to/customer_id`,
   `ticket_messages.ticket_id`, diverse `customer_*`-Tabellen. Eine additive
   Index-Migration.

3. **`queue.php retry_after=360` < Job-Timeouts**. `SendCampaignJob` (600s)
   und `ImportCustomersJob` (1800s) ueberschreiten das Reservierungsfenster.
   Bei >1 Worker startet ein zweiter Worker den Campaign-Job neu ->
   Doppelversand an Kunden. Aktuell nur latent (systemd-Unit nutzt einen
   Worker). Empfehlung: `retry_after` > groesstem Timeout ODER
   `WithoutOverlapping`/`$tries` auf diese Jobs.

4. **`SendCampaignJob`/`ImportCustomersJob` ohne `failed()`-Pfad**.
   Kampagne bleibt bei einem Fehler dauerhaft in `sending` (kein
   Re-Dispatch); ein fehlgeschlagener Import loescht die CSV und meldet dem
   Betreiber nichts. Empfehlung: `failed()`-Handler + Status-Reset/Hinweis.

### Mittel

5. **Klartext-Passwoerter per E-Mail** (`EmployeeController::store` ->
   `employee_welcome.blade.php`; mit `MAIL_MAILER=log` zusaetzlich im Logfile).
   Empfehlung: Setz-Link statt Passwort (Muster wie `PortalAccessService`
   `setlink`). Ebenso Portal-Startpasswort = Geburtsdatum: Passwortwechsel
   beim ersten Login erzwingen (`portal_password_set_at` ist null).

6. **Instagram-Sofortversand kann ~2 Min blockieren** (`MetaPublisher`
   Container-Polling im Web-Request) -> nginx/PHP-Timeout. Empfehlung: den
   "Jetzt posten"-Weg in einen Job auslagern (der Cron-Weg ist ok).

7. **`LexofficeService` ohne explizites `->timeout()`** (30s-Default x retry
   ~90s in `CommissionController::book`). Empfehlung: `->timeout(20)` setzen
   (jetzt ohnehin `throw:false`).

8. **`AdminController::contracts()` unpaginiert** (`->latest()->get()` ueber
   die gesamte Vertragstabelle). Empfehlung: paginieren wie die anderen Listen.

9. **`bank`-Aenderung nur STANDARDMAESSIG Vier-Augen** (`ChangeProofPolicy`
   Modus `all` genehmigt auch Bankdaten automatisch). Konsistent mit dem
   woertlichen `CLAUDE.md`, widerspricht aber der absoluten Lesart. Falls die
   Regel absolut gemeint ist: Modus `all` fuer `type==='bank'` ausschliessen.

10. **`EmailInboxController::aiAccept/aiReject`** ohne die `canAccessCustomer`-
    Pruefung der Geschwister-Methoden (Wirkung gering: nur Kategorie, Gruppe
    `role:admin,manager,support`). **`EmailMarketingController`** ohne
    `can_send_emails`-Gate (Empfangskreis bleibt korrekt auf das eigene
    Portfolio beschraenkt). Konsistenzluecken.

### Niedrig / Hygiene

11. Frontend: Breeze-Reste `layouts/guest.blade.php` (laedt `fonts.bunny.net`
    - einzige externe Ressource ueberhaupt, nur ueber `confirm-password`/
    `verify-email` erreichbar) + `welcome.blade.php` loeschen; damit
    verschwinden auch 7 der 8 fehlenden `ar.json`-Keys. Verbleibend:
    `lang/ar/validation.php` fehlt (arabische Formularfehler auf Englisch),
    `support/thanks.blade.php:28` untuebersetzt, `/ar/kontakt` und
    `/ar/{legal}` fuehren nach DE, Portal ohne arabische Webfont,
    `services/show.blade.php`-Anfrageformular ohne `for`/`id`-Labels (BFSG).
12. Datenschicht-Hygiene: `Contract`-Datumsfelder ungecastet (kompensiert per
    `Carbon::parse`), diverse fehlende Casts, kleinere N+1
    (`employees.blade.php` count-in-loop, `customer_show` uploader),
    `ApplyContractEndings` ohne per-Datensatz-`try/catch`,
    `routes/console.php:75` hardcodiert `created_by=1/assigned_to=1`,
    `AppLayout`-Komponente rendert nicht existierende `layouts.app` (toter
    Code, 500 beim ersten `<x-app-layout>`).
13. `visibleCustomerIds()` pro Listen-Request 7-10x neu berechnet (keine
    Memoisierung) - auf dem nicht indexierten Pivot (Punkt 2) spuerbar.
14. Provisions-Idempotenz nur applikativ (`index(contract_id,type)` nicht
    unique) - theoretischer Doppelbuchungs-Race. `unique`-Teilindex erwaegen.
15. Doku-Widersprueche in `CLAUDE.md`: Social-Kanal-Loeschung (Phase 1 vs 2),
    OCR-Default (Code `false` vs Betriebszustand `true`).

---

## Verifiziert sauber (aktiv geprueft, kein Handlungsbedarf)

- **Geschaeftsregeln**: alle 13 Regel-Gruppen aus `CLAUDE.md` im Code erzwungen
  (Kundenloeschung max. 30 + Staff nie + Service-Guard `role==='customer'`;
  Kundennummern-Format; Provisions-Hook + Storno nur bei manueller Kuendigung/
  Loeschung + genau eine Neuvertrag-Provision; Vertrags-Dedup/Wechsel/
  Overlap-Guard; Stage-Merge; Portal-Einladung Geburtsdatum/Setlink;
  Medien-Slots RAW-Cache/private Originale/Alt DE+AR/SVG-Sanitizer; Meta
  Bearer-only/Berlin->UTC/ein Auto-Versuch/Budget-Cap; Kunden-Merge verlustfrei;
  Smart-Upload gratis-zuerst; Change-Requests private Disk/kein KI/Bank
  Vier-Augen; Zaehlerstand-Regeln; Aufgaben-Auto-Mail-Regeln; Website
  SEO/Redirect/Legal/Purge).
- **Routing**: kein `/admin`,`/portal`,`/partner`-Pfad ohne `auth`; alle
  sensiblen Gruppen korrekt rollenbeschraenkt; oeffentliche Routen gethrottelt.
- **Mass Assignment**: 0 Treffer `$request->all()`; alle Schreibpfade bauen
  explizite Arrays; Rollen-Eskalation ausgeschlossen.
- **SQLi / Path Traversal**: alle `*Raw`/`DB::raw` sind Konstanten; kein
  Download-Endpunkt nimmt nutzergelieferte Pfadsegmente; private Disk nicht
  unsigniert erreichbar.
- **Secrets**: `.env` nie committet, `.env.example` durchgehend leer, kein
  Key-Muster im Repo.
- **Modelle/Migrationen**: 0 fillable/Spalten-Mismatches (Laufzeit), 0 Cast-
  Keys ohne Spalte, 0 kaputte Relationen, 0 doppelte Spalten-Adds, alle FKs
  mit expliziter Loeschregel (`customers.user_id` CASCADE passt zur
  Loesch-Logik).
- **Scheduler/Queue**: alle 19 Command-Ziele existieren, `withoutOverlapping`
  auf Langlaeufern, `jobs/job_batches/failed_jobs` vorhanden.
- **Referenzen**: alle 341 benannten Routen und 306 View-Ziele aus Blades/PHP
  existieren (Ausnahme: tote `layouts.app`-Komponente, s.o.); keine
  unaufloesbaren `use App\...`.
- **E-Mail-Templates**: kein `<svg>`, tabellenbasiert, Inline-Styles,
  Willkommens-Mail ohne Logo-Bild; Favicon-Partial in allen 15 realen Layouts.
- **Website externe Ressourcen**: NULL (ausser dem o.g. Breeze-Rest, den die
  Website nie nutzt) - DSGVO-Kernanforderung erfuellt; Fonts lokal; hreflang/
  canonical/RTL korrekt; Farbschema "Smaragd & Gold" ohne verbotene Kuehltoene.
- **Laufzeit-UI**: Login, Website DE/AR, Admin-Dashboard, Kundenportal DE und
  Arabisch-RTL, Medien, Provisionen, Werbung rendern fehlerfrei; Anmeldung als
  Admin und als Kunde funktioniert; keine Konsolenfehler, keine externen
  Requests.

## Teststatus (Runde 1)

`php artisan test`: **gruen** (1484 + 13 neue Regressionstests). Neue Dateien:
`tests/Feature/AuthorizationHardeningTest.php`,
`tests/Feature/LexofficeServiceFallbackTest.php`.

---

# Runde 2 - Vertiefter Funktions-/Flow-Audit (07.08.2026)

Zweiter, tieferer Durchgang mit Schwerpunkt auf der Frage "funktioniert die
Website und haengt sie richtig am System": Lead-/Anfragen-Wege, Tickets,
Kundenservice, Kundenportal. Diesmal wurden die Tatebstaende zusaetzlich
**live durchgespielt** (Headless-Browser: Kontaktformular, Leistungs-Anfrage,
Hilfe-Formular echt abgeschickt) und in DB + Beraterwelt verifiziert.

## Ergebnis Runde 2

Die Website ist **korrekt mit dem System verdrahtet**: alle vier
Lead-Eingaenge (`/kontakt`, `/leistungen/{slug}/anfrage`, `/api/website-contact`,
`/api/website-inquiry`) legen ein `source=website`-Ticket an, loesen die
Team-Glocke aus und landen im richtigen Posteingang (`/admin/inquiries` fuer
Gast-Leads ohne Kundenbezug, `/admin/tickets` sobald ein Kunde per E-Mail
gematcht wird - **kein** `source`-Filter schliesst Website-Tickets aus). Das
Kundenportal ist **sauber gescopet** - der Portal-Audit fand **keine einzige
IDOR** ueber alle `{id}`-Routen; sensible Aenderungen laufen ausnahmslos ueber
den Nachweis-pflichtigen Change-Request-Flow.

Der Live-Test bestaetigte: Kontaktformular -> Ticket + Einwilligung + Danke;
Leistungs-Anfrage -> Ticket; Hilfe -> Ticket; alle drei mit Team-Glocke; alle
drei in der Beraterwelt sichtbar; Einwilligungs-Karte im Ticket sichtbar.

## In diesem PR behoben (Runde 2, mit Tests)

### DSGVO-BUG - Website-Lead-Loeschung liess Gast-Daten dauerhaft in der DB
`app/Console/Commands/PurgeWebsiteLeads.php`

`tickets:purge-website-leads` rief `$ticket->delete()` - `Ticket` nutzt aber
`SoftDeletes`, also blieb die Zeile mit `deleted_at` erhalten und trug
Gast-Name/E-Mail/Telefon/IP/Freitext **fuer immer** weiter, unsichtbar in
JEDER Oberflaeche (die Papierkorb-/Anfragen-Ansichten filtern sie weg). Der
Docblock verspricht ausdruecklich "nach der Frist darf nichts uebrig bleiben".
Der bestehende Test uebersah es, weil `Ticket::find()` Soft-Deletes ohnehin
ausblendet. **Fix**: `forceDelete()` + physische Anhang-Dateien loeschen.
Test: `WebsiteMergeTest::test_purge_hard_deletes_lead_leaving_no_soft_deleted_pii`.

### FLOW - Leistungs-Anfrage speicherte keinen Einwilligungsnachweis
`app/Http/Controllers/ServicePageController.php`

Das Formular verlangt `consent => accepted`, speicherte aber - anders als das
Kontaktformular - **kein** `consent_given_at/ip/text`. Leistungs-Leads hatten
also keinen DSGVO-Beleg. **Fix**: Einwilligungsprotokoll (Zeitpunkt/IP/Text in
der gezeigten Sprache) wie beim Kontaktformular. Test: `ServiceInquiryFlowTest`.

### FLOW - Leistungs-Anfrage schickte dem Interessenten keine Bestaetigung
`app/Http/Controllers/ServicePageController.php`

Nur die Team-Mail ging raus; der Anfragende bekam - anders als bei `/kontakt` -
**keine** Eingangsbestaetigung. **Fix**: `WebsiteInquiryConfirmationMail`
(DE/AR) an die Lead-E-Mail. Test: `ServiceInquiryFlowTest`.

### FLOW - Einwilligungsnachweis war gespeichert, aber fuer das Team unsichtbar
`resources/views/admin/ticket_show.blade.php`, `app/Models/Ticket.php`

Die `consent_*`-Spalten wurden vom Kontaktformular befuellt, aber **keine**
Admin-Ansicht zeigte sie - die Art.-7-Evidenz war unsichtbar, obwohl die
Datenschutzseite ihre Speicherung verspricht. **Fix**: Einwilligungs-Karte im
Ticket (Zeitpunkt/IP/Text), `consent_given_at`-Cast + `consent_*` in
`$fillable`. Live im Ticket verifiziert.

### FLOW/Haertung - Portal-Dokumentanzeige ohne nosniff
`app/Http/Controllers/PortalController.php`

`documentView` lieferte inline ohne `X-Content-Type-Options: nosniff` (der
Schwester-Weg `viewAttachment` setzt ihn). **Fix**: Header ergaenzt.

### Robustheit - `tickets.type` von ENUM auf String
`database/migrations/2026_08_07_120000_change_tickets_type_to_string.php`

Dieselbe latente Falle wie bei `tasks.type` (die schon einmal zuschlug) und
`tickets.status`: `tickets.type` war noch ein MySQL-ENUM. Ein neuer Typ-Wert
haette auf MySQL still einen 500 geworfen, waehrend die SQLite-Tests gruen
bleiben. `Ticket::TYPES` + `in:`-Validierung sind die Quelle der Wahrheit.
**Fix**: additive Migration ENUM -> `string(30)`, Muster wie beim
Status/`tasks.type`-Fix.

### Kosmetik - Anfragen-Liste beschriftete Hilfe-Leads als "E-Mail"
`resources/views/admin/inquiries.blade.php`

Quelle jetzt korrekt je Wert (🌐 Website / 🆘 Hilfe / 📧 E-Mail).

## Offen aus Runde 2 - Empfehlung fuer den Betreiber

1. **DSGVO-Aufbewahrung (mittel):** nur `source=website`-Gast-Leads haben eine
   Loeschfrist. Gast-Tickets aus `hilfe-formular` und `email` sammeln sich
   unbegrenzt mit Gast-PII an. Empfehlung: analoge Purge-Frist (Frist ist eine
   Betreiber-Entscheidung, daher hier nicht gesetzt).
2. **Support-Rolle ohne Glocke bei Gast-Leads (niedrig):** `TicketNotifier`
   benachrichtigt bei neuen Tickets nur admin/manager. Support-Nutzer sehen
   `/admin/inquiries`, bekommen aber keine Glocke fuer Hilfe-/E-Mail-Gast-Leads
   (Website-Leads haben ersatzweise die Team-Mail). `storeManual` (manuelle
   Anfrage) loest bewusst gar keine Glocke aus.
3. **Kein "Lead -> Kunde uebernehmen" (niedrig):** Website-/Hilfe-Gast-Leads
   werden nicht automatisch mit einem spaeter angelegten Kunden verknuepft
   (nur E-Mail-Quelle wird beim Bestaetigen rueckverknuepft). Ein
   Uebernehmen-Button in `/admin/inquiries` waere hilfreich.
4. **Portal-Erstlogin (niedrig, Design):** Start-Passwort = Geburtsdatum, kein
   erzwungener Wechsel beim ersten Login (bereits in Runde 1 vermerkt).
5. **Mini-UX:** Dashboard-"Vollstaendigkeit" prueft `health_insurance_company`,
   das Profilformular bietet aber nur die KV-Nummer - der Hinweis "Krankenkasse
   fehlt" ist fuer den Kunden nicht direkt ausraeumbar.

## Verifiziert sauber (Runde 2)

- **Kundenportal**: keine IDOR ueber alle `{id}`-Routen; jeder Datensatz wird
  gegen den eigenen `Customer` aufgeloest; alle Uploads auf privater Disk;
  sensible Aenderungen nur ueber Change-Request + Nachweis; identische
  Domain-Schicht wie die Beraterwelt (gleiche `Contract::displayStatus()`,
  `ChangeProofPolicy`) -> Kunde und Team sehen konsistenten Stand.
- **Ticket-Lebenszyklus**: Erstellung -> Zuweisung -> Antwort -> Schluss mit
  konsistenten Glocken; interne Notizen nie fuer Kunden sichtbar
  (`is_internal=false` beim Antworten strukturell erzwungen); Anhaenge privat;
  Rollen-Gating bei Loeschen/Endgueltig-Loeschen passt zur DSGVO-Regel;
  `auto-close` ueberspringt wartende Tickets; Magic-Login signiert + rollen-
  gehaertet.
- **CSRF**: nur die zwei API-Endpunkte ausgenommen; die zwei In-Portal-Formulare
  behalten CSRF + Throttle; `/api/website-inquiry` faellt bei fehlendem Token
  geschlossen (401); `/api/website-contact` verwirft Spam mit HTTP 200 (nie 500).
- **Bestaetigungs-Mails** zweisprachig, tabellenbasiert, ohne SVG, korrekte
  Empfaenger.

## Teststatus (Runde 2)

`php artisan test`: **gruen** - 1489 Tests, 0 Fehler (+4 neue Tests gegenueber
Runde 1). Neue Datei: `tests/Feature/ServiceInquiryFlowTest.php`; erweitert:
`tests/Feature/WebsiteMergeTest.php`.

---

# Runde 3 - Tiefenpruefung der Fachlogik + Live-Betrieb (07.08.2026)

Dritte Runde, Schwerpunkt auf den bis dahin nur gestreiften, geschaeftskritischen
Subsystemen: Dokumenten-Analyse/Parser, Vertrags-/Provisions-Lebenszyklus,
Mailbox/Meta-Integration. Zusaetzlich ein **Live-Betriebstest**: alle 19
geplanten Kommandos real ausgefuehrt, alle Detail-/Bearbeiten-/Berichts-Seiten
mit echten IDs aufgerufen, der Geld-Pfad (Provisionsbuchung/Storno) real
durchgespielt.

## Live-Betrieb: sauber

- Volle Testsuite gruen; **alle 19 Scheduler-Kommandos** ohne Fehler; **jede**
  Admin-Detail-/Bearbeiten-/Berichts-/Statistik-Seite liefert 200 (kein 500);
  Vertragsanlage -> Provisionshaken bucht genau eine Provision mit korrektem
  Empfaenger/Betrag, idempotent, Storno bei manueller Kuendigung.

## In diesem PR behoben (Runde 3, mit Tests)

### P1 (Geld) - Neukunden-Ein-Klick buchte Provisionen DOPPELT
`app/Http/Controllers/ReportController.php`, `resources/views/admin/reports_neukunden.blade.php`

Jeder Vertrag bucht automatisch eine Neuvertrag-Provision (Contract::created)
- OHNE Periode. Die "bereits erfasst"-Erkennung im Neukunden-Bericht filterte
aber auf `period_from/period_to` und sah die automatischen Buchungen daher NIE
("0 bereits erfasst"). Ein Klick auf "Erfassen" legte dann eine ZWEITE
Provision on top an -> Doppelverguetung, und der Monatsbericht summierte beide.
**Fix**: Erkennung ueber die Neukunden des Zeitraums (statt Periodendatum), und
die Ein-Klick-Nachbuchung entfaellt, sobald automatisch gebucht wurde (Hinweis
+ Link zur Provisions-Seite; manueller Fallback nur, wenn noch nichts gebucht
ist). Tests: `NewCustomerReportTest`.

### P1 (Datenverlust) - "R+V" wurde mit fremden Versicherern verwechselt
`app/Models/Contract.php` (`insurersLookAlike`)

`normalizeInsurerName` entfernt das Branchenwort 'v', wodurch **"R+V" auf "r"**
schrumpft; die anschliessende Teilstring-Pruefung machte "r" zum Treffer fast
jedes Namens ("r" in "geneRali"). Folge: eine Generali-Kfz-Police fuer dasselbe
Kennzeichen galt als GLEICHER Versicherer -> der R+V-Bestandsvertrag wurde
ueberschrieben statt ein Wechsel als eigener Vertrag angelegt (Bestand +
Neugeschaefts-Provision verloren). **Fix**: Teilstring-Treffer nur bei
tragfaehiger Kernlaenge (>= 3 Zeichen); kurze Kerne verlangen exakte
Gleichheit. Tests: `ContractDeduplicationTest`.

### P1 (Datenverlust) - Energie-Anbieterwechsel ueberschrieb den Altvertrag
`app/Services/DocumentIntake/DocumentIntakeService.php` (`findExistingContractByIdentity`, Energie-Zweig)

MaLo-ID/Zaehlernummer bezeichnen den physischen Zaehler, nicht den Versorger -
beim Wechsel bleiben sie gleich. Der MaLo-/Zaehler-Treffer hatte (anders als der
Fahrzeug-Zweig und die Kundennummer) KEINEN Versorger-Abgleich, sodass die
E.ON-Bestaetigung mit gleicher MaLo den LichtBlick-Vertrag ueberschrieb
(Bestand + Provision weg, kein Wechsel). **Fix**: `insurersLookAlike`-Guard auch
auf MaLo/Zaehler (gleiche Regel wie Fahrzeug/Kundennummer); der
Auftrag->Vertrag-Abgleich desselben Versorgers laeuft ohnehin ueber
`findApplicationContractForConfirmation` und bleibt unberuehrt. Tests:
`ContractDeduplicationTest`.

### P1 (Betrieb) - Eine "Gift"-Mail legte den GESAMTEN Postfach-Sync lahm
`app/Services/Mailbox/MailboxSyncService.php`

Die Kopf-Felder sind `varchar(255)`; unter MySQL strict wirft ein Betreff/
Anzeigename > 255 Zeichen "Data too long". Da die Nachrichten-Verarbeitung nicht
gekapselt war, brach der ganze Lauf ab, `last_synced_at` rueckte nie vor und der
2-Minuten-Job holte dieselbe Mail endlos erneut (Dauerausfall aller Postfaecher,
per langem Betreff ausloesbar). **Fix**: Kopf-Felder auf Spaltenbreite kuerzen,
Wasserstandsmarke je gesehener Mail ZUERST vorruecken, jede Nachricht in
try/catch (ueberspringen statt Abbruch), zusaetzlich je-Postfach-Guard in
`syncAllActive`. Tests: `MailboxSyncServiceTest`.

### P1/P2 (Datenverlust) - Banner-Loeschen zerstoerte veroeffentlichte Social-Links
`app/Http/Controllers/BannerController.php`

`Banner` hat kein SoftDeletes; `delete()` kaskadierte auf
`banner_social_posts` -> `banner_social_channels` und vernichtete `short_code`,
`external_post_id` und Klickzahlen AUCH bei bereits auf Facebook/Instagram
veroeffentlichten Beitraegen -> deren Live-`/s/{code}`-Kurzlinks liefen ins
404, Statistik weg (verletzt "veroeffentlichte Beitraege bleiben dauerhaft
klickbar"). **Fix**: Loeschen wird abgelehnt, sobald veroeffentlichte Kanaele
existieren, mit Hinweis auf Deaktivieren statt Loeschen. Tests:
`BannerSocialPublishingTest`.

## Offen aus Runde 3 - Empfehlung fuer den Betreiber

Bestaetigte, aber bewusst NICHT hier behobene Punkte (verhaltensaendernd,
Migration oder groesseres Redesign):

1. **`tickets.type`-Analogie fuer weitere ENUMs**: bereits in Runde 1/2
   adressiert; keine offene ENUM-Falle mehr in den gepruueften Pfaden.
2. **Gmail-Provider Seitennavigation (P2)**: `messages.list` liefert die 50
   NEUESTEN und ignoriert `nextPageToken`, waehrend die Marke vorwaerts wandert
   -> bei > 50 Mails/Fenster koennen aeltere Gmail-Mails verloren gehen. Fix
   braucht aufsteigende Sortierung/Paginierung im Gmail-Provider.
3. **Nicht-idempotenter manueller Social-Retry (P2/P3)**: geht der API-Response
   verloren (Timeout), kann "Erneut versuchen" doppelt posten (der Auto-Weg ist
   per `auto_attempted_at` geschuetzt). Idempotenz-Schluessel noetig; zusaetzlich
   IG-Sofortversand besser in einen Job auslagern (Runde 1, Punkt 6).
4. **`contracts:apply-endings` ohne per-Datensatz-try/catch**: ein einzelner
   fehlerhafter Vertrag stoppt den Tageslauf fuer die restlichen (Muster wie
   `SendTaskAutoEmails::process`).
5. **`CommissionController::book` nicht idempotent** gegen verlorene
   Lexoffice-Antwort (Doppelbeleg moeglich) - Idempotenz/Transaktion um den
   externen Aufruf.
6. **VehicleOverlapGuard VIN-vs-Kennzeichen-Asymmetrie**: ein Fahrzeug, das
   einmal nur mit FIN, einmal nur mit Kennzeichen erfasst ist, wird als zwei
   Fahrzeuge behandelt -> Doppelversicherung nicht erkannt.
7. **DSGVO-Aufbewahrung** fuer `hilfe-formular`/`email`-Gast-Leads;
   `emails:prune-unmatched` behaelt `suggested`- und haengengebliebene
   Nachrichten unbegrenzt.
8. **Meldebestaetigung "Vornamen"-Praefix**, LichtBlick-IBAN ohne
   Kontoinhaber-Pruefung, Haushalts-Verknuepfung ohne Hausnummer - Parser-
   Feinheiten mit begrenztem Radius (Details im Parser-Audit).
9. **Neukunden-Leaderboard** zeigt Mitarbeitern das Jahresbeitrags-Volumen
   (Provisionsbetraege sind korrekt verborgen) - je nach Auslegung "Betrag".

## Verifiziert sauber (Runde 3, adversarisch geprueft)

- **Meta/Token**: Token nur als Bearer-Header (nie Query/Body/Log); IG-Caption
  > 2200 wird abgelehnt; ein Auto-Versuch je Kanal; neue Anzeigen PAUSED;
  Budget-Deckel in Controller UND Service (Obergrenze 10000); EUR->Cent nur im
  Service; Insights-Dashboard liest nur Cache; `/s/{code}` ohne Open-Redirect,
  atomarer Klickzaehler, entkoppelt von `Banner::current()`.
- **Dokumenten-Intake**: Gesundheitskarte Instituts- vs Versichertennummer;
  Auftrags-/eVB-/Vorgangsnummern nie als Vertragsnummer; Brutto statt Netto;
  Deckung aus dem richtigen Feld; MRZ mit Pruefziffern; IBAN-Kontoinhaber-Gate
  (ausser LichtBlick, s.o.); `ContractRevisionRecorder` ueberschreibt nie mit
  Leerwerten; atomare `whereNull`-Zuordnung; Duplikat-Wiederverwendung erst
  nach den Vorlagen-Parsern.
- **Provisionen**: genau eine Neuvertrag-Buchung je Vertrag; natuerliche Enden
  (Wechsel-Kette, Tages-Job) buchen KEIN Storno (`endsWithoutStorno` ueberlebt
  den Recorder); Storno idempotent; Kunden-Purge kaskadiert ohne Storno;
  Werber-Exklusivitaet; Wechsel-Datum-Mathematik korrekt (halb-offene
  Intervalle).

## Teststatus (Runde 3)

`php artisan test`: **gruen** - 0 Fehler (+10 neue Regressionstests gegenueber
Runde 2). Erweitert: `NewCustomerReportTest`, `ContractDeduplicationTest`,
`MailboxSyncServiceTest`, `BannerSocialPublishingTest`.
