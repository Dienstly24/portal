# Dienstly24 Portal — Projektkontext für Claude

Dies ist das CRM/Kundenportal von **Dienstly24**, einem Versicherungs- und
Energie-Makler. Laravel 13 / PHP 8.3+, gehostet auf einem Hostinger-VPS.
Domains: `admin.dienstly24.de` (Beraterwelt), `portal.dienstly24.de`
(Kundenportal). Die Kommunikation mit dem Betreiber läuft überwiegend auf
Arabisch; **antworte dem Nutzer auf Arabisch**, aber halte allen Code,
Commits, UI-Texte und Kommentare auf **Deutsch/ASCII**.

## Arbeitsweise (WICHTIG — immer so vorgehen)

1. **Nie direkt deployen.** Für jede Änderung einen Feature-Branch anlegen,
   committen, pushen und einen **Pull Request mit `base=main`** öffnen.
2. Der **Nutzer reviewt und merged selbst.** Merge auf `main` löst über
   GitHub Actions automatisch den Deploy aus.
3. **Prüfe bei jedem PR, dass `base=main` ist** (ein früherer PR wurde
   versehentlich gegen einen toten Branch geöffnet und lief ins Leere).
4. Nach einem Merge für Folgearbeit **immer `git fetch origin main` und neu
   branchen** — sonst arbeitet man auf veraltetem Stand.
5. Vor jedem Push **die volle Testsuite grün** halten: `php artisan test`.
6. UI-/E-Mail-Änderungen möglichst **real verifizieren** (Headless-Chromium
   unter `/opt/pw-browsers/…`, `playwright-core`), nicht nur Tests.

## Deploy

- CI/CD: `.github/workflows/deploy.yml` — Tests bei Push & PR; Deploy nur bei
  Push auf `main`.
- **Bekanntes Problem:** Der SSH-Deploy schlägt teils mit `i/o timeout` fehl
  (VPS-Erreichbarkeit/Firewall Port 22). Das ist **kein Code-Fehler**.
  Manueller Deploy auf dem Server:
  ```
  cd /var/www/dienstly24/portal && git fetch --all --prune \
    && git reset --hard origin/main && bash scripts/deploy.sh
  ```

## Feste Regeln (Sicherheit / DSGVO)

- **Löschen von Kunden:** Admin per UI **max. 30 pro Bulk-Aktion**;
  **Mitarbeiter dürfen NIE löschen**. Voll-Purge nur per CLI
  (`php artisan customers:purge --force`).
- `CustomerDeletionService` darf **niemals Staff-/Partner-Accounts** löschen
  (Guard: nur `role === 'customer'`).
- **Keine Geheimnisse im Chat/Repo** (SSH-Keys, Tokens, Passwörter) — nur
  GitHub Secrets / Server-`.env`.
- **Keine erfundenen Daten**: keine falschen Impressum-Angaben, USt-IdNr.
  oder Fake-Statistiken (z. B. „15.000 Kunden") in der UI.
- Magic-Login-Link nie in QR-Codes oder geteilten Assets einbetten.
- Terminal-Befehle für den Nutzer immer **Deutsch/ASCII**.

## Kundennummern

- Neuanlage: `JJ` + 5-stellig laufend (2026 → `2600001`, `2600002` …) via
  `CustomerNumberGenerator::generate()`.
- Import aus Fremdplattform: `25` + Original-Nummer via
  `generateForImport($original)`. Alt-Nummern (`C-…`) bleiben gültig.

## Wichtige Bausteine

- **E-Mails** (`resources/views/emails/`): tabellenbasiert, Inline-Styles,
  **kein SVG** (Gmail/Outlook entfernen es → Emoji nutzen). Bilder als
  CID-Inline via `{{ isset($message) ? $message->embed(public_path(...)) : url(...) }}`.
- **Willkommens-Mail** = `CustomerWelcomeMail` + `customer_welcome.blade.php`
  (kompakt, ein Bildschirm). Enthält Magic-Login (90 Tage) und Hilfe-Button.
- **Hilfe-Formular**: `SupportFormController` → `/hilfe`. Aus der Mail mit
  verschlüsseltem Kunden-Token vorbefüllt; Absenden legt automatisch ein
  Ticket an, verknüpft mit der Kundenakte.
- **Website-Kontaktformular** (`website/index.html`): sendet per JS an
  `POST /api/website-contact` (`WebsiteContactController`) statt per Mail.
  Mehrstufiger Spam-Schutz: JS-Einmal-Token mit Mindest-Ausfuellzeit,
  Honeypot, Throttle, `SpamFilter` (Spam wird still verworfen). Echte
  Anfragen werden Tickets (Quelle `website`) + Glocke + Support-Mail.
  Details/Inbetriebnahme: `docs/SPAM_SCHUTZ_WEBSITE_ANFRAGE.md`.
- **Rechtsseiten** (`/impressum`, `/agb`, `/datenschutz`,
  `/cookie-richtlinie`, `/kontakt`): leiten standardmäßig auf die offizielle
  Website weiter (`LegalPageController`, Basis-URL unter Einstellungen →
  Rechtliches). Feld leeren = Portal zeigt eigene Fallback-Seiten.
- **Login/Registrierung** (`resources/views/auth/`): Single-Screen (kein
  Scroll), Glas-Karte, `logo-white.png` ohne weißen Kasten, DE/AR-Umschalter.
- **Arabisch/RTL**: `lang/ar.json`, `SetLocale`-Middleware,
  `dir="rtl"`-Layout. Neue UI-Strings mit `__()` wrappen und in `ar.json`
  ergänzen.
- **Banner-Verwaltung**: `BannerController`, Statistik-Dashboard unter
  `/admin/banners/statistik`. Routen auf `role:admin,manager` beschränkt.
- **Farbschema „Smaragd & Gold"** (Betreiber-Entscheidung 22.07.2026,
  ersetzt „Graphit + Smaragd"; Richtungswahl dokumentiert in
  `docs/design/design-richtungen.html`): Smaragd bleibt Marken- und
  Aktionsfarbe `#17A65B` (Verlauf `#19b463`->`#128a4b`); dunkle Flaechen
  sind Gruen-Graphit `#131A17`/`#0F1512`/`#0B1310` (passend zum
  Logo-Metall); GOLD `#B8A16B` (hell `#D1C18F`) ist reiner Premium-AKZENT
  fuer Badges, aktive Zustaende, Kicker, Zierlinien - NIE fuer
  Primaer-Buttons; helle Flaechen sind WARM: Canvas `#F1EEE5`
  (Website-Canvas `#F8F6F0`), Surface `#FBFAF6`, Linien `#E0DCD0`,
  Text `#16211C`/`#5F6B62`. Website-Ueberschriften in Serif
  (Playfair Display, AR: Amiri). KEIN Petrol-Gruen, keine kuehlen
  Grautoene (`#DCDEE3`/`#ECEEF1`/`#CDD1D8`) mehr verwenden.
  E-Mail-Templates sind bewusst noch auf altem Stand (separates Thema,
  Outlook-Risiko).
