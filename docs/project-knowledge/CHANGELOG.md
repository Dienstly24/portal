# Changelog

Aenderungsprotokoll ab Anlage der Wissensbasis (23.09.2026). Aeltere
Aenderungen: `git log`, `CLAUDE.md` (datierte Abschnitte) und `docs/*.md`.
Neueste Eintraege oben. Format je Eintrag:
Date · Task · Files Changed · Components Affected · Database Changes ·
API Changes · Potential Side Effects · Tests Performed · Result.

---

## 09.10.2026 - Bedienung: Auswahl, Popover, Datum TT.MM.JJJJ, Sammelaktionen Aufgaben, Geburtsort optional

- **Task**: Betreiber-Auftrag "Bedienung im gesamten System professioneller, eindeutiger, kontrollierbar" (7 Punkte), zentral gebaut.
- **Files Changed**: neu `resources/css/bedienung.css`, `resources/js/datum.js`, `resources/js/datum-logik.js`, `resources/views/partials/feldfehler.blade.php`, `tests/js/datum-logik.test.js`, `tests/Feature/AufgabenSammelaktionTest.php`, `tests/Feature/GeburtsortOptionalTest.php`, `docs/BEDIENUNG_UX_2026-10-09.md`; geaendert `components.css`, `app.css`, `app.js`, `ui.js`, Layouts admin/portal/partner, `TaskController`, `AdminController`, `PortalController`, Views `tasks`, `contract_edit/create/new`, `customer_edit/create`, `portal/profile`, Partials `contract_origin_fields`, `contract_form_fields`, `beziehung_festlegen`, `routes/web.php`, `lang/ar.json`, `package.json` (`test:js`), `.github/workflows/deploy.yml`; `CLAUDE.md`, Wissensbasis.
- **Components Affected**: jedes Formular (Checkbox/Radio, Datum, Feldfehler), Popover/Menues, Aufgabenliste, Vertragsformular, Kundenformular, Kundenportal-Profil.
- **Database Changes**: keine (`customers.birth_place` war bereits nullable).
- **API Changes**: `POST admin/tasks/sammelaktion` (`admin.tasks.bulk`), `POST admin/tasks/sammelaktion/rueckgaengig` (`admin.tasks.bulk_undo`), beide staff + throttle.
- **Potential Side Effects**: Datumsfelder sind jetzt Textfelder mit Maske (Original bleibt verborgen als Formularfeld); Skripte, die `.value` des Originals setzen, aktualisieren die Anzeige mit. Reiter der Beraterwelt aktiv gruen statt graphit. `?tab=<unbekannt>` zeigt "Meine Aufgaben".
- **Tests Performed**: `AufgabenSammelaktionTest` 11, `GeburtsortOptionalTest` 3 (ohne Fix 3/3 rot), `npm run test:js` 6; volle Suite; Pint sauber; PHPStan lokal NICHT lauffaehig (Paket nur ueber gesperrten Host), laeuft in CI. Browser: Herkunft aendern + speichern, Datum tippen/einfuegen/31.02., Router-Segment, Sammelaktion + Rueckgaengig, Beziehungs-Dialog (1366/820 px), Sweep ueber 87 Admin- und 15 Portalseiten.
- **Result**: gebaut; offene mittlere/niedrige Punkte in `docs/BEDIENUNG_UX_2026-10-09.md`.

## 08.10.2026 - Vertragsrollen: versicherte Personen, Beitragszahler, Beguenstigte (PR-6)

- **Task**: PR-6 des freigegebenen Dubletten-/Familien-Plans. Der Versicherungsnehmer bleibt `contracts.customer_id`; weitere Personen eines Vertrags werden verknuepft statt in eine Akte gezwungen.
- **Files Changed**: Migration `2026_10_08_110000_vertrag_beteiligte`; neu `App\Models\VertragBeteiligter`, `App\Services\Vertrag\VertragBeteiligteService`, `Admin\VertragBeteiligteController`, View `admin/partials/vertrag_beteiligte`; geaendert `Admin\ContractController::contractEdit`, `AdminController::show`, Views `contract_edit`, `customer_show`, `partials/kundensuche_script` (`data-verknuepfen-optional`), `routes/web.php`; Test `VertragBeteiligteTest`; `CLAUDE.md`, Wissensbasis.
- **Components Affected**: Vertrag bearbeiten (Karte "Personen am Vertrag"), Kundenakte Registerkarte "Vertraege" (Karte "Beteiligt an Vertraegen anderer Kunden"), Version History, Kunden-Zusammenfuehrung (generisch).
- **Database Changes**: neue Tabelle `vertrag_beteiligte` (reversibel). Kein bestehender Datensatz wird geaendert.
- **API Changes**: `POST admin/contracts/{id}/beteiligte` (`admin.contract.beteiligte.store`), `DELETE admin/vertrag-beteiligte/{id}` (`admin.contract.beteiligte.destroy`) - alle Personalrollen, Zugriff auf VN UND verknuepfte Akte geprueft.
- **Potential Side Effects**: keine Kennzahl aendert sich - Beteiligungen zaehlen nicht zu den eigenen Vertraegen, nicht in die Beitragsuebersicht und nicht ins Portal (Portal je Person ist PR-7).
- **Tests Performed**: `VertragBeteiligteTest` 10 Faelle (alle rot ohne die neue Tabelle/Route); volle Suite 3515, 3510 gruen, 5 uebersprungen (OCR, lokal kein tesseract); PHPStan 0 Fehler, Pint sauber. Nicht im Browser angesehen.
- **Result**: gebaut.

## 07.10.2026 - Kinder unter 16: keine eigene Kundennummer, Erinnerung mit 15 (KI-096)

