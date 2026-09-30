# Changelog

Aenderungsprotokoll ab Anlage der Wissensbasis (23.09.2026). Aeltere
Aenderungen: `git log`, `CLAUDE.md` (datierte Abschnitte) und `docs/*.md`.
Neueste Eintraege oben. Format je Eintrag:
Date · Task · Files Changed · Components Affected · Database Changes ·
API Changes · Potential Side Effects · Tests Performed · Result.

---

## 30.09.2026 - Entgeltabrechnung: Kunde und Arbeitgeber werden gelesen (KI-045)

- **Task**: Betreiber-Meldung mit einer echten Abrechnung: "erkenne den Lohnzettel - uns interessieren Name und Anschrift des Kunden und der Name des Arbeitgebers; der steht ueblicherweise EINZEILIG ueber der Kundenanschrift".
- **Files Changed**: `GehaltsabrechnungParser` (Titel-Liste, Empfaengerblock ueber Spaltenposition, Arbeitgeber, Geburtsdatum, IBAN, Netto); neu `app/Support/Adresszeile.php`; `ClaudeDocumentAiProvider` (Prompt-Absatz zur Entgeltabrechnung); `tests/Feature/Ai/GehaltsabrechnungParserTest.php` (+6 Faelle).
- **Components Affected**: Dokumenten-Eingang (Typ-Erkennung, Kunden-Zuordnung, Review-Modal Gruppe "Arbeitgeber"), KI-Eskalation.
- **Database Changes**: keine. `customers.employer_name`/`employer_address` bestehen seit dem Arbeitsvertrag-Parser.
- **API Changes**: keine.
- **Potential Side Effects**: Die Typ-Erkennung greift bei sechs weiteren Ueberschriften - ein Dokument, das bisher "Sonstiges" war, kann jetzt `gehaltsabrechnung` werden. Der Arbeitgeber steht zusaetzlich als Feld (bisher nur Zusammenfassung); uebernommen wird er weiterhin nur per Haken im Review.
- **Tests Performed**: Am ECHTEN Dokument gemessen (pdftotext -layout) - vorher `NULL`, nachher Name, Anschrift, Arbeitgeber + Anschrift, Geburtsdatum, IBAN, Brutto/Netto. Gegenprobe an der bisherigen Bauform: unveraendert vollstaendig, zusaetzlich Arbeitgeber-Felder. Mutationsprobe: 6 der 8 Parser-Tests scheitern ohne den Fix. Volle Suite 3188/3188 gruen, 0 uebersprungen; `pint --test` sauber; PHPStan Stufe 5: 0 Fehler.
- **Result**: IMPLEMENTED.

---

## 30.09.2026 - KI-044: Rueckseite von Aufenthaltstitel und Personalausweis

