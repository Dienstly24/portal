# Dienstly24 – Systemanalyse & Erweiterungsplan

**Datum:** 2026-07-10 · **Branch:** `claude/dienstly-system-audit-akcmkd` · **Basis:** Laravel 13.18 (PHP 8.3), SQLite/MySQL, Blade + Alpine.js + Tailwind, kein SPA-Frontend.

Methodik: vollständige statische Durchsicht aller Models, Controller, Services, Migrationen, Routen und Views. Ergänzend wurde der bestehende `docs/AUDIT_REPORT.md` (technisches/sicherheitsbezogenes Audit vom 2026-07-08) ausgewertet und nicht dupliziert – dieses Dokument fokussiert auf **fachliche Architektur und Erweiterung**, nicht auf Code-Bugs.

**Es wurden in diesem Schritt keine Code-Änderungen vorgenommen.** Dies ist reine Analyse und Planung, wie beauftragt.

---

## 1. System-Audit: Was existiert bereits?

### 1.1 Modulübersicht (Ist-Zustand)

| Modul | Status | Kernklassen |
|---|---|---|
| **Kundenverwaltung** | ✅ vorhanden, gut ausgebaut | `Customer`, `CustomerAddress`, `CustomerContact`, `CustomerFamily`, `CustomerNote`, `CustomerVehicle`, `CustomerTimeline` |
| **Vertragsverwaltung** | ✅ vorhanden, mit Sparten-Details | `Contract` + `ContractVehicleDetail`, `ContractEnergyDetail`, `ContractInternetDetail` |
| **Dokumentenverwaltung** | ✅ vorhanden, aber nur kundenzentriert | `Document` (Kategorien: Vertrag/Police/Rechnung/Identität/Schaden/Sonstige, Sichtbarkeit kunde/intern) |
| **Aufgabenverwaltung** | ⚠️ vorhanden, aber isoliert | `Task` (kunde-, nicht vertrags-/partnerbezogen) |
| **Kommunikation (Kunde↔Team)** | ✅ vorhanden | `Ticket` + `TicketMessage` (inkl. anonyme Website-Anfragen über Gastfelder) |
| **Kommunikation (intern, Team↔Team)** | ✅ vorhanden, **bewusst getrennt** | `InternalConversation` (eigenständiger Mitarbeiter-Chat), `InternalMessage` (kundengebundene interne Notizen/Chat mit @Mentions) |
| **Kundenportal** | ✅ vorhanden | Verträge, Tickets, Dokumente, Self-Service (Familie/Adresse/Kontakt/Bank/Vertragsmeldung) |
| **Self-Service / Genehmigungen** | ✅ vorhanden, **bereits konsolidiert** | `CustomerChangeRequest` + `ChangeRequestService` (ersetzte ein älteres `ApprovalRequest` – Commit `e0de5ef`) |
| **Lexoffice-Integration** | ⚠️ vorhanden, aber nur manuell/CRUD | `LexofficeService`, `LexofficeController` (Kontakte importieren, Rechnungen anzeigen/senden/herunterladen, Finanzübersicht) |
| **Partnerverwaltung** | ❌ **existiert nicht** | – |
| **E-Mail-Eingang / Postfach-Integration** | ❌ **existiert nicht** | Nur `WebsiteInquiryController::storeManual()` – ein manuelles Formular, in das ein Mitarbeiter Inhalte einer info@-Mail *von Hand* abtippt |
| **Provisionsverwaltung** | ❌ **existiert nicht** | – |
| **Fonds-Finanz-Anbindung** | ❌ **existiert nicht** | Keine Modelle/Felder für Fonds-Finanz-Nummern, keine Import-Pipeline |
| **Externe Referenzen (generisch)** | ❌ **existiert nicht** | Keine polymorphe Referenztabelle; einzig `contracts.lexoffice_id` als 1:1-Sonderfall |
| **Mitarbeiterverwaltung / Rollen** | ✅ vorhanden, granular | `User` mit Rollen (admin/manager/support/employee/customer), Portfolio-Zuweisung (`employee_customers`), Vertretungsregelung (`Substitution`) |
| **E-Mail-Marketing (Ausgang)** | ✅ vorhanden | `EmailCampaign`, `CampaignMail`, Geburtstags-/Vertragsablauf-Mails |
| **Aktivitäts-Log / Audit** | ✅ vorhanden | `ActivityLog` |

### 1.2 Bewertung nach den in der Aufgabe genannten Kriterien

