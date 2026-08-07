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

---

# Runde 4 - Nebenlaeufigkeit, Merge, Auth, Verifikation (07.08.2026)

Vierte Runde, Schwerpunkt auf bisher nicht tief gepruueften Systemen
(Kunden-Merge/Matching/Import, Auth-Flows, Nachweis-Verifikation, Zaehler,
Partner-Portal) UND einer geziel­ten **Nebenlaeufigkeits-/Idempotenz-Analyse**
(check-then-act-Races, fehlende UNIQUE-Indizes, nicht-atomare Updates gegen
Produktion = MySQL). Larastan war als statischer Analysator vorgesehen, liess
sich aber wegen GitHub-Auth ueber den Proxy nicht installieren - ersetzt durch
adversarische Tiefenpruefung.

## In diesem PR behoben (Runde 4, mit Tests)

### P1 (Datenverlust, irreversibel) - "Alle sicheren zusammenfuehren" verschmolz VERSCHIEDENE Personen
`app/Services/Matching/DuplicateDetectionService.php`, `app/Http/Controllers/AdminController.php`

Ein GEMEINSAMES Konto (IBAN) oder eine gemeinsame Vertragsnummer hob den
Konfidenz-Score auf >= 85 - unabhaengig von Name/Geburtsdatum. Der Ein-Klick
"Alle sicheren zusammenfuehren" (Schwelle 40) verschmolz solche Paare
unbeaufsichtigt und UNWIDERRUFLICH in den aeltesten Datensatz. Ein Ehepaar
mit gemeinsamem Konto (verschiedene Nachnamen) oder Vater/Sohn (gleicher Name,
anderes Geburtsdatum) wurde so zu einer Person zusammengefuehrt - eine echte
Kundenakte samt Vertraegen/Dokumenten verschwand. **Fix**: neue
`hasIdentityConflict()`-Pruefung schliesst Paare mit widersprechendem
Geburtsdatum oder ohne gemeinsames Namenswort vom UNBEAUFSICHTIGTEN Merge aus
(manuelle Einzelpruefung bleibt moeglich); die Button-Zahl folgt. Tests:
`DuplicateBulkMergeTest`.

### P1 (Geld) - Doppelte Neuvertrag-Provision unter Nebenlaeufigkeit
`app/Services/Provision/ContractProvisionService.php`

Die Idempotenz "genau eine Neuvertrag-Provision je Vertrag" war ein
check-then-act (`exists()` dann `create()`) OHNE UNIQUE-Index und ohne Sperre.
Zwei gleichzeitige Ausloeser (Formular-Doppelklick, created-Hook vs.
nachtraegliche Werber-Buchung) konnten beide den Check bestehen und den Werber
DOPPELT verguenten. **Fix**: `DB::transaction` + `lockForUpdate` auf den Vertrag
serialisiert die Buchung (ein UNIQUE-Index scheidet aus, weil Boni/Storno
legitim mehrfach je Vertrag vorkommen). Tests: bestehende `ProvisionManagementTest`
gruen.

### P2 (Verfuegbarkeit) - Kundennummern-Kollision warf 500 statt neu zu ziehen
`app/Models/Customer.php`

`CustomerNumberGenerator` zieht die Nummer per check-then-act; die
`customers.customer_number`-UNIQUE verhindert Duplikate, aber der Verlierer
zweier gleichzeitiger Anlagen bekam eine unbehandelte `QueryException` (500 /
Import-Fehlerzeile). **Fix**: `Customer::save()` faengt die
`UniqueConstraintViolationException` und zieht die Nummer EINMAL neu - Muster
wie `Ticket::save`. (Von zwei Audit-Straengen unabhaengig gemeldet.)

### P2 (Doppel-Post) - Geplanter Social-Versand vs. "Jetzt posten"
`app/Console/Commands/PublishScheduledSocialPosts.php`, `app/Http/Controllers/BannerSocialController.php`

Der geplante Lauf beanspruchte den Kanal per read-then-write
(`forceFill(auto_attempted_at)->save()`); ein gleichzeitiger "Jetzt per API
posten"-Klick konnte denselben Beitrag doppelt veroeffentlichen. **Fix**:
atomarer Claim (`whereNull(auto_attempted_at)->update(...)`, nur bei
affected==1 weiter); `publishNow` lehnt einen bereits per API veroeffentlichten
Kanal (external_post_id) ab.

### P2 (Auth) - Passwort-Bestaetigung ohne Throttle
`routes/auth.php`