- **Task**: Betreiber-Meldung mit zwei Fotos: vom Personalausweis und vom Aufenthaltstitel werden vor allem Anschrift und Geburtsort gebraucht, erkannt wurde nur die Vorderseite.
- **Files Changed**: neu `app/Services/Ai/Concerns/LiestDeutscheAusweiskarte.php`, `app/Services/Ai/TemplateParsers/PersonalausweisParser.php`; `AufenthaltstitelParser` (auf den Baustein umgestellt), `AppServiceProvider` (Registrierung vor dem Aufenthaltstitel), `DocumentAnalyzer::acceptTemplateOrEscalate` (`pflichtangaben`), `TesseractTextExtractor` (MRZ im Original nachlesen), `ClaudeDocumentAiProvider` (Prompt: Personalausweis); Test neu `AusweiskartenRueckseiteTest`.
- **Database Changes**: keine.
- **API Changes**: keine.
- **Potential Side Effects**: Fotos des Personalausweises werden jetzt gratis gelesen (Typ `personalausweis`) statt ueber Heuristik/KI. Eine erkannte Ausweis-Rueckseite OHNE lesbare Anschrift bzw. eine Personalausweis-Vorderseite ohne Geburtsort geht jetzt zur KI (Kosten nur in diesem Fall; Duplikat-Kostendeckel gilt). Fuer kleine Kartenfotos, deren vergroesserte Fassung keine MRZ ergibt, laeuft Tesseract ein zweites Mal (nur Kartenfotos).
- **Tests Performed**: neuer Test ohne Fix 12/14 rot; volle Suite gruen (3210 Tests); `composer stan`, `composer lint` gruen. Real: die eingesandten Fotos durch den echten `TesseractTextExtractor` + Parser-Kette - Personalausweis-Rueckseite liefert Name, Geburtsdatum, Staatsangehoerigkeit und Dokumentennummer (`LILMT...` per Pruefziffer zu `L1LMT...` repariert); die Anschrift ist auf diesem stark verkleinerten Foto unlesbar -> geht jetzt zur KI statt still zu fehlen. Das eAT-Foto (beide Seiten auf 568 px) ist fuer OCR zu klein; die Faelle sind mit dem am Foto gemessenen OCR-Rauschen als Test nachgebaut.
- **Nachtrag (zweites Foto des Betreibers, 30.09.2026)**: auf dem neuen Foto las die Originalgroesse alles, die vergroesserte Fassung aber keine MRZ und nur verstuemmelte Beschriftungen ("Anschrif/Addrenn") - das Nachlesen loeste nicht aus, und "24768 Rendsburg 4" / "Fockbeker Chaussee 90 |" / '"72 cm ...' scheiterten am Randrauschen. Behoben (Wortanfaenge, Rauschen am Zeilenende, Endungs-Lesung als Rueckfall); jetzt liefert der echte Weg auf dem Foto Name, Geburtsdatum, Nummer UND Anschrift gratis. Neuer Testfall ohne Fix rot; volle Suite 3211 gruen, stan/lint gruen.
- **Result**: KI-044 FIXED.

---

## 29.09.2026 - KI-033: Formular-Anfragen mit ungeprueftem Absender

- **Task**: Betreiber-Entscheidung zur Designfrage KI-033: Zuordnung per E-Mail bleiben lassen, aber als "Absender nicht verifiziert" markieren und dem Kunden erst nach Bestaetigung zeigen.
- **Files Changed**: neu `app/Support/FormularAbsender.php`; `SupportFormController`, `WebsiteController::submitContact`, `WebsiteContactController`, `ServicePageController::submit`, `WebsiteInquiryController::store`; `Ticket` (Konstanten, `scopeKundenSichtbar`, `absenderBestaetigen`, `vonKundenaktLoesen`), `TicketEvent` (zwei Ereignisse); `PortalController` (alle Ticket-Abfragen); KI-Werkzeuge `GetOpenTickets`, `GetProcessStatus`, `CreateTicket`, `RequestDocument`; `TicketNotifier`, `AutoCloseResolvedTickets`, `TicketController` (Antwortweg, zwei neue Aktionen); Views `admin/ticket_show`, `admin/tickets`, `emails/support_inquiry`; Routen; `ROUTES_INVENTORY.md` neu erzeugt (enthaelt dabei auch drei bisher fehlende Routen aus F-011).
- **Database Changes**: Migration `2026_09_29_100000_absender_pruefung_an_tickets` - `tickets.absender_status`, `absender_geprueft_von`, `absender_geprueft_am` (alle nullable, kein Nachtrag).
- **API Changes**: `POST /admin/tickets/{id}/absender-bestaetigen`, `POST /admin/tickets/{id}/absender-loesen`.
- **Potential Side Effects**: Kunden sehen eine Anfrage, die sie selbst ueber die WEBSITE (nicht das Portal, nicht per Token-Link) geschickt haben, erst nach Bestaetigung im Portal; die Antwort des Teams erreicht sie per E-Mail wie bisher bei Gast-Anfragen. `/api/website-inquiry` ordnet jetzt auch ueber `email2` zu (wie die anderen Formulare).
- **Tests Performed**: neuer Test ohne Fix rot (4 Mutationen, jede gefangen); volle Suite, PHPStan, Pint (TESTING_STATUS Lauf 8); Chromium: echtes Website-Formular mit der Adresse eines Kunden -> Hinweis in Liste und Detail, Kundenportal zeigt nichts (Detail 404), "Absender bestaetigen" per Klick -> danach im Portal sichtbar (200), keine JS-Fehler.
- **Result**: KI-033 FIXED.

---

## 28.09.2026 - Vertragsherkunft: Eigenvertrag, Fremdvertrag, uebernommen (F-011)

