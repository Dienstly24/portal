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
| KI-025 | HIGH | security | Stored XSS: SVG/HTML aus E-Mail-/WhatsApp-Anhaengen inline (SVG ohne CSP) | FIXED |
| KI-026 | HIGH | security | Magic-Login/Einladungslink nach eigenem Passwort weiter benutzbar | FIXED |
| KI-041 | MEDIUM | security | Admin-Reset des Portals liess alte Magic-/Einladungslinks gueltig (Umgehung von KI-026) | FIXED |
| KI-042 | MEDIUM | security | 2FA-Einrichtung als zweiter Pruefweg ohne Sperre/Protokoll (Raten, Ersatzcodes ersetzt) | FIXED |
| KI-043 | MEDIUM | security | Zugangslinks ueberlebten Adress-/Passwortaenderung durch die Verwaltung und erneutes Senden | FIXED |
| KI-044 | MEDIUM | correctness | Ausweis-Rueckseiten (eAT + Personalausweis) nicht erkannt: Anschrift/Geburtsort fehlten | FIXED |
| KI-049 | MEDIUM | correctness | Dubletten-Seite: Handler-Skript seit SEC-4 syntaktisch kaputt (Sammel-Knoepfe ohne Wirkung, Rueckfragen fehlten) | FIXED |
| KI-045 | MEDIUM | correctness | Entgeltabrechnung "Verdienstabrechnung": weder Kunde noch Arbeitgeber gelesen | FIXED |
| KI-046 | MEDIUM | correctness | Erstwagen einer Zweitwagenregelung zweckentfremdet in der Vorversicherung, Vertraege nicht verknuepft | FIXED |
| KI-047 | MEDIUM | correctness | `Contract`: der Provisions-Listener auf `deleting` beendete die Listener-Kette (jeder spaetere deleting-Listener lief nie) | FIXED |
| KI-048 | LOW | testing | `ReportsDashboardTest::test_verlaengerung_ist_ablauf_im_zeitraum_ohne_kuendigung` scheitert am 1. eines Monats (datumsabhaengig) | FIXED |
| KI-040 | HIGH | security | Mitarbeiterverwaltung lud jedes Konto: Kundenkonto -> manager, sperren, loeschen | FIXED |
| KI-027 | MEDIUM | security | E-Signatur: Code-Versand unbegrenzt, Fehlversuche je Code zurueckgesetzt | FIXED |
| KI-028 | MEDIUM | security | HTML-Injection per innerHTML in Such-/Trefferlisten | FIXED |
| KI-029 | MEDIUM | correctness | E-Mail-Eingang: Kundensuche fuer Support 403 (tot) | FIXED |
| KI-030 | MEDIUM | concurrency | E-Signatur: gleichzeitiges Absenden -> doppelter Abschluss | FIXED |
| KI-033 | MEDIUM | business-logic | Oeffentliche Formulare ordnen ungepruefte Anfragen per E-Mail einer Kundenakte zu | FIXED |
| KI-038 | LOW | security | `/reset-password` verriet per Meldung, ob ein Konto existiert | FIXED |
| KI-039 | LOW | concurrency | Doppelklick auf Registrierungs-Bestaetigung -> HTTP 500 | FIXED |
| KI-031 | LOW | correctness | WhatsApp: mehrere Rufnummern in einer Zustellung -> erstes Konto | FIXED |
| KI-032 | LOW | security | Sprachumschalter folgte fremdem Referer | FIXED |
| KI-034 | LOW | business-logic | Abmeldelink wirkt schon beim GET (Link-Scanner) | OPEN (Betreiber-Entscheidung) |
| KI-035 | LOW | security | 2FA-Schalter AUS entwertet eingerichtete zweite Faktoren | OPEN (Betreiber-Entscheidung) |
| KI-036 | LOW | maintenance | `ActivityLog.meta` doppelt kodiert (~85 Schreibstellen) | FIXED |
| KI-037 | LOW | security | SvgSanitizer liess externe `url()`/`@import` durch | FIXED |
| KI-002 | HIGH | ops/security | Turnstile-Schluessel in Produktion nicht belegt | OPEN (Betreiber) |
| KI-004 | HIGH | ops | Sicherung auf dem Server nicht belegt in Betrieb | OPEN (Betreiber) |
| KI-008 | HIGH | legal | KI-Assistent/KI-Training: DPA, Datenschutz, Verzeichnis | OPEN (Betreiber) |
| KI-012 | MEDIUM | legal | Rechtstexte: TODOs; Turnstile-Absatz jetzt vorhanden | IN_PROGRESS |
| KI-003 | MEDIUM | security | Netzseite: keine Host-Firewall, Origin ungeprueft | OPEN (Betreiber) |
| KI-009 | MEDIUM | legal | E-Signatur: Formerfordernis ungeklaert | OPEN (Betreiber) |
| KI-013 | MEDIUM | ops | Worker und Cron nicht aus dem Repo belegbar | OPEN (Betreiber) |
| KI-001 | LOW | security | `can_see_all_customers` Default `true` | OPEN (Betreiber-Entscheidung) |
| KI-005 | LOW | feature-gap | BIMI: Zertifikat + Auslieferungsort | OPEN (Betreiber) |
| KI-018 | LOW | maintenance | phpstan in netzbeschraenkter Umgebung nicht installierbar | OPEN (Umgehung belegt, siehe Eintrag) |
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
| KI-022 | HIGH | security | Provisionsbetrag in der Vertragsakte fuer jedes Personal sichtbar | FIXED |
| KI-024 | HIGH | ops | `main` seit PR #354 rot (PHPStan-Baseline verwies auf geloeschte Datei) - #354 nie ausgeliefert | FIXED |
| KI-023 | HIGH | correctness | TARIFCHECK24-Status falsch gedeutet (1 = "bestaetigt", 3 unbekannt), kein Rechnungs-Upload | FIXED |