**Bereits vorhanden und funktionsfähig:**
- Rollen- und Portfoliomodell mit Vertretungslogik (krankheits-/urlaubsbedingte Fallübernahme)
- Einheitlicher Self-Service-Genehmigungsworkflow (ein einziges Change-Request-System für Familie/Adresse/Kontakt/Bank/Vertrag/Profil – **keine Duplikate**, das war bereits in einem früheren Schritt bereinigt worden)
- Dokumenten-Sichtbarkeitssteuerung (intern vs. kundensichtbar), private Storage mit autorisierten Downloads
- Kundenakte-Vollständigkeitsprüfung (`Customer::completeness()`)
- Interne Kommunikation ist **sauber in zwei Zwecke getrennt**: `InternalConversation` (freie Team-Unterhaltungen ohne Kundenbezug) und `InternalMessage` (kundengebundene interne Notizen/Chat). Das ist keine Dopplung, sondern zwei unterschiedliche Anwendungsfälle mit eigener Berechtigungslogik.

**Fehlende Funktionen (Kernlücken für die geplante Erweiterung):**
1. **Keine echte E-Mail-Eingangsverarbeitung.** Es gibt keinen IMAP/OAuth-Client, kein Postfach-Modell, keine automatische Zuordnung eingehender Mails. `info@`-Mails werden aktuell manuell von einem Mitarbeiter in ein Formular übertragen (`WebsiteInquiryController::storeManual`) – das ist der Platzhalter, den die geplante Integration ablösen soll.
2. **Keine Partnerverwaltung.** Kein Konzept für Makler-/Partnerkontakte, Partner-IDs, Partnerhistorie.
3. **Keine Provisionsverwaltung.** Keine Struktur für Abrechnungen, Gutschriften, Beträge, Zuordnung zu Partnern.
4. **Keine Fonds-Finanz-Anbindung.** Weder Datenfelder noch Verarbeitungslogik für Fonds-Finanz-Nummern/-Dokumente.
5. **Kein generisches Referenzsystem.** Externe Kennungen (Fonds-Finanz-Nr., Partner-ID, weitere Vertragsnummern) lassen sich aktuell nirgends strukturiert speichern, außer dem Einzelfall `contracts.lexoffice_id`.
6. **Keine automatisierte Kunden-Matching-Logik.** Es gibt keine Score-basierte Erkennung/Zusammenführung von Kunden anhand Namen/Geburtsdatum/E-Mail (nur eine manuelle `mergeCustomers`-Funktion für Admins).
7. **Keine Dokumenten-KI/Parsing-Pipeline.** Uploads werden manuell kategorisiert; es gibt keine Texterkennung/Klassifikation für PDFs (Anträge, Abrechnungen).
8. **Lexoffice-Automatisierung fehlt.** Vorhanden ist nur die manuelle Bedienung der Lexoffice-API (Kontakt anlegen, Rechnung senden). Automatisches Einlesen von Provisions-PDFs → Lexoffice-Beleg fehlt komplett.

**Funktionen, die verbessert werden sollten (bestehende, aber unvollständige Bausteine):**
- `Task` ist nicht mit `Contract`, `Ticket` oder (zukünftig) `Partner` verknüpfbar – nur mit `Customer`. Für die geplanten Workflows (Versicherung fordert Dokument an → Aufgabe → Kunde lädt hoch) fehlt die Vertrags-/Kategoriebindung.
- `Document` kennt nur `customer_id` – keine Zuordnung zu `Contract`, `Ticket`, `Partner` oder E-Mail-Herkunft. Für "Dokument automatisch einem Vertrag zuordnen" muss das Modell erweitert werden (siehe Abschnitt 6).
- `Ticket` hat bereits Gast-Felder und eine `source`-Spalte (website/email) – das ist die richtige Grundlage, um echte E-Mails dort eingehen zu lassen, sobald ein Postfach-Poller existiert.
- Der bestehende Audit-Report (`docs/AUDIT_REPORT.md`) listet weitere technische Mängel (IDOR-Lücken in Admin-Routen, `.env`-Schreibzugriff aus der Weboberfläche, MySQL-only-SQL in Migrationen). Diese sind **Voraussetzung**, bevor produktive Zugangsdaten für externe Postfächer im System gespeichert werden – siehe Risiken in Abschnitt 12.