- **Task**: Betreiber-Auftrag: ein als Vorvertrag erfasster Fremdvertrag (z. B. ADAC) sah aus wie ein eigener; Wochen spaeter wurde an einem Vertrag ohne Mandat gearbeitet, und jede Kennzahl zaehlte ihn mit.
- **Files Changed**: Migration `2026_09_28_100000_vertragsherkunft`; `Contract` (Konstanten, Scopes, Relationen, Portal-Einstellung, KI-Hinweis); `Admin\ContractController`; `AdminController` (Dashboard, Kundenliste, Schnellsuche); `ReportController`, `DashboardAnalyticsService`, `EmployeeController`, `PartnerPortalController`, `PortalController`; `ChangeRequestReviewController` + `CustomerChangeRequest::contractId()`; `DocumentRequestController`; `SettingsController` + `UpdateSettingsRequest`; `ContractProvisionService`, `CommissionStatusEngine`, `CommissionContractBuilder`; KI-Werkzeuge `GetCustomerContractsTool`, `GetRelevantContractInformationTool`; Views (Formular-Partial, Abzeichen, Warnleiste, Vertragsliste, Kundenakte, Vertragsakte, Dashboard, Eingang, Aenderungsantraege, Einstellungen, Portal-Vertragsliste, zwei neue Seiten); `lang/ar.json`; Routen; neuer Test `VertragsherkunftTest`, 7 Tests um `origin` ergaenzt (Pflichtfeld bei Neuanlage).
- **Database Changes**: `contracts` + 7 Spalten (siehe DATABASE_MAP). Altbestand: `origin = brokered`, `origin_verified = false` (Annahme, Pruefliste im Dashboard). Migration umkehrbar.
- **API Changes**: 3 neue Routen unter `/admin/vertragsherkunft/...`; `/admin/contracts?herkunft=eigen|fremd|alle` (Standard eigen); Schnellsuche liefert `origin`.
- **Potential Side Effects**: Dashboard "Aktive Vertraege", Auswertungs-Dashboard, Kundenliste, Kundenakte, Berichte und Portal zaehlen Fremdvertraege nicht mehr mit; nach dem Deploy steht der GESAMTE Altbestand als "ungepruefte Herkunft" im Dashboard (gewollt). Fremdvertraege buchen keine Werber-Provision und erscheinen nie als "Provision fehlt". Wird ein Eigenvertrag nachtraeglich zum Fremdvertrag, bleibt eine schon gebuchte Provision stehen (bewusst keine automatische Gegenbuchung - Finanzentscheidung).
- **Tests Performed**: `php -l` aller geaenderten PHP-Dateien. Die Testsuite lief lokal NICHT: `composer install` scheitert in dieser Umgebung an `phpstan/phpstan` (nur als GitHub-ZIP im Lockfile, Host gesperrt - vgl. KI-018/`docs/TESTUMGEBUNG.md`). Massstab ist die CI des Pull Requests. `ROUTES_INVENTORY.md` im Nachgang mit `scripts/wissensbasis-routen.php` neu erzeugt (511 Routen, +3 unter `/admin/vertragsherkunft/...`; `vendor/` dafuer per `composer install --no-dev --prefer-source` - die Laufzeitpakete brauchen den gesperrten Host nicht).
- **CI (PR #359)**: erster Lauf rot - Pint (voll qualifizierte Klassennamen) und 21 Tests durch EINEN Blade-Fehler im Dashboard (`Wort@if` wird nicht als Direktive erkannt, `@endif` schon -> ParseError). Behoben; danach Pint, PHPStan (Stufe 5), Testsuite SQLite UND MySQL gruen. Geaenderte Vorlagen zusaetzlich lokal mit dem Blade-Compiler + `php -l` geprueft.
- **Result**: IMPLEMENTED, CI gruen.

---

## 28.09.2026 - PR #358: KI-042, KI-043, Einladungs-Lebenslauf, Nachpruefung KI-025..041

- **Task**: Betreiber-Auftrag nach der Threat-Model-Runde: KI-042 und KI-043 beheben, KI-025..041 erneut auf Umgehungen pruefen. KI-033/034/035 und die Mehrfachnutzung des Magic-Links bewusst NICHT angefasst.
- **Files Changed**: `Auth\TwoFactorController`, `TwoFactorService` (KI-042); `User` (Widerrufsstand, Adress-Hook, `setzeVerwaltungsPasswort`), `EinmalLink`, `CustomerWelcomeMail`, `PasswordSetupController`, `PortalAccessService`, `AdminController`, `EmployeeController::resendInvitation` (KI-043); Tests neu `ZweiFaktorEinrichtungUmgehungTest`, `ZugangslinkWiderrufTest`, `MitarbeiterEinladungErneutSendenTest`, erweitert `EinmalLinkTest`, `SignaturCodeUndGleichzeitigkeitTest` (Sperre selbst), `SuchlistenFremddatenTest` (Waechter verbreitert), `SprachumschalterWeiterleitungTest` (14 Randfaelle).
- **Database Changes**: Migration `2026_09_28_140000_zugangslink_version_an_users` - `users.zugangslink_version` (unsigned int, Default 0).
- **API Changes**: keine neuen Routen. Verhalten: Zugangslinks tragen `v`; alte Links werden nach Adress-/Passwortaenderung durch die Verwaltung, Reset und erneutem Senden ungueltig (403); `POST /sicherheit/zwei-faktor` leitet bei eingerichtetem Faktor zur Abfrage; Manager -> Einladung an Administrator 403.
- **Potential Side Effects**: Kunden, die eine AELTERE Willkommensmail anklicken, nachdem eine neuere verschickt wurde, sehen 403 mit Hinweis - gewollt. Ersteinrichtung der 2FA sperrt nach 5 falschen Codes fuer 300 s (wie die Abfrage). Bereits verschickte Links ohne `v` gelten bis zum ersten Widerruf an ihrem Konto.
- **Tests Performed**: jeder neue Test ohne Fix bzw. per Mutation rot nachgewiesen; volle Suite, PHPStan, Pint, `composer audit`, `npm audit` (siehe TESTING_STATUS Lauf 7); Sperre der E-Signatur zusaetzlich mit zwei echten PHP-Prozessen.
- **Result**: KI-042, KI-043 FIXED.

---

## 28.09.2026 - Pre-Merge-Review PR #358: KI-041

- **Task**: unabhaengige Nachpruefung vor dem Merge (Betreiber-Auftrag): jeder Fix auf Umgehungswege geprueft.
- **Files Changed**: `PortalAccessService::resetPortal` (setzt `password_changed_at`), `EinmalLink` (Kommentar), Tests `EinmalLinkTest` (+1), `InlineDateiauslieferungTest` (+1 Gegenprobe Nachweis-Upload mit gefaelschtem Typ - kein Fund, Waechter).
- **Database/API Changes**: keine.
- **Tests Performed**: neuer Test ohne Fix rot; volle Suite, PHPStan, Pint gruen (siehe TESTING_STATUS Lauf 6).
- **Result**: KI-041 FIXED.

---

## 28.09.2026 - System-Audit: KI-025 bis KI-040

- **Task**: Betreiber-Auftrag "vollstaendige, tiefe Pruefung" (Full Audit -> Verifikation -> Erstbericht -> Reparatur -> Tests -> Nachpruefung -> Schlussbericht). Bericht: `docs/AUDIT_2026-09-28_SYSTEMPRUEFUNG.md`.
- **Files Changed**: neu `app/Support/InlineDatei.php`, `app/Support/EinmalLink.php`; `Admin\CustomerDocumentController`, `PortalController`, `CustomerMessageController`, `PortalMessageController` (KI-025); `MagicLoginController`, `PasswordSetupController`, `CustomerWelcomeMail` (KI-026); `SignatureSigningController`, `SignatureSigningService`, `lang/{de,ar,en}/signing.php` (KI-027/030); `layouts/admin`, `layouts/portal`, `employee_edit`, `employee_show`, `email_inbox`, `email_message`, `banners` (KI-028/029); `WhatsAppWebhookController`, `WhatsAppAdapter` (KI-031); `routes/web.php` Sprachumschalter (KI-032); `ActivityLog` (KI-036); `SvgSanitizer` (KI-037); `NewPasswordController` (KI-038); `RegisteredUserController` (KI-039); `EmployeeController`, `PostfachController` (KI-040); `lang/ar.json`.
- **Database Changes**: keine (keine Migration).
- **API Changes**: keine neuen Routen. Verhalten: "Anzeigen" liefert Nicht-PDF/Nicht-Rasterbild als Download; `/admin/employees/{id}` fuer Nicht-Personal 404; Signatur-Code gedrosselt; Webhook stoesst je Rufnummer einen eigenen Job an.
- **Potential Side Effects**: Kunden, die nach dem Setzen ihres Passworts erneut den Magic-Link der Willkommensmail klicken, bekommen 403 mit dem Hinweis, sich mit Passwort anzumelden (gewollt). Word-/Excel-Dateien oeffneten schon vorher nicht im Browser - unveraendert Download.
- **Tests Performed**: Ausgangslage 3087/3087; danach 3121/3121 (34 neue, jeder neue Test ohne den Fix rot nachgewiesen), 0 uebersprungen; PHPStan 0; Pint gruen; `composer audit`/`npm audit` 0; Chromium: Kopfzeilen-Suche (kein eingeschleustes HTML), E-Mail-Eingang als Support (Suche 200, Zuordnen aktiv), keine JS-Fehler.
- **Result**: FIXED (KI-025..032, 036..040); KI-033..035 OPEN (Betreiber-Entscheidung).

---

## 24.09.2026 - CI wieder gruen: veralteter PHPStan-Baseline-Eintrag (KI-024)

- **Task**: Betreiber-Meldung "Problem beim Mergen". Ursache: Job "Codeformat und statische Analyse" rot - seit PR #354 auch auf `main`, #354 wurde deshalb nie ausgeliefert.
- **Files Changed**: `phpstan-baseline.neon` (2 veraltete Eintraege raus), `VermittlerAbrechnungController` (`LocalTime::for` statt Makro), `VermittlerRechnungAbgleich` (3 Typ-Befunde).
- **Database/API Changes**: keine. **Potential Side Effects**: keine fachlichen.
- **Tests Performed**: PHPStan lokal (Umgehung KI-018) 0 Fehler; volle Suite 3082 bestanden, 0 fehlgeschlagen; Pint gruen.
- **Result**: FIXED.

---

## 23.09.2026 - TARIFCHECK24: Status-Codes richtig, Rechnung als Beleg (KI-023)

- **Task**: Betreiber-Meldung: Rechnungen nicht als PDF/Bild hochladbar; Vertragsakte zeigte 75 EUR als gezahlt, obwohl offen. Ablauf: Referenz-Nr. -> monatliche CSV (Id + Status) -> Rechnung -> belegt bezahlt.
- **Files Changed**: `VermittlerStatusMap`, `Contract` (Status `verifiziert`, `bezahlt_belegt`), `VermittlerAbrechnungImporter` (belegte Zahlung nicht zurueckstufen), `VermittlerReportService`, `CommissionStatus` (Code 3), `VermittlerListeReader::textFromBinary`, neu `VermittlerRechnungAbgleich`, `VermittlerInvoice`, Migration `2026_09_23_120000_vermittler_rechnungen`, Controller + 4 Routen, Views `vermittler_invoice`, `vermittler_abrechnung`, `vermittler_report`, `contract_vermittler_box`, `commissions_internal/invoice`; Tests `VermittlerRechnungTest` (neu), `VermittlerAbrechnungTest` (1 Fall nachgezogen).
- **Database Changes**: neue Tabelle `vermittler_invoices`; `vermittler_settlements` + `invoice_id`, `invoice_amount`, `payment_confirmed_at`. Keine Datenumschreibung - der gespeicherte Wert `in_abrechnung` bleibt, nur seine Bedeutung/Beschriftung ist jetzt "offen".
- **API Changes**: 4 neue Routen unter `/admin/vermittler-abrechnung/rechnung...` (Recht `provisionen-verwalten`).
- **Potential Side Effects**: Vertraege mit Code 1 erscheinen jetzt orange "Offen" statt gruen; Code 3 geht nicht mehr in die Pruefliste; die Bestaetigungsquote sinkt auf den echten Wert.
- **Tests Performed**: neuer Test ohne Fix 12/12 rot, mit Fix gruen; volle Suite 3081 bestanden, 0 fehlgeschlagen (5 OCR lokal uebersprungen); Pint gruen; Browser (Chromium 1280 px): Vorschau, Uebernahme, Vertragsakte in allen drei Zustaenden, keine JS-Fehler.
- **Nachtrag (Betreiber-Entscheidung, selber Tag)**: die Monats-CSV ist der Arbeitsweg; Code 4 heisst "Bezahlt" (gruen) ohne Rechnung, der Rechnungs-Upload ist freiwillig. Echter Export (1805 Zeilen, 2023-2026) lokal eingelesen: 4,2 s, 0 fehlerhaft, Encoding/Dezimalkomma korrekt, zweiter Lauf idempotent. Neuer Test fuer den Monatsrhythmus (Status 1->4, 1->2, neuer Vorgang).
- **Result**: FIXED.

---

## 23.09.2026 - Provisionen nur mit dem Recht `provisionen-verwalten` (KI-022)

- **Task**: Betreiber-Frage am Screenshot: "Provisionen sieht nur der Admin -
  keine Mitarbeiter, keine Kunden?" Pruefung ergab: Kunden sicher; Mitarbeiter
  und Support sahen den Betrag in der Vertragsakte; Manager erreichten drei
  Provisionsbereiche ueber ihre Rolle.
- **Files Changed**: `routes/web.php`; `ProvisionController`,
  `CommissionController`, `VermittlerAbrechnungController` (HasMiddleware),
  `ReportController`, `EmployeeController`, `PartnerController`;
  `AdminNavigation`; Views `contract_vermittler_box`, `reports`,
  `reports_neukunden`, `dashboard`, `email_inbox`, `inbox_doc_row`,
  `partner_show`, `partners`, `employee_edit`, `_partner_fields`;
  neuer Test `ProvisionenNurFuerBerechtigteTest`, 4 Tests nachgezogen
  (Umleitung -> 403); CLAUDE.md, AUTH_SYSTEM, SECURITY_AUDIT, KNOWN_ISSUES,
  ROUTES_INVENTORY (neu erzeugt).
- **Components Affected**: Vertragsakte, Berichte, Provisionsbereiche,
  Mitarbeiter-/Partnerformular, Dokumenten-Eingang, Navigation.
- **Database Changes**: keine. **API Changes**: 22 Routen von
  `role:admin,manager` auf `can:provisionen-verwalten`; ohne Recht 403 statt
  Umleitung.
- **Potential Side Effects**: Manager OHNE Haken "Provisionen verwalten"
  verlieren Gutschriften, Ausgangs-Provisionen und Vermittler-Abrechnung -
  gewollt; der Admin vergibt das Recht einzeln.
- **Tests Performed**: neuer Test ohne Fix 4/5 rot, mit Fix gruen; volle
  Suite 3069 bestanden, 0 fehlgeschlagen (5 OCR-Faelle lokal ohne tesseract
  uebersprungen, CI hat es); Pint gruen; `composer stan` lokal nicht
  ausfuehrbar (KI-018), CI ist Massstab.
- **Result**: FIXED.

---

## 23.09.2026 - Reparaturrunde 1: was sich im Code beheben liess

- **Task**: Betreiber-Auftrag "Behebe, was sich beheben laesst" - alle Befunde aus
  KNOWN_ISSUES, die ohne Server-, Konto- oder Rechtshandlung behebbar sind.
- **Files Changed**:
  - `lang/ar.json` (+21), `resources/views/auth/register-pending.blade.php` (RTL-Abstand)
  - `app/Http/Controllers/EmployeeController.php` (Rechte-Liste der Mail)
  - `app/Support/Sprache.php` (neu), `app/Providers/AppServiceProvider.php`,
    `app/Http/Middleware/SetLocale.php`, `config/app.php`, `.env.example`
  - `app/Http/Controllers/TarifrechnerController.php`, `resources/views/admin/announcements.blade.php`
  - `app/Http/Controllers/AppointmentController.php`
  - `resources/views/website/legal/datenschutz.blade.php` (Turnstile-Absatz)
  - entfernt: 4 Auth-Controller, `auth/verify-email`, `auth/confirm-password`,
    `layouts/guest`, `App\View\Components\GuestLayout`, 10 Blade-Komponenten,
    2 Breeze-Tests; `routes/auth.php`, `EnsurePasswordChanged` (Ausnahmeliste)
  - `package.json`/`package-lock.json` (autoprefixer raus), `.github/workflows/deploy.yml`
  - 7 neue Testdateien (siehe TESTING_STATUS), Wissensbasis nachgezogen
- **Components Affected**: Auth-Routen, Mitarbeiter-Anlage, Ankuendigungen,
  Termine, Sprachwahl (Konsole/Queue), Datenschutzerklaerung, Portal (AR).
- **Database Changes**: keine. **API Changes**: 5 Routen entfernt
  (`verification.notice/verify/send`, `password.confirm` GET+POST) - von keiner
  Seite und keiner Middleware benutzt; 509 -> 504 Routen.
- **Potential Side Effects**: Konsole/Queue laufen jetzt auf Deutsch (vorher
  Englisch) - gewollt. Mitarbeiter sehen den Loeschknopf nur noch an eigenen
  Ankuendigungen. Ein Termin mit fremder/unbekannter `assigned_to`-Kennung wird
  abgelehnt statt 500.
- **Tests Performed**: jeder neue Test gegen den alten Stand (rot) und den
  neuen (gruen); Gegenproben fuer die Waechter; volle Suite 3069/3069, 0
  uebersprungen; Pint gruen; `npm run build` gruen; Browser (Chromium, 390 px
  AR und 1440 px): AR-Registrierungsseite, Ankuendigungen je Rolle,
  `/verify-email` 404. `composer stan` lokal nicht moeglich (KI-018) -> CI.
- **Result**: FIXED: KI-006, KI-007, KI-010, KI-011, KI-014, KI-015, KI-016,
  KI-017, neu gefunden und behoben KI-019, KI-020, KI-021; KI-012 IN_PROGRESS.
  Bewusst NICHT angefasst: KI-001 (Betreiber-Entscheidung, gehoert in die
  Aufgabe "Employee & Support Customer Access Architecture") und alle Punkte,
  die Server, Konten oder Anwalt brauchen.

---

## 23.09.2026 - Project Knowledge Base angelegt, Voll-Audit

- **Task**: Betreiber-Auftrag "Projekt als dauerhaftes System mit
  technischem Gedaechtnis fuehren": Voll-Inventur, Wissensbasis,
  Feature-/Issue-Register, Architektur, Audit, Fahrplan.
- **Ausgangsstand**: `main` @ 04c0823 (nach PR #353, BIMI).
- **Files Changed**:
  - neu `docs/project-knowledge/` (README, PROJECT_OVERVIEW, ARCHITECTURE,
    FRONTEND_MAP, BACKEND_MAP, DATABASE_MAP, API_MAP, ROUTES_INVENTORY
    (generiert), AUTH_SYSTEM, USER_FLOWS, FEATURE_MAP, INTEGRATIONS,
    DEPLOYMENT, UI_UX_AUDIT, SECURITY_AUDIT, PERFORMANCE_AUDIT,
    TESTING_STATUS, TECHNICAL_DEBT, KNOWN_ISSUES, REPAIR_ROADMAP, CHANGELOG)
  - neu `scripts/wissensbasis-routen.php` (erzeugt das Routen-Inventar, rein lesend)
  - `CLAUDE.md`: Arbeitsweise Punkt 8 + Verweis unter "Weitere Doku"
- **Components Affected**: keine Laufzeitkomponente (nur Dokumentation +
  ein Hilfsskript, das von keiner Route/keinem Befehl geladen wird).
- **Database Changes**: keine. **API Changes**: keine.
- **Potential Side Effects**: keine im Betrieb. Pint prueft das neue Skript
  (gruen).
- **Tests Performed**: volle Testsuite `php artisan test`: 3044/3044 gruen, 0 uebersprungen (Details in
  [TESTING_STATUS.md](TESTING_STATUS.md)), `vendor/bin/pint --test` fuer das
  Skript, `composer audit`, `npm audit --omit=dev --audit-level=high`,
  `npm run build`, `php artisan route:list`. `composer stan` in dieser
  Umgebung NICHT ausfuehrbar (KI-018) - CI prueft es.
- **Result**: Wissensbasis angelegt; 18 offene Befunde registriert
  (0 CRITICAL, 3 HIGH - alle Betrieb/Recht, keiner im Code), 13 historische
  als VERIFIED uebernommen; Fahrplan R-01..R-19.
