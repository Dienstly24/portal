# Known Issues - Issue Registry

**Einzige Quelle fuer alle bekannten Befunde.** Eintraege werden NIE
geloescht: behoben -> `FIXED` (mit PR), bestaetigt -> `VERIFIED` (mit
Nachweis). Kennungen werden nie wiederverwendet.

- Severity: `CRITICAL` (Datenverlust/Sicherheitsluecke/Betrieb steht) ·
  `HIGH` · `MEDIUM` · `LOW`
- Status: `OPEN` · `IN_PROGRESS` · `FIXED` · `VERIFIED` · `WONT_FIX` · `DUPLICATE`
- Kategorien: security, legal, ops, correctness, ux-i18n, testing,
  maintenance, performance, feature-gap

Stand der Pruefung: 23.09.2026 (`main` @ 04c0823). Ergebnis des ersten
Voll-Audits: **kein CRITICAL-Befund im Code.** Die hoechsten Risiken liegen im
BETRIEB (Server-Zustand nicht belegt) und in RECHTLICHEN Voraussetzungen.

## Uebersicht

Stand 23.09.2026 nach der ersten Reparaturrunde (Branch `claude/zen-sagan-xmewba`).

| ID | Sev | Kategorie | Kurz | Status |
|---|---|---|---|---|
| KI-002 | HIGH | ops/security | Turnstile-Schluessel in Produktion nicht belegt | OPEN (Betreiber) |
| KI-004 | HIGH | ops | Sicherung auf dem Server nicht belegt in Betrieb | OPEN (Betreiber) |
| KI-008 | HIGH | legal | KI-Assistent/KI-Training: DPA, Datenschutz, Verzeichnis | OPEN (Betreiber) |
| KI-012 | MEDIUM | legal | Rechtstexte: TODOs; Turnstile-Absatz jetzt vorhanden | IN_PROGRESS |
| KI-003 | MEDIUM | security | Netzseite: keine Host-Firewall, Origin ungeprueft | OPEN (Betreiber) |
| KI-009 | MEDIUM | legal | E-Signatur: Formerfordernis ungeklaert | OPEN (Betreiber) |
| KI-013 | MEDIUM | ops | Worker und Cron nicht aus dem Repo belegbar | OPEN (Betreiber) |
| KI-001 | LOW | security | `can_see_all_customers` Default `true` | OPEN (Betreiber-Entscheidung) |
| KI-005 | LOW | feature-gap | BIMI: Zertifikat + Auslieferungsort | OPEN (Betreiber) |
| KI-018 | LOW | maintenance | phpstan in netzbeschraenkter Umgebung nicht installierbar | OPEN (Hinweis) |
| KI-007 | MEDIUM | ux-i18n | 21 Kundentexte ohne arabische Uebersetzung | FIXED |
| KI-006 | LOW | correctness | Einladungsmail nennt nicht vergebene Rechte | FIXED |
| KI-010 | LOW | security | Interne Kennungen am Vertragsdatensatz | FIXED (Waechter-Test) |
| KI-011 | LOW | testing | Termine, Ankuendigungen, Tarifrechner ohne Tests | FIXED |
| KI-014 | LOW | ops | Standard-Locale `en` in Konsole/Queue | FIXED |
| KI-015 | LOW | maintenance | CI: gemischte Action-Versionen | FIXED |
| KI-016 | LOW | maintenance | Tote Breeze-Reste | FIXED (Mail-Anbieter-Konfiguration: WONT_FIX) |
| KI-017 | LOW | maintenance | `autoprefixer` ungenutzt | FIXED |
| KI-019 | MEDIUM | security | Jeder Mitarbeiter konnte jede Ankuendigung loeschen | FIXED |
| KI-020 | LOW | correctness | Termin: `assigned_to` ungeprueft (500er unter MySQL) | FIXED |
| KI-021 | LOW | ux-i18n | Registrierungsseite AR: Haekchen ueber dem Text | FIXED |

FIXED wird zu VERIFIED, sobald der PR gemergt und die CI (inkl. MySQL-Lauf
und PHPStan) auf `main` gruen ist.

---

## Befunde im Detail