- **Task**: Betreiber-Auftrag 07.10.2026 - "Tala Alhamoud" (Kind) trug die Kundennummer 2600810. Regel: unter 16 keine Nummer, kein Vertrag, kein Portal; Kind unter dem Vater (sonst Mutter); Erinnerung mit 15; Nummer ab 16; beide Alter als Einstellung. Bestand ZUERST pruefen, dann auf Freigabe umstellen.
- **Files Changed**: Migration `2026_10_07_120000_abhaengige_kinder_ohne_kundennummer`; neu `App\Support\FamilienAlter`, `ArchivierteKundennummer`, `Family/AbhaengigesKindService`, `AbhaengigerKundeException`, Befehle `ErinnereKinderPortalVorbereitung`, `PruefeKinderMitKundennummer`, `StelleKindAufAbhaengigUm`; geaendert `Customer`, `CustomerFamilyRelation`, `Contract`, `CustomerNumberGenerator`, `CustomerAutoCreationService`, `FamilyRelationService`, `ApplyFamilyTransitions`, `PortalAccessService`, `DocumentIntakeService`, `AdminController`, `Admin\ContractController`, `CustomerFamilyRelationController`, `SmartDocumentUploadController`, `RegisteredUserController`, `SettingsController` + `UpdateSettingsRequest`, Views `customer_show`, `partials/family_relations`, `family_transitions`, `customers`, `settings`, `routes/web.php`, `routes/console.php`, `lang/ar.json`; Tests `AbhaengigeKinderTest` (neu), `CustomerFamilyRelationTest` (nachgezogen); `CLAUDE.md`.
- **Components Affected**: jede Kundenanlage, Vertragsanlage, Portal-Einladung, Registrierung, Familien-Registerkarte, Einstellungen, Planer.
- **Database Changes**: `customers.customer_number` nullbar, neue Spalte `customers.portal_vorbereitung_erinnert_at`, neue Tabelle `archivierte_kundennummern`. Bestandsdaten werden von der Migration NICHT veraendert.
- **API Changes**: Route `POST admin/customers/{id}/kundennummer-vergeben` (`admin.customer.kundennummer_vergeben`).
- **Potential Side Effects**: Das Selbststaendigkeitsalter steigt von 15 auf 16 - Kinder, die 15 sind, gelten wieder als abhaengig (Anzeige, Vererbung der Kontaktdaten). Neue Kinderakten erscheinen in Listen ohne Kundennummer. Ein Vertrag kann nicht mehr an einer Kinderakte angelegt werden.
- **Tests Performed**: `AbhaengigeKinderTest` 23 Faelle (20 davon rot auf dem alten Stand); volle Suite, PHPStan 0, Pint sauber.
- **Result**: KI-096 FIXED im Code; Bestand wartet auf `kunden:kinder-pruefen` + Freigabe.
## 08.10.2026 - Haushalt: Mitglieder, Hauptansprechpartner, Vertraege des Haushalts (PR-5b)

- **Task**: PR-5 des freigegebenen Dubletten-/Familien-Plans, zweiter Teil.
- **Files Changed**: Migration `2026_10_08_100000_haushalte`; neu `App\Models\Haushalt`, `App\Models\HaushaltMitglied`, `App\Services\Haushalt\HaushaltService`, `Admin\HaushaltController`, Befehl `haushalte:aus-beziehungen-bilden`, Views `admin/partials/haushalt`, `admin/partials/kundensuche_script` (aus `linked_customers` herausgeloest, jetzt fuer jede Karte); `Customer::haushaltMitgliedschaften()`; `AdminController::show`; `DuplicateDetectionService` (Mitbewohner sind keine Dublette); `routes/web.php`; Test `HaushaltTest`; `CLAUDE.md`, Wissensbasis.
- **Components Affected**: Kundenakte (Registerkarte "Familie"), Dubletten-Pruefung, Kunden-Zusammenfuehrung (generisch, UNIQUE verwirft doppelte Zeilen).
- **Database Changes**: neue Tabellen `haushalte`, `haushalt_mitglieder` (reversibel). Kein bestehender Datensatz wird geaendert; die Uebernahme aus "Gleicher Haushalt" ist ein eigener Befehl mit Probelauf.
- **API Changes**: `POST admin/customers/{id}/haushalt`, `POST admin/haushalte/{haushaltId}/mitglieder`, `POST admin/haushalt-mitglieder/{id}/austragen|hauptansprechpartner|beitragszahler` - alle Personalrollen, Zugriff auf jede beteiligte Akte geprueft.
- **Potential Side Effects**: Paare im selben aktuellen Haushalt verschwinden aus der Dubletten-Liste (nach dem Auszug erscheinen sie wieder).
- **Tests Performed**: `HaushaltTest` 10 Faelle (der Dubletten-Fall scheitert ohne die Aenderung im Abgleich, alle anderen ohne die neuen Tabellen); volle Suite 3482, 3477 gruen, 5 uebersprungen (OCR, lokal kein tesseract); PHPStan 0 Fehler, Pint sauber. Nicht im Browser angesehen.
- **Result**: gebaut.

## 08.10.2026 - Weitere Beziehungsarten: Lebenspartnerschaft, Lebensgefaehrten, Grosselternteil - Enkel (PR-5a)

- **Task**: PR-5 des freigegebenen Dubletten-/Familien-Plans, erster Teil (Beziehungsarten). Der Haushalt folgt als PR-5b.
- **Files Changed**: `CustomerRelationship` (neue Arten, `DIRECTED_TYPES`, `isDirected()`, Richtungstexte), `CustomerFamilyRelation` (Rollen `lebenspartner`, `partner`, `grossvater/grossmutter/grosselternteil`, `enkel/enkelin/enkelkind`, `GRANDCHILD_ROLES`, `GRANDPARENT_ROLES`, `PARTNER_ROLES`, `inverseRole`), `CustomerRelationshipService` (Abbildung Art <-> Rolle, Bestaetigt-Pruefung), `FamilyRelationService::overview` (Gruppen Enkel/Grosseltern, "Weitere" = Rest), `Admin\DuplicateController` (Richtung Pflicht bei beiden gerichteten Arten), Views `beziehung_festlegen`, `beziehung_festlegen_script`, `linked_customers`, `family_relations`; Rueckbau-Pruefung der Migration `2026_10_02_090000`; Tests `WeitereBeziehungsartenTest`, `KundenbeziehungenTest`; `CLAUDE.md`, Wissensbasis.
- **Components Affected**: "Beziehung festlegen" (Dubletten-Pruefung, Verwandte Kunden, Kundenakte), Registerkarte "Familie".
- **Database Changes**: keine Migration (`type`/`relationship_type` sind Zeichenketten). Der `down()` der Migration vom 02.10.2026 bricht jetzt bei jeder Art ab, die er nicht abbilden kann.
- **API Changes**: die Beziehungs-Endpunkte nehmen die drei neuen Arten an; `grosseltern_enkel` verlangt die Richtung wie `elternteil_kind`. Die Sammel-Aktion kennt keine gerichtete Art.
- **Potential Side Effects**: Familienkarte heisst "Partner/in" statt "Ehepartner/in" (umfasst jetzt alle Partnerrollen). Bestehende Daten werden nicht veraendert.
- **Tests Performed**: `WeitereBeziehungsartenTest` 9 Faelle (alle rot ohne den Fix); volle Suite 3472, 3467 gruen, 5 uebersprungen (OCR, lokal kein tesseract); PHPStan 0 Fehler, Pint sauber. Nicht im Browser angesehen.
- **Result**: gebaut.

## 08.10.2026 - Gemeinsam genutzte Kontaktdaten + Hausnummer einmal (PR-4, KI-069, KI-095)

