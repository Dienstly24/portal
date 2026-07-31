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
- **Marketing-Website IM PORTAL (Merge, Betreiber-Auftrag 30.07.2026)**:
  `www.dienstly24.de` wird von DIESER App ausgeliefert (`WebsiteController`,
  Views `resources/views/website/`, Assets `public/website-assets/` -
  NIE `public/website/`, das verschattet die Vorschau-Route `/website`).
  `config/website.php` = kanonischer Host, Redirect-Hosts, Kontaktdaten,
  Bild-Slots. `RedirectWebsiteHost` leitet non-www/.com/http per 301 um;
  `/` zeigt auf Website-Hosts die Startseite (sonst Portal-Verhalten).
  ECHTE arabische URLs unter `/ar/...` (`forceLocale:ar`, Hocharabisch,
  hreflang de/ar/x-default); Rechtsseiten aus der alten statischen Site
  portiert (`website/legal/`, auf Website-Hosts immer lokal - nie extern
  umleiten, Schleifengefahr). REGEL: Website-Seiten laden NIE externe
  Ressourcen (Google Fonts -> Abmahnung; Schriften liegen lokal in
  `public/fonts/`, Subsets je Sprache). robots.txt/sitemap.xml dynamisch
  (`SeoController`: NUR der kanonische Host offen; portal/admin UND
  Staging-/Extra-Hosts disallow + noindex). Schutzschichten
  (`ExtraBasicAuth`, Betreiber-Bedingung): `ADMIN_BASIC_AUTH=user:pass`
  = zweite Auth-Schicht vor /admin (Pflicht bis 2FA existiert);
  `STAGING_HOSTS`+`STAGING_BASIC_AUTH` (+`WEBSITE_EXTRA_HOSTS`) =
  passwortgeschuetzte Vorschau-Domain. Cutover-Plan (TTL 300s, dienstags
  vormittags, Rollback, statisches Hosting nach 1 Woche abschalten):
  `docs/WEBSITE_MERGE_UMSETZUNG.md`; Backup+Restore-Test: `scripts/backup.sh`.
  Formular `POST /kontakt` -> Ticket (source=website) + Einwilligungs-
  Protokoll (`consent_given_at/ip/text` auf tickets) + Bestaetigungs-Mail
  (`WebsiteInquiryConfirmationMail`, DE/AR) + `/kontakt/danke`;
  unkonvertierte Website-Leads loescht `tickets:purge-website-leads` nach
  6 Monaten. Fehlerseiten 404/500 zweisprachig im Markendesign.
  Go-Live-Schritte (DNS/vHost/Cloudflare/APP_URL): `docs/WEBSITE_MERGE_UMSETZUNG.md`;
  arabische Betreiber-Anleitung: `docs/ANLEITUNG_MEDIEN_UND_ANFRAGEN_AR.md`.
  Tests: `WebsiteMergeTest`.
- **Medienverwaltung `/admin/medien`** (`MediaLibraryController`,
  `MediaAsset`): Bild hochladen -> Slot waehlen (feste Plaetze aus
  `config/website.php`) -> Alt-Texte DE+AR (PFLICHT) -> sofort live.
  Automatisch AVIF/WebP/JPG in 480/960/1600px, jede Variante < 200 KB,
  EXIF weg (`ImageVariantGenerator`); echte MIME-Pruefung + SVG-Sanitizer;
  Original privat (`media-originals/`), nur Varianten oeffentlich, URLs
  IMMER relativ `/storage/...` (P0-6: nie APP_URL/IP-abhaengig, gleiche
  Regel in `ServicePage::imageUrl()`). Slot exklusiv - Vorgaenger wandert
  ins Archiv, nie geloescht; Papierkorb 30 Tage (`media:purge-trash`).
  Loeschen nur admin/manager, Upload alle Staff-Rollen. MARKEN-SLOTS
  (`logo-hell`, `logo-dunkel`, `logo-symbol-hell`, `favicon`, Flag
  `admin_only`): ueberschreiben die Dateien aus `public/images` in der
  GESAMTEN App (Website, Portal, Beraterwelt, Login) ueber
  `App\Support\BrandAssets` - nie wieder Logo-Dateien per FTP tauschen;
  ohne zugewiesenes Bild bleibt der generierte Bestand. Transparenz:
  erkennt der Generator einen Alphakanal, ist die Fallback-Variante PNG
  statt JPG (sonst weisser Kasten hinter der Wortmarke). WICHTIG:
  `MediaAsset::forSlot()` cacht ROHE Spaltenwerte (ein Eintrag, dann
  `newFromBuilder`) - Eloquent-Objekte im Cache kommen aus
  database/file/redis als `__PHP_Incomplete_Class` zurueck (500 auf
  jeder Seite). Reparatur-Befehle:
  `service-pages:fix-umlauts --write` (P0-7, `UmlautRepair`-Wortliste +
  Warnung beim Speichern im Admin) und `website:fix-storage-urls --write`.
  Tests: `MediaLibraryTest`.