**Mögliche Überschneidungen – explizit geprüft, keine gefunden:**
- Kunden: nur ein Kundenmodell (`Customer`), `family_members` als historisches Duplikat von `customer_family` wurde bereits sicher entfernt (Migration `2026_07_09_180000`).
- Dokumente: nur ein Modell (`Document`), Kategorien sauber getrennt (Vertrag/Police/Rechnung/Identität/Schaden/Sonstige).
- Aufgaben: nur ein Modell (`Task`), keine parallele To-Do-Struktur.
- Kommunikation: `Ticket` (Kunde↔Team) und `InternalConversation`/`InternalMessage` (intern) haben getrennte, nicht überlappende Zwecke – **keine Dopplung**, sondern korrekt getrennte Verantwortlichkeiten. Diese Trennung sollte in der neuen Architektur beibehalten werden.

---

## 2. Zielarchitektur: Zentrale Plattform

### 2.1 Leitprinzip

Jede fachliche Information im System (E-Mail, Dokument, Aufgabe, Provision) muss **über ein gemeinsames Verknüpfungsmodell** an Kunde, Vertrag oder Partner andockbar sein – ohne die bestehenden, kundenzentrierten Tabellen umzubauen. Der pragmatische Weg dafür ist ein polymorphes "Attachable"-Muster, das Laravel nativ unterstützt (`morphTo`/`morphMany`), statt für jede Kombination (Dokument-zu-Vertrag, Dokument-zu-Partner, E-Mail-zu-Kunde, E-Mail-zu-Vertrag …) eigene Fremdschlüsseltabellen zu bauen.

```
                      ┌─────────────┐
                      │   Partner    │
                      └──────┬──────┘
                             │ 1:n
        ┌───────────┐   ┌───▼────────┐   ┌───────────┐
        │  Customer  │──►│  Contract   │◄──┤ Commission │
        └─────┬──────┘   └────┬───────┘   └───────────┘
              │ 1:n           │ 1:n
    ┌─────────▼───────┐  ┌────▼────────────┐
    │  EmailMessage     │  │ ExternalReference│  (polymorph: Fonds-Finanz-Nr.,
    │ (polymorph link zu│  │ (polymorph: Contract, Customer, Partner) 
    │ Customer/Contract/│  └──────────────────┘
    │ Partner/Ticket)   │
    └─────────┬─────────┘
              │ 1:n
       ┌──────▼──────┐
       │  Document    │ (polymorph link zu Customer/Contract/Partner/EmailMessage)
       └─────────────┘
```

### 2.2 Konkrete Datenmodell-Erweiterung (Vorschlag, noch nicht implementiert)

| Neue Tabelle | Zweck |
|---|---|
| `partners` | Makler/Partnerstammdaten (Name, Partner-ID, Bankverbindung, Provisionsmodell) |
| `commissions` | Einzelne Provisionsgutschriften, verknüpft mit `partner_id`, optional `contract_id`, Lexoffice-Beleg-Referenz |
| `email_accounts` | Konfigurierte Postfächer (info@, kv@, …), Zugangsdaten verschlüsselt, Protokoll (IMAP/OAuth), aktiv/inaktiv |
| `email_messages` | Jede eingehende (und optional ausgehende) Mail, mit Status der automatischen Verarbeitung |
| `email_message_links` (polymorph) | Verknüpfung `email_messages` ↔ `customers`/`contracts`/`partners`/`tickets`, inkl. Match-Score und Bestätigungsstatus |
| `external_references` (polymorph) | Generische externe Kennungen: Typ (`fonds_finanz`, `partner_id`, `affiliate`, …), Wert, verknüpft an `customers`/`contracts`/`partners` |
| `document_links` (polymorph, **oder** Erweiterung von `documents` um `linkable_type`/`linkable_id`) | Dokument zusätzlich an `Contract`/`Partner`/`EmailMessage` andocken, nicht nur an `Customer` |

Diese Tabellen sind additiv – **keine bestehende Tabelle muss umgebaut werden**. `Document.customer_id` bleibt Pflichtfeld (jedes Dokument gehört weiterhin eindeutig einem Kunden), die polymorphe Verknüpfung ergänzt nur die zusätzliche fachliche Zuordnung (z. B. "dieses Dokument gehört zu Vertrag X und kam aus E-Mail Y").

---

## 3. E-Mail-Integrationskonzept

### 3.1 Empfehlung: IMAP als gemeinsamer Nenner + OAuth2 wo verfügbar