- **Task**: PR-4 des freigegebenen Dubletten-Plans.
- **Files Changed**: neu `App\Support\Anschrift`, `App\Models\GeteilterKontaktwert`, Migration `2026_10_08_090000_geteilte_kontaktdaten`, Befehl `kunden:anschriften-pruefen` (`PruefeKundenAnschriften`); `Customer::fullAddress/householdKey`; `DuplicateDetectionService` (Blocking/Signale ohne markierte Werte, `gemeinsameWerte()`, `SIGNAL_ART`); `Admin\DuplicateController::geteiltMarkieren/geteiltAufheben`; `routes/web.php`; `admin/customer_duplicates.blade.php`; Tests `GeteilteKontaktdatenTest`, `tests/Unit/AnschriftTest.php`; `CLAUDE.md`, Wissensbasis.
- **Components Affected**: Dubletten-Pruefung, Anzeige der Anschrift ueberall, Haushalts-Schluessel.
- **Database Changes**: neue Tabelle `geteilte_kontaktdaten` (reversibel). Kein bestehender Datensatz wird geaendert.
- **API Changes**: `POST admin/customers/duplicates/geteilt` (customer_a, customer_b, art, notiz), `DELETE admin/customers/duplicates/geteilt/{id}` - beide nur admin/manager.
- **Potential Side Effects**: Anschriften mit doppelter Hausnummer erscheinen ueberall korrekt; "Straße"/"Str." gilt jetzt als dieselbe Anschrift, dadurch koennen in der Dubletten-Liste wenige NEUE Adress-Treffer auftauchen. Wird der APP_KEY gewechselt, greifen die Markierungen nicht mehr (Paare erscheinen wieder, nichts geht verloren).
- **Tests Performed**: `GeteilteKontaktdatenTest` 12 Faelle (9 rot ohne den Fix), `AnschriftTest` 6 Faelle; volle Suite, PHPStan, Pint (Ergebnis im PR).
- **Result**: KI-069 und KI-095 FIXED.

## 07.10.2026 - Merge-Vorschau: abweichende Angaben waehlen (PR-3c, KI-094)

- **Task**: PR-3c des freigegebenen Dubletten-Plans (Schritt 3, letzter Teil).
- **Files Changed**: `CustomerMergeService` (`STAMMDATEN`, `FELDGRUPPEN`, `abweichendeFelder()`, `applyFeldwahl()`, `merge(..., $feldwahl)`, keine Teil-Ergaenzung behaltener Gruppen); `CustomerMergeUndoService` (uebernommene Werte zurueck); `Admin\DuplicateController::mergeForm/mergeCustomers`; `admin/customer_merge.blade.php` (Abschnitt "Abweichende Angaben", Auswahl per Suche laedt die Vorschau fuer genau dieses Paar, veraltete Texte "geloescht"/"nicht rueckgaengig" korrigiert); neu `tests/Feature/MergeFeldwahlTest.php`; `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`.
- **Components Affected**: Kunden-Zusammenfuehrung (Einzel-Merge; Sammel-Merge behaelt den Hauptkunden, mischt aber keine Anschriften mehr).
- **Database Changes**: keine (Protokoll bekommt den Schluessel `uebernommen`).
- **API Changes**: `POST admin/customers/{id}/merge` nimmt `feldwahl[<gruppe>]=haupt|duplikat`.
- **Potential Side Effects**: Weicht die Anschrift ab und bleibt der Hauptkunde, wird sie nicht mehr aus der anderen ergaenzt - einzelne Teilfelder koennen deshalb leer bleiben, wo frueher ein falscher Wert stand.
- **Tests Performed**: `MergeFeldwahlTest` 10 Faelle (9 rot ohne den Fix). Volle Suite 3442, 3437 gruen, 5 uebersprungen (OCR, tesseract lokal nicht installiert). PHPStan 0 Fehler, Pint sauber. Nicht im Browser angesehen.
- **Result**: KI-094 FIXED; Schritt 3 des Plans abgeschlossen.

## 07.10.2026 - NAFI-Kfz-Antrag: Vertrag wird wieder angelegt (KI-093)

- **Task**: Betreiber-Meldung 07.10.2026 - beim Kfz-Antrag (andsafe, NAFI) erschienen im Eingang nur die Kundendaten, kein Vertrag.
- **Files Changed**: `NafiKfzAntragParser` (Versicherer hinter der Tarifkennung, Leistung "(kw/ps/ccm)", TSN/Typ), `tests/Feature/Ai/NafiKfzAntragParserTest.php`, `CLAUDE.md`, `KNOWN_ISSUES.md`.
- **Components Affected**: Dokumenten-Eingang (Kfz-Antraege aus NAFI).
- **Database Changes**: keine.
- **API Changes**: keine.
- **Potential Side Effects**: Antraege, deren Versicherer vorher leer blieb, legen jetzt einen Vertrag (Stufe antrag) an. Bereits hochgeladene Dokumente aendern sich erst bei erneuter Analyse.
- **Tests Performed**: `NafiKfzAntragParserTest` 8 Faelle (3 neu, rot ohne den Fix); volle Suite, PHPStan, Pint.
- **Result**: KI-093 FIXED.

## 04.10.2026 - Zusammenfuehrung rueckgaengig machen, Abschluss nach 30 Tagen (KI-064, Teil 2)

- **Task**: PR-3b des freigegebenen Dubletten-Plans (Schritt 3). Die Feldwahl in der Vorschau folgt als PR-3c.
- **Files Changed**: neu `CustomerMergeUndoService`, neu Befehl `CloseCustomerMerges` (`kunden:zusammenfuehrungen-abschliessen`, Planer 05:45); `CustomerMerge` (`RUECKGAENGIG_TAGE`); `CustomerMergeService` (Protokoll merkt `verlierer_war_aktiv`); `Customer::mitArchiv()` typisiert; `Admin\DuplicateController::undoMerge` + Route `admin.customer.merge.undo` (role:admin); `AdminController::customerShow` + `admin/customer_show.blade.php` (Karte "In diese Akte zusammengefuehrt"); neu `tests/Feature/MergeRueckgaengigTest.php`; `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`, `ROUTES_INVENTORY.md` (per Skript).
- **Components Affected**: Kundenakte (nur admin), Kunden-Zusammenfuehrung, Planer.
- **Database Changes**: keine.
- **API Changes**: neue Route `POST admin/customers/zusammenfuehrungen/{merge}/rueckgaengig`.
- **Potential Side Effects**: Nach 30 Tagen werden Stammdaten und Protokoll archivierter Akten geleert - ab dann ist eine Zusammenfuehrung endgueltig. Was nach dem Merge am Hauptkunden neu entstand, bleibt dort auch nach dem Rueckgaengigmachen.
- **Tests Performed**: `MergeRueckgaengigTest` 9 Faelle. Volle Suite 3390, 3385 gruen, 5 uebersprungen (OCR, tesseract lokal nicht installiert). PHPStan 0 Fehler, Pint sauber.
- **Result**: KI-064 FIXED.
## 07.10.2026 - E-Signatur Teil B: interne Unterschrift (Meine Unterschrift, Handy-QR, Zwischenstand)