- **Logo-Assets** (alle aus `logo.png` per GD generiert, `public/images/`):
  `logo-white.png` (weisse Wortmarke, für dunkle Flächen: Login, Sidebars),
  `logo-transparent.png` (farbige Wortmarke, für helle Flächen),
  `logo-icon.png` (512px D-Symbol, transparent), `logo-icon-white.png`
  (D-Symbol weiss fuer dunkle Sidebars), `favicon.png` (32px),
  `apple-touch-icon.png` (180px). Favicon zentral via
  `resources/views/partials/favicon.blade.php` (vor jedem `</head>`).
  `logo.png` = Original mit weissem Hintergrund (Quelle der Varianten).
  Willkommens-Mail bewusst OHNE Logo-Bild (Outlook blockiert CID) –
  Textmarke im Hero.
- **Smart Document Upload** (`SmartDocumentUploadController`,
  `DocumentAnalyzer`): Analyse laeuft **„kostenlos zuerst"** (Betreiber-
  Entscheidung) und der KI-Anbieter ist austauschbar
  (`DocumentAiProviderInterface`, Registrierung in `AppServiceProvider`,
  Auswahl per `AI_DOCUMENT_PROVIDER`). Ablauf in `DocumentAnalyzer::analyze`
  (kostenaufsteigend):
  0) **PDF-Textebene zuerst** (`PdfTextLayerExtractor`, `pdftotext`) - viele
  hochgeladene Dokumente (CHECK24-Beratungsprotokolle, Antraege/Policen aus
  Versicherer-Portalen, alles aus einer Software) sind DIGITALE PDFs mit
  perfekter Textebene: gratis, fehlerfrei, sofort. Nur wenn keine Textebene
  da ist (echter Scan), 1) **OCR** - `TesseractTextExtractor` liest den Text.
  Auf dem gewonnenen Text bestimmt `HeuristicDocumentClassifier` Typ +
  Basisfelder (Stichwort-Erkennung + konservative Regex-Extraktion:
  IBAN/FIN/Kennzeichen/E-Mail nur aus eindeutig abgegrenzten Zeilen, keine
  Namen/Adressen aus Freitext; `ai_source = 'ocr'`, Konfidenz 20/40).
  2) **Reicht das kostenlose Ergebnis** (`ocrResultSufficient()`: Text KURZ
  genug fuer die einfache Heuristik - lange, mehrseitige Dokumente haben zu
  viele Abschnitte und erzeugen Falschtreffer, daher Eskalation - UND Typ
  erkannt UND mind. ein Feld), wird es OHNE KI-Aufruf uebernommen.
  3) **Sonst Eskalation an den KI-Anbieter**: bei vorhandener Textebene
  bekommt Claude den (auf `OCR_AI_TEXT_MAX_CHARS`, Default 12000, gekuerzten)
  **TEXT** statt der teuren Bild-/PDF-Seiten (ein 19-seitiges Protokoll als
  Vision kostet schnell 20+ Cent, als Text nur Bruchteile), sonst Vision;
  `ai_source = 'ai'`. 4) Ohne KI-Anbieter bleibt es beim kostenlosen
  Ergebnis. Mitarbeiter koennen die KI ueber den Button
  **„🤖 Mit KI analysieren"** bewusst erzwingen (`reanalyze` ->
  `AnalyzeDocumentJob(forceAi: true)`, Vision). Die kostenlose Basisebene
  (Textebene + OCR) ist standardmäßig **AUS** (`OCR_ENABLED=false`, die
  Textebene folgt per Default `OCR_TEXT_LAYER=OCR_ENABLED`) und muss erst nach
  Installation der Systempakete freigeschaltet werden: `apt install
  tesseract-ocr tesseract-ocr-deu poppler-utils` auf dem VPS, danach
  `OCR_ENABLED=true` in der `.env`. Rohtext wird bewusst NICHT gespeichert
  (Datenminimierung) - nur das validierte Extraktionsergebnis.