FIXED wird zu VERIFIED, sobald der PR gemergt und die CI (inkl. MySQL-Lauf
und PHPStan) auf `main` gruen ist.

---

## Befunde im Detail

### KI-049 - Dubletten-Seite: Handler-Skript syntaktisch kaputt
- **Category** functionality · **Severity** MEDIUM · **Status** FIXED
- **Location** `resources/views/admin/customer_duplicates.blade.php` (Registrierungsblock `@pushOnce('cspScripts')`)
- **Description** Bei der SEC-4-Umstellung (onclick -> `window.__h`) wurden zwei `confirm('…„Verwandte Kunden"…')`-Texte am geraden Anfuehrungszeichen abgeschnitten: im HTML blieb Restext hinter `data-h-submit` stehen, im Skript zwei nicht geschlossene Zeichenketten. Ein SyntaxError verwirft das GANZE Skript - damit fehlten auch die Handler der Sammel-Knoepfe "Ehepaar"/"Kein Duplikat" und die Rueckfrage der Sammel-Zusammenfuehrung. Kein 500er, keine Meldung: die Knoepfe taten einfach nichts (dieselbe Klasse wie die tote Dashboard-Suche, CLAUDE.md SEC-4 Falle 3). Der Waechter-Test prueft nur, DASS eine Registrierung existiert, nicht, dass das Skript gueltig ist.
- **Fix (01.10.2026)**: im Zuge von "Beziehung festlegen" neu geschrieben - Einzel-Rueckfrage per `data-confirm`, Sammel-Handler intakt, typografische Anfuehrungszeichen in den JS-Texten.
- **Discovered** 01.10.2026 (beim Umbau gelesen)
- **Hinweis**: zuerst als KI-046 vergeben; die Nummer war parallel in PR #365 belegt, deshalb KI-049.

### KI-046 - Erstwagen der Zweitwagenregelung in der Vorversicherung
- **Category** correctness · **Severity** MEDIUM · **Status** FIXED
- **Location** `contract_kfz_fields` (Vorversicherung, SF-Einstufung), `ContractVehicleDetail`
- **Description** Betreiber-Meldung 01.10.2026: Die Sondereinstufung eines Zweitwagens (z. B. SF 4) wird WEGEN eines anderen Vertrags gewaehrt. Dafuer gab es keinen Ort - der Erstwagen wurde in der Vorversicherung erfasst ("Vorheriger Versicherer: Zweite Wagen ADAC", "Vertragsnummer: AD-5406305005"). Die Vorversicherung beschreibt aber den Vorvertrag DIESES Fahrzeugs; die beiden Vertraege waren nicht verknuepft, nichts liess sich auswerten oder pruefen.
- **Fix (01.10.2026)**: Tabelle `vehicle_sf_references` (Bezug je Sparte, intern/extern, Halter, Snapshot, Nachweis, Pruefvermerk), `SfReferenceService` als einziger Schreibweg (Selbst-/Kreisbezug abgelehnt, Protokoll), `SfReferenceValidator` (Warnungen, optionale Pflicht), `SfReferenceNotifier` (Rueckstufung/Kuendigung/Loeschung des Erstwagens), Anzeige in beide Richtungen, Chip "Keine Vorversicherung" + Hinweis bei Zweitwagen-Text. Bestand: `kfz:zweitwagen-pruefen` (nur lesend). Tests `SfBezugsfahrzeugTest`, `KfzSfRegelnTest`.
- **Discovered** 01.10.2026 (Betreiber-Meldung)