- **Website-Kontaktformular der STATISCHEN Uebergangs-Site**
  (`website/index.html`, bis der DNS-Umzug durch ist): sendet per JS an
  `POST /api/website-contact` (`WebsiteContactController`) statt per Mail.
  Mehrstufiger Spam-Schutz: JS-Einmal-Token mit Mindest-Ausfuellzeit,
  Honeypot, Throttle, `SpamFilter` (Spam wird still verworfen). Echte
  Anfragen werden Tickets (Quelle `website`) + Glocke + Support-Mail.
  Details/Inbetriebnahme: `docs/SPAM_SCHUTZ_WEBSITE_ANFRAGE.md`;
  Upload-Hinweise (inkl. `.htaccess`, zip loeschen): `website/LIESMICH.txt`.
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
- **Kfz-Angebot der Sparkassen DirektVersicherung**
  (`SparkasseDirektKfzParser`, 31.07.2026): Spaltenlayout (Beschriftung links,
  Wert rechts), Stufe `antrag`. Bewusst NICHT übernommen: die Empfehlung
  „FahrerSchutzPlus" (nicht gewählt, verfälscht sonst den Beitrag), die
  Service-Adressen des Versicherers und monatsgenaue Angaben
  (Erstzulassung „01.2004") - ein Tag wäre erfunden, die Angabe steht dafür in
  der Zusammenfassung. `power_kw` ist jetzt ein extrahierbares Fahrzeugfeld
  (Validierung, Vertragsanlage, Version History, KI-Prompt Feld P.2).
- **Gewerbliche Sparten** (Betreiber-Vorgabe 30.07.2026): `betriebshaftpflicht`
  und `frachtfuehrerhaftpflicht` sind EIGENE Sparten in `Contract::TYPES`
  (Flag `'gewerblich' => true`, Gruppe „Gewerblich" im Vertragsformular,
  `Contract::isCommercial()`/`commercialTypeKeys()`) - sie versichern den
  BETRIEB, nicht die Privatperson, und dürfen nicht in der privaten
  Sammelsparte `haftpflicht` landen. Die Fonds-Finanz-Beratungsdokumentation
  liest sie gratis (`GewerbeBeratungsdokumentationParser`, Sparte aus dem Kopf
  „Vermittlungsauftrags: …"; „Verkehrshaftungsversicherung" = Frachtführer).
  Neue Sparte = eine Zeile in `Contract::TYPES`, keine Migration
  (`type` ist String). Tests: `GewerblicheSpartenTest`.
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
- **eAT-Rueckseite + Arbeitsvertrag im Dokumenten-Eingang** (Betreiber-
  Vorgabe 29.07.2026): Der `AufenthaltstitelParser` liest jetzt auch die
  RUECKSEITE der Aufenthaltstitel-Karte - sie traegt keine Vorderseiten-
  Beschriftungen, dafuer die TD1-MRZ (drei Zeilen, beginnend "AR...").
  Dekodierung deterministisch MIT Pruefziffern-Validierung (kaputte
  Pruefziffer -> Feld wird verworfen, nie falsch uebernommen): Name,
  Geburtsdatum, Geschlecht, Staatsangehoerigkeit, Dokumentennummer, Ablauf;
  zusaetzlich Anschrift-Aufkleber (-> strukturierte Adresse) und
  GEBURTSORT. Der eAT zaehlt beim Batch-Merge als Ausweis-Dokument
  (Personendaten-Hoheit). Neuer Dokumenttyp `arbeitsvertrag`
  (`ArbeitsvertragParser`): liest aus dem Vertragskopf den ARBEITGEBER
  (Firmenname per Rechtsform-Erkennung + Anschrift; "vertreten durch" wird
  uebersprungen), den Arbeitnehmer (Name/Anschrift/Anrede->Geschlecht)
  sowie Taetigkeit ("als Bauhelfer eingestellt") und Beginn. Kundenakte
  hat dafuer die Felder `employer_name`/`employer_address` (Bearbeiten-
  Formular neben Beruf, Merge-faehig); Uebernahme im Review-Modal ueber
  die neuen apply-Gruppen `occupation`/`employer` - Beruf ist damit
  bewusst wieder auf der Whitelist (der Arbeitsvertrag nennt ihn
  woertlich; die Uebernahme bleibt eine Mitarbeiter-Auswahl,
  Fuehrerscheindatum/weitere Fahrer bleiben ausgeschlossen). Tests:
  `AufenthaltstitelParserTest`, `ArbeitsvertragParserTest`,
  `SmartDocumentUploadTest`.
- **Zuordnungs-Vorschlaege im Dokumenten-Eingang** (Betreiber-Vorgabe
  29.07.2026): Beim Oeffnen von „Kunden zuordnen…" / „Neuen Kunden
  erstellen" laedt der Dialog SOFORT die naechstliegenden Kunden
  (`SmartDocumentUploadController::customerSuggestions` ->
  `DocumentIntakeService::findSuggestions`) - der Mitarbeiter soll nicht
  selbst suchen muessen, auch wenn die automatische Erkennung („Kein Kunde
  gefunden", Score < 40) nichts lieferte. Zwei Quellen: HARTE
  Identitaetsmerkmale aus dem Dokument (Vertrags-/Mitgliedsnummer,
  FIN/Kennzeichen normalisiert, MaLo-ID, Zaehlernummer -> Score 100) und
  WEICHE Personendaten ueber `CustomerMatchingService::topMatches()` mit
  bewusst breiterem Kandidatenpool als `match()` (jedes Namenswort ab 3
  Zeichen, Firmenname, PLZ) - so taucht ein Kunde auch bei abweichender
  Schreibweise auf. Jeder Vorschlag nennt seinen GRUND, ausgewaehlt wird
  immer per Klick (nie automatisch). Im Neuanlage-Modus dient dieselbe
  Liste als Dubletten-Warnung und wechselt per Klick in die Zuordnung.
  Portfolio-Scope wie ueberall: fremde Kunden werden nur als Anzahl
  gemeldet, nie mit Namen. Tests in `SmartDocumentUploadTest`.
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
  Vertrag je Fahrzeug UND Versicherer. Praezisierung 26.07.2026: die
  Fahrzeug-Identitaet (FIN/Kennzeichen) ordnet nur beim SELBEN Versicherer
  zu (`insurersLookAlike`, "ADAC" = "ADAC Autoversicherung AG"); die Police
  eines ANDEREN Versicherers fuer dasselbe Auto ist ein WECHSEL und wird ein
  eigener Vertrag. Kennzeichen-Vergleich zentral + umlaut-tolerant
  (`ContractVehicleDetail::normalizePlate`, "LUEN-G 1110" = "LUN-G1110").
- **Auftrag zuerst, Vertrag spaeter: ein Vorgang, EIN Vertrag**
  (Betreiber-Vorgabe 29.07.2026, Details in
  `docs/AUFTRAG_UND_VERTRAG_ZUSAMMENFUEHREN.md`): Zuerst wird der
  AUFTRAG/ANTRAG hochgeladen (viele Daten, aber keine Bestaetigung), Wochen
  spaeter die VERTRAGSBESTAETIGUNG/POLICE mit Vertragsnummer, Kundennummer,
  MaLo-ID, Lieferbeginn und Abschlag. Beide teilen oft KEIN hartes Merkmal
  (EWE-Auftrag nennt nur die Zaehlernummer, die Bestaetigung nur die MaLo-ID)
  - frueher entstanden daraus zwei Vertraege. Neu: `contracts.stage`
  (`antrag`/`vertrag`/null=Altbestand) haelt die Stufe fest;
  `Document::contractStageFor()` leitet sie aus `versicherung.document_stage`
  (Parser/KI), dem Dokumenttyp und dem Vorhandensein einer Vertragsnummer ab.
  `DocumentIntakeService::findApplicationContractForConfirmation()` ergaenzt
  den vorhandenen ANTRAGS-Vertrag statt ein Duplikat anzulegen - streng:
  gleiche Sparte (Strom != Gas), gleiche Gesellschaft, kein Widerspruch in
  MaLo/Zaehler/FIN/Kennzeichen, max. 12 Monate alt; bei mehreren offenen
  Antraegen entscheidet ein Indiz (Tarif/Fahrzeug), sonst wird NICHT geraten.
  Uebernahme: endgueltige Vertragsnummer ersetzt eine vorlaeufige
  Auftragsnummer, leere neue Werte loeschen nie Bestand, Stufe wandert nur
  vorwaerts, jede Aenderung in der Version History + Glocke an den Betreuer
  (und KEINE doppelte Provision). Spaetere Post findet ihren Vertrag ueber
  Vertragsnummer, FIN/Kennzeichen, MaLo, NORMALISIERTE Zaehlernummer
  ("1 LOG00 9228 3078" = "1LOG0092283078", Zaehlerfoto traegt den Stand nach)
  und die Kundennummer beim Versorger. Tests: `ContractConfirmationTest`.
- **Vertrags-Lebenszyklus: schlauer Status, Kuendigung, Wechsel-Automatik**
  (Betreiber-Vorgabe 25./26.07.2026): `cancellation_date` ist das
  EINREICHUNGS-Datum der Kuendigung (Formular-Label "eingereicht am"), der
  Vertrag endet zum Ablauf: `Contract::effectiveCancellationDate()` = Ablauf
  (nie frueher als die Einreichung; ohne Ablauf gilt das erfasste Datum).
  Die deutsche KFZ-Frist (EIN Monat zum Ablauf, 31.12.-Vertrag -> letzter
  Kuendigungstag 30.11.) prueft das Formular als LIVE-HINWEIS (gruen Frist
  gewahrt / rot verpasst inkl. regulaerem Folgejahr-Datum); GESPEICHERT
  wird, was der Betreiber erfasst (Fakten, inkl. Sonderkuendigung - nie
  still "korrigieren"). Anzeige ueberall via `Contract::displayStatus()`
  (eine Quelle): "Gekündigt zum <wirksames Ende>" (orange bis dahin, dann
  rot), "Aktiv ab <Beginn>" (blau) fuer Zukunfts-Vertraege. Der
  GESPEICHERTE status wird taeglich 05:15 von `contracts:apply-endings`
  nachgezogen: erreichtes wirksames Ende -> cancelled, E-Scooter nach
  Saisonende -> expired; beides NATUERLICHE Enden OHNE Provisions-Storno
  (`endsWithoutStorno`); laufende Vertraege ohne Kuendigung bleiben aktiv
  (stillschweigende Verlaengerung - ein blosses Ablaufdatum ist KEIN
  Ende). Statuswechsel stehen als System-Eintrag in der Version History.
  **Doppelversicherungs-Schutz + Wechsel-Automatik**
  (`VehicleOverlapGuard` + `ContractSwitchService`): dasselbe Fahrzeug
  (FIN -> Kennzeichen umlaut-tolerant -> HSN+TSN als letzte Stufe) darf
  nie zwei Vertraege mit ueberschneidendem Zeitraum haben. Neuer Vertrag
  fuer dasselbe Fahrzeug bei ANDEREM Versicherer = WECHSEL: der
  Altvertrag bekommt automatisch die Kuendigung erfasst (eingereicht
  heute, Ablauf = Beginn des neuen; ein fruehere Ablauf bleibt) - greift
  im Admin-Formular (Hinweis in der Erfolgsmeldung) UND im
  Dokumenten-Eingang; ohne Beginn keine Automatik (keine erfundenen
  Daten). GLEICHER Versicherer = Duplikat -> Anlegen wird mit
  handlungsleitender Meldung abgelehnt. Bearbeiten blockiert nur (aendert
  nie still Altvertraege). Die Wechsel-Kette "Gekündigt zum X" -> "Aktiv
  ab X" ist erlaubt (halb-offene Intervalle), Zweitwagen sowieso. Tests:
  `ContractDisplayStatusTest`, `VehicleOverlapGuardTest`,
  `ContractEndingsCommandTest`, `ContractDeduplicationTest`.
- **Aufgaben & Wiedervorlagen** (Vollausbau, Betreiber-Vorgabe 26.07.2026):
  `/admin/tasks` (`TaskController`, alle Staff-Rollen). Kundenauswahl im
  Formular per **Sofort-Suche** (`/admin/tasks/kunden-suche`, Portfolio-
  Scope wie ueberall, KEINE Liste aller Kunden mehr). Wiedervorlage
  ueber Faelligkeits-Praesets (Heute ... +10/+20 Tage/+1 Monat), aus der
  Liste verschiebbar (+1 Tag ... +1 Monat) und voll bearbeitbar (Modal).
  Taeglich 07:45 buendelt `tasks:remind` je Mitarbeiter EINEN
  Glocken-Hinweis (heute faellig / ueberfaellig, dedup je Nutzer).
  **Geplante Auto-E-Mail je Aufgabe**: beim Anlegen optional Betreff/Text
  (Vorlagen + {{platzhalter}}) und Stichtag erfassen - Versand stuendlich
  8-18 Uhr durch `tasks:send-auto-emails` via `DirectEmailMail`;
  Platzhalter werden erst BEIM Versand gerendert, Versand steht in
  Kundenakte (Timeline) + Glocke, `last_contact` wird gesetzt. Erledigte
  Aufgaben versenden NIE (Model-Hook -> Status `skipped`); bereits
  gesendete Mails sind unveraenderlich (Historie). Planen erfordert die
  Composer-Berechtigung (`can_send_emails` bzw. admin/manager/support)
  und einen Kunden mit ECHTER E-Mail-Adresse. Statusverlauf:
  `auto_email_status` pending/sent/skipped/failed (failed erst nach 3
  Tagen Retry). `tasks.type` ist jetzt String statt ENUM (Typ 'reminder'
  des Geburtstags-Jobs war im ENUM ungueltig); gueltige Typen zentral in
  `Task::TYPES`. Kundenakte-Header hat Button "Aufgabe / Wiedervorlage"
  (oeffnet das Modal vorbefuellt). Tests: `TaskSystemTest`.
- **Kundenaenderungen: Nachweispflicht + automatische Pruefung +
  Mitteilungen an Gesellschaften** (Betreiber-Vorgabe 29.07.2026, Details
  in `docs/NACHWEIS_KUNDENAENDERUNGEN.md`): Sensible Self-Service-
  Aenderungen (Bankverbindung, Anschrift, Name/Geburtsdatum) werden nur
  MIT Nachweis angenommen - Kontonachweis, Meldebescheinigung oder Ausweis
  (`ChangeProofPolicy` ist die eine Quelle dafuer, wer was braucht); der
  Kunde erfasst zusaetzlich "Gueltig ab" (`effective_from`, nie geraten).
  Nachweise liegen auf der PRIVATEN Disk unter
  `customers/{id}/nachweise` (Kundenloeschung raeumt sie mit weg), Zugriff
  nur ueber `admin.change_requests.proof` + Portfolio-Policy.
  `ChangeProofVerifier` (Job `VerifyChangeRequestProofJob`) liest den Beleg
  "kostenlos zuerst" (PDF-Textebene, sonst OCR - KEIN KI-Aufruf) und prueft
  nur, ob der BEANTRAGTE Wert darin steht: IBAN exakt bzw. OCR-tolerant
  (O/0, I/1, S/5), Adresse umlaut- und "str./strasse"-tolerant, Name
  ueber Namensteile. Rohtext wird NIE gespeichert (Datenminimierung), nur
  das Ergebnis (`proof_status` verified/partial/mismatch/unreadable/
  missing + Pruefpunkte). Automatische Freigabe nur bei `verified` und nur
  nach Einstellung (Einstellungen -> Kundenaenderungen; Standard: Adresse/
  Name automatisch, BANK bleibt Vier-Augen-Prinzip - ein Treffer belegt den
  Inhalt des Dokuments, nicht seine Echtheit); jede Uebernahme meldet die
  Glocke. Nach der Freigabe entsteht je Gesellschaft des Kunden EIN
  fertiger Entwurf (`InsurerNotificationBuilder`, Tabelle
  `change_notifications`, Seite `/admin/change-requests/{id}/mitteilungen`)
  mit alter/neuer Angabe, Vertragsnummern und "gueltig ab"; Versand ist
  IMMER eine bewusste Mitarbeiter-Aktion (Nachweis optional als Anhang,
  Protokoll in der Kundenakte), Alternativen "per Post/Portal erledigt".
  Rueckfragen gehen mit einem Klick als Chat-Nachricht raus und fuehren
  direkt in die Unterhaltung (`admin.change_requests.ask` -> Kundenchat).
  Tests: `ChangeRequestVerificationTest`.
- **Zaehlerstand + Verbrauchshistorie** (Betreiber-Vorgabe 29.07.2026,
  Details in `docs/ZAEHLERSTAND_VERBRAUCHSHISTORIE.md`): Ein Foto des
  Stromzaehlers genuegt - `MeterPhotoReader` liest KOSTENLOS (OCR/Textebene,
  KI nur als Eskalation) Zaehlernummer und Stand; die Nummer ist die Bruecke
  zum erfassten Energievertrag und damit zum Kunden (`MeterReadingService::
  locate()`, exakt oder Teiltreffer "92283078" = "1LOG0092283078", Tier
  `auto` wie eine Vertragsnummer). Jede Ablesung ist eine eigene Zeile
  (`meter_readings`, analog `vehicle_mileage_readings`) mit Wert, Einheit
  (kWh/m³), OBIS-Zaehlwerk, Ablesetag und EXAKTEM Meldezeitpunkt
  (`captured_at` = Upload-Zeitpunkt), Quelle (staff/customer/document) und
  dem Foto als Beleg; `contract_energy_details.meter_reading` bleibt als
  "aktueller Stand" und wird mitgefuehrt. Verbrauch =
  Differenz zweier Staende (`consumptionHistory()`/`consumptionStatus()`)
  inkl. Tagesschnitt und Jahres-Hochrechnung gegen den vereinbarten
  Verbrauch (erst ab 14 Tagen Zeitraum - kuerzer wird NICHT hochgerechnet).
  Bezug (1.8.0) und Einspeisung (2.8.0, Zweirichtungszaehler/PV) strikt
  getrennt. Regeln: mehrere Kunden zur selben Nummer -> KEINE Zuordnung
  (nie raten); ohne lesbaren Stand KEINE Ablesung; ein niedrigerer Stand
  wird gespeichert, markiert und ueberschreibt den Bestand nicht;
  identische Meldung nicht doppelt (idempotent). Anzeige: Portal-Karte
  „Zählerstand & Verbrauch" (Wert und/oder Handy-Foto melden) und
  Energie-Cockpit auf der Vertrags-Bearbeiten-Seite; Loeschen einer
  Ablesung nur admin/manager. Tests: `MeterReadingTest`.
- **Neukunden-Bericht + Vermittler-Provisionen** (Betreiber-Vorgabe
  25.07.2026): `/admin/reports/neukunden` (`ReportController::newCustomers`,
  Tab auf der Berichte-Seite) zeigt die Neukunden des Monats (blaetterbar,
  freier Zeitraum moeglich) mit ANLEGER (`customers.created_by`, wird beim
  Erstellen automatisch auf den angemeldeten Mitarbeiter gesetzt -
  Creating-Hook im Customer-Modell) und WERBER: Mitarbeiter
  (`acquired_by`) ODER Partner (`acquired_by_partner_id`), exklusiv.
  Bewusst NICHT `partner_id` wiederverwendet - das steuert den
  Partner-Portal-Zugriff, Werber-Attribution darf keine Datensicht
  eroeffnen (DSGVO). Jede Zeile fuehrt in die Kundenakte; Vertraege zeigen
  Gesellschaft (insurer), Beginn/Ende, Status mit Link. admin/manager
  setzen Werber + Mitarbeiter-Sichtbarkeit (= Betreuer-Sync
  `employee_customers`) direkt aus der Liste (Popover); Mitarbeiter sehen
  nur ihr Portfolio, ohne Verwaltungs-Controls. Leaderboard „Wer hat wie
  viele gebracht" + Provisions-Vorschau: Saetze je Mitarbeiter/Partner
  (`provision_fixed` EUR je Neuvertrag, `provision_percent` % vom
  Jahresbeitrag; gepflegt in Mitarbeiter-Bearbeiten bzw. Partnerakte).
  Vorschlag wird per Klick als `Provision` erfasst (Tabelle `provisions`,
  AUSGANG an eigene Vermittler - NICHT verwechseln mit `commissions` =
  EINGANG Gutschriften). Verwaltung unter `/admin/provisionen`
  (`ProvisionController`, Tabs mit der Gutschriften-Seite). Tests:
  `NewCustomerReportTest`.
- **Provisions-Management (Vollausbau, Betreiber-Vorgabe 25.07.2026)**:
  Provisionen entstehen AUTOMATISCH bei jeder Vertragsanlage (Formular,
  Dokumenten-Eingang, Imports, CLI - zentraler Hook im Contract-Modell ->
  `ContractProvisionService`). Empfaenger ist der WERBER des Kunden
  (`acquired_by` XOR `acquired_by_partner_id`); der Betrag kommt aus dem
  SPARTEN-Satz (`provision_rates`: je Mitarbeiter/Partner je Sparte fix +
  Prozent vom Jahresbeitrag, Pflege unter `/admin/provisionen/saetze`),
  Fallback globaler Satz am Empfaenger. Ohne Werber oder Satz KEINE Buchung
  (nie Betraege erfinden); Werber nachtraeglich setzen bucht offene
  Vertraege nach (Idempotenz: je Vertrag genau EINE Neuvertrag-Provision).
  Workflow offen -> freigegeben -> ausgezahlt (oder storniert), Statuswege
  begrenzt (`updateStatus`). Storno-Regel (praezisiert 26.07.2026): die
  Provision gibt es EINMALIG je Verkauf - ein NATUERLICHES Vertragsende
  (Wechsel-Kette, Tages-Job `contracts:apply-endings` stellt cancelled;
  Flag `Contract::$endsWithoutStorno`) bucht KEIN Storno, die Provision
  bleibt verdient. Nur MANUELLE Stornierung (Formular status=cancelled)
  oder Loeschung eines Vertrags erzeugt die automatische NEGATIVE
  Gegenbuchung (type=storno, `related_provision_id`) - Originale werden
  NIE geloescht (Finanzhistorie; Kunden-Purge per FK-Kaskade bucht
  bewusst NICHT).
  Betrags-Anpassung/Bonus/Abzug nur mit Grund; JEDE Aenderung steht im
  unveraenderlichen `provision_audit_logs` (wer/wann/alt/neu/Grund).
  Monatsbericht `/admin/provisionen/bericht` (je Empfaenger: Neukunden,
  Vertraege je Sparte, Provision/Abzuege/Netto) mit Export Excel
  (`XlsxWriter`, ohne Fremdpaket, CSV-Fallback) + PDF (Druckansicht);
  Leistungs-Dashboard `/admin/provisionen/dashboard`. ALLES nur
  role:admin,manager - Mitarbeiter/Partner sehen keinerlei Betraege,
  Saetze, Berichte oder Statistiken; KEINE Benachrichtigungen an
  Empfaenger (interner Prozess). Tests: `ProvisionManagementTest`.

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
- **Rechtsseiten liegen jetzt in der App** (`/impressum` etc., seit dem
  Website-Merge 30.07.2026) - der fruehere Punkt "WordPress-Rechtsseiten
  fuellen" ist damit erledigt. Offen bleibt die finale PRUEFUNG der Texte
  durch Rechtsanwalt/Datenschutzbeauftragten vor dem Livegang (Hinweise
  stehen als Kommentare in den Views).
- **Website-Go-Live** (nach dem Merge): DNS/vHost/Cloudflare-Umzug von
  www.dienstly24.de auf den VPS + `APP_URL` setzen + einmalige
  Reparatur-Befehle - Checkliste in `docs/WEBSITE_MERGE_UMSETZUNG.md`.
  Ausserdem vom Betreiber zu bestaetigen: schriftliche Freigaben der
  zitierten Kundenstimmen + Belegbarkeit "3.000+ Kunden" (UWG). 2FA fuer
  /admin und Cloudflare Turnstile sind bewusst offene Folgepakete.
- **Finale Logo-Dateien** kommen vom Betreiber (bevorzugt SVG, sonst PNG
  transparent ≥320px hoch; Light- und Dark-Variante; optional 512×512 Icon).
- **Partner-Portal** (voller Ausbau) und **E-Mail-Einwilligung des Kunden
  (Variante B)**: Konzepte in `docs/KONZEPT_PARTNER_GESCHAEFTSMODELL.md` und
  `docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md` — warten auf Entscheidungen des
  Betreibers, noch nicht bauen.

## Weitere Doku

Ausführliche Berichte und Konzepte liegen unter `docs/` (Audit, Phasen,
Production-Readiness, Konzepte). Bei Bedarf dort nachschlagen.