`POST confirm-password` hatte - anders als Login/Reset - kein Rate-Limit. Eine
gekaperte Session (z.B. ueber einen geleakten Magic-Login-Link) konnte das echte
Passwort unbegrenzt raten. **Fix**: `throttle:6,1`.

### P2 (Verifikation) - Echte Nachweise scheiterten an Zeilenumbruch
`app/Services/ChangeRequest/ChangeProofVerifier.php`

`squash()` entfernte nur Leerzeichen, nicht Zeilenumbrueche - eine ueber zwei
Zeilen umbrochene IBAN/Name im OCR/PDF-Text passte nie auf die einzeilige Nadel,
sodass ein KORREKTER Nachweis faelschlich als "mismatch" galt (fail-closed, aber
die Gratis-Pruefung war auf mehrzeiligen Layouts praktisch tot). **Fix**: alle
Whitespaces (inkl. `\R`) entfernen. (Sicherheits-Gegenprobe: die OCR-Toleranz
kann weiterhin KEINE andere IBAN akzeptieren - nur Buchstabe->Ziffer, nie
Ziffer->Ziffer.)

### P2/P3 (Resilienz) - geplante Kommandos gehaertet
`routes/console.php`

`withoutOverlapping()` fuer `tickets:auto-close`, `health:apply-due-switches`,
`contracts:apply-endings` (manueller Lauf darf den geplanten nicht doppeln).
Die "Kind wird 15"-Closure nutzte fest `created_by/assigned_to = 1` (FK-Fehler,
falls User 1 fehlt -> Kinder wegen Ein-Tages-Fenster dauerhaft uebersprungen):
jetzt echter Admin/Manager, per-Datensatz-try/catch und Tages-Idempotenz.

### P3 (Auth) - is_active=NULL konsistent als aktiv
`app/Http/Requests/Auth/LoginRequest.php`

Das Passwort-Login wertete `is_active=NULL` (Alt-/Importkonten) als deaktiviert,
waehrend der Rest der App NULL als aktiv fuehrt. **Fix**: isset-Angleichung.

## Offen aus Runde 4 - Empfehlung fuer den Betreiber

Bestaetigt, aber bewusst NICHT hier (Produktentscheidung, Migration mit
Bestandsdaten-Risiko oder groesseres Redesign):

1. **UNIQUE-Index auf `customers.user_id`**: verhindert doppelte Kundenakten aus
   dem gleichzeitigen `firstOrCreate` im Portal - braucht vorab eine
   Bestands-Deduplizierung (Merge), daher kein blindes Migrations-Script.
2. **Import-Dubletten ohne Geburtsdatum**: der 70-Punkte-Tier wird ohne
   `birth_date` nie erreicht (Max 65) -> Wiederimport legt stille Doppel-Kunden
   an bzw. wirft bei E-Mail-Kollision Fehlerzeilen; Preview/Commit weichen ab.
3. **Nachweis-Verifikation zu grosszuegig bei Auto-Freigabe**: Name/Adresse per
   Teilstring/Nachname-Substring koennen falsch matchen; bei Default
   Auto-Freigabe (Adresse/Name) mutieren so Identitaets-/Meldedaten ohne
   Vier-Augen. Empfehlung: wortgenaue/zeilenverankerte Treffer, Namens-Tie
   verpflichtend, oder Auto-Freigabe-Default auf "aus". (Bank bleibt korrekt
   Vier-Augen.)
4. **Passwort-Reset-Nutzer-Enumeration**: unterschiedliche Antworten fuer
   bekannte/unbekannte E-Mail. Empfehlung: eine generische Antwort.
5. **Magic-Login 90 Tage, mehrfach nutzbar**: nach dem Setzen eines echten
   Passworts nicht invalidiert. Empfehlung: Einmal-Nutzung/Invalidierung.
6. **`SendCampaignJob`**: `timeout(600) > retry_after(360)` + nicht idempotent
   (kein `failed()`, kein Skip bereits gesendeter Empfaenger) -> Doppelversand,
   sobald ein zweiter Worker laeuft. Latent unter Ein-Worker-Deploy.
7. **`CommissionController::book`**: nicht idempotent gegen verlorene
   Lexoffice-Antwort (Doppelbeleg). **UNIQUE-Indizes** auf `meter_readings`
   und `change_notifications` (aktuell check-then-act).