- **Task**: Betreiber-Auftrag 04./07.10.2026 Teil B - ein Mitarbeiter/Geschaeftsfuehrer unterschreibt selbst, bevor das Dokument an den Kunden geht; Entscheidungen 07.10.2026 (2FA Pflicht, 4-h-Fenster je Sitzung/Geraet, Bestaetigung je Dokument; Upload nur Personal mit 2FA und Admin-Glocke; Handy-QR 10 Min einmal).
- **Files Changed**: Migration `2026_10_07_100000_interne_unterschrift`; neu `UserSignature`, `SignatureHandoff`, `SignatureInternalSigning`, `InternalSigningService`, `UserSignatureService`, `InterneFreigabe`, `SignatureHandoffService`, `Admin\InternalSignatureController`, `SignatureHandoffController`, `InterneUnterschriftException`, Views `admin/signatures/my_signature`, `admin/signatures/partials/intern_dialog`, `signature/handoff`, `public/js/unterschrift-pad.js`; geaendert `SignedPdfBuilder` (Basis = Zwischenstand, Protokoll "Interne Unterschriften"), `SignedPdfVerifier`/`SignatureQualityGate` (Feldauswahl, Warnung > 5 s), `PdfStamper` (eindeutige Bildnamen, KI-092), `SignatureRequestService` (Feld `intern`, Versand wartet/abgeschlossen), `SignaturePageRenderer`, `SignatureSigningController::document`, `SignatureRequestPolicy`, `Admin\SignatureController`, `EmployeeController` + `employee_edit`, `TwoFactorService::markVerified`, `Bildfreistellung::tinteFreistellen`, `Unterschriftsbild::ausZeichnung`, Editor/Detailseite/Liste, `routes/web.php`, `CLAUDE.md`.
- **Components Affected**: Signatur-Editor, Versand, Unterzeichner-Ansicht (sieht die Fassung mit interner Unterschrift), Abschluss-PDF und Protokoll, Mitarbeiterverwaltung, Profil.
- **Database Changes**: 3 neue Tabellen, Spalten an `users`, `signature_fields`, `signature_requests` - rein additiv.
- **API Changes**: Routen `admin.meine_unterschrift*`, `admin.signatures.internal.sign`, `admin.signatures.internal.reset`, `signature.handoff.show/.store` (oeffentlich, Token).
- **Potential Side Effects**: Bildnamen im PDF heissen jetzt `D24Sig<obj>` statt `D24Sig<Seite>x<n>` (Diagnose/Selbsttest lesen die Namen aus dem Stempler, nicht fest). Ein Versand mit offener interner Unterschrift eines Kollegen bleibt Entwurf und laeuft danach automatisch. Admins duerfen von sich aus intern unterschreiben.
- **Tests Performed**: `InterneUnterschriftTest` (21 Faelle); volle Suite, PHPStan 0, Pint sauber; Headless-Chromium: Profil zeichnen + Code, Editor-Karte, Pruefdialog, Unterschreiben-und-senden, Zwischenstand-Vorschau, Touch-Zeichnen am iPhone-Viewport (kein seitlicher Bildlauf), Handy-Seite.
- **Result**: Teil B fertig; KI-092 FIXED; KI-090 Warnung eingebaut (weiter OPEN).

---

## 04.10.2026 - E-Signatur: Qualitaetsgate, Testmatrix, Eingangspruefung (Audit Teil A, KI-080..091)

- **Task**: Betreiber-Auftrag 04.10.2026 Teil A - "keine Unterschrift darf je wieder erfasst, aber unsichtbar sein, ohne dass wir es wissen".
- **Files Changed**: neu `SignatureQualityGate`, `PdfEingangspruefung`, `CheckSignatureQuality` (`signaturen:qualitaet-pruefen`), `Admin\SignatureQualityController` + `admin/signatures/quality.blade.php`, `SignaturQualitaetMail` + `emails/signatur_qualitaet.blade.php`, Migration `2026_10_04_120000_signatur_qualitaetspruefung`; geaendert `SignedPdfVerifier` (alle Feldarten, poppler, qpdf), `SignatureSigningService` (Gate, Dauer, Link-Frist nach Abschluss), `SignedPdfRegenerator`, `SignatureRequestService` (Eingangspruefung, Gate vor Versand, Feldbezug), `SignedPdfBuilder` (Umlaute, volle Texte, Upload-Hash), `PdfStamper` (Protokoll-Umbruch, Mehrseitigkeit, Sichtbereich), `PdfDocument`/`PdfPage` (CropBox), `FeldGeometrie`, `SignaturePageRenderer`/`PdfSichtbarkeit` (-cropbox), `SignatureDiagnostics` (offene Vorgaenge simulieren), `CompanySignatureAssetService` (zu helle Bilder), `SignatureSigningController` (410 nach Frist), `Admin\SignatureController` (Original-Download protokolliert), `SystemHealthService`, `routes/web.php`, `routes/console.php`, `config/services.php`, `lang/de/signing.php`, CI (`qpdf`), `docs/AUDIT_2026-10-04_SIGNATUR.md`, `docs/TESTUMGEBUNG.md`, `scripts/testumgebung-pruefen.sh`; Tests `SignaturQualitaetsmatrixTest`, `SignaturQualitaetsgateTest`, `ProtokollUmbruchTest`, `tests/Support/SignaturPdfFixtures.php`.
- **Components Affected**: Signatur-Upload, Versand, Abschluss, Neu-Erzeugen, Unterzeichner-Link, Systemzustand, Planer.
- **Database Changes**: `signature_requests` + `feld_bezug` (Default `mediabox` = Bestand), `upload_original_path`, `upload_original_hash`, `quality_status` (Index), `quality_checked_at`, `quality_findings`, `render_ms`. Rein additiv.
- **API Changes**: neue Routen `admin.signatures.quality`, `.quality.check`, `.quality.regenerate` (nur admin).
- **Potential Side Effects**: Versand wird abgelehnt, wenn eine bereits gesetzte Unternehmenssignatur im Bild nicht sichtbar waere. Beschaedigte Uploads werden von qpdf neu geschrieben (Basis-Hash != Upload-Hash, beide im Protokoll). Der Unterzeichner-Link liest das fertige Dokument nur noch 7 Tage. Ohne qpdf auf dem Server entfaellt nur die Strukturpruefung.
- **Tests Performed**: neue Tests 21 Faelle; volle Suite gruen, 0 uebersprungen; PHPStan 0, Pint sauber. Messung (Median 3 Laeufe): Abschluss 1 Seite 28 -> 108 ms, 12 Seiten 108 -> 1079 ms.
- **Result**: KI-080..088 FIXED; KI-089..091 OPEN (dokumentiert).

---

## 04.10.2026 - Zusammenfuehren archiviert statt zu loeschen, alte Kundennummer bleibt auffindbar (KI-064, Teil 1)

