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

## Teststatus

`php artisan test`: **gruen** (1484 + 13 neue Regressionstests). Neue Dateien:
`tests/Feature/AuthorizationHardeningTest.php`,
`tests/Feature/LexofficeServiceFallbackTest.php`.