| Anbieter | Empfohlener Zugang | Bemerkung |
|---|---|---|
| Hostinger | IMAP/SMTP mit App-spezifischem Passwort | Kein OAuth verfügbar – Zugangsdaten müssen verschlüsselt gespeichert werden |
| Gmail / Google Workspace | **OAuth2 + Gmail API** (nicht IMAP-Passwort) | Google deaktiviert "less secure apps"; OAuth ist Pflicht. Refresh-Token verschlüsselt speichern |
| Microsoft 365 | **OAuth2 + Microsoft Graph API** | Analog zu Gmail; Basic-Auth für IMAP ist bei M365 inzwischen deaktiviert |
| Generisches IMAP/SMTP | Standard-IMAP mit Benutzer/Passwort oder App-Passwort | Fallback für alle anderen Anbieter |

**Technischer Ansatz für Laravel:**
- Ein Abstraktionsinterface `MailboxProviderInterface` mit Implementierungen `ImapMailboxProvider`, `GmailApiMailboxProvider`, `GraphApiMailboxProvider`.
- Ein PHP-IMAP-Paket (z. B. `webklex/php-imap`) für den generischen Fall; für Gmail/M365 die jeweiligen REST-APIs (Push-Benachrichtigungen möglich statt Polling: Gmail `watch()` + Pub/Sub, Microsoft Graph `subscriptions`/Webhooks).
- Ein Scheduler-Job (`artisan schedule`) pollt IMAP-Postfächer alle 1–2 Minuten; Gmail/M365 nutzen wo möglich Webhooks statt Polling, um Rate Limits zu schonen und Echtzeit-Zuordnung zu ermöglichen.
- Jede eingehende Mail wird zunächst roh in `email_messages` gespeichert (Betreff, Absender, Body, Anhänge als `Document` mit `visibility=internal`), **bevor** die inhaltliche Analyse startet – damit nichts verloren geht, wenn die Verarbeitung fehlschlägt.

### 3.2 Admin-UI zum Hinzufügen neuer Postfächer

Ein neues Modul `admin/settings/email-accounts`:
- Formular: Anzeigename, Adresse, Protokoll-Auswahl (Hostinger-IMAP / Google OAuth / Microsoft OAuth / generisches IMAP), Ordner, die überwacht werden sollen.
- Für OAuth-Anbieter: "Verbinden"-Button, der den OAuth-Consent-Flow startet (Redirect → Google/Microsoft → Callback speichert Refresh-Token).
- Für IMAP: Host/Port/Verschlüsselung/Benutzer/Passwort-Formular mit Verbindungstest ("Testen"-Button vor dem Speichern).
- Aktiv/Inaktiv-Schalter je Postfach, letzter Sync-Zeitpunkt, Fehlerzähler sichtbar für Admins.

### 3.3 Sicherheit & DSGVO

- **Zugangsdaten:** niemals im Klartext in der Datenbank oder in `.env` (der bestehende Audit-Befund M6 zu Klartext-Keys in `.env` muss vorher behoben sein). Verschlüsselung über Laravels `encrypted`-Cast (AES-256, nutzt bereits `Customer` für sensible Felder als Vorbild) oder ein dediziertes Secrets-Backend.
- **OAuth-Tokens:** nur Refresh-Token verschlüsselt persistieren, Access-Token nur im Speicher/Cache mit kurzer TTL.
- **Prinzip der minimalen Berechtigung:** Gmail-Scope `gmail.readonly` bzw. `gmail.modify` (kein Vollzugriff), Microsoft Graph `Mail.Read` statt `Mail.ReadWrite` wo ausreichend.
- **DSGVO:**
  - Verarbeitungsverzeichnis erweitern (neue Verarbeitungstätigkeit "automatisierte E-Mail-Zuordnung").
  - Auftragsverarbeitungsvertrag (AVV) mit Google/Microsoft ist bei Workspace/365-Business-Konten i. d. R. bereits Teil der Verträge – prüfen, ob die konkret genutzten Konten darunterfallen.
  - Löschkonzept: E-Mails/Anhänge, die keinem Kunden zugeordnet werden können, dürfen nicht unbegrenzt gespeichert werden (Aufbewahrungsfrist definieren, z. B. 90 Tage für unzugeordnete Mails, danach Löschung oder manuelle Entscheidung).
  - Zugriffskontrolle: nur berechtigte Mitarbeiter (nach Portfolio/Rolle) dürfen Mailinhalte einsehen – analog zur bestehenden `visibleCustomerIds`-Logik.
- **Automatisierung:** Nach erfolgreicher Zuordnung kann automatisch eine `Task` oder `Ticket`-Antwort ausgelöst werden (siehe Abschnitt 4); vollautomatisches *Beantworten* von Kunden-Mails wird **nicht** empfohlen (Fehlerrisiko bei automatischer Kundenkommunikation) – stattdessen automatische *Vorbereitung* und Mitarbeiter-Bestätigung.