- **Vertrags-Duplikat-Schutz + Version History** (`DocumentIntakeService`,
  Betreiber-Vorgabe 23.07.2026): Ein neu importiertes Dokument fuer ein
  bereits erfasstes Fahrzeug/eine Police erzeugt KEIN Duplikat mehr.
  `findExistingContractByIdentity()` sucht den Bestandsvertrag streng ueber
  die Identitaet (Vertragsnummer -> FIN/VIN -> Kennzeichen -> Energie
  MaLo-ID/Zaehlernummer). Trifft einer zu, aktualisiert
  `updateContractFromExtraction()` nur ihn und schreibt jede geaenderte
  Angabe feldgenau in die Version History (`contract_revisions`,
  `ContractRevision`, `ContractRevisionRecorder`): Feld, alter/neuer Wert,
  Zeitpunkt, Quelle, Bearbeiter (null = System). Regeln: leere neue Werte
  ueberschreiben nie einen Bestand (kein Datenverlust); Zusatzleistungen
  werden ergaenzt, nie entfernt; feste Identitaetsfelder (Kennzeichen, FIN,
  MaLo-ID ...) werden nur ergaenzt, wenn leer. Anzeige des Verlaufs auf der
  Vertrags-Bearbeiten-Seite (`partials/contract_revisions.blade.php`). Nur
  wenn kein passender Vertrag existiert, wird ein neuer angelegt -> genau EIN
  Vertrag je Fahrzeug (Single Source of Truth).

## Offene Themen / wartet auf den Betreiber

- **OCR auf dem VPS ist aktiv** (Stand 18.07.2026): `tesseract-ocr`,
  `tesseract-ocr-deu`, `tesseract-ocr-ara`, `poppler-utils` sind installiert,
  `OCR_ENABLED=true` und `OCR_LANGUAGES=deu+eng+ara` in der Produktions-`.env`
  gesetzt. Der Smart Document Upload laeuft damit „kostenlos zuerst" (OCR,
  Eskalation zu Claude nur bei Bedarf). Kein offener Punkt mehr - hier nur als
  Betriebszustand dokumentiert.

- **E-Mail-Zustellbarkeit (Spam bei Outlook):** SPF, DKIM und DMARC sind
  inzwischen **korrekt gesetzt** (geprüft 14.07.2026: SPF `include:_spf.mail.hostinger.com`,
  DKIM `hostingermail1._domainkey` = verifiziert, DMARC `p=none`). Die frühere
  Annahme „DKIM leer (`p=`)" ist damit überholt. Verbleibendes Thema ist die
  **Reputation der neuen Absender-Domain** (v. a. Microsoft/Outlook):
  aufwärmen, „Kein Spam"/Kontakt-Signal, Microsoft SNDS/JMRP. Nächster
  Schritt: Testversand an mail-tester.com. Details + Checkliste in
  `docs/EMAIL_ZUSTELLBARKEIT_SPF_DKIM_DMARC.md`.
- **WordPress-Rechtsseiten** (`dienstly24.de/impressum` etc.) sind leer und
  müssen mit Inhalt gefüllt werden.
- **Finale Logo-Dateien** kommen vom Betreiber (bevorzugt SVG, sonst PNG
  transparent ≥320px hoch; Light- und Dark-Variante; optional 512×512 Icon).
- **Partner-Portal** (voller Ausbau) und **E-Mail-Einwilligung des Kunden
  (Variante B)**: Konzepte in `docs/KONZEPT_PARTNER_GESCHAEFTSMODELL.md` und
  `docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md` — warten auf Entscheidungen des
  Betreibers, noch nicht bauen.

## Weitere Doku

Ausführliche Berichte und Konzepte liegen unter `docs/` (Audit, Phasen,
Production-Readiness, Konzepte). Bei Bedarf dort nachschlagen.