- **Task**: PR-3a des freigegebenen Dubletten-Plans (Schritt 3). Rueckgaengig und Feldwahl folgen als PR-3b.
- **Files Changed**: Migration `2026_10_04_090000_kunden_archiv_statt_loeschen_beim_zusammenfuehren`; `Customer` (globaler Scope, `mitArchiv()`, `aufgegangenIn()`, Alias in `scopeSearch`); neu `CustomerMerge`; `CustomerMergeService` (archivieren statt loeschen, Protokoll, Ketten umhaengen, Verlierer-Konto stilllegen statt loeschen); `CustomerDeletionService` (Huellen + Konten mitloeschen); `CustomerNumberGenerator` (archivierte Nummern belegt); `AdminController::customerShow` (Weiterleitung); neu `tests/Feature/MergeArchivTest.php`; `CustomerMergeServiceTest`, `CustomerMergeDataPreservationTest` nachgezogen; `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`, `DATABASE_MAP.md`.
- **Components Affected**: Einzel-, Sammel- und Ein-Klick-Merge, Kundensuche, Kundenakte (alte Links), Kundennummernvergabe, Kundenloeschung/Purge.
- **Database Changes**: `customers.merged_into_id` (FK auf customers, cascadeOnDelete), `customers.archived_at` (Index); neue Tabelle `customer_merges`. `down()` bricht ab, solange archivierte Akten existieren.
- **API Changes**: `Customer`-Abfragen liefern archivierte Akten nicht mehr (globaler Scope); `Customer::mitArchiv()` schliesst sie ein.
- **Potential Side Effects**: Der unterlegene Portalzugang bleibt (stillgelegt) bestehen und belegt seine Login-Adresse weiter. Huellen und das Protokoll tragen Kundendaten, bis der Hauptkunde geloescht wird - die Aufbewahrungsfrist regelt PR-3b.
- **Tests Performed**: `MergeArchivTest` 8 Faelle. Volle Suite 3381, 3376 gruen, 5 uebersprungen (OCR, tesseract lokal nicht installiert). PHPStan 0 Fehler, Pint sauber.
- **Result**: KI-064 IN_PROGRESS (Archiv, Alias, Protokoll fertig).

---

## 03.10.2026 - Merge-Sperren: zwei Portalzugaenge, Familienrollen, Sammel-Merge nur "sicher" (KI-065/066/068)

- **Task**: PR-2 des freigegebenen Dubletten-Plans (Schritt 3, erster Teil).
- **Files Changed**: `CustomerMergeService` (`mergeBlockers()`, `hasActivePortalAccess()`, `merge(..., $uebersteuertMit)`, `mergeFamilyRelations()`, unterlegener aktiver Zugang wird deaktiviert statt geloescht), neu `MergeBlockedException`; `Admin\DuplicateController` (Einzel-Merge und Formular lesen `mergeBlockers()`, Sammel-Merge nur `sicher` ohne Sperre); `admin/customer_merge.blade.php` ("Zusammenfuehren gesperrt"); neu `tests/Feature/MergeSperrenTest.php`; `DuplicateBulkMergeTest`, `CustomerMergeDataPreservationTest`, `DublettenFamilieTest` nachgezogen (Sammel-Merge braucht jetzt gleiches Geburtsdatum); `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`.
- **Components Affected**: Einzel-, Sammel- und Ein-Klick-Merge.
- **Database Changes**: keine.
- **API Changes**: `CustomerMergeService::merge()` wirft `MergeBlockedException`, wenn Sperrgruende bestehen und keine Begruendung uebergeben wird.
- **Potential Side Effects**: Paare mit gleichem Namen OHNE Geburtsdatum sind nicht mehr sammel-zusammenfuehrbar - sie laufen einzeln ueber "Pruefen & zusammenfuehren".
- **Tests Performed**: `MergeSperrenTest` 8 Faelle; ohne Fix 7 rot (der achte belegt den Fehlbefund KI-067). Volle Suite 3373, 3368 gruen, 5 uebersprungen (OCR, tesseract lokal nicht installiert). PHPStan 0 Fehler, Pint sauber.
- **Result**: KI-065, KI-066, KI-068 FIXED; KI-067 WONT_FIX (Fehlbefund).

---

## 03.10.2026 - Dubletten: Familienmitglieder nie als "sicher", nie per Ein-Klick zusammengefuehrt (KI-063)

- **Task**: Betreiber-Auftrag "Moegliche Dubletten / Verwandte Kunden / Beziehung festlegen", Schritt 1 (Bestandsaufnahme, Befunde KI-063..068) und Schritt 2 Teil 1 (PR-1 des freigegebenen Plans). Anlass: Vater und Sohn Abboud als "44 % · ✓ sicher".
- **Files Changed**: `DuplicateDetectionService` (`classify()`, `identityConflicts()`, Familienrollen-Paare ausgeschlossen, Sortierung nach Klasse), `Admin\DuplicateController` (alle drei Merge-Wege lesen die Klasse; Gruppenpruefung; Uebersteuern mit Begruendung), `admin/customer_duplicates.blade.php`, `admin/customer_merge.blade.php`; neu `tests/Feature/DublettenFamilieTest.php`; `DuplicateBulkMergeTest` (sicheres Paar braucht jetzt gleiches Geburtsdatum); `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`.
- **Components Affected**: Dubletten-Seite, "Alle sicheren zusammenfuehren", Sammel-Merge, Einzel-Merge.
- **Database Changes**: keine. **API Changes**: `POST /admin/customers/{id}/merge` nimmt bei Widerspruch zusaetzlich `konflikt_bestaetigt` + `konflikt_begruendung`.
- **Potential Side Effects**: "Alle sicheren zusammenfuehren" erfasst deutlich weniger Paare - nur noch gleicher Name UND gleiches Geburtsdatum (vorher genuegte z. B. gleiche IBAN/E-Mail). Paare mit Widerspruch verschwinden nicht, sie stehen als "Moegliche Familie" am Ende der Liste. Der Score bleibt als Sortierzahl.
- **Tests Performed**: `DublettenFamilieTest` 10 Faelle; ohne Fix 5 rot (die uebrigen nutzen die neue Schnittstelle). Volle Suite 3349 gruen, 5 uebersprungen (OCR, tesseract lokal nicht installiert; CI hat es). PHPStan 0 Fehler, Pint sauber. Im Browser (Headless-Chromium, 1280 px und 390 px) nachgesehen: Familien-Karte ohne Prozent/"sicher"/Merge-Knopf, Merge-Formular mit Widerspruch und Uebersteuern, kein waagerechter Bildlauf, keine JS-Fehler.
- **Result**: KI-063 FIXED; KI-064..068 erfasst (OPEN, Folge-PRs).

---

## 02.10.2026 - Antrag auf Familienversicherung: Mitglied, Partner und Kinder (KI-053, KI-054)