---

## 4. Intelligente E-Mail-Verarbeitung

```
E-Mail-Eingang (IMAP/Gmail/Graph)
        │
        ▼
Rohspeicherung in email_messages (+ Anhänge als Document, visibility=internal)
        │
        ▼
Absender-Erkennung  ──► bekannter Partner? bekannter Kunde? unbekannt?
        │
        ▼
Kategorisierung (Regelwerk + Schlüsselwörter/Absenderdomain):
   Versicherung │ Fonds Finanz │ Energie │ Dokumente │ Provisionen │ Kundenanfrage
        │
        ▼
Kunde/Vertrag/Partner-Matching (siehe Abschnitt 5)
        │
        ▼
Aktion auslösen:
   - Kundenanfrage       → Ticket erzeugen/verknüpfen
   - Dokument/Anhang      → Document mit Kategorie + Verknüpfung anlegen
   - Provisionsabrechnung → Commission-Datensatz + Lexoffice-Workflow anstoßen (Abschnitt 10)
   - Fonds-Finanz-Mail    → Fonds-Finanz-Workflow (Abschnitt 8)
   - unklar               → Aufgabe "manuell prüfen" für zuständigen Mitarbeiter
```

**Kategorisierung – Realistischer erster Ansatz:** ein regelbasiertes System (Absenderdomain, Betreff-Schlüsselwörter, Anhangstyp) reicht für den Start und ist deterministisch/nachvollziehbar. Ein LLM-gestützter Klassifikator kann optional als zweite Stufe ergänzt werden, wenn Regeln nicht greifen – sollte aber immer mit einem Konfidenzwert arbeiten und bei Unsicherheit an einen Mitarbeiter eskalieren, nicht automatisch buchen.

---

## 5. Kunden-Matching-Konzept

Bewertungsschema wie beauftragt, als gewichteter Score:

| Merkmal | Gewicht (Vorschlag) |
|---|---|
| Geburtsdatum exakt | 40 |
| Vorname + Nachname (normalisiert, fuzzy) | 30 |
| E-Mail exakt | 20 |
| Adresse (PLZ + Straße, fuzzy) | 10 |
| weitere Daten (Telefon, IBAN-Teilübereinstimmung) | +5 Bonus |

- **> 90 %** → automatische Zuordnung, Vorgang wird protokolliert (`ActivityLog`), Mitarbeiter sieht die Zuordnung nachträglich, kann sie aber rückgängig machen.
- **70–90 %** → Vorschlag wird angezeigt, Mitarbeiter muss mit einem Klick bestätigen ("Ja, das ist Kunde XY").
- **< 70 %** → landet in einer Prüfliste ("nicht zugeordnete E-Mails"), keine automatische Aktion.

Technisch: eine Service-Klasse `CustomerMatchingService`, die für jedes eingehende Objekt (E-Mail, manuell erfasste Anfrage) einen `MatchResult` (Kunde, Score, Begründung je Kriterium) liefert. Wichtig: Namensvergleich normalisiert (Groß-/Kleinschreibung, Umlaute, Titel wie "Dr.") und nutzt z. B. Levenshtein/Soundex als Fuzzy-Baustein, nicht nur exakten String-Vergleich.

---

## 6. Automatische Kundenerstellung

Wenn kein Match gefunden wird (oder der Mitarbeiter "neuer Kunde" bestätigt):

1. `Customer` anlegen mit `customer_number` (bestehende Logik zur Nummernvergabe wiederverwenden/vereinheitlichen – aktuell muss `customer_number` beim manuellen Anlegen durch den Mitarbeiter vergeben werden; für die Automatisierung braucht es einen zentralen Nummerngenerator, z. B. `CustomerNumberGenerator`, damit Web-Formular, E-Mail-Import und Fonds-Finanz-Import dieselbe Sequenz nutzen und keine Kollisionen/Lücken entstehen).
2. Quelle speichern (`source` = `email_import`, `fonds_finanz`, `website`, `manual`) – neues Feld auf `Customer` oder in einer `customer_origin`-Zeile.
3. Verträge/Dokumente aus dem auslösenden Vorgang sofort verknüpfen.
4. **Duplikatsschutz:** vor dem Anlegen läuft zwingend das Matching aus Abschnitt 5; unterhalb der 70 %-Schwelle wird ein neuer Kunde nur nach expliziter Mitarbeiter-Bestätigung angelegt, nie vollautomatisch – das verhindert stille Duplikate.

