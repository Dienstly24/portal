# Changelog

Aenderungsprotokoll ab Anlage der Wissensbasis (23.09.2026). Aeltere
Aenderungen: `git log`, `CLAUDE.md` (datierte Abschnitte) und `docs/*.md`.
Neueste Eintraege oben. Format je Eintrag:
Date · Task · Files Changed · Components Affected · Database Changes ·
API Changes · Potential Side Effects · Tests Performed · Result.

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