- **Task**: Betreiber-Auftrag mit zwei echten PDF (ausgefuellter Antrag + Blankoformular): "erkenne diese Art von Familien-Antraegen, damit das System beim Hochladen den Antragsteller, die Ehefrau und die Kinder erkennt und sie automatisch unter der Akte des Antragstellers anlegt."
- **Files Changed**: `FamilienversicherungParser` (zweite Bauform: KKH-Vordruck 0765), `GeburtsurkundeParser` (Erkennung verlangt jetzt einen Standesamts-Beleg), `LiestSpalten` (neuer Baustein `zellenDerZeile`, `spaltenAus` nutzt ihn), `DocumentIntakeService::linkFamilienversicherungAngehoerige()`, `SmartDocumentUploadController::createCustomersFromPersons` (Paarung Person<->Kunde + Aufruf der Rollen-Verknuepfung), `ClaudeDocumentAiProvider` (Prompt-Absatz), `DocumentAnalyzer::hasAnyPersonField` (versteht den Pseudo-Namen `personen`); neu `tests/Feature/Ai/FamilienversicherungFaveParserTest.php` (20 Faelle), neu `tests/Feature/FamilienversicherungAnlageTest.php` (7 Faelle); `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`.
- **Components Affected**: Dokumenten-Eingang (Typ-Erkennung, Mehrpersonen-Anlage "Kunden anlegen", Review-Modal), Familienbeziehungen (`customer_family_relations`, `customer_relationships`), KI-Eskalation, Geburtsurkunden-Erkennung.
- **Database Changes**: keine. Es entstehen Zeilen in den vorhandenen Beziehungstabellen.
- **API Changes**: keine. `POST /admin/documents/{id}/create-customers-from-persons` unveraendert; `linked` zaehlt bei einem Familienversicherungs-Antrag die ueber die ROLLE verknuepften Angehoerigen statt der Namensgleichen.
- **Potential Side Effects**: Der `GeburtsurkundeParser` loest nicht mehr aus, wenn das Wort "Geburtsurkunde" nur im Kleingedruckten steht - ein echter Standesamts-Vordruck traegt immer Standesamt/Urkundsperson/Registernummer. Ein Foto einer Urkunde, auf dem ALLE diese Worte unlesbar sind, laeuft jetzt in die KI-Eskalation statt in eine halbe Erkennung. Die aeltere Bauform des Familienversicherungs-Fragebogens ist unberuehrt (eigener Test).
- **Tests Performed**: An der ECHTEN PDF-Textebene gemessen (pdftotext -layout, beide Dateien). Ausgangszustand belegt: die Kette lieferte `geburtsurkunde` mit dem VORNAMEN DER EHEFRAU als "Kind". Gegenprobe ohne den Fix: 22 von 24 Faellen rot. Danach alle 27 gruen; Waechter-Test fuehrt die echte Kette AUS DEM CONTAINER. Volle Suite gruen; `pint --test` sauber; PHPStan Stufe 5 ohne neuen Baseline-Eintrag.
- **Result**: KI-053 FIXED, KI-054 FIXED.

---

## 03.10.2026 - E-Signatur Phase 0: Unterschriften sichtbar, Selbsttest, Neuerzeugung (KI-055..058, 061, 062)

- **Task**: Betreiber-Auftrag Phase 0 "Signaturen fehlen im fertigen PDF". Grundlage: Ausgabe von `signaturen:diagnose` auf dem Server (17 geprueft, 11 betroffen; Ursachen U11 8x, U1 6x, U2 1x; poppler "XObject 'D24Sig1x0' is unknown"; `--zeichen`: 0 von 1424 Namen ausserhalb WinAnsi).
- **Files Changed**: `PdfStamper` (Ressourcen per Referenz KI-062, indirektes /Contents KI-056, Farbbild + /SMask KI-055, `platzierteBilder()`), `PdfStamp` (`freistellen`), neu `App\Support\Bildfreistellung`, neu `App\Support\FeldGeometrie`, `SignedPdfBuilder` (eine Umrechnung, Firmenbild freigestellt, Protokollzeile "Neu erzeugt"), neu `PdfSichtbarkeit` (aus der Diagnose herausgeloest), neu `SignedPdfVerifier`, neu `SignedPdfRegenerator`, `SignatureSigningService` (Selbsttest vor Abschluss, beide Hashes im Ereignis), `SignatureStatus::COMPLETION_FAILED`, `SignaturePageRenderer` (KI-057), `SignatureDiagnostics` (Ursachen nur fuer unsichtbare Felder, sonst Risiken), `SignatureRequestPolicy::regenerate`, Route `admin.signatures.regenerate`, `admin/signatures/show.blade.php`, `SignatureEvent` (`pdf_regenerated`), neu `signaturen:neu-erzeugen`; Tests neu `SignaturPdfSichtbarkeitTest` (10), `tests/Unit/BildfreistellungTest.php` (5), umgebaut `SignaturDiagnoseTest` (9); `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`, `BACKEND_MAP.md`.
- **Components Affected**: Abschluss einer Signaturanfrage, fertiges PDF, Vorschau, Detailseite (Fehlerzustand), Uebersicht (Reiter "In Bearbeitung" enthaelt den neuen Zustand).
- **Database Changes**: keine Migration. Neuer Statuswert `completion_failed` in der bestehenden String-Spalte.
- **API Changes**: neue Route POST `/admin/signaturen/{id}/neu-erzeugen`.
- **Potential Side Effects**: Firmenbilder mit weissem Grund erscheinen freigestellt (gewollt). Neue PDF sind etwas groesser (Farbbild statt 1x1-Flaeche), aber kleiner als vorher bei grossen Stempeln (Verkleinern auf 300 dpi). Ein Abschluss kann jetzt sichtbar scheitern statt still ein unbrauchbares Dokument zu liefern. Bestand wird NICHT automatisch geaendert - erst `signaturen:neu-erzeugen --alle --ausfuehren`; das alte PDF bleibt liegen.
- **Tests Performed**: Gegenprobe mit dem alten Stempler: 4 von 10 neuen Faellen rot (Ressourcen-Referenz, indirektes /Contents, Stempel farbig, nur Stempel). Ergebnis-PDF gerendert und angesehen (Unterschrift, roter Stempel, Formularlinie unter dem freigestellten Stempel sichtbar). Volle Suite 3355/3355 gruen, 0 uebersprungen; `composer stan` 0 Fehler; `composer lint` gruen.
- **Result**: KI-055, KI-056, KI-057, KI-058, KI-061, KI-062 FIXED. KI-059/060 bleiben OPEN (Verdacht; auf dem Server nicht beobachtet: keine Fortschreibungen, Generation 0).

---

## 02.10.2026 - E-Signatur: Diagnosebefehl `signaturen:diagnose` (KI-055..061 erfasst)

- **Task**: Betreiber-Meldung "Unterschriften erscheinen nicht im fertigen PDF" (Kunde UND Firmenstempel, Beispiel 9982d7a2-...). Betreiber-Vorgabe: VOR jedem Fix einen Diagnosebefehl bauen, auf der gemeldeten Anfrage laufen lassen und alle betroffenen Anfragen mit Ursache listen.
- **Files Changed**: neu `app/Services/Signature/SignatureDiagnostics.php`, neu `app/Console/Commands/DiagnoseSignatures.php`, `app/Services/Pdf/PdfDocument.php` (drei LESENDE Hilfsmethoden: `objectVersions`, `objectStreamMembers`, `headerOffset`), neu `tests/Feature/SignaturDiagnoseTest.php` (7 Faelle); `CLAUDE.md`, `KNOWN_ISSUES.md`, `FEATURE_MAP.md`, `BACKEND_MAP.md`.
- **Components Affected**: keine Laufzeitkomponente - der Befehl ist streng lesend und wird von keiner Route und keinem Planer aufgerufen.
- **Database Changes**: keine. **API Changes**: keine (ein neuer Artisan-Befehl).
- **Potential Side Effects**: keine im Betrieb. Der Bildvergleich rendert mit pdftoppm in ein Temp-Verzeichnis und loescht es.
- **Tests Performed**: 7 neue Faelle (sauber -> sichtbar ohne Ursache; deckendes Logo -> U1; indirektes /Contents -> U2; fehlendes PDF -> Nachbau im Speicher, nichts gespeichert; Befehl veraendert weder Dateien noch Ereignisse noch die Zeile und nennt keine Personendaten; Bestandslauf listet nur Betroffene; WinAnsi-Pruefung nennt Zeichen, nie Namen). Volle Suite 3338/3338 gruen, 0 uebersprungen, `composer stan` 0 Fehler, `composer lint` gruen.
- **Nachtrag 03.10.2026 (CI rot im MySQL-Job)**: dort fehlte poppler - der Job installiert jetzt dieselben Pakete wie der SQLite-Job (`deploy.yml`, `docs/TESTUMGEBUNG.md`). Dabei aufgefallen: ohne `pdftoppm` wertete der Befehl die "nicht gefunden"-Meldung als Ursache U11 und fuehrte JEDE Anfrage als betroffen. U11 zaehlt jetzt nur bei tatsaechlich gerenderter Seite, sonst "nicht_pruefbar" + Hinweis (Test `test_ohne_pdftoppm_ist_nichts_pruefbar_aber_nichts_beschuldigt`, scheitert ohne die Aenderung).
- **Result**: Ursachen KI-055 (Firmenbild) und KI-056 (indirektes /Contents) am echten Ablauf belegt; KI-057..061 erfasst. Fix bewusst NICHT in diesem PR - erst die Ausgabe vom Server.