---

## 7. Externe Referenzen – Architektur

Eine generische, polymorphe Tabelle statt Spalten pro Anbieter:

```
external_references
  id
  referenceable_type   (Customer | Contract | Partner)
  referenceable_id
  type                 (fonds_finanz_number | partner_id | affiliate_code | contract_number_external | …)
  value
  source               (fonds_finanz | lexoffice | manual | …)
  created_at / updated_at
```

Damit lässt sich **jede** externe Kennung anhängen, ohne das Schema erneut zu ändern. Affiliate-Referenzen (laut Auftrag aktuell nicht umzusetzen) passen ohne weitere Anpassung in dieselbe Struktur (`type = affiliate_code`), sobald sie gebraucht werden – die Architektur ist also bereits erweiterbar, ohne dass heute Code dafür geschrieben werden muss.

---

## 8. Fonds-Finanz-Workflow (Planung)

```
Fonds-Finanz-Dokument/Mail eingehend
   → Kunde matchen (Abschnitt 5) über Name/Geburtsdatum, ergänzt um Fonds-Finanz-Nummer (external_references, falls bereits bekannt)
   → Gesellschaft, Sparte, Produkt, Vertragsnummer, Dokumentnummer aus dem Dokument extrahieren
   → Contract anlegen/aktualisieren (type=Sparte, insurer=Gesellschaft, contract_number)
   → external_reference (type=fonds_finanz_number) am Contract hinterlegen
   → Dokument dem Vertrag zuordnen (document_links)
   → bei Antragsnachbearbeitung: Task für zuständigen Betreuer ("Antrag XY nachbearbeiten")
```

Die Datenextraktion aus Fonds-Finanz-Dokumenten (meist strukturierte PDFs/CSV-Exporte) sollte zunächst über einen **Parser für das bekannte Fonds-Finanz-Format** erfolgen (feste Feldpositionen/Tags), nicht über generische KI-Texterkennung – das ist zuverlässiger und leichter zu testen.

---

## 9. Versicherungs-Workflow (Planung)

```
Versicherung fordert Dokument/Information an (per Mail erkannt, Kategorie "Versicherung")
   → Kunde/Vertrag matchen
   → Task anlegen (Typ "Dokument anfordern", verknüpft mit Contract + Customer)
   → Kunde benachrichtigen (Mail/Portal-Hinweis: "Bitte laden Sie Dokument X hoch")
   → Kunde lädt über bestehenden Portal-Upload hoch (Mechanismus existiert bereits: `PortalController`/Dokumente)
   → Mitarbeiterprüfung (Aufgabe wechselt Status → "zu prüfen")
   → Weiterleitung an Versicherung (manuell oder – Ausbaustufe – automatisiert per Mail-Antwort mit Anhang)
```

Diese Kette lässt sich fast vollständig auf bestehende Bausteine abbilden (`Task`, `Document`, Portal-Upload); es fehlt nur die Verknüpfung `Task ↔ Contract` und ein Auslöse-Mechanismus aus der E-Mail-Pipeline.

---

## 10. Provisionen & Lexoffice (Planung)

```
Provisions-PDF eingehend (E-Mail-Anhang oder manueller Upload)
   → Partner anhand Absender/PDF-Kopf erkennen
   → Positionen aus PDF lesen (Partner, Kunde/Vertrag falls vorhanden, Beträge)
   → Commission-Datensätze anlegen (partner_id, amount, contract_id nullable, source_document)
   → Lexoffice-Beleg erzeugen (bestehende LexofficeService.createInvoice/Voucher-Funktionen wiederverwenden statt neu zu bauen!)
   → Partnerhistorie aktualisieren (Summe/Zeitverlauf je Partner)
```

Wichtig: **Der bestehende `LexofficeService` wird wiederverwendet**, nicht dupliziert – er hat bereits Voucher-Upload (`uploadVoucher`) und Invoice-Erstellung. Es fehlt nur die vorgeschaltete PDF-Parsing-Stufe und die neuen `Partner`/`Commission`-Modelle, die die Ergebnisse an den bestehenden Service übergeben.

PDF-Parsing-Ansatz: strukturierte Extraktion (z. B. `smalot/pdfparser` für Text, ggf. Tabellen-Erkennung) mit anbieterspezifischen Templates, da Abrechnungsformate je Partner unterschiedlich sind. Bei unbekanntem Format → manuelle Erfassungsmaske statt Fehlversuch.