### KI-047 - deleting-Listener am Vertrag brach die Kette ab
- **Category** correctness · **Severity** MEDIUM · **Status** FIXED
- **Location** `Contract::boot()` (`static::deleting` des Provisions-Stornos)
- **Description** Gefunden beim Bau von KI-046: der Listener war eine Pfeilfunktion und lieferte die ANZAHL der Stornobuchungen zurueck. Laravel feuert `deleting` als "until"-Ereignis - jeder Rueckgabewert ausser `null` beendet die Kette. Jeder spaeter registrierte `deleting`-Listener am Vertrag lief deshalb nie, ohne Fehlermeldung. Bisher gab es keinen zweiten - der erste (Benachrichtigung beim Loeschen eines Erstwagens) waere still ausgefallen.
- **Fix (01.10.2026)**: Listener als Block ohne Rueckgabewert; Provisionslogik unveraendert. Test `SfBezugsfahrzeugTest::test_kuendigung_und_loeschung_des_erstwagens_werden_gemeldet` (scheitert ohne den Fix).
- **Discovered** 01.10.2026

### KI-048 - Dashboard-Test scheitert am Monatsersten
- **Category** testing · **Severity** LOW · **Status** FIXED
- **Location** `tests/Feature/ReportsDashboardTest.php` (`test_verlaengerung_ist_ablauf_im_zeitraum_ohne_kuendigung`)
- **Description** Der Test legt den Ablauf auf `now()->startOfMonth()->addDays(2)`. Am 1. eines Monats liegt dieses Datum in der Zukunft und zaehlt (noch) nicht als Verlaengerung - der Test ist an diesem Tag rot, unabhaengig vom Code. Auf `main` am 01.10.2026 nachgestellt (Basislauf vor jeder Aenderung).
- **Fix (01.10.2026, PR #365)**: der Test blockierte am 1. Oktober die Pflicht-Checks des PRs (beide Testjobs rot, Deploy uebersprungen) und wurde deshalb hier mitbehoben. Der Code war richtig ("Dieser Monat" = Monatserster bis heute), falsch war das Testdatum. Jetzt steht die Uhr fest auf dem Monatsersten (`travelTo`), der Ablauf ist der Monatserste selbst. Gegenprobe: mit dem alten Datum ist der Test nun an JEDEM Tag rot, nicht nur am Ersten.
- **Discovered** 01.10.2026

### KI-045 - Entgeltabrechnung "Verdienstabrechnung" wurde gar nicht erkannt
- **Category** functionality · **Severity** MEDIUM · **Status** FIXED
- **Location** `GehaltsabrechnungParser`, neu `App\Support\Adresszeile`, `ClaudeDocumentAiProvider` (Prompt)
- **Description** Vom Betreiber mit einer echten Abrechnung gemeldet. Vier Ursachen, jede fuer sich ausreichend, damit das Dokument als "Sonstiges / kein Kunde gefunden" im Eingang landet: (1) die Typ-Erkennung kannte nur vier Ueberschriften, das Dokument heisst "Verdienstabrechnung"; (2) der Empfaengerblock wurde ueber die Anrede "Herrn/Frau" gesucht, die dort fehlt; (3) gelesen wurde "die erste Zelle der Zeile" - im Empfaengerblock bleibt aber eine Zeile links LEER, dort steht die Merkmalsspalte ("Telefon") - dieselbe Klasse wie beim Gruenwelt-Briefkopf und der eAT-Rueckseite; (4) der Arbeitgeber wurde ueber eine Rechtsform (GmbH/AG/...) gesucht, viele Traeger fuehren keine. Nebenbefunde am selben Dokument: `Geburtsdatum: …` (Doppelpunkt statt Spaltenabstand), `Auf Konto (IBAN) : …` und "Gesetzliches Netto" statt "Gesamtnetto" fielen still weg.
- **Fix (30.09.2026)**: Titel-Liste `GehaltsabrechnungParser::TITEL`; Empfaengerblock ueber die FORM seiner Zellen an der SPALTENPOSITION (eine Zelle "PLZ Ort", darueber Strasse und Name; Anrede optional, liefert nur noch das Geschlecht); Arbeitgeber aus der einzeiligen ABSENDERZEILE ueber dem Empfaenger (`Adresszeile::mitName`) bzw. aus dem Block darueber (`Adresszeile::anschrift`) - welcher Fall gilt, entscheidet das Umfeld, nicht die Zeichenkette. Der Arbeitgeber steht jetzt in `employer_name`/`employer_address` (im Review als Gruppe "Arbeitgeber" uebernehmbar) statt nur in der Zusammenfassung. Laesst sich die Strasse nicht sicher loesen, bleibt das Feld LEER. KI-Prompt kannte die Entgeltabrechnung gar nicht und beschreibt sie jetzt inkl. Absenderzeile. Tests `GehaltsabrechnungParserTest` (8 Faelle, 6 scheitern ohne den Fix).
- **Bewusst offen**: die Namenstrennung "letztes Wort = Nachname" macht aus "Yusuf Al Rahman" den Vornamen "Yusuf Al" und den Nachnamen "Rahman". Die Regel steht in ueber zehn Parsern und in `CLAUDE.md`; sie zentral auf Namenspartikel (Al/El/Abu/Bin/van/von) umzustellen ist eine eigene Aufgabe.
- **Discovered** 30.09.2026 (Betreiber-Meldung mit echtem Dokument)

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

- **Umgehung (24.09.2026, belegt)**: `composer install --prefer-source` scheitert nur an `phpstan/phpstan` (reines Release-Paket). Git-Klone der GESPERRTEN Versionen funktionieren: `git clone --depth 1 --branch <version> https://github.com/phpstan/phpstan.git vendor/phpstan/phpstan` und ebenso `larastan/larastan`, dann `php vendor/phpstan/phpstan/phpstan analyse --memory-limit=2G --autoload-file=<psr4-loader fuer Larastan\\Larastan\\ -> vendor/larastan/larastan/src>`. So am 24.09.2026 gelaufen (0 Fehler). Wer diese Pruefung auslaesst, pusht ungeprueften Code - genau so entstand KI-024.

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

### KI-022 - Provisionsbetrag in der Vertragsakte fuer jedes Personal sichtbar
- **Category** security · **Severity** HIGH · **Status** FIXED
- **Location** `resources/views/admin/partials/contract_vermittler_box.blade.php`, `routes/web.php` (Gruppe Partner & Provisionen), `AdminNavigation::vertrieb`
- **Description** Vom Betreiber am Screenshot gemeldet ("Provisionen nur fuer den Admin"). Die Box "Vermittler / Abrechnung" hatte keine Pruefung: jeder Mitarbeiter und Support mit Zugriff auf den Vertrag sah den Provisionsbetrag. Dazu reichte fuer Gutschriften, Ausgangs-Provisionen und Vermittler-Abrechnung die ROLLE manager statt des Rechts `provisionen-verwalten`. Kunden waren nicht betroffen.
- **Fix (23.09.2026)**: ueberall das Recht (Route + Controller + Views); Saetze bleiben erhalten, wenn jemand ohne Recht speichert. Test `ProvisionenNurFuerBerechtigteTest` (ohne Fix 4/5 rot).
- **Discovered** 23.09.2026

### KI-024 - `main` seit PR #354 rot, #354 nie ausgeliefert
- **Category** ops · **Severity** HIGH · **Status** FIXED
- **Location** `phpstan-baseline.neon`
- **Description** PR #354 hat `VerifyEmailController.php` geloescht, der Baseline-Eintrag dazu blieb stehen. PHPStan bricht bei einem Eintrag fuer eine nicht vorhandene Datei SOFORT ab ("Invalid entry in ignoreErrors"). Der Job "Codeformat und statische Analyse" war damit auf `main` und auf jedem neuen PR rot - und weil der Deploy an den Tests haengt, wurde #354 NICHT ausgeliefert. Lokal fiel es nicht auf, weil phpstan hier nicht lief (KI-018).
- **Fix (24.09.2026)**: Eintrag entfernt; dazu ein zweiter veralteter Eintrag (`VermittlerSettlement::$anzahl`, Ursache mit KI-023 behoben). PHPStan lokal nachgeholt: 5 neue Meldungen im Code von KI-023 an der Ursache behoben (keine neue Baseline-Zeile), danach 0 Fehler. LEHRE: wer eine Datei loescht, sucht sie auch in `phpstan-baseline.neon`.
- **Discovered** 24.09.2026

### KI-025 - Stored XSS ueber Dateien aus fremder Quelle
- **Category** security · **Severity** HIGH · **Status** FIXED
- **Location** `Admin\CustomerDocumentController::documentDownload` (`?view=1`), `PortalController::documentView`, `CustomerMessageController`/`PortalMessageController::viewAttachment`
- **Description** E-Mail-Anhaenge beliebiger Absender und WhatsApp-Dokumente werden Dokumente der Kundenakte. "Anzeigen" lieferte sie mit dem am Inhalt erratenen Typ aus; `SecurityHeaders` setzt die CSP nur auf `text/html` -> eine SVG mit `<script>` lief ohne CSP im Ursprung der Beraterwelt (bzw. des Portals, wenn fuer den Kunden sichtbar).
- **Fix (28.09.2026)**: `App\Support\InlineDatei` - inline nur PDF/JPEG/PNG/WebP/GIF, am INHALT bestimmt; alles andere als Download (`application/octet-stream`). Test `InlineDateiauslieferungTest` (ohne Fix 5/6 rot).
- **Discovered** 28.09.2026 (System-Audit, `docs/AUDIT_2026-09-28_SYSTEMPRUEFUNG.md`)

### KI-026 - Wiederverwendbare Zugangslinks
- **Category** security · **Severity** HIGH · **Status** FIXED
- **Location** `MagicLoginController`, `PasswordSetupController`
- **Description** Magic-Login (90 Tage) und Einladungslink (14 Tage) blieben nach dem Setzen des eigenen Passworts gueltig - eine alte Mail war ein zweiter Schluessel zum Konto.
- **Fix (28.09.2026)**: `App\Support\EinmalLink` - gilt nur, wenn nach dem letzten `password_changed_at` ausgestellt (Ausstellung = signiertes `expires` minus Gueltigkeit). Keine Migration; unbenutzte, bereits verschickte Links funktionieren weiter. Test `EinmalLinkTest` (ohne Fix rot).
- **Discovered** 28.09.2026

### KI-040 - Mitarbeiterverwaltung lud jedes Konto
- **Category** security · **Severity** HIGH · **Status** FIXED
- **Location** `EmployeeController` (edit/show/update/destroy/toggleActive/assign/unassign/transferPortfolio, storeSubstitution), `PostfachController::reassign`
- **Description** `User::findOrFail($id)` ohne Rollenfilter: ein Manager konnte einem KUNDENKONTO per "Speichern" die Rolle manager geben (Zugang zur Beraterwelt), ein Kundenkonto sperren (sonst admin-only) und ein Admin eine Kundenakte am `CustomerDeletionService` vorbei loeschen (Kaskade `customers.user_id`). Vertretungen und Postfach-Zuweisungen nahmen ebenfalls Kunden-/Partnerkonten an.
- **Fix (28.09.2026)**: nur Personal-Rollen (404 sonst), Validierung auf Personal. Test `MitarbeiterverwaltungNurPersonalTest` (ohne Fix rot).
- **Discovered** 28.09.2026 (Nachpruefung)

### KI-041 - Admin-Reset entwertete alte Zugangslinks nicht
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `PortalAccessService::resetPortal`, `App\Support\EinmalLink`
- **Description** Gefunden in der unabhaengigen Nachpruefung vor dem Merge von PR #358: KI-026 entwertet Links erst, wenn der KUNDE ein Passwort setzt. "Portal zuruecksetzen" - die Antwort des Betriebs auf "jemand anderes hat meine Mail" - setzte nur ein neues Startpasswort; ein alter Magic-Link blieb bis zu 90 Tage gueltig.
- **Fix (28.09.2026)**: `resetPortal()` setzt `password_changed_at` (eine Sekunde zurueck, damit der Link der neuen Willkommensmail gilt). Test `EinmalLinkTest::test_portal_reset_entwertet_alte_links` (ohne Fix rot).
- **Discovered** 28.09.2026 (Pre-Merge-Review)
- **Nachtrag (KI-043)**: der Mechanismus ist ersetzt - `resetPortal()` widerruft ueber `users.zugangslink_version` statt ueber `password_changed_at` (der Sekunden-Vergleich liess einen Link aus derselben Sekunde gelten). Das Verhalten ist dasselbe, der Test benutzt jetzt den Link der echten Mail.

### KI-042 - 2FA-Einrichtung als zweiter Pruefweg
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `Auth\TwoFactorController::setupStore`, `TwoFactorService::confirmSetup`, `EnsureTwoFactor::ALLOWED_ROUTES`
- **Description** Die Einrichtung ist in jedem Zustand erreichbar und pruefte den Code auch bei einem BESTAETIGTEN Faktor - gegen dasselbe Geheimnis wie die Abfrage, aber ohne deren Sperre (5/300 s je Konto+IP) und ohne `two_factor_failed`. Mit gestohlenem Passwort: 10 statt 1 Versuch je Minute, unsichtbar; ein Treffer liess herein und ersetzte die Ersatzcodes.
- **Fix (28.09.2026)**: eingerichteter Faktor -> Umleitung zur Abfrage ohne Codepruefung; `confirmSetup` lehnt einen bestaetigten Faktor ab; die Ersteinrichtung zaehlt Fehlversuche im SELBEN Limiter-Schluessel wie die Abfrage und protokolliert sie (`weg: einrichtung`). Test `ZweiFaktorEinrichtungUmgehungTest` (ohne Fix 5/6 rot; der sechste sichert die normale Ersteinrichtung).
- **Discovered** 28.09.2026 (Threat-Model-Runde vor dem Merge, `docs/AUDIT_2026-09-28_SYSTEMPRUEFUNG.md` Teil F)

### KI-043 - Zugangslinks ueberlebten Aenderungen durch die Verwaltung
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `AdminController::customerUpdate/storeCustomer`, `PortalAccessService`, `EmployeeController::resendInvitation`, `App\Support\EinmalLink`
- **Description** Nach Aenderung der Login-Adresse und/oder einem von der Verwaltung gesetzten Passwort meldete der Magic-Link an die ALTE Adresse weiter an (Tippfehler bei der Anlage -> Fremder hat 90 Tage Zugang). "Einladung erneut senden" liess alle frueheren Einladungen 14 Tage parallel gueltig.
- **Fix (28.09.2026)**: Widerrufsstand `users.zugangslink_version`; jeder Link traegt ihn signiert als `v` (`EinmalLink::parameter()`), gilt nur bei Gleichheit. Widerruf (`User::zugangslinksWiderrufen()`, atomar in der DB) bei neuer Login-Adresse (Modell-Hook), Verwaltungspasswort (`User::setzeVerwaltungsPasswort()`), Portal-Reset und jeder neuen Einladung. Manager duerfen die Einladung eines Administrators nicht mehr erneut senden (Grenze aus dem Bearbeiten). Bestandslinks ohne `v` gelten bis zum ersten Widerruf. Tests `ZugangslinkWiderrufTest`, `MitarbeiterEinladungErneutSendenTest` (Mutationen belegt).
- **Bewusst offen (Betreiber-Entscheidung)**: ein unbenutzter bzw. ohne eigenes Passwort benutzter Magic-Link ist bis zum Ablauf (90 Tage) mehrfach verwendbar; kein Einmal-Verbrauch.
- **Discovered** 28.09.2026 (Threat-Model-Runde vor dem Merge)

### KI-044 - Rueckseite von Aufenthaltstitel und Personalausweis nicht erkannt
- **Category** correctness · **Severity** MEDIUM · **Status** FIXED
- **Location** `AufenthaltstitelParser` (Rueckseite), fehlender Parser fuer den Personalausweis, `TesseractTextExtractor::upscaleIfSmall`
- **Description** Betreiber-Meldung 30.09.2026: vom Ausweis werden vor allem ANSCHRIFT und GEBURTSORT gebraucht, erkannt wurde nur die Vorderseite. An den eingesandten Fotos mit Tesseract gemessen: (1) die Rueckseite des eAT wurde NUR an einer fehlerfrei gelesenen MRZ-Datenzeile erkannt - "<<<<" als "cccceee", "«", Rauschen am Zeilenrand oder eine verlesene Staatsangehoerigkeit liessen die ganze Karte samt Anschrift durchfallen; (2) fuer den Personalausweis gab es gar keinen Parser (Vorderseite traegt den Geburtsort, Rueckseite die Anschrift), und "D<<" (Deutschland) passte nicht auf das Muster "drei Buchstaben"; (3) die 2x-Vergroesserung kleiner Bilder liess Tesseract den MRZ-Block am echten Personalausweis-Foto komplett weglassen; (4) ein Ergebnis ohne Anschrift galt als fertig - die KI las das Bild nie.
- **Fix (30.09.2026)**: gemeinsamer Baustein `LiestDeutscheAusweiskarte` (MRZ nach POSITION gelesen, OCR-Verwechslungen nur dort zurueckgesetzt, wo die Norm sie eindeutig macht, jede Zahl ueber ihre Pruefziffer bestaetigt; Rueckseite auch ohne Datenzeile an Namenszeile + zwei Rueckseiten-Beschriftungen; Anschrift auch bei zerlegter Beschriftung, nie aus der Behoerdenzeile); neuer `PersonalausweisParser` (Vorder- und Rueckseite); MRZ-Nachlesen im Original, wenn die Vergroesserung sie verschluckt; `pflichtangaben` im Parser-Ergebnis -> `DocumentAnalyzer` eskaliert zur KI, wenn Anschrift (Rueckseite) bzw. Geburtsort (Vorderseite Personalausweis) fehlen; KI-Prompt kennt den Personalausweis. Test `AusweiskartenRueckseiteTest` (ohne Fix 12/14 rot).
- **Discovered** 30.09.2026 (Betreiber-Meldung mit Fotos)

### KI-027 - Bestaetigungscode der E-Signatur ohne echte Grenze
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `SignatureSigningController::requestCode/verify`, `SignatureTokenService`
- **Description** Jeder neue Code setzte die Fehlversuche zurueck, der Versand war nur ueber den Token-Limiter (120/min) begrenzt -> Raten in Tagen machbar, Postfach flutbar.
- **Fix**: 1 Code/Minute, 5/Stunde je Unterzeichner, 10 Fehlversuche/Stunde ueber alle Codes (Limiter, keine Migration). Test `SignaturCodeUndGleichzeitigkeitTest`.
- **Discovered** 28.09.2026

### KI-028 - HTML-Injection in Suchlisten
- **Category** security · **Severity** MEDIUM · **Status** FIXED
- **Location** `layouts/admin` (Kopfzeilen-Suche, Glocke), `layouts/portal` (Glocke), `employee_edit`, `employee_show`, `email_inbox`, `email_message`, `banners`
- **Description** Kundennamen/Ticket-Betreffe (Registrierung, oeffentliche Formulare) roh in `innerHTML`. CSP stoppt Skripte, nicht Links/Formular-Attrappen/Tracking-Bilder.
- **Fix**: escapen bzw. `textContent`; Waechter-Test `SuchlistenFremddatenTest` scannt alle Views. Im Browser (Chromium) nachgeprueft.
- **Discovered** 28.09.2026

### KI-029 - E-Mail-Eingang: Kundensuche fuer Support tot
- **Category** correctness · **Severity** MEDIUM · **Status** FIXED
- **Description** Eingang ist fuer support freigegeben, die Suche rief `admin.employees.customer-search` (nur admin/manager) -> 403, leere Liste.
- **Fix**: `admin.customers.search` (portfolio-gescoped). Test `SuchlistenFremddatenTest`, Browser: 200 + Treffer + Zuordnen-Knopf aktiv.
- **Discovered** 28.09.2026

### KI-030 - E-Signatur: gleichzeitiges Absenden
- **Category** concurrency · **Severity** MEDIUM · **Status** FIXED
- **Location** `SignatureSigningService::sign/complete`
- **Description** Pruefung "schon unterschrieben?" ohne Sperre: doppelter Abschluss (PDF + Mails doppelt), Bilddatei nach Abschluss ueberschreibbar.
- **Fix**: `Cache::lock` je Unterzeichner bzw. Vorgang, Zustand innerhalb neu gelesen. Test `SignaturCodeUndGleichzeitigkeitTest`.
- **Discovered** 28.09.2026

### KI-031 - WhatsApp: mehrere Rufnummern in einer Zustellung
- **Category** correctness · **Severity** LOW · **Status** FIXED
- **Fix**: `WhatsAppAdapter::splitByPhoneNumberId`, ein Job je Nummer. Test in `WhatsAppChannelTest`.

### KI-032 - Sprachumschalter folgte fremdem Referer
- **Category** security · **Severity** LOW · **Status** FIXED
- **Fix**: nur eigener Host, sonst Startseite. Test `SprachumschalterWeiterleitungTest`.

### KI-033 - Ungepruefte Anfragen in der Kundenakte (Designfrage)
- **Category** business-logic · **Severity** MEDIUM · **Status** FIXED (Betreiber-Entscheidung 29.09.2026: Variante "als ungeprueft markieren")
- **Location** `SupportFormController`, `WebsiteController::submitContact`, `WebsiteContactController`, `ServicePageController::submit`, `WebsiteInquiryController`
- **Description** Die Formulare haengen eine Anfrage allein ueber die E-Mail-Adresse an eine Kundenakte - wer die Adresse eines Kunden kennt, legt einen Vorgang in dessen Akte und Portal an, der wie vom Kunden aussieht. Vorschlag: sichtbar "Absender nicht verifiziert" + nicht im Portal, bis ein Mitarbeiter bestaetigt. Aendert einen Ablauf -> nicht eigenmaechtig.
- **Threat Model (28.09.2026)**: kein Lesezugriff fuer den Absender (Antwortseite und Bestaetigungsmail verraten nichts, Antworten gingen an die echte Adresse). Aber: er legte einen Vorgang im PORTAL des Opfers an (Text frei waehlbar -> Phishing im vertrauten Kanal), im KI-Assistenten als offenen Vorgang, in der Kunden-Glocke; beim Team erschien er als Anfrage des Kunden, bei `/leistungen/.../anfrage` mit der Rufnummer des Absenders an der Akte (Rueckruf beim Falschen); `/hilfe` schrieb die Anfrage im ActivityLog dem Konto des Opfers zu. Das WordPress-Formular (`/api/website-inquiry`) ist trotz Token ebenfalls oeffentlich - das Token belegt nur den Server.
- **Fix (29.09.2026)**: `App\Support\FormularAbsender` ist die EINE Stelle fuer alle vier Formulare. Zuordnung per Adresse bleibt, der Vorgang traegt `tickets.absender_status = ungeprueft` und die Angaben des Absenders (`guest_*`). `Ticket::scopeKundenSichtbar()` blendet ihn in Portal (Liste, Dashboard, Detail, Anhang, Antwort, Schliessen, Bewertung) und KI-Werkzeugen aus; Kunden-Glocke (Antwort, Status, Auto-Close) entfaellt, Antworten gehen wie bei Gast-Anfragen nur an die Formular-Adresse. Team: Hinweis in Liste, Detailseite, Glocke und Support-Mail; "Absender bestaetigen" (ab dann normal sichtbar) oder "von der Akte loesen" (Gast-Anfrage, nichts geloescht) - beides mit Ticket-Zugriff UND Bearbeiten-Recht, beides im Ticket-Verlauf. Vertrauenswuerdig bleiben Login und Token aus der Willkommensmail. Altbestand bleibt unmarkiert (ob er ueber das Token kam, steht nirgends - nicht geraten). Test `FormularAbsenderUngeprueftTest` (13, Mutationen belegt, dazu ein Waechter ueber alle kundenseitigen Ticket-Abfragen).

### KI-034 - Abmeldung schon beim GET (Designfrage)
- **Category** business-logic · **Severity** LOW · **Status** OPEN (Betreiber-Entscheidung)
- **Description** Link-Scanner (Outlook Safe Links) rufen Links vorab auf und melden Kunden ab. Vorschlag: GET zeigt Bestaetigungsknopf, POST (RFC 8058) bleibt Ein-Klick.

### KI-035 - 2FA-Schalter (Designfrage)
- **Category** security · **Severity** LOW · **Status** OPEN (Betreiber-Entscheidung)
- **Description** Schalter AUS -> auch eingerichtete zweite Faktoren werden nicht abgefragt. Vorschlag: wer eingerichtet hat, wird immer gefragt.

### KI-036 - `ActivityLog.meta` doppelt kodiert
- **Category** maintenance · **Severity** LOW · **Status** FIXED
- **Fix**: Mutator `ActivityLog::setMetaAttribute` nimmt Array oder JSON-String und speichert immer ein JSON-Objekt (eine Stelle statt ~85). Altbestand bleibt lesbar ueber `metaArray()`. Test `ActivityLogMetaKodierungTest`.

### KI-037 - SvgSanitizer: externe CSS-Verweise
- **Category** security · **Severity** LOW · **Status** FIXED
- **Fix**: `@import` und externe `url()` in `<style>`/Attributen entfernt, `url(#...)` bleibt. Test `SvgSanitizerExterneVerweiseTest`.

### KI-038 - Konto-Enumeration ueber `/reset-password`
- **Category** security · **Severity** LOW · **Status** FIXED
- **Description** Mit beliebigem Token: "kein Konto gefunden" gegen "Link abgelaufen" verriet die Existenz eines Kontos.
- **Fix**: gleiche Meldung. Test in `EinmalLinkTest`.

### KI-039 - Doppelklick auf Registrierungs-Bestaetigung
- **Category** concurrency · **Severity** LOW · **Status** FIXED
- **Description** Zweiter gleichzeitiger Aufruf scheiterte am Unique-Index `users.email` -> HTTP 500.
- **Fix**: Unique-Verletzung abgefangen, Transaktion rollt vollstaendig zurueck, Hinweis "Konto besteht bereits". Test `RegistrierungDoppelklickTest`.

### KI-023 - TARIFCHECK24-Status falsch gedeutet, Rechnung nicht hochladbar
- **Category** correctness · **Severity** HIGH · **Status** FIXED
- **Location** `VermittlerStatusMap`, `VermittlerReportService`, `contract_vermittler_box.blade.php`, Rechnungsabgleich
- **Description** Vom Betreiber gemeldet. (1) Code 1 wurde als "bestaetigt / In Abrechnung gefunden" (gruen) gedeutet - er heisst OFFEN; die Vertragsakte zeigte "Provision 75,00 EUR" neben einem Haken, als waere gezahlt. Code 3 (verifiziert) war unbekannt und landete in der Pruefliste. Auswertung und Bestaetigungsquote zaehlten offene Positionen als bestaetigt. (2) Eine Rechnung liess sich weder als PDF noch als Bild hochladen - es gab nur ein Suchfeld.
- **Fix (23.09.2026)**: Codes 1/2/3/4 = offen/storniert/verifiziert/bezahlt; Code 4 = "Bezahlt" (die Monats-CSV genuegt, Betreiber-Entscheidung); optional "auch durch Rechnung belegt" per Rechnungs-Upload (PDF/Bild/Text, zweistufig) prueft nur bekannte Ids/Referenz-Nr. und den Betrag je Zeile; Box trennt "erwartet" von "belegt". Test `VermittlerRechnungTest` (ohne Fix 12/12 rot).
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