---

## 02.10.2026 - Namenspartikel an den Nachnamen, Wissensbasis konfliktfest (KI-051, KI-052)

- **Task**: Betreiber-Auftrag: "loese das Problem der arabischen Namen" und "loese, dass sich die Datei nicht mehr zusammenfuehren laesst - das war vorher nicht so".
- **Files Changed**: neu `app/Support/PersonenName.php`, neu `tests/Unit/PersonenNameTest.php` (19 Faelle); `ValidatesExtractedFields::validatedPerson()` (Regel eingehaengt); neu `.gitattributes`, neu `tests/Feature/WissensbasisRegisterTest.php` (5 Faelle); `GehaltsabrechnungParserTest` (Erwartung auf das richtige Ergebnis nachgezogen); `CLAUDE.md`, `KNOWN_ISSUES.md`.
- **Components Affected**: JEDE Quelle von Personendaten - 23 Vorlagen-Parser, KI-Antwort, OCR-Heuristik, Mehrpersonen-Liste. Zusammenfuehren von Zweigen.
- **Database Changes**: keine. Bestandsdaten werden NICHT nachtraeglich umgeschrieben - die Regel wirkt ab der naechsten Analyse.
- **API Changes**: keine.
- **Potential Side Effects**: Neu analysierte Dokumente liefern fuer Namen mit Partikel einen anderen (richtigen) Schnitt als frueher. Fuer den Kundenabgleich ist das unkritisch, weil dort ueber Namensteile verglichen wird; Altbestand bleibt unveraendert. `merge=union` gilt nur fuer die zwei Protokolldateien.
- **Tests Performed**: 19 Unit-Faelle inkl. der Gegenproben ("Al Pacino" bleibt, "abdul" wird nicht angefasst); durch die ECHTE Parser-Kette geprueft ("Yusuf Al Rahman" -> Yusuf / Al Rahman). union-Merge am Versuchsaufbau nachgestellt (zwei Zweige, beide Eintraege erhalten, kein Konflikt). Mutationsprobe am Waechter: doppelte Kennung -> 2 von 5 Faellen rot. Volle Suite gruen; `pint --test` sauber; PHPStan Stufe 5: 0 Fehler.
- **Result**: KI-051 FIXED, KI-052 FIXED.

---

## 02.10.2026 - Krankenkassen-Bestaetigung: der Empfaenger ist der Arbeitgeber (KI-050)

- **Task**: Betreiber-Meldung mit einem echten Schreiben einer gesetzlichen Krankenkasse ("Bestaetigung: <Name> ist bei uns versichert"): "erkenne die Datei, damit das System sie erkennt".
- **Files Changed**: neu `app/Services/Ai/TemplateParsers/MitgliedsbescheinigungParser.php`, neu `app/Services/Ai/Concerns/LiestSpalten.php`; `GehaltsabrechnungParser` (nutzt den gemeinsamen Baustein statt einer privaten Kopie), `Document::AI_TYPES` (neuer Typ `mitgliedsbescheinigung`), `AppServiceProvider` (Registrierung vor den Beitritts-Formularen), `ClaudeDocumentAiProvider` (Prompt-Absatz); neu `tests/Feature/Ai/MitgliedsbescheinigungParserTest.php` (13 Faelle); `phpstan-baseline.neon` (3 Muster nachgezogen, KEIN neuer Eintrag).
- **Components Affected**: Dokumenten-Eingang (Typ-Erkennung, Kunden-Zuordnung, Review-Modal Gruppe "Arbeitgeber"), KI-Eskalation, Entgeltabrechnungs-Parser (nur Umbau, Verhalten unveraendert).
- **Database Changes**: keine. `customers.employer_name`/`employer_address` bestehen seit dem Arbeitsvertrag-Parser.
- **API Changes**: keine.
- **Potential Side Effects**: Ein neuer Dokumenttyp erscheint in der Typ-Liste. Schreiben, die bisher "Sonstiges" waren und zur KI gingen, werden jetzt gratis gelesen - das spart je Dokument einen KI-Aufruf. Der Typ ist bewusst NICHT in `NEW_BUSINESS_TYPES`: sonst entstuende ein zweiter Kranken-Vertrag fuer dieselbe Mitgliedschaft.
- **Tests Performed**: Am ECHTEN Schreiben gemessen - vorher kein Parser-Treffer und die Service-Adresse der Kasse als Kunden-E-Mail, nachher Name, Geburtsdatum, Geschlecht, Kasse, RVNR, Versicherungsbeginn und Arbeitgeber samt Anschrift; beide Wege (Foto/Tesseract und PDF-Textebene mit geteilten Zeilen) liefern dasselbe. Gegenprobe: verlesene RVNR wird verworfen. Mutationsprobe: ohne Registrierung bzw. ohne Typ scheitern die beiden Waechter-Tests. Volle Suite 3230/3230 gruen, 0 uebersprungen; `pint --test` sauber; PHPStan Stufe 5: 0 Fehler.
- **Result**: KI-050 IMPLEMENTED.

---

## 01.10.2026 - Kundenbeziehungen: "Beziehung festlegen" statt nur "Ehepaar"