---

## 11. UX/UI-Analyse

### Mitarbeiter-Sicht (Ist-Zustand geprüft)

**Positiv:** Der Admin-Bereich ist klar nach Themen strukturiert (Kunden/Verträge/Tickets/Aufgaben/Lexoffice/Team), Portfolio-Filterung reduziert Datenmenge auf Relevantes, globale Suche vorhanden, Aktivitäts-Log für Nachvollziehbarkeit.

**Verbesserungsbedarf für die geplante Erweiterung:**
- Es gibt noch **keine zentrale "Posteingang"-Ansicht**, die eingehende Mails, unklare Kundenzuordnungen und offene Aufgaben in einer Arbeitsliste bündelt – das wird mit der E-Mail-Integration zum wichtigsten neuen Bildschirm für Mitarbeiter ("Aufgaben-Inbox").
- `AdminController` ist mit 723 Zeilen der mit Abstand größte Controller (Kunden, Verträge, Notizen, Dokumente, Familie, Fahrzeuge, Merge, Timeline in einer Klasse) – für neue Funktionen (Partner, Provisionen) sollten von Anfang an **eigene Controller** angelegt werden, um diesen Massenzuwachs nicht fortzusetzen.
- Bestätigungsschritte für Matching (70–90 %) sollten mit möglichst einem Klick direkt aus der Übersicht heraus erledigbar sein (kein Seitenwechsel nötig), um die Vorgabe "wenige Klicks" zu erfüllen.

### Kunden-Sicht (Ist-Zustand geprüft)

**Positiv:** Übersichtliches Portal-Menü (Verträge, Tickets, Dokumente, Self-Service-Formulare), Vollständigkeits-Widget zeigt fehlende Angaben mit direktem Link, Uploads mit Fortschrittsanzeige (laut letztem Commit bereits mit echtem Upload-Progress ergänzt), einheitliche Anrede in Mails/Ansprache.

**Verbesserungsbedarf:**
- Es gibt keinen sichtbaren Status "Ihr Dokument wird gerade automatisiert geprüft" – sobald die intelligente Verarbeitung eingeführt wird, sollte der Kunde im Portal sehen, dass sein Upload einer Anfrage/Aufgabe zugeordnet wurde (Statusanzeige), nicht nur, dass die Datei hochgeladen ist.

---

## 12. Ergebnis: Entwicklungsplan

### 12.1 Architekturvorschlag (Zusammenfassung)

- Bestehende kundenzentrierte Struktur bleibt Kern (Customer/Contract/Document/Task/Ticket unverändert in ihrer Grundfunktion).
- Neue Module werden **additiv** ergänzt: `Partner`, `Commission`, `EmailAccount`, `EmailMessage`, `ExternalReference`.
- Verknüpfung über polymorphe Relationen statt Spezialtabellen pro Kombination.
- E-Mail-Verarbeitung als eigener Service-Layer (`Mailbox\*`-Namespace), entkoppelt von bestehenden Controllern, damit die Sicherheits- und Testabdeckung isoliert wachsen kann.

### 12.2 Datenmodell-Anpassungen (Reihenfolge)

1. `partners`, `commissions`
2. `email_accounts`, `email_messages`
3. `external_references` (polymorph)
4. `documents`: zusätzliche polymorphe Verknüpfung (`linkable_type`/`linkable_id`) **ergänzen**, `customer_id` bleibt Pflichtfeld
5. `tasks`: optionales `contract_id` ergänzen (für Versicherungs-/Fonds-Finanz-Workflow)

### 12.3 Notwendige neue Module/Services

- `PartnerController` + `Partner`-Views (Admin)
- `CommissionController` + Provisionshistorie je Partner
- `EmailAccountController` (Admin-Einstellungen für Postfächer)
- `MailboxSyncService` (IMAP/Gmail/Graph-Abstraktion, geplant über Scheduler)
- `EmailClassificationService` (Kategorisierung)
- `CustomerMatchingService` (Scoring, siehe Abschnitt 5)
- `FondsFinanzImportService`
- `CommissionPdfParserService` → bestehenden `LexofficeService` **wiederverwenden**, nicht duplizieren

### 12.4 Priorisierung (Empfehlung)