8. **`User.$fillable` enthaelt `role`/`access_level`/`can_*`**: aktuell nirgends
   ausnutzbar (alle Schreibpfade hardcoden die Rolle), aber ein stehender
   Footgun - `role`/`access_level` besser `$guarded`.
9. **`VehicleOverlapGuard` VIN-vs-Kennzeichen-Asymmetrie** (Runde 3), Meter
   Rueckdatierungs-Flag, DSGVO-Aufbewahrung Hilfe-/E-Mail-Gast-Leads,
   Gmail-Provider-Paginierung.

## Verifiziert sauber (Runde 4, adversarisch)

- **Kunden-Merge**: keine FK-Tabelle mit customer_id uebersehen (schema-getrieben
  + Sonderfaelle Relationship/Portal-User); Opt-out bleibt erhalten;
  `deleteCollidingDuplicateRows` verhindert den Remap-500; keine doppelten
  Kundennummern.
- **Partner-Portal**: keine IDOR (partner-gebunden), Partner sehen nur eigene
  Kunden/Provisionen; Deaktivierung wirkt auf bestehende Sessions; keine
  Rollen-Eskalation ueber Registrierung/Import (Rolle hart `customer`).
- **Nachweis**: nie KI-Aufruf, kein Rohtext gespeichert, Bank bleibt
  Vier-Augen, OCR-Toleranz akzeptiert keine ANDERE IBAN; Zaehler-Mathematik
  ohne Division durch 0, Hochrechnung erst ab 14 Tagen, Bezug/Einspeisung
  getrennt, Portal-Zaehler streng auf den eigenen Vertrag gescopet.
- **Race-sicher bereits im Code**: Ticketnummern (UNIQUE + Retry),
  `AnalyzeDocumentJob` (atomarer Claim), Dokument-Zuordnung (whereNull-Claim),
  Erneuerungs-Erinnerungen (UNIQUE + Claim), atomare Zaehler (`increment`),
  `firstOrCreate` auf UNIQUE-Kombis, `withoutOverlapping` auf den Langlaeufern.

## Teststatus (Runde 4)

`php artisan test`: **gruen** - 0 Fehler (+4 neue Regressionstests). Erweitert:
`DuplicateBulkMergeTest`, `Auth/AuthenticationTest`; unveraendert gruen:
`ProvisionManagementTest`, `BannerSocialPublishingTest`, `ChangeRequestVerificationTest`.

---

# Runde 5 - Vollsimulation + letzte Subsysteme (07.08.2026)

Fuenfte Runde: **Live-Simulation ALLER Kern-Workflows** gegen die echte
Modell-/Service-Schicht plus Tiefenpruefung der letzten nicht auditierten
Subsysteme (Aktivitaetserfassung, Benachrichtigungen, Medien, E-Mail-
Marketing, Backup, Ankuendigungen).

## Vollsimulation: 23/23 gruen

Jeder Workflow wurde end-to-end durchgespielt und das Ergebnis geprueft:
Vertrag->Provision(Betrag/Empfaenger)->Kuendigung->Storno; Versicherer- UND
Versorger-Wechsel legen je einen eigenen Vertrag an (Bestand erhalten);
Ticket Anlage->Zuweisung->Antwort->Kundenantwort(reopen)->Schluss inkl.
Glocken, interne Notiz nie kundensichtbar; Merge erhaelt beide Vertraege;
Portal-Einladung (birthdate/setlink); Zaehlerstand->Verbrauch; Bank-Aenderung
bleibt Vier-Augen; erledigte Aufgabe versendet nie. Zusaetzlich: alle 18
Admin- und 6 oeffentlichen Seiten liefern 200 (kein 500).

## In diesem PR behoben (Runde 5, mit Tests)

### P2 (Zustellbarkeit/DSGVO) - Ein-Klick-Abmeldung (RFC 8058) war tot
`app/Http/Controllers/UnsubscribeController.php`, `routes/web.php`, `bootstrap/app.php`

Die Marketing-Mail setzt `List-Unsubscribe-Post: List-Unsubscribe=One-Click`,
aber `/abmelden/{token}` existierte nur als GET und war nicht CSRF-ausgenommen.
Der native "Abmelden"-Button von Gmail/Yahoo/Apple sendet einen Server-POST ->
405/419 -> der Kunde wurde NICHT abgemeldet, obwohl der Header genau das
verspricht (und grosse Anbieter das von Massenversendern verlangen). **Fix**:
POST-Route + `oneClick()` (200) + CSRF-Ausnahme `abmelden/*`. Test:
`EmailMarketingImprovementsTest`.