- **Task**: Betreiber-Auftrag: in der Dubletten-Pruefung gab es nur "Ehepaar" und "Kein Duplikat"; Vater/Sohn, Geschwister, Nachbarn, Haushalt gingen verloren. Allgemeine Beziehung mit Art und (bei Elternteil-Kind) Richtung, Abschnitt "Verknuepfte Kunden" in der Kundenakte, Gleichlauf mit der Registerkarte "Familie".
- **Files Changed**: Migration `2026_10_02_090000_beziehungsarten_an_customer_relationships`; `CustomerRelationship` (Arten, Guard, Vorschlag); neu `app/Services/Relationships/CustomerRelationshipService.php`; `FamilyRelationService` (Gleichlauf statt `exemptFromDuplicates`), `CustomerFamilyRelation` (`duplicateExemptionType` entfernt); `DocumentIntakeService` (3 Stellen -> `markRelatedUnconfirmed`); `CustomerMergeService` (Art + Richtung beim Umhaengen); `DuplicateDetectionService` (Anzeige-Art bei mehreren Zeilen); `Admin\DuplicateController`, `AdminController::customerShow`; Routen `customers.relationships.confirm`, `customer.relationships.store`; Views `customer_duplicates`, `customer_relationships`, `customer_show`, neu `partials/beziehung_festlegen(_script)`, `partials/linked_customers`; Tests neu `KundenbeziehungenTest`, nachgezogen 5 Testdateien (alte Artnamen).
- **Components Affected**: Dubletten-Pruefung, Verwandte Kunden, Kundenakte, Registerkarte Familie, Dokumenten-Eingang (Familien-Verknuepfung), Kunden-Zusammenfuehrung.
- **Database Changes**: `customer_relationships.parent_customer_id` (FK, nullable); UNIQUE (a, b) -> (a, b, type); Daten: spouse->ehepartner (ohne Rolle), household->gleicher_haushalt, family->aus Familienrollen abgeleitet sonst sonstige_verwandte. Vor Produktion: Backup + Probelauf auf Kopie.
- **API Changes**: keine (zwei neue Admin-POST-Routen).
- **Potential Side Effects**: Aenderungen in der Registerkarte "Familie" aendern jetzt die Art in `customer_relationships` mit (und umgekehrt). "Kein Duplikat" ueberschreibt keine vorhandene Beziehung mehr. Dokumenten-Eingang ueberschreibt keine Familienart mehr (frueher: Ehepaar -> family). Altbestand-Ehepaare erscheinen als "unbestaetigt" bis zur Pruefung.
- **Tests Performed**: neu `KundenbeziehungenTest` (25 Faelle), 5 Testdateien auf die neuen Artnamen nachgezogen; volle Suite 3236 gruen, 5 uebersprungen (OCR - tesseract/poppler fehlen in der Arbeitsumgebung), 1 rot und VORBESTEHEND (`ReportsDashboardTest::test_verlaengerung_ist_ablauf_im_zeitraum_ohne_kuendigung` scheitert auch auf dem unveraenderten Basis-Commit 8d16b84 - Datum "Monatsanfang + 2 Tage" liegt am 1./2. eines Monats in der Zukunft); `composer stan` 0 Fehler, `composer lint` sauber. Migration real auf einer Wegwerf-SQLite mit Altbestand (spouse/family/household/not_duplicate + Familienrollen): Zaehlung auf der Konsole, Rueckbau verweigert ("1 Paar(e) mit mehreren Beziehungsarten"), Migration bleibt angewendet. Browser (Chromium headless): Dialog, Elternteil-Vorschlag (aelterer vorausgewaehlt), Sammelaktion, Filter "Ehepaar (unbestaetigt)" + Bestaetigen, Kundenakte mit Sofort-Suche, Telefonbreite ohne waagerechten Ueberlauf, keine JS-Fehler. Dabei gefunden und behoben: "Verwandte Kunden" lud ab zwei Zeilen Vertraege nach (Lazy-Loading-Sperre -> 500) - eigener Test, scheitert ohne den Fix.
- **Nachtrag (Merge mit main, 02.10.2026)**: den datumsabhaengigen `ReportsDashboardTest` hatte dieser PR zunaechst selbst repariert; PR #365 hat ihn parallel als KI-048 behoben - uebernommen wird dessen Fassung. Der Befund der Dubletten-Seite heisst KI-049 (KI-046/047 waren in #365 vergeben). Die Migration liegt jetzt auf `2026_10_02_090000_...` - #365 brachte eine Migration mit identischem Zeitstempel `2026_10_01_100000`; die Reihenfolge haette am Dateinamen gehangen. Sie ist noch nirgends gelaufen, die Umbenennung ist also gefahrlos.
- **Result**: IMPLEMENTED.

---

## 01.10.2026 - KFZ Phase 1: SF-Sondereinstufung mit Bezugsfahrzeug (KI-046)

- **Task**: Betreiber-Auftrag KFZ-Modul, Phase 1: der Erstwagen einer Zweitwagen-/Drittwagen-/Familien-Einstufung wird strukturiert und verknuepft erfasst statt zweckentfremdet in der Vorversicherung.
- **Files Changed**: Migration `2026_10_01_100000_create_vehicle_sf_references`; neu `config/kfz_rules.php`, `app/Models/VehicleSfReference.php`, `app/Services/Kfz/SfReferenceService.php`, `SfReferenceValidator.php`, `SfReferenceNotifier.php`, `app/Console/Commands/KfzZweitwagenPruefen.php`, Views `partials/contract_kfz_sf_reference`, `partials/contract_sf_erstwagen_badge`, `docs/project-knowledge/KFZ_RULES.md`; geaendert `Contract` (Relation `sfDependents`, Listener, KI-047), `ContractVehicleDetail` (Rang/Rueckstufung/Vorschlag/Anzeige, Listener), `VehicleSfEntry`, `Admin\ContractController`, `AdminController` (Eager Loading), `SettingsController` + `UpdateSettingsRequest` + `settings.blade.php` (Schalter), Views `contract_kfz_fields`, `contract_kfz_cockpit`, `contract_edit`, `customer_show`; `routes/web.php`.
- **Components Affected**: Vertragsformular KFZ, Vertragsakte, Kundenakte (Vertragszeile), Einstellungen, Glocke/Aufgaben.
- **Database Changes**: neue Tabelle `vehicle_sf_references`; `vehicle_sf_history` + `special_reason`, `reference_label`, `reference_contract_id`; `contract_vehicle_details` + `no_previous_insurance`. Rein additiv, kein Datenverlust.
- **API Changes**: neue JSON-Route `GET admin/sf-bezug/{customerId}/suche` (`admin.contract.sf_reference_search`, Portfolio-Pruefung, throttle 240/min).
- **Potential Side Effects**: Ein als Fremdvertrag angelegter Erstwagen steht im Kundenbestand (Abschnitt Fremdvertraege, Herkunft "ungeprueft") - nicht im Eigenbestand, keine Courtage. KI-047: `deleting`-Listener am Vertrag laufen jetzt alle (bisher nur der erste).
- **Tests Performed**: neu `SfBezugsfahrzeugTest` (19), `KfzSfRegelnTest` (6); volle Suite, `composer stan`, `composer lint` - Ergebnis im PR. Basislauf auf `main` vor der Aenderung: 1 datumsabhaengiger Fehlschlag (KI-048), 5 uebersprungen (OCR-Werkzeuge fehlen in dieser Umgebung).
- **Nachtrag (CI)**: Beide Testjobs der CI waren rot - einzig durch den datumsabhaengigen `ReportsDashboardTest` (KI-048, am Monatsersten immer rot, auch auf `main`). Im selben PR behoben (Uhr im Test fest auf den Monatsersten), damit der PR mergebar wird.
- **Result**: IMPLEMENTED (Phase 1 von 3; Phase 2/3 geplant, Regeln in KFZ_RULES.md).

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
