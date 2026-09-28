# System-Audit 28.09.2026 - Befunde, Behebung, Nachpruefung

Auftrag des Betreibers: vollstaendige, tiefe Pruefung des Systems mit
Verifikation jedes Befunds, Erstbericht VOR den Reparaturen, Reparatur,
Tests, erneute Pruefung von Null und Schlussbericht.

Stand der Pruefung: `main` = 8251cec (nach PR #355). Befunde tragen die
Kennungen **KI-025 bis KI-040** im Issue-Register (KI-038..040 aus der
Nachpruefung, Teil C)
(`docs/project-knowledge/KNOWN_ISSUES.md`).

---

## Teil A - Erstbericht (vor jeder Aenderung)

### A.1 Zusammenfassung

Das System ist in einem **guten, gepflegten Zustand**. Die Ausgangslage
wurde gemessen, nicht angenommen:

| Pruefung | Ergebnis vor der Reparatur |
|---|---|
| Volle Testsuite (`phpunit`, SQLite) | 3087 bestanden, 0 fehlgeschlagen, 0 uebersprungen |
| `composer stan` (PHPStan Stufe 5 + Baseline) | 0 Fehler |
| `composer lint` (Pint) | gruen |
| `composer audit` | 0 Sicherheitsmeldungen |
| `npm audit` | 0 Schwachstellen |
| `npm run build` | gruen |

Die frueheren Audits (SEC-1..5, ARCH-1..8, 15./16.09.) haben die breiten
Klassen bereits geschlossen: SQL-Injection (keine Nutzereingabe in
Roh-SQL gefunden, Spaltennamen immer aus Whitelists), Prozessaufrufe
(ausschliesslich Array-Form von `Process`), CSP mit Nonce, signierte
Webhooks mit `hash_equals`, OAuth mit `state`, Rollen- und
Portfolio-Pruefungen an den Routen.

Gefunden wurden deshalb **keine breiten Loecher, sondern Stellen, an
denen zwei fuer sich richtige Teile zusammen ein Loch ergeben** - genau
die Art, die ein Einzeltest nicht sieht:

- Die CSP gilt nur fuer HTML-Antworten **und** Dateien aus fremder Quelle
  (E-Mail-Anhang, WhatsApp) werden mit dem Typ ausgeliefert, den ihr
  Inhalt nahelegt -> eine SVG laeuft ohne CSP im Ursprung der
  Beraterwelt (**KI-025, HIGH**).
- Ein Zugangslink ist signiert und befristet **und** nichts merkt sich,
  dass er schon benutzt wurde -> ein alter Link setzt ein laengst
  geaendertes Passwort ein weiteres Mal (**KI-026, HIGH**).
- Der Bestaetigungscode der E-Signatur hat 6 Versuche **und** jeder neue
  Code setzt den Zaehler zurueck -> die Grenze ist keine (**KI-027**).

### A.2 Befunde nach Schwere

Legende Einstufung: **Confirmed** = nachgestellt (Test oder Code-Pfad
lueckenlos belegt) · **Potential** = moeglich, aber an Bedingungen
geknuepft, die aus dem Repository nicht belegbar sind · **False
Positive** = geprueft und verworfen · **Improvement** = kein Fehler,
Empfehlung.

| ID | Schwere | Einstufung | Kurz | Prioritaet |
|---|---|---|---|---|
| KI-025 | HIGH | Confirmed | Stored XSS: SVG/HTML aus E-Mail-/WhatsApp-Anhaengen inline im Ursprung der Beraterwelt bzw. des Portals, SVG ohne CSP | P1 |
| KI-026 | HIGH | Confirmed | Magic-Login (90 Tage) und Einladungslink (14 Tage) bleiben nach dem Setzen des eigenen Passworts benutzbar | P1 |
| KI-027 | MEDIUM | Confirmed | E-Signatur: neuer Code setzt Fehlversuche zurueck, Codeversand unbegrenzt (Raten + Postfach fluten) | P1 |
| KI-028 | MEDIUM | Confirmed | HTML-Injection per `innerHTML` in Kopfzeilen-Suche und Kundensuchen (Kundennamen, Ticket-Betreffe aus oeffentlichen Formularen) | P2 |
| KI-029 | MEDIUM | Confirmed | E-Mail-Eingang: Kundensuche fuer die Rolle **support** tot (Endpunkt nur admin/manager -> 403) | P2 |
| KI-030 | MEDIUM | Confirmed | E-Signatur: gleichzeitiges Absenden -> doppelter Abschluss (PDF + Mails doppelt), Unterschriftsbild nach Abschluss ueberschreibbar | P2 |
| KI-031 | LOW | Potential | WhatsApp: eine Zustellung mit mehreren Rufnummern wird komplett dem ersten Konto zugeordnet | P3 |
| KI-032 | LOW | Confirmed | Sprachumschalter: `back()` folgt einem fremden Referer; aendert per GET die Kundenakte | P3 |
| KI-033 | MEDIUM | Confirmed (Designfrage) | Oeffentliche Formulare haengen eine UNGEPRUEFTE Anfrage allein ueber die E-Mail-Adresse an eine Kundenakte - sie erscheint im Portal des Kunden | Entscheidung Betreiber |
| KI-034 | LOW | Confirmed (Designfrage) | Abmeldelink wirkt schon beim GET - Link-Scanner (Outlook Safe Links) melden Kunden ab | Entscheidung Betreiber |
| KI-035 | LOW | Confirmed (Designfrage) | 2FA-Schalter AUS entwertet auch bereits eingerichtete zweite Faktoren | Entscheidung Betreiber |
| KI-036 | LOW | Improvement | `ActivityLog.meta` an ~85 Stellen doppelt kodiert (abgefangen durch `metaArray()`) | P4 |
| KI-037 | LOW | Improvement | `SvgSanitizer` laesst `<style>`/`url()` durch (nur Fremdladen, kein Skript; Upload nur Personal) | P4 |

Verworfen (**False Positive**, mit Begruendung, damit niemand sie erneut
prueft):

- *Offene Weiterleitung beim Banner-Klick* (`/\evil.com`): `redirect()->to()`
  macht daraus eine ABSOLUTE Adresse auf dem eigenen Host.
- *Kundennummer-Kollision*: `Customer::save()` faengt die
  Unique-Verletzung ab und zieht einmal neu.
- *SQL-Injection in `selectRaw`*: alle variablen Spalten kommen aus festen
  Listen (`CommissionAnalytics::groupedBy`, `VermittlerReportService`).
- *Webhook-Faelschung*: HMAC ueber den rohen Koerper, `hash_equals`,
  kein Konto = keine Annahme.
- *Befehlseinschleusung* (tesseract/pdftotext/pdftoppm): nur Array-Form.
- *Race bei Kampagnenversand*: Unique-Index + Protokolleintrag vor dem
  Versand (bewusste Entscheidung "hoechstens einmal").

### A.3 Befunde im Detail

#### KI-025 - Stored XSS ueber Dateien aus fremder Quelle (HIGH, Confirmed)

- **Beleg**: `InlineDateiauslieferungTest` (ohne Fix 4 von 5 rot:
  `Content-Type: image/svg+xml` bzw. `text/html`, Anzeige inline).
- **Ursache**: `Storage::response()` errät den Typ aus dem Inhalt;
  `SecurityHeaders` setzt die CSP nur auf `text/html`. Eine SVG mit
  `<script>` laeuft damit OHNE jede Richtlinie im Ursprung
  `admin.dienstly24.de` - mit der Sitzung des Mitarbeiters.
- **Weg hinein**: jeder beliebige E-Mail-Absender (Anhang ->
  `EmailAttachmentService::createDocuments`) und jeder WhatsApp-Kontakt
  (`AttachmentFilingService`). Ein Mitarbeiter klickt "Anzeigen" im
  Dokumenten-Eingang oder in der Kundenakte. Macht er das Dokument fuer
  den Kunden sichtbar, gilt dasselbe im Portal.
- **Betroffen**: `Admin\CustomerDocumentController::documentDownload`
  (`?view=1`), `PortalController::documentView`,
  `CustomerMessageController`/`PortalMessageController::viewAttachment`
  (Typ kam dort aus der Plattform-Angabe).
- **Reparatur**: EINE Stelle (`App\Support\InlineDatei`) entscheidet am
  INHALT, ob eine Datei inline gezeigt werden darf (PDF, JPEG, PNG, WebP,
  GIF); alles andere wird als Download ausgeliefert. Dazu
  `X-Content-Type-Options: nosniff` und eine abschliessende
  `Content-Security-Policy` auch fuer die inline gezeigten Dateien.

#### KI-026 - Wiederverwendbare Zugangslinks (HIGH, Confirmed)

- **Beleg**: Test `EinmalLinkTest` - Link benutzen, Passwort setzen,
  denselben Link erneut benutzen: vorher angemeldet bzw. Passwort erneut
  gesetzt.
- **Ursache**: `magic.login` (90 Tage) und `password.setup` (14 Tage)
  pruefen nur Signatur und Ablauf. Nichts haelt fest, dass der Link
  seinen Zweck erfuellt hat.
- **Wirkung**: wer eine alte Willkommens- oder Einladungsmail in die
  Haende bekommt (weitergeleitet, geteiltes Postfach, altes Geraet),
  meldet sich beim Kunden an bzw. setzt das Passwort eines Mitarbeiters
  neu - auch Wochen nachdem der Berechtigte sein eigenes Passwort
  gewaehlt hat. Beim Kunden gibt es keinen zweiten Faktor.
- **Reparatur**: ein Link gilt nur, wenn er NACH dem letzten bewusst
  gesetzten Passwort (`password_changed_at`) ausgestellt wurde. Der
  Ausstellungszeitpunkt steckt bereits signiert im Link (`expires` minus
  Gueltigkeit) - keine neue Spalte, keine Migration, und bereits
  verschickte, noch unbenutzte Links funktionieren weiter.

#### KI-027 - Bestaetigungscode der E-Signatur ohne echte Grenze (MEDIUM, Confirmed)

- **Ursache**: `SignatureTokenService::issueVerificationCode()` setzt
  `verification_attempts` auf 0; der Codeversand ist nur ueber den
  allgemeinen Token-Limiter (120/min) begrenzt.
- **Wirkung**: mit dem Link (ohne Postfach) ~17 Codes und ~100 Rateversuche
  je Minute -> der 6-stellige Code faellt statistisch in wenigen Tagen;
  nebenbei gehen bis zu 17 Mails je Minute an den Unterzeichner.
- **Reparatur**: hoechstens ein Code je Minute und 5 je Stunde je
  Unterzeichner; Fehlversuche werden ueber alle Codes einer Stunde
  gezaehlt (Limiter, keine Migration).

#### KI-028 - HTML-Injection in Suchlisten (MEDIUM, Confirmed)

- `layouts/admin.blade.php` (Kopfzeilen-Suche: Kundenname,
  Ticket-Betreff), `employee_edit`, `employee_show`, `email_inbox`,
  `email_message`, `banners` bauen Trefferzeilen per `innerHTML` aus
  Fremddaten. Ticket-Betreff und Kundenname kommen aus oeffentlichen
  Formularen bzw. der Registrierung. Die CSP verhindert Skripte - nicht
  aber eingeschleuste Links, Formular-Attrappen oder Tracking-Bilder
  (`img-src https:`). Reparatur: escapen bzw. `textContent`.

#### KI-029 - E-Mail-Eingang: Kundensuche fuer Support tot (MEDIUM, Confirmed)

- Der Eingang ist fuer admin/manager/support freigegeben, die Suche darin
  ruft aber `admin.employees.customer-search` (nur admin/manager). Fuer
  Support kam 403 zurueck, die Liste blieb einfach leer - Zuordnen
  unmoeglich. Reparatur: die portfolio-gescopte gemeinsame Kundensuche
  `admin.customers.search`. Die Zuordnung selbst prueft weiterhin
  `canAccessCustomer`.

#### KI-030 - E-Signatur: gleichzeitiges Absenden (MEDIUM, Confirmed)

- `sign()` prueft `hasSigned()` ohne Sperre; zwei gleichzeitige Anfragen
  (Doppelklick, zweiter Reiter) laufen beide durch, `complete()` laeuft
  zweimal (zwei PDF-Erzeugungen, jede Abschluss-Mail doppelt), und die
  zweite Zeichnung ueberschreibt die Bilddatei NACH dem Abschluss - das
  gespeicherte Bild waere dann nicht mehr das im PDF. Reparatur: Sperre
  je Unterzeichner um `sign()`, Sperre + erneute Pruefung je Vorgang um
  `complete()`.

#### KI-031 - WhatsApp: mehrere Rufnummern in einer Zustellung (LOW, Potential)

- Der Controller bestimmt das Konto aus der ERSTEN `phone_number_id`; der
  Job verarbeitet dann alle Eintraege unter diesem Konto. Liefert Meta
  Nachrichten zweier eigener Nummern in einer Zustellung, landet die der
  zweiten Nummer beim falschen Konto (Antwort ueber die falsche Nummer).
  Reparatur: der Adapter verarbeitet nur Eintraege seiner eigenen
  Rufnummer, der Controller stoesst je Nummer einen eigenen Job an.

#### KI-032 - Sprachumschalter (LOW, Confirmed)

- `back()` nimmt den `Referer` unbesehen - auch einen fremden Host.
  Reparatur: nur Adressen des eigenen Hosts, sonst Startseite.

#### KI-033 bis KI-035 - Designfragen (Entscheidung Betreiber)

- **KI-033**: `/hilfe`, `/kontakt`, `/api/website-contact` und das
  Leistungsseiten-Formular ordnen eine Anfrage OHNE jede Pruefung der
  Kundenakte mit derselben E-Mail zu. Jeder, der die Adresse eines Kunden
  kennt, legt so einen Vorgang in dessen Akte und in dessen Portal an -
  der Mitarbeiter sieht einen scheinbar vom Kunden stammenden Auftrag
  ("bitte IBAN aendern ..."). Vorschlag: zuordnen, aber sichtbar als
  "Absender nicht verifiziert" kennzeichnen und NICHT im Portal zeigen,
  bis ein Mitarbeiter bestaetigt. Aendert einen Geschaeftsablauf ->
  nicht eigenmaechtig umgesetzt.
- **KI-034**: GET auf den Abmeldelink meldet sofort ab. Sicherheits-
  scanner mancher Mailsysteme rufen Links vorab auf. Vorschlag: GET zeigt
  eine Seite mit Knopf, der POST (RFC 8058) bleibt Ein-Klick. Rechtlich
  unkritisch (ein Klick mehr), aber eine Aenderung am Abmeldeweg -> Frage
  an den Betreiber.
- **KI-035**: Ist der globale 2FA-Schalter AUS, wird auch ein
  eingerichteter zweiter Faktor nicht mehr abgefragt. Vorschlag: wer
  eingerichtet hat, wird immer gefragt.

#### KI-036 / KI-037 - Verbesserungen

- **KI-036**: `ActivityLog::record()` existiert, ~85 Stellen schreiben
  weiter `json_encode(...)` in eine Array-Spalte. Lesend abgefangen
  (`metaArray()`), aber jede JSON-Abfrage auf `meta` waere falsch.
  Mechanische Umstellung in einem eigenen PR.
- **KI-037**: `SvgSanitizer` entfernt Skripte zuverlaessig, laesst aber
  `<style>` und `url()` stehen (Fremdladen moeglich). Upload nur durch
  Personal.

### A.4 Was aus dem Repository NICHT pruefbar ist

Unveraendert gegenueber `SECURITY_AUDIT.md`: Serverzustand (Firewall,
Origin-Erreichbarkeit, Worker/Cron, Sicherung auf dem VPS), echte
Postfaecher, Meta-/Google-Konten. Diese Punkte stehen als OPEN
(Betreiber) im Register (KI-002/003/004/013) und werden hier nicht als
geprueft ausgegeben.

---

## Teil B - Reparaturen

Jeder Fix hat einen Test, der OHNE den Fix scheitert (nachgewiesen durch
Zuruecknehmen der Code-Aenderung und erneuten Lauf).

| ID | Aenderung | Dateien | Test (ohne Fix rot) |
|---|---|---|---|
| KI-025 | `App\Support\InlineDatei`: inline nur PDF/JPEG/PNG/WebP/GIF, am Inhalt bestimmt; sonst Download | `InlineDatei`, `Admin\CustomerDocumentController`, `PortalController`, `CustomerMessageController`, `PortalMessageController` | `InlineDateiauslieferungTest` (5 von 6 rot) |
| KI-026 | `App\Support\EinmalLink`: gilt nur, wenn nach `password_changed_at` ausgestellt | `EinmalLink`, `MagicLoginController`, `PasswordSetupController`, `CustomerWelcomeMail`, `lang/ar.json` | `EinmalLinkTest` |
| KI-027 | Codeversand 1/min + 5/h je Unterzeichner, 10 Fehlversuche/h ueber alle Codes | `SignatureSigningController`, `lang/{de,ar,en}/signing.php` | `SignaturCodeUndGleichzeitigkeitTest` |
| KI-028 | Escapen/`textContent` statt roher Fremddaten in `innerHTML`; Waechter-Test ueber alle Views | `layouts/admin`, `layouts/portal`, `employee_edit`, `employee_show`, `email_inbox`, `email_message`, `banners` | `SuchlistenFremddatenTest` |
| KI-029 | E-Mail-Eingang nutzt `admin.customers.search` | `email_inbox`, `email_message` | `SuchlistenFremddatenTest` |
| KI-030 | `Cache::lock` je Unterzeichner um `sign()`, je Vorgang um `complete()`, Zustand unter Sperre neu gelesen | `SignatureSigningService` | `SignaturCodeUndGleichzeitigkeitTest` |
| KI-031 | Zustellung je Rufnummer aufgeteilt, ein Job je Konto | `WhatsAppAdapter::splitByPhoneNumberId`, `WhatsAppWebhookController` | `WhatsAppChannelTest::test_zwei_nummern_...` |
| KI-032 | Rueckweg nur auf den eigenen Host | `routes/web.php` | `SprachumschalterWeiterleitungTest` |
| KI-036 | Mutator speichert `meta` immer als JSON-Objekt | `ActivityLog` | `ActivityLogMetaKodierungTest` |
| KI-037 | `@import`/externe `url()` aus SVG-CSS entfernt, `url(#..)` bleibt | `SvgSanitizer` | `SvgSanitizerExterneVerweiseTest` |

Nicht umgesetzt (Entscheidung des Betreibers noetig): KI-033, KI-034,
KI-035 - Begruendung in A.3.

---

## Teil C - Nachpruefung von Null (Phase 6)

Die Nachpruefung lief nicht als "sind die alten Befunde weg?", sondern als
neuer Durchgang mit anderen Fragen (Konten per ID, Doppelklick/Race,
Fehlermeldungen als Orakel) plus einer feindlichen Durchsicht der eigenen
Aenderungen. Sie fand drei WEITERE Befunde - alle behoben:

| ID | Schwere | Befund | Fix | Test |
|---|---|---|---|---|
| KI-040 | **HIGH** | `EmployeeController` lud jedes Konto per ID: Manager machte aus einem KUNDENKONTO einen Manager (Zugang zur Beraterwelt), sperrte Kundenkonten (sonst admin-only); Admin loeschte Kundenakten am `CustomerDeletionService` vorbei. Vertretung und Postfach-Zuweisung nahmen Kunden-/Partnerkonten an | Rollenfilter "Personal" an allen Stellen | `MitarbeiterverwaltungNurPersonalTest` (ohne Fix rot) |
| KI-038 | LOW | `/reset-password` mit beliebigem Token verriet, ob ein Konto existiert | gleiche Meldung | `EinmalLinkTest` (ohne Fix rot) |
| KI-039 | LOW | Doppelklick auf den Registrierungs-Bestaetigungslink -> HTTP 500 | Unique-Verletzung abgefangen, Rollback vollstaendig | `RegistrierungDoppelklickTest` (ohne Fix rot) |

Feindliche Durchsicht der eigenen Aenderungen:

- `InlineDatei`: fehlende Datei -> Pruefung auf Existenz steht in jedem
  Aufrufer davor; nicht bestimmbarer Typ -> Download (sicherer Fall).
- `EinmalLink`: bereits verschickte, noch unbenutzte Links gelten weiter
  (kein neuer Parameter); `password_changed_at` wird nur von
  `setPassword()` geschrieben - Startpasswort und Registrierung setzen es
  nicht, deshalb keine Selbstaussperrung. Vergleich STRENG spaeter.
- Sperren: verschiedene Schluessel fuer Unterzeichner und Vorgang - keine
  Verklemmung; `cache_locks` existiert fuer den Datenbank-Cache, Redis
  kann Sperren nativ. Eine abgelaufene Wartezeit landet im bestehenden
  "Nachlauf gescheitert"-Zweig, die Unterschrift bleibt gespeichert.
- Kein Regressionsfund: volle Suite gruen, PHPStan 0.

---

## Teil D - Schlussbericht

1. **Zustand vorher**: gepflegt, gruen in allen Pruefwerkzeugen; keine
   breiten Loecher, aber Stellen, an denen zwei richtige Teile zusammen ein
   Loch ergeben.
2. **Befunde** (16): HIGH 3 (KI-025, KI-026, KI-040) · MEDIUM 5 (KI-027,
   028, 029, 030, 033) · LOW 8 (KI-031, 032, 034, 035, 036, 037, 038, 039).
   Dazu 6 geprueft und verworfen (False Positives, A.2).
3. **Behoben**: 13 - KI-025..032, KI-036..040.
4. **Nicht behoben**: KI-033/034/035 - Aenderungen an Geschaeftsablaeufen
   bzw. an einer bewussten Schutzeinstellung, Entscheidung des Betreibers.
5. **Geaenderte Dateien**: siehe Teil B/C und `CHANGELOG.md` (28.09.2026).
6. **Tests**: vorher 3087/3087; nachher **3121/3121** (34 neue, jeder ohne
   Fix rot), 0 uebersprungen (tesseract + poppler installiert);
   `composer stan` 0; `composer lint` gruen; `composer audit` 0;
   `npm audit` 0; `npm run build` gruen.
7. **Sicherheits-Nachpruefung**: Angreifer-Gegenproben in
   `docs/project-knowledge/SECURITY_AUDIT.md` (Abschnitt 28.09.2026).
8. **Browser** (Chromium, echte Sitzung): Kopfzeilen-Suche mit
   `<img>`/`<b>` im Kundennamen zeigt Text statt Markup; E-Mail-Eingang als
   Support: Suche 200, Treffer, Zuordnen-Knopf aktiv; keine JS-Fehler.
9. **Regression**: keine gefunden. Gewollte Verhaltensaenderungen: alter
   Magic-Link nach eigenem Passwort -> 403 mit Hinweis; "Anzeigen" von
   Nicht-PDF/Nicht-Bild -> Download.
10. **Restrisiken**: KI-033..035 (offen); MySQL-Lauf und Deploy nur in der
    CI pruefbar; Serverseite unveraendert UNKNOWN (KI-002/003/004/013).
11. **Manuelle Schritte**: keine fuer diesen PR (keine Migration, keine
    `.env`-Aenderung). Nach dem Merge: CI (inkl. MySQL) abwarten.
12. **Empfehlungen**: die drei Entscheidungen KI-033..035 treffen; die
    ~85 `json_encode`-Aufrufe bei Gelegenheit auf `ActivityLog::record()`
    umstellen; fuer jede neue Datei-Anzeige `InlineDatei` benutzen und fuer
    jede Konto-Auswahl "aktives Personal" validieren - beides steht jetzt in
    `CLAUDE.md`.

---

## Teil E - Unabhaengige Nachpruefung vor dem Merge (PR #358)

Jeder Fix wurde aus der Rolle des Angreifers auf Umgehungswege geprueft.

| Fix | Umgehungsversuch | Ergebnis |
|---|---|---|
| KI-025 | Nachweis-Upload: echte PNG + HTML, Browser meldet `text/html` (`ChangeRequestDocument.mime` = Client-Angabe) | **kein Fund** - `isViewable()` laesst nur sichere Typen inline, sonst Download; als Waechter-Test behalten |
| KI-025 | finfo erkennt Text nicht eindeutig -> Rueckfall auf die Endung (`x.pdf`) | kein Fund - es kommen nur Typen der Positivliste in Frage, mit `nosniff` fuehrt keiner Code aus |
| KI-026 | **Admin "Portal zuruecksetzen"** nach geleakter Mail | **FUND KI-041 (MEDIUM)**: alter Magic-Link blieb gueltig -> behoben, Test ohne Fix rot |
| KI-026 | Parameter `expires` manipulieren | kein Fund - signiert |
| KI-040 | andere Wege, ein Konto per ID anzusprechen (Tickets, Postfach, Vertretung, Einladung, 2FA-Reset, Partner) | kein Fund - ueberall Personal-/Rollenpruefung |
| KI-028 | breitere Suche nach `innerHTML` mit Fremddaten | einziger Rest `customer_show` (Name der EIGENEN lokalen Datei im Upload-Dialog - kein Fremddatum) |
| KI-032 | `//fremd`, `user@fremd`-Referer | kein Fund - Host-Vergleich nach `parse_url` |
| KI-027/030 | neuer Code je Minute, zweiter Reiter | kein Fund - Limiter je Unterzeichner, Sperre je Unterzeichner/Vorgang |

Restrisiken (unveraendert, bewusst): KI-033..035 (Entscheidung Betreiber);
ein Kunde OHNE Startpasswort, der nie ein eigenes Passwort setzt, kann den
Magic-Link bis zum Ablauf wiederverwenden (der Link IST dann sein einziger
Zugang); "Einladung erneut senden" beim Personal entwertet die alte
Einladung nicht (Personal hat zusaetzlich den zweiten Faktor).

**Nachtrag Teil F**: die letzte Zeile oben ("Einladung erneut senden ...
entwertet die alte Einladung nicht") ist seit KI-043 ueberholt.

---

## Teil F - Threat-Model-Runde und Behebung KI-042 / KI-043 (PR #358)

Auf Betreiber-Auftrag wurden die Restrisiken aus Teil E einzeln aus der
Rolle des Angreifers bewertet. Dabei fielen zwei bestaetigte Befunde auf,
die hier behoben sind. KI-033, KI-034, KI-035 und die Mehrfachnutzung des
Magic-Links bleiben bewusst unveraendert (Betreiber-Entscheidung).

### KI-042 - 2FA-Einrichtung als zweiter Pruefweg (MEDIUM, behoben)

- **Ursache**: Die Einrichtungs-Routen sind in jedem Zustand erreichbar
  (`EnsureTwoFactor::ALLOWED_ROUTES`). `setupStore` pruefte den Code auch bei
  einem BESTAETIGTEN zweiten Faktor - gegen dasselbe Geheimnis wie die
  Abfrage, aber ohne deren Sperre (5 Fehlversuche/300 s je Konto+IP) und ohne
  `two_factor_failed`-Protokoll. Mit gestohlenem Passwort: 10 Versuche/Minute
  (nur Routen-Throttle) statt 1/Minute, unsichtbar; ein Treffer liess herein
  und erzeugte neue Ersatzcodes (die des Mitarbeiters ungueltig).
- **Nachweis vorher**: 9 Fehlversuche ueber die Einrichtung ohne Protokoll
  und ohne Sperre, danach richtiger Code -> `/admin` erreichbar, alter
  Ersatzcode ungueltig.
- **Fix**: `TwoFactorController::setupStore` leitet bei eingerichtetem Faktor
  zur Abfrage um, OHNE einen Code zu pruefen; `TwoFactorService::confirmSetup`
  lehnt einen bestaetigten Faktor zusaetzlich ab. Die ERSTE Einrichtung
  zaehlt Fehlversuche im selben Limiter-Schluessel wie die Abfrage
  (`2fa:<id>|<ip>`) und protokolliert sie (`two_factor_failed`, `weg:
  einrichtung`) - kein Weg verschafft dem anderen zusaetzliche Versuche.
- **Tests**: `ZweiFaktorEinrichtungUmgehungTest` (6). Ohne Fix rot: 5 von 6;
  der sechste haelt fest, dass die normale Ersteinrichtung weiter
  funktioniert (muss in beiden Faellen gruen sein).

### KI-043 - Zugangslinks ueberlebten Aenderungen durch die Verwaltung (MEDIUM, behoben)

- **Ursache**: `EinmalLink` kannte nur `password_changed_at` (selbst
  gewaehltes Passwort). Die Kundenakte schrieb ein von der Verwaltung
  gesetztes Passwort direkt per `bcrypt`, eine neue Login-Adresse beruehrte
  keinen Zustand, "Einladung erneut senden" liess die vorigen Links stehen.
- **Nachweis vorher**: Adresse UND Passwort in der Kundenakte geaendert, der
  Magic-Link an die alte Adresse meldete danach weiter an (302 ins Portal).
- **Fix - ein Widerrufsstand statt verteilter Pruefungen**:
  `users.zugangslink_version` (Migration `2026_09_28_140000`). Jeder neue
  Link traegt den Stand als signierten Parameter `v`
  (`EinmalLink::parameter()`, die EINE Stelle fuer Magic-Login UND
  Einladung); `EinmalLink::nochGueltig()` verlangt Gleichheit mit dem Stand
  am Konto. Hochgezaehlt wird nur ueber `User::zugangslinksWiderrufen()`,
  atomar in der Datenbank (ein veraltetes Modell kann den Stand nicht
  zurueckschreiben). Ausloeser: Aenderung der Login-Adresse (Modell-Hook -
  gilt fuer jeden Schreibweg), von der Verwaltung gesetztes Passwort
  (`User::setzeVerwaltungsPasswort()`, ersetzt beide direkten
  `bcrypt`-Stellen in `AdminController`), Portal-Reset, jede neu
  verschickte Einladung (Kunde und Personal).
  **Warum ein Zaehler und kein Zeitstempel "ungueltig ab"**: der Vergleich
  in Sekunden liess einen Link gelten, der in derselben Sekunde VOR dem
  Widerruf entstand (zweimal "senden"). KI-041 wird jetzt ebenfalls ueber
  den Zaehler geloest; `password_changed_at` steht wieder nur fuer ein
  selbst gewaehltes Passwort (so war es in der Migration vom 18.08.2026
  definiert).
  **Bestand**: Links von vor dem Deployment tragen kein `v`, zaehlen als 0
  und gelten, bis an ihrem Konto zum ersten Mal widerrufen wird.
- **Tests**: `ZugangslinkWiderrufTest` (8, Links aus der WIRKLICH
  verschickten Mail); `EinmalLinkTest::test_portal_reset_entwertet_alte_links`
  benutzt jetzt ebenfalls die echte Mail und laeuft ohne Zeitreise (Reset
  und alter Link koennen in dieselbe Sekunde fallen - der fruehere
  Sekunden-Vergleich haette dann durchgelassen, der Zaehler nicht). Mutationen, jeweils rot: Pruefung des Standes aus -> 6 Faelle;
  Adress-Hook aus -> 2; Widerruf beim Verwaltungspasswort aus -> 1;
  Hochzaehlen am Objekt statt in der DB -> 1. Der Fall "manipuliertes `v`"
  ist ein Signatur-Waechter und in beiden Faellen gruen.

### Einladung des Personals: Lebenslauf vorher/nachher

| | vorher | nachher |
|---|---|---|
| erneut senden | neuer Link, alle alten weiter 14 Tage gueltig | alte ungueltig, nur der neue gilt |
| eine Einladung angenommen | alle anderen ungueltig (KI-026) | unveraendert |
| Konto deaktiviert | alle ungueltig | unveraendert |
| Manager sendet an Administrator | erlaubt | 403 - dieselbe Grenze wie beim Bearbeiten, weil das Senden jetzt die Links des Kontos widerruft |
| Rolle/Rechte | Link setzt nur das Passwort | unveraendert (Test) |

Tests: `MitarbeiterEinladungErneutSendenTest` (6). Ohne Widerruf rot:
`test_nach_erneutem_senden_gilt_nur_die_neue_einladung`; ohne die
Manager-Grenze rot: `test_manager_kann_die_einladung_eines_administrators_nicht_erneut_senden`.
Die uebrigen vier sind Waechter fuer Eigenschaften, die schon vorher galten
(Rolle unveraendert, Konto nicht umbiegbar, nach Annahme alle verbraucht,
Manager darf Mitarbeitern weiter senden).

### Nachpruefung KI-025..KI-041 (diese Runde)

| Befund | Umgehung gesucht | Ergebnis | Test-Qualitaet |
|---|---|---|---|
| KI-025 | alle Datei-Auslieferungen (`->response`, `->download`, `Content-Disposition`, `->file`), oeffentliche Platte | kein Fund: Anzeigen nur ueber `InlineDatei`/`isViewable`; Mail-Anhaenge nur als Download; Signatur-PDF/-Bilder erzeugt die Anwendung selbst; kein Fremd-Dokument auf `public` | verhaltensbasiert, ohne Fix rot |
| KI-026/041/043 | Ausstellung, Signatur, Ablauf, Widerruf, Passwort-/Adresswechsel, Reset, erneut senden, Wiederholung | nach KI-043 kein Fund; offen bleibt die Mehrfachnutzung bis zum eigenen Passwort (Designfrage) | Links aus der echten Mail, Mutationen belegt |
| KI-027 | Limiter je Unterzeichner | kein Fund | ohne Fix rot |
| KI-030 | Gleichzeitigkeit | **Pruefluecke geschlossen**: die bisherigen Tests belegten nur das Neu-Lesen innerhalb der Sperre. Neu: gehaltene Sperre -> Unterschreiben bzw. Abschluss schreibt NICHTS (ohne Sperre rot). Zusaetzlich mit zwei echten PHP-Prozessen gegen den `database`-Cache belegt: Prozess B erhielt die Sperre erst nach der Freigabe durch A (3,27 s Wartezeit) | jetzt Sperre selbst geprueft |
| KI-028 | ALLE `innerHTML`/`outerHTML`/`insertAdjacentHTML` und mehrzeilige Vorlagen | kein Fremddatum roh; zwei begruendete Ausnahmen (eigener lokaler Dateiname im Upload-Dialog, feste Symbolliste im Tarifrechner) | **Waechter verbreitert**: jede Variable/Eigenschaft, drei Senken, mehrzeilige Vorlagen; Mutationen belegt |
| KI-032 | `//`, `///`, `user@`, Subdomain, `https:x`, `javascript:`, `data:`, Rueckstrich-Varianten, Fragment-/Abfrage-Trick, Grossschreibung | kein Fund; 14 Faelle als Test, 7 davon ohne Fix rot | jetzt vollstaendig |
| KI-029, 031, 036..040 | wie Teil E | kein Fund | unveraendert |

### Bewusst NICHT geaendert (Betreiber-Entscheidung)

KI-033 (oeffentliche Formulare ordnen per E-Mail zu), KI-034 (Abmeldung
beim GET), KI-035 (2FA-Schalter AUS entwertet eingerichtete Faktoren) und
die Mehrfachnutzung des Magic-Links bis zum eigenen Passwort bzw. bis zum
Ablauf nach 90 Tagen.