### P2 (Go-Live-Ausfall) - kein Vertrauens-Proxy -> HTTPS-Redirect-Schleife
`bootstrap/app.php`

`RedirectWebsiteHost` leitet bei `!secure()` auf https um; ohne
`trustProxies` ignoriert Laravel aber `X-Forwarded-Proto`. Beim Cloudflare-
Cutover ueber HTTP entsteht eine 301-Endlosschleife (gesamte Website down);
zudem sind alle IPs (ActivityLog/WorkSession/throttle) die Proxy-IP. **Fix**:
`trustProxies(at: '*', headers: X-Forwarded-*)`.

### P3 (Timing) - geplante Kampagnen feuerten 1-2h zu spaet
`app/Http/Controllers/EmailMarketingController.php`, View

`scheduled_for` wurde als UTC gespeichert, obwohl der Betreiber deutsche
Ortszeit eingibt (dieselbe Falle, die die Social-Posts via OPERATOR_TZ schon
loesen). **Fix**: Eingabe als Europe/Berlin -> UTC, Anzeige zurueckgerechnet,
Vergangenheitspruefung nach der Umrechnung. Test: `EmailMarketingImprovementsTest`.

### P3 (Scope) - geloeschter Ersteller -> Kampagne an ALLE Kunden
`app/Jobs/SendCampaignJob.php`

War der Kampagnen-Ersteller geloescht (created_by genullt), fiel der
Empfaengerkreis auf ALLE marktbaren Kunden zurueck statt auf das Portfolio.
**Fix**: kein aufloesbarer Ersteller -> leere Liste (kein Massenversand).

### P3 (Haertung) - diverse
- `InternalNotificationController`: Badge-Zahl wird jetzt wie die Liste
  Portfolio-gescopet (Zahl passt zur Anzeige).
- `scripts/backup.sh`: `tar`-Exit 1 ("file changed as we read it") wird
  toleriert -> Backup bricht bei laufenden Uploads nicht mehr vor .env-Kopie/
  Rotation ab.
- `TarifrechnerController`: `expires_at` validiert (`nullable|date`, 500-Schutz).
- `ImageVariantGenerator`: Pixelmasse-Deckel (50 MP) VOR dem Dekodieren
  (Dekompressions-Bomben-Schutz gegen OOM-500).

## Offen aus Runde 5 - Empfehlung fuer den Betreiber

1. **Aktivitaets-/Kampagnen-Zeitzonen weiter vereinheitlichen**: die Reports
   bucketieren noch nach UTC-Kalendertag; nur der Kampagnen-Versand ist jetzt
   auf Berlin umgestellt. (Gehoert zur groesseren Zeitzonen-Vereinheitlichung
   aus Runde 1, Punkt 1.)
2. Die uebrigen offenen Punkte aus Runde 1-4 (UNIQUE `customers.user_id`,
   Import-Dubletten, Nachweis-Auto-Freigabe-Strenge, Reset-Enumeration,
   Magic-Login-Einmalnutzung, `SendCampaignJob`-Idempotenz/Timeout,
   Commission-Idempotenz) bleiben bestehen.

## Verifiziert sauber (Runde 5)

- **Medien**: `forSlot` cacht Rohspalten (kein `__PHP_Incomplete_Class`), echte
  MIME-Pruefung, SVG-Sanitizer vor Ablage, Slot-Exklusivitaet, private
  Originale/oeffentliche Varianten - solide.
- **Aktivitaetserfassung**: exakt-einmal-Gutschrift der aktiven Sekunden (CAS),
  Stale-Session-Schluss, DSGVO-Prune nur der Navigations-Zeilen.
- **Benachrichtigungen**: mark-read strikt auf den eigenen Nutzer gescopet
  (kein Fremdzugriff); Dokumentanfragen-Lebenszyklus + Upload-Scope korrekt.
- **Ankuendigungen**: keine XSS (escaped Ausgabe). **deploy.sh**: solide
  (Wartungsmodus-trap, Vite-Backup/Restore, storage:link, Cache, FPM-Reload).
  **SEO/Redirect**: keine logische Schleife (nur die o.g. Proxy-Abhaengigkeit).

## Teststatus (Runde 5)

`php artisan test`: **gruen** - 0 Fehler (+3 neue Regressionstests).
Live-Simulation aller Workflows 23/23; alle Admin-/oeffentlichen Seiten 200.
Erweitert: `EmailMarketingImprovementsTest`.