### KI-001 - `can_see_all_customers` Default `true`
- **Category** security · **Severity** LOW · **Status** OPEN (Betreiber-Entscheidung)
- **Location** `database/migrations/2026_07_06_180001_add_employee_fields.php:11`
- **Description** Die Spalte hat `default(true)`. Das Mitarbeiter-Formular setzt den Wert ausdruecklich (Voreinstellung "Begrenzte Kunden", Audit UX-14) - der Default greift nur bei Wegen ohne ausdrueckliche Angabe (`admin:set-password` legt Admins an, die ohnehin alles sehen) und bei ALTkonten aus der Zeit vor UX-14.
- **Root Cause** Historische Voreinstellung.
- **Impact** Staff-Altkonten koennen den gesamten Bestand sehen, ohne dass es jemand bewusst entschieden hat.
- **Recommended Fix** (1) Liste aller Staff-Konten mit `can_see_all_customers=1` dem Betreiber vorlegen; (2) Migration `default(false)` (nicht-destruktiv, aendert keine Zeile). Teil der Aufgabe "Employee & Support Customer Access Architecture".
- **Dependencies** Portfolio-Logik (`User::canSeeAllCustomers`), Betreuer-Zuweisung
- **Verification** Test: neues Konto ohne Angabe -> `false`; Auswertung der Altkonten auf dem Server.
- **Discovered** 15.09.2026 (Audit) · **Last Verified** 23.09.2026 (Code)