| Priorität | Thema | Begründung |
|---|---|---|
| 1 | Offene technische Findings aus `AUDIT_REPORT.md` schließen, insb. `.env`-Schreibzugriff (M6) und fehlende Autorisierungsprüfungen (M1) | Bevor produktive Postfach-Zugangsdaten im System gespeichert werden, muss die bestehende Angriffsfläche geschlossen sein |
| 2 | `Partner`, `ExternalReference`, `Commission` als Datenmodell + einfache Admin-CRUD-Oberfläche | Schafft die Grundlage, auf die E-Mail- und Fonds-Finanz-Automatisierung aufsetzen |
| 3 | E-Mail-Integration Stufe 1: ein Postfach (info@) per IMAP, Rohspeicherung + manuelle Zuordnung über UI | Ersetzt den heutigen Handeintrag durch echten Import, ohne sofort auf volle Automatisierung angewiesen zu sein |
| 4 | Kunden-Matching-Service + automatische/vorgeschlagene Zuordnung | Reduziert manuellen Aufwand, baut auf Stufe 3 auf |
| 5 | Kategorisierung + automatische Aktionen (Ticket/Task/Commission-Anstoß) | Voller "intelligenter" Workflow laut Abschnitt 4 |
| 6 | Fonds-Finanz-Workflow, Versicherungs-Workflow, Provisions-PDF-Parsing | Fachspezifische Ausbaustufen, je nach Geschäftspriorität austauschbar in der Reihenfolge |
| 7 | Weitere Postfächer (kv@, Gmail/M365 OAuth) | Sobald Stufe 3–5 für ein Postfach stabil laufen, horizontale Erweiterung auf weitere Konten/Anbieter |

### 12.5 Technische Risiken

- **Falsch-positive Kundenzuordnung** bei automatischem Matching > 90 % – Gegenmaßnahme: jede automatische Zuordnung bleibt für Mitarbeiter sichtbar/rückgängig machbar, vollständiges Audit-Log.
- **OAuth-Token-Verwaltung** (Ablauf, Widerruf durch Google/Microsoft-Admin) – braucht Monitoring/Alarmierung bei fehlgeschlagenem Refresh, sonst reißt der Mail-Import unbemerkt ab.
- **PDF-Format-Änderungen** bei Fonds Finanz/Partnern brechen Parser – Parser müssen defensiv scheitern (→ manuelle Erfassung statt falscher Daten), nie stillschweigend falsche Beträge in Lexoffice buchen.
- **DSGVO/Aufbewahrung** unzugeordneter Mails – ohne Löschkonzept wächst ein unkontrolliertes Datenarchiv.
- **Bestehende Controller-Größe** (`AdminController`, 723 Zeilen) – Risiko, dass neue Funktionen dort "angeflanscht" werden und die Wartbarkeit weiter sinkt; deshalb explizit neue, eigenständige Controller für Partner/Commission/E-Mail vorsehen.
- **MySQL/SQLite-Kompatibilität**: das Projekt läuft aktuell auf SQLite (Standard) und muss produktiv ggf. auf MySQL – neue Migrationen müssen datenbankneutral geschrieben werden (der Audit-Report dokumentiert bereits einen früheren MySQL-only-Fehler in einer Migration).

### 12.6 Nächste konkrete Schritte (sobald Freigabe zur Umsetzung erfolgt)

1. Technische Findings aus dem bestehenden Audit-Report abarbeiten (falls noch nicht geschehen – bitte gegenprüfen, ob `system-audit-fixes` bereits gemerged ist).
2. Migrationen + Models für `Partner`, `Commission`, `ExternalReference` anlegen (additiv, kein Breaking Change).
3. Admin-CRUD für Partnerverwaltung (kleinster eigenständiger Baustein, ohne Abhängigkeit von E-Mail-Integration).
4. IMAP-Anbindung für **ein** Postfach als Pilot (info@), inkl. Admin-UI zum Verwalten von Postfächern.
5. Matching-Service + Zuordnungs-UI (Bestätigungsliste für 70–90 %).
6. Kategorisierung + automatische Aktionen.
7. Fonds-Finanz- und Provisions-Workflows.
8. Erweiterung um weitere Postfächer/Anbieter (Gmail/M365 OAuth).

---

**Zusammenfassung:** Das bestehende System ist in den vorhandenen Bereichen (Kunden, Verträge, Self-Service, interne Kommunikation) bereits sauber strukturiert und frei von den in der Aufgabe befürchteten Dopplungen. Die größte Lücke ist die komplett fehlende E-Mail-Eingangs- und Partner-/Provisionsverwaltung – hierfür wurde eine additive, polymorphe Architektur vorgeschlagen, die ohne Umbau der bestehenden Kern-Tabellen auskommt. Es wurde noch keine Implementierung begonnen.