### KI-002 - Turnstile in Produktion nicht belegt
- **Category** ops/security · **Severity** HIGH · **Status** OPEN
- **Location** Server-`.env` (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`); `App\Services\Security\TurnstileVerifier`
- **Description** Der Bot-Schutz ist fail-closed. Fehlen die Schluessel in Produktion, lehnt `/register` JEDE Selbstregistrierung ab.
- **Root Cause** Betreiber-Schritt aus SEC-1 (03.09.2026), Umsetzung nicht belegt.
- **Impact** Kunden koennen sich ggf. nicht selbst registrieren, ohne dass es auffaellt.
- **Recommended Fix** Schluessel setzen (`docs/ANLEITUNG_SICHERHEIT_AR.md`), danach Registrierung einmal real testen.
- **Verification** `/admin/systemzustand` bzw. Testregistrierung.
- **Discovered** 03.09.2026 · **Last Verified** 23.09.2026 (nur Code; Server UNKNOWN)

### KI-003 - Netzseite / Firewall
- **Category** security · **Severity** MEDIUM · **Status** OPEN
- **Location** VPS (ausserhalb des Repos); `scripts/netz-pruefen.sh`, `docs/SICHERHEIT_NETZWERK_ORIGIN.md`
- **Description** `ufw` inactive (05.09.2026); ob der Origin per IP direkt antwortet und `portal.dienstly24.de` wurden nicht gemessen. Edge ist das Hoster-CDN, nicht Cloudflare.
- **Impact** Umgehung von CDN-Schutz, unnoetig offene Ports.
- **Recommended Fix** `bash scripts/netz-pruefen.sh --vorschlag` auf dem Server, Regelsatz mit offener Zweitsitzung aktivieren (Betreiber).
- **Discovered** 05.09.2026 · **Last Verified** 16.09.2026 (Doku)

### KI-004 - Sicherung nicht belegt in Betrieb
- **Category** ops · **Severity** HIGH · **Status** OPEN
- **Location** `scripts/backup.sh`, `scripts/restore.sh`, Server-Cron
- **Description** Das Verfahren ist am 16.09.2026 vollstaendig bewiesen - auf dem Server fehlen Nachweise fuer Passwort, zweiten Speicherort, Cron und einen `restore.sh --pruefen`-Lauf.
- **Impact** Bei Datenverlust keine belegte Wiederherstellung.
- **Recommended Fix** Einrichtung laut `docs/BACKUP_UND_WIEDERHERSTELLUNG.md`; Abschnitt "Sicherung" auf `/admin/systemzustand` muss gruen sein.
- **Discovered** 16.09.2026 · **Last Verified** 23.09.2026 (Doku)

### KI-005 - BIMI unvollstaendig
- **Category** feature-gap · **Severity** LOW · **Status** OPEN (Kauf durch Betreiber)
- **Location** DNS `default._bimi`, `public/dienstly-bimi-logo.svg`
- **Recommended Fix** siehe CLAUDE.md "Offene Themen / BIMI". **Discovered** 22.09.2026

### KI-006 - Einladungsmail nennt nicht vergebene Rechte
- **Category** correctness · **Severity** LOW · **Status** FIXED
- **Location** `app/Http/Controllers/EmployeeController.php` (`store`, Liste `$permLabels`)
- **Description** Die Rechte fuer das Konto kommen aus `vergebbareRechte()` (ein Manager kann nur eigene Rechte weitergeben), die Rechte-LISTE in `EmployeeWelcomeMail` dagegen aus `$request->has(...)`. Das Formular zeigt einem Manager alle fuenf Rechte an.
- **Impact** Kreuzt ein Manager ein Recht an, das er selbst nicht hat, wird es korrekt NICHT vergeben, die Mail behauptet es aber.
- **Recommended Fix** `$permLabels` aus den tatsaechlich gespeicherten Werten des neuen Kontos bilden (`$employee->can_*`).
- **Verification** Test: Manager ohne `can_send_emails` kreuzt es an -> Mail enthaelt es nicht.
- **Discovered** 23.09.2026 · **Last Verified** 23.09.2026
- **Fix (23.09.2026)**: `$permLabels` aus dem gespeicherten Konto (`$employee->can_*`). Test `EinladungsmailRechteTest` (scheiterte vorher).

### KI-007 - Fehlende arabische Uebersetzungen
- **Category** ux-i18n · **Severity** MEDIUM · **Status** FIXED
- **Location** `lang/ar.json`; betroffen u.a. `auth/register-pending` (10 Saetze - die ganze Seite nach der Registrierung), `portal/dashboard` (Banner), `portal/messages` ("Fruehere Nachrichten laden", "Wird geladen"), `partials/doc_preview`, `website` ("Cookie-Einstellungen" im Fuss), `services/thanks`, `auth/register`.
- **Root Cause** Neue `__()`-Texte ohne `ar.json`-Eintrag; kein Test deckt Vollstaendigkeit ab.
- **Impact** Arabischsprachige Kunden sehen mitten im Ablauf deutschen Text (ausgerechnet nach der Registrierung).
- **Recommended Fix** 28 Schluessel uebersetzen + Waechter-Test "jeder `__()`-Text in portal/auth/website/services/partials steht in `ar.json`" (Breeze-Reste ausnehmen oder loeschen, KI-016).
- **Verification** Pruefskript aus dieser Sitzung (Regex ueber `__('...')`) meldet 0.
- **Discovered** 23.09.2026
- **Fix (23.09.2026)**: 21 Uebersetzungen in `lang/ar.json`; die 7 englischen Breeze-Texte entfielen mit KI-016. Waechter `ArabischeUebersetzungVollstaendigTest` (portal, auth, website, services, support, partials; scheiterte vorher). Im Browser (390 px, AR) geprueft.

### KI-008 - KI: rechtliche Voraussetzungen
- **Category** legal · **Severity** HIGH · **Status** OPEN (Betreiber)
- **Description** Vor Scharfschalten des KI-Assistenten: DPA-Umfang, Datenschutzerklaerung, Verarbeitungsverzeichnis. Vor dem ersten echten Verlauf im KI-Training: eigener Verarbeitungszweck (Art. 5 Abs. 1 lit. b DSGVO).
- **Impact** Rechtsrisiko bei Nutzung. Technisch sind beide Schalter AUS bzw. manuell.
- **Discovered** 17.08./18.09.2026 (CLAUDE.md)

### KI-009 - E-Signatur: Formerfordernis
- **Category** legal · **Severity** MEDIUM · **Status** OPEN (Betreiber)
- **Description** Einfache elektronische Signatur; welche Vorgaenge Schriftform brauchen (Sect. 126a BGB), ist anwaltlich zu klaeren; Nachweisdaten ins Verarbeitungsverzeichnis. **Discovered** 09.09.2026

### KI-010 - Interne Kennungen am Vertragsdatensatz
- **Category** security · **Severity** LOW · **Status** FIXED
- **Location** `app/Models/Contract.php` (kein `$hidden`); Spalten `vermittler_id`, `vermittler_*`, `internal_contract_number`, `commission_status`, `pool`; Relationen `contractCommissions()`, `provisions()`, `vermittlerSettlements()`
- **Description** Die strukturelle Trennung "Kunde hat keine Beziehung zu Provisionen" gilt fuer `Customer`, nicht fuer `Contract`. Im Portal ist heute nichts betroffen (Views geben Felder einzeln aus, kein `@json($contract)`, Test prueft HTML). Ein kuenftiger JSON-Endpunkt im Portal, der ein Vertragsmodell serialisiert, wuerde diese Werte ausliefern.
- **Recommended Fix** Waechter-Test: kein Portal-/Partner-Endpunkt liefert diese Schluessel; alternativ API-Resource fuer Portal-Vertraege. `$hidden` nur nach Pruefung der Admin-JSON-Nutzung.
- **Discovered** 23.09.2026
- **Fix (23.09.2026)**: Waechter `PortalOhneInterneKennungenTest` ruft JEDE GET-Seite des Kundenportals (plus Vertragsdetail) und das Partnerportal ab und verbietet `vermittler_id`, `internal_contract_number`, `pool`. Gegenprobe: ein eingebautes `json_encode($contract)` laesst ihn scheitern. `$hidden` bewusst NICHT gesetzt (Admin-JSON unveraendert).

### KI-011 - Fehlende Funktionstests
- **Category** testing · **Severity** LOW · **Status** FIXED
- **Location** `AppointmentController`, Ankuendigungen (`AdminController`), `TarifrechnerController` - nur indirekt in Rechte-/Index-Tests beruehrt.
- **Recommended Fix** je ein Feature-Test fuer Anlegen/Bearbeiten/Loeschen inkl. Portfolio-Scope. **Discovered** 23.09.2026
- **Fix (23.09.2026)**: `TermineAnkuendigungenTarifrechnerTest` (12 Faelle). Beim Schreiben gefunden: KI-019, KI-020.

### KI-012 - Rechtstexte unvollstaendig
- **Category** legal · **Severity** MEDIUM · **Status** IN_PROGRESS
- **Location** `resources/views/website/legal/`: `datenschutz` (Hoster Hostinger nennen; **Cloudflare Turnstile fehlt ganz** - Pflicht laut SEC-1), `erstinformation` (2x NESA bestaetigen), `agb` (Haftung pruefen), `widerruf` (Abschnitt deaktiviert).
- **Impact** Abmahn-/Aufsichtsrisiko; Datenschutzerklaerung nennt einen eingesetzten Empfaenger nicht.
- **Recommended Fix** Texte durch Anwalt/DSB; Turnstile-Absatz ergaenzen (Empfaenger Cloudflare, Zweck, Art. 6 Abs. 1 lit. f).
- **Discovered** vor 30.07.2026 (TODOs), Turnstile-Luecke bestaetigt 23.09.2026
- **Teilfix (23.09.2026)**: Absatz "Schutz der Registrierung (Cloudflare Turnstile)" in `website/legal/datenschutz`, erscheint NUR bei gesetztem Site-Key (wie Matomo). Test `DatenschutzTurnstileTest`. Offen bleiben die anwaltliche Pruefung des Wortlauts und die uebrigen TODOs (Hoster, NESA, AGB-Haftung, Widerruf).

### KI-013 - Worker/Cron nicht belegt
- **Category** ops · **Severity** MEDIUM · **Status** OPEN (Betreiber-Pruefung)
- **Description** Ob `schedule:run` minuetlich laeuft und Worker fuer `default` UND `lang` laufen, steht nicht im Repo. Der Import-Worker fuer `lang` ist leicht zu vergessen.
- **Verification** `/admin/systemzustand` (Planer-Abschnitt, aeltester Job), `php artisan queue:health`.
- **Discovered** 23.09.2026

### KI-014 - Standard-Locale `en`
- **Category** ops · **Severity** LOW · **Status** FIXED
- **Location** `config/app.php` (`locale`, `fallback_locale` = `en`), `.env.example`
- **Description** Web-Anfragen setzen de/ar per `SetLocale`. Konsole und Queue laufen ohne Middleware mit `en` - Validierungs-/`__()`-Texte in geplanten Laeufen und gequeueten Mails fallen auf Englisch bzw. auf den Schluessel zurueck. Produktionswert von `APP_LOCALE`: UNKNOWN.
- **Recommended Fix** Default `de` (Konfiguration + `.env.example`), Mails mit fester Sprache weiter per `->locale()`.
- **Discovered** 23.09.2026
- **Fix (23.09.2026)**: `App\Support\Sprache` (EINE Liste de/ar), `AppServiceProvider` stellt beim Start jede andere Sprache auf `de` - greift auch bei einer alten Server-.env mit `APP_LOCALE=en`; Standard in `config/app.php` und `.env.example` = `de`; `SetLocale` nutzt dieselbe Liste. Test `StandardspracheTest` (Gegenprobe ohne den Aufruf: rot).

### KI-015 - CI-Pflege
- **Category** maintenance · **Severity** LOW · **Status** FIXED
- **Location** `.github/workflows/deploy.yml`
- **Description** `actions/checkout` @v4 und @v7, `actions/cache` @v4 und @v6, `actions/setup-node` @v4 und @v7 gemischt (Dependabot hat nur einzelne Jobs angehoben); Kommentar im Job `qualitaet` nennt "922 Meldungen", Baseline hat 290.
- **Recommended Fix** Versionen vereinheitlichen, Kommentar korrigieren. **Discovered** 23.09.2026
- **Fix (23.09.2026)**: checkout@v7, cache@v6, setup-node@v7 in allen Jobs; Kommentar ohne veraltete Zahl.

### KI-016 - Tote Reste
- **Category** maintenance · **Severity** LOW · **Status** FIXED
- **Location** Routen `verify-email*`, `email/verification-notification`, `confirm-password` (keine Route nutzt `verified`/`password.confirm`); Views `auth/verify-email`, `auth/confirm-password`, `layouts/guest`; Komponenten `auth-session-status`, `danger-button`, `nav-link`, `responsive-nav-link`, `secondary-button` (0 Nutzungen) und `application-logo`, `input-*`, `text-input`, `primary-button` (nur von diesen Resten genutzt); `config/services.php`: postmark, resend, ses, slack.
- **Impact** Sieht wie Funktion aus, ist keine (Lehre Workflow-Engine); englische Texte ohne Uebersetzung.
- **Recommended Fix** Entfernen mit Test, dass die Routen 404 liefern; vorher `EnsurePasswordChanged`-Ausnahmeliste (`password.confirm`) mitziehen. **Discovered** 23.09.2026
- **Fix (23.09.2026)**: 4 Controller, 2 Seiten, `layouts/guest`, `GuestLayout`, 10 Komponenten und 2 Breeze-Tests entfernt; Routen weg (504 statt 509); `password.confirm` aus der Ausnahmeliste von `EnsurePasswordChanged`. Test `BreezeResteEntferntTest` (404, Routen fehlen, keine Route verlangt `password.confirm`). **WONT_FIX**: postmark/resend/ses/slack in `config/services.php` - sie gehoeren zu den Standard-Mailern in `config/mail.php`; einseitig entfernt waere die Konfiguration inkonsistent, Nutzen gering.

### KI-017 - `autoprefixer` ungenutzt
- **Category** maintenance · **Severity** LOW · **Status** FIXED
- **Location** `package.json` devDependencies; `postcss.config.js` ist leer
- **Recommended Fix** Paket entfernen (`npm uninstall autoprefixer`), Build pruefen. **Discovered** 23.09.2026
- **Fix (23.09.2026)**: `npm uninstall autoprefixer` (9 Pakete aus dem Lockfile), `npm run build` gruen.

### KI-018 - phpstan in netzbeschraenkter Umgebung
- **Category** maintenance · **Severity** LOW · **Status** OPEN (Hinweis)
- **Description** `phpstan/phpstan` wird nur als Zip ueber `api.github.com` verteilt; ist der Host gesperrt, hilft auch `--prefer-source` nicht. Folge: `composer stan` lokal nicht ausfuehrbar, die CI bleibt Massstab. Umgehung fuer Tests: Paket voruebergehend aus Lock/composer.json nehmen, danach `git checkout` (so am 23.09.2026 gemacht).
- **Discovered** 23.09.2026

### KI-019 - Jeder Mitarbeiter konnte jede Ankuendigung loeschen
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `TarifrechnerController::destroyAnnouncement`, `admin/announcements.blade.php`
- **Description** Die Route stand fuer alle Staff-Rollen offen und prueft den Eigentuemer nicht; der Loeschknopf stand an jeder Ankuendigung. Ein Mitarbeiter konnte Mitteilungen der Leitung entfernen.
- **Fix (23.09.2026)**: `darfAnkuendigungLoeschen()` (Ersteller oder admin/manager) als EINE Regel fuer Route UND Knopf. Test `TermineAnkuendigungenTarifrechnerTest` (scheiterte vorher mit 302 statt 403); im Browser: Mitarbeiter 1 Knopf (eigene), Leitung 2.
- **Discovered** 23.09.2026

### KI-020 - Termin nahm `assigned_to` ungeprueft an
- **Category** correctness · **Severity** LOW · **Status** FIXED
- **Location** `AppointmentController::store`
- **Description** Eine unbekannte Nutzer-ID fuehrte zu einem Fremdschluessel-Fehler (500er, im Test nachgewiesen); auch ein Kundenkonto waere als "Zustaendiger" eintragbar gewesen.
- **Fix (23.09.2026)**: Validierung `exists:users,id` beschraenkt auf Staff-Rollen. Test in `TermineAnkuendigungenTarifrechnerTest`.
- **Discovered** 23.09.2026

### KI-021 - Registrierungsseite (AR): Haekchen ueber dem Text
- **Category** ux-i18n · **Severity** LOW · **Status** FIXED
- **Location** `resources/views/auth/register-pending.blade.php`
- **Description** Im Browser (390 px, Arabisch) gefunden: das Haekchen wanderte nach rechts, der Freiraum blieb links (`padding-left`) - das Zeichen lag auf dem ersten Wort.
- **Fix (23.09.2026)**: `padding-inline-start`; Test in `ArabischeUebersetzungVollstaendigTest`; Screenshot nach dem Fix geprueft.
- **Discovered** 23.09.2026


---

## Historie (behoben und verifiziert)

Aus den Audits uebernommen, damit die Registry vollstaendig ist. Nachweis
"Testsuite 23.09.2026" = der benannte Test lief in dieser Sitzung gruen.

| ID | Sev | Kurz | Behoben | Nachweis | Status |
|---|---|---|---|---|---|
| KI-H01 | CRITICAL | JSON-LD `@context` wurde als Blade-Direktive uebersetzt (roher PHP-Text auf jeder oeffentlichen Seite) | PR #338 (15.09.) | `StructuredDataTest`, `BladeCompileTest` | VERIFIED |
| KI-H02 | HIGH | Sichtbarkeit mehrfach implementiert, Vertretung vergessen | 15.09. | `CustomerVisibilityTest`, `ZugriffspruefungTest` | VERIFIED |
| KI-H03 | HIGH | Website-Assistent ohne Kostenbremse | 15.09. | `AssistantBudgetTest` | VERIFIED |
| KI-H04 | HIGH | `retry_after` < Job-Laufzeit (doppelte Posts bei Redis) | 15.09. | `QueueTimeoutTest` | VERIFIED |
| KI-H05 | HIGH | HTTP 500 beim Unterschreiben: `php8.3-gd` fehlte; Caches gehoerten root | 10.09. | `BildverarbeitungFehltTest`, deploy.sh chown | VERIFIED |
| KI-H06 | HIGH | `@push` nach `@stack` - Kopfzeilen-Suche/Glocke tot | 06.09. | CSP-Tests | VERIFIED |
| KI-H07 | MEDIUM | Sicherheitstests uebersprangen sich selbst | 16.09. | `TestumgebungTest` | VERIFIED |
| KI-H08 | MEDIUM | Chat lud je Abfrage den ganzen Verlauf (473 kB) | 15.09. | `ChatFeedTest` | VERIFIED |
| KI-H09 | MEDIUM | `Customer::$email`/`Document::$mime_type` existierten nicht | 15.09. | `StatischeAnalyseFundeTest` | VERIFIED |
| KI-H10 | HIGH | Manager konnte sich Provisionszugriff selbst setzen | PR #350 | `RechteEskalationTest` | VERIFIED |
| KI-H11 | MEDIUM | Antwortweg bei Webhook-Stapeln unbestimmt (SQLite vs MySQL) | 18.09. | `MehrkanalUnterhaltungTest` 10b/10c | VERIFIED |
| KI-H12 | MEDIUM | Cookie-Banner verdeckte Anmeldeformular auf iPhone | 19.09. | Browser + `EinwilligungUndHamburgTest` | VERIFIED |
| KI-H13 | HIGH | Spezialisierter Energieportal-Parser kam hinter generischem nie zum Zug | 13.09. | Waechter-Test Parser-Kette | VERIFIED |

Status VERIFIED gilt fuer den Stand dieser Sitzung; siehe
[TESTING_STATUS.md](TESTING_STATUS.md) fuer das Laufergebnis.
