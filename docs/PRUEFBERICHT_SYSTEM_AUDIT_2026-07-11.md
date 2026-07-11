# Dienstly24 – Vollständiger System-Prüfbericht

**Datum:** 2026-07-11 · **Branch:** `claude/dienstly-system-audit-akcmkd` · **Stand:** nach Umsetzung der Prioritäten 1–9
**Methodik:** statische Code-Durchsicht aller Module, Migrationen, Routen und Views; Testlauf (168/168 grün); Abgleich mit dem Architekturplan (`SYSTEM_ANALYSE_ERWEITERUNGSPLAN.md`) und dem Alt-Audit (`AUDIT_REPORT.md`).

**Es wurden für diesen Bericht keinerlei Code-Änderungen, Korrekturen oder Refactorings durchgeführt.** Alle Befunde sind dokumentiert, nichts wurde "still" behoben – auch Schwächen in den neu gebauten Modulen dieser Session werden offen benannt.

---

## 1. Funktionaler System-Test

| Bereich | Status | Befund |
|---|---|---|
| **Kundenverwaltung** | ✅ funktionsfähig | Akte mit Verschlüsselung sensibler Felder, Merge, Timeline, Vollständigkeits-Widget, Portfolio-Scoping. Quelle (`source`) wird nur bei automatischer Anlage gesetzt, manuell angelegte Kunden bleiben NULL (dokumentiert, unkritisch). |
| **Vertragsverwaltung** | ✅ funktionsfähig, ⚠️ Modell-Enge | `contracts.type` ist ein Enum mit nur 5 Werten (kfz/krankenversicherung/internet/strom_gas/andere). Alle Versicherungssparten außerhalb davon (Haftpflicht, Hausrat, BU, Leben …) landen in „andere" – der Fonds-Finanz-Import bildet Sparten deshalb verlustbehaftet ab. |
| **Dokumentenverwaltung** | ✅ funktionsfähig | Privater Storage, Sichtbarkeit kunde/intern, autorisierte Downloads, Ersetzen mit Datei-Löschung. **Aber:** beim Löschen eines Kunden werden nur DB-Zeilen kaskadiert – die physischen Dateien bleiben auf der Festplatte (siehe 3.1/8). |
| **Aufgabenverwaltung** | ✅ funktionsfähig, ⚠️ Lücke | Workflows erzeugen Aufgaben korrekt. `Task` hat aber weiterhin **kein `contract_id`** (Architekturplan 20.2 Punkt 5 nicht umgesetzt) – Versicherungs-/FF-Aufgaben referenzieren den Vertrag nur als Text in der Beschreibung. |
| **Ticketsystem** | ✅ funktionsfähig, ⚠️ Lücke | E-Mail-Kundenanfragen mit unbestätigtem Match erzeugen Gast-Tickets. Wird die Zuordnung später im Posteingang bestätigt, wird das **bereits erzeugte Gast-Ticket nicht nachträglich mit dem Kunden verknüpft**. |
| **Kommunikation intern** | ✅ funktionsfähig | Saubere Trennung Chat/Notizen/Ticket bleibt erhalten; keine Dopplungen entstanden. |
| **Kundenportal** | ✅ funktionsfähig | Self-Service, Dokumentenanfragen mit Status + Upload, Dashboard-Hinweise. Familien-**Löschen** fehlt vollständig (Detail in Abschnitt 10). |
| **E-Mail-Integration** | ✅ IMAP produktiv, ❌ OAuth | IMAP/Hostinger voll funktionsfähig (Sync alle 2 Min., Rohspeicherung, Dedupe). **Gmail/M365 sind nur als Platzhalter angelegt** – Kontotyp wählbar, aber der OAuth-Flow existiert nicht (`OAuthMailboxProvider` wirft bewusst eine klare Fehlermeldung). Postfächer dieser Typen sind aktuell nicht nutzbar. |
| **Workflow-Engine** | ✅ funktionsfähig | Regelbasierte Kategorisierung + Aktionen, idempotent. Die im Plan (Abschnitt 12) beschriebene KI-Stufe ist bewusst noch nicht gebaut. |
| **Fonds-Finanz** | ✅ funktionsfähig, ⚠️ Umfang | Parser + Import mit HITL-Stufen und Konfliktbehandlung. Es wird **nur der Mail-Text** geparst – PDF-Anhänge werden nicht ausgewertet (Textextraktion fehlt). |
| **Provisionen** | ✅ funktionsfähig, ⚠️ Umfang | Partner-Erkennung, Erfassung, HITL-Buchung. Auch hier: **keine PDF-Analyse**, nur Mail-Text. Die Lexoffice-Kategorie-ID für „Einnahmen" ist hartkodiert. |
| **Lexoffice** | ⚠️ eingeschränkt verifiziert | Bestehende Funktionen unverändert; neues `createVoucher` ist nur gegen HTTP-Fakes getestet, nie gegen die echte API. Fehlerpfad ist sauber (Gutschrift bleibt offen). |
| **Partnerverwaltung** | ✅ funktionsfähig | CRUD, Domain-Erkennung, Historie, Summen. Kein Partner-Löschen vorgesehen (nur Deaktivieren) – vertretbare Designentscheidung wegen Provisionshistorie. |
| **Benutzerverwaltung** | ✅ funktionsfähig | Rollen, Portfolio, Vertretung – unverändert aus Bestand. |

---

## 2. Datenbank-Audit

**Positiv:**
- Alle neuen Tabellen sind additiv, UUID-konsistent, mit sinnvollen FK-Regeln (`cascadeOnDelete` für abhängige Daten, `nullOnDelete` für Referenzen).
- `external_references` (polymorph) verhindert wie geplant Spalten-Wildwuchs; Unique-Constraint gegen doppelte Kennungen.
- Duplikatsschutz auf Datenebene: `commissions(partner_id, credit_note_number)` unique, `email_messages(email_account_id, message_uid)` unique.
- Keine Tabellen-Duplikate; das im Alt-Audit bemängelte `family_members`-Duplikat ist entfernt.

**Befunde:**
- **Fehlende Indexe:** `email_messages` hat keinen Index auf `match_status` und `processed_at` – Posteingang, Nav-Badge und Prune-Command filtern genau darauf. Bei aktuellem Volumen unkritisch, bei tausenden Mails messbar. Gleiches gilt für `document_requests.status` (nur Kombi-Index mit customer_id vorhanden).
- **Timeline nicht generalisiert:** Die geplante zentrale `timeline_events`-Struktur (Plan Abschnitt 15) wurde nicht umgesetzt; `customer_timeline` bleibt kundenzentriert. Partner-/Vertragsereignisse haben keine gemeinsame Verlaufssicht – aktuell durch `ActivityLog` teilkompensiert.
- **Enum-Engen:** `contracts.type` (siehe 1.); `contracts.status` ohne „gekündigt zum"-Semantik.
- **Skalierbarkeit / Mandantenfähigkeit:** Das System ist strikt **single-tenant** – es gibt keinerlei `company_id`/Tenant-Konzept. Mehrere Unternehmen auf einer Instanz erfordern entweder Instanz-pro-Firma (realistisch, geringer Aufwand) oder einen tiefgreifenden Umbau aller Abfragen (hoher Aufwand). Für „viele Benutzer" einer Firma ist die Architektur ausreichend (Portfolio-Scoping, Indexe auf Kernpfaden).
- JSON-Spalten (`email_domains`, `folders`, `mentioned_users`) sind nicht indizierbar – bei den kleinen Datenmengen (Partner, Ordner) korrekt gewählt.

---

## 3. Sicherheitsprüfung

### 3.1 Datenschutz / DSGVO

| Punkt | Bewertung |
|---|---|
| KV-/RV-Nummer, Steuer-ID | ✅ verschlüsselt (encrypted-Cast), Änderungen auditiert |
| Postfach-Zugangsdaten | ✅ verschlüsselt (`encrypted:array`), `hidden`, nie in JSON/Arrays – testverifiziert |
| Unzugeordnete E-Mails | ✅ Löschkonzept aktiv (90 Tage, konfigurierbar, dry-run, Audit-Log) |
| Kundenlöschung – E-Mails | ✅ Volltexte werden mitgelöscht (Art. 17) |
| **Kundenlöschung – Dokumentdateien** | ❌ **DB-Zeilen kaskadieren, aber die physischen Dateien unter `customers/<id>/…` und `email_attachments/…` bleiben auf der Festplatte.** DSGVO-Lücke: personenbezogene Rohdaten überleben die Löschung. |
| Gesundheitsdaten in Mail-Texten | ⚠️ E-Mail-Volltexte können Gesundheitsdaten enthalten und liegen unverschlüsselt in der DB (wie alle Bestandsdaten). DB-at-rest-Verschlüsselung ist Hosting-Thema, sollte aber im Verarbeitungsverzeichnis stehen. |

### 3.2 Benutzerrechte

- ✅ Posteingang auf admin/manager/support beschränkt; manuelle Zuweisung prüft `canAccessCustomer`; Dokumentenanfragen durchgängig portfolio-geprüft; Fremdvertrags-Referenzen werden abgewiesen (422).
- ❌ **`EmailInboxController::confirm()`/`reject()` prüfen den Kundenzugriff NICHT** – ein Support-Mitarbeiter mit eingeschränktem Portfolio kann Zuordnungsvorschläge für fremde Kunden bestätigen/ablehnen. Die `assign()`-Methode hat den Check, confirm/reject nicht. *(Befund im eigenen Code dieser Session.)*
- ⚠️ Die Posteingang-**Liste** filtert nicht nach Portfolio – ein eingeschränkter Support-Nutzer sieht Betreff/Absender/Kundenvorschlag aller wartenden Mails. Vertretbar für eine zentrale Triage-Rolle, sollte aber bewusste Entscheidung sein, nicht Zufall.
- ✅ Kundenrollen kommen an keine internen Daten (getestet: fremde Dokumentenanfragen 404, interne Dokumente unsichtbar, InternalMessages ohne Portal-Routen).

### 3.3 E-Mail-Sicherheit

- ✅ TLS-Zertifikatsprüfung aktiv (`validate_cert: true`), Verschlüsselung ssl/tls default, Verbindungstest vor Speicherung.
- ✅ Sync-Fehler landen in `last_error` je Konto (sichtbar für Admin), kein stiller Abriss.
- ⚠️ Sync-Fenster arbeitet mit `since(last_synced_at − 1h)` statt UID-Tracking – funktional korrekt (Dedupe via Unique-Constraint), erzeugt aber Dauer-Überlappung und re-lädt bei jedem Lauf bereits bekannte Mails (Traffic/Latenz, siehe 5.).

### 3.4 Dokument-Sicherheit

- ✅ Upload-Validierung (Mime-Whitelist, 10 MB), privater Storage, autorisierte Downloads (Portal: nur eigene + kundensichtbare; Admin: Portfolio).
- ❌ **Anhänge werden bereits bei Status „suggested" (unbestätigter Kunden-Vorschlag, 70–90 %) als Dokumente am vorgeschlagenen Kunden gespeichert.** `MailboxSyncService::storeAttachmentsIfMatched` prüft nur `customer_id` – der ist bei Vorschlägen bereits gesetzt. Lehnt ein Mitarbeiter den Vorschlag später ab, bleiben die Dokumente in der **falschen Kundenakte** (visibility=internal, der Kunde sieht sie nicht – aber Datenintegrität/DSGVO-Zuordnung verletzt). *(Befund im eigenen Code dieser Session.)*

### 3.5 KI-Sicherheit

- Aktuell ist **keine KI produktiv** – alle Parser sind deterministisch und behandeln E-Mail-Inhalte ausschließlich als Datenquelle (Label-Extraktion), nie als Anweisung. Prompt-Injection-Angriffsfläche: derzeit keine.
- Das HITL-Fundament (Bestätigungsstufen, Audit-Log, keine Auto-Buchungen) ist genau so gebaut, wie es der Architekturplan für die spätere KI-Stufe verlangt – die Freigabe-Grenzen existieren bereits, bevor ein Modell angebunden wird. Das ist die richtige Reihenfolge.
- Offen bleibt (erst bei KI-Anbindung relevant): `ai_decisions`-Protokolltabelle, Konfidenz-Logging, AVV für den Modellanbieter.

### 3.6 Alt-Befunde (aus `AUDIT_REPORT.md`, weiterhin offen)

- Willkommens-Mails enthalten **Klartext-Passwörter** (Kunde + Mitarbeiter) – Passwort-Set-Link empfohlen.
- Lexoffice-API-Key wird im Settings-Formular im Klartext angezeigt.
- Kampagnen-/Ticket-Mails werden synchron im Request versendet (Queue vorhanden, aber ungenutzt) – gilt auch für die neue `DocumentRequestMail`.

---

## 4. Code-Qualität

**Positiv:**
- Neue Module durchgängig als kleine, einzeln getestete Services (Matching, Parser, Workflows) mit Constructor-Injection – klare Verantwortlichkeiten, gute Testbarkeit (60 neue Tests in dieser Ausbaustufe, Suite 168/168).
- Keine parallele Logik entstanden: ein Nummerngenerator, eine Matching-Engine, ein Freigabemuster; `SystemUserResolver` wurde bei der zweiten Verwendung sofort extrahiert.
- Neue Controller statt Anbau an den `AdminController`; `route:cache` bleibt möglich.

**Befunde:**
- `AdminController` bleibt mit ~740 Zeilen ein Altlast-Monolith (unverändert gelassen, wie beauftragt – kein Refactoring ohne Auftrag).
- Die Label-Parser von Fonds-Finanz und Provisionen teilen sich das Zeilen-Parsing-Muster als Kopie – tolerierbar (unterschiedliche Domänen), bei einem dritten Parser sollte eine gemeinsame Basisklasse entstehen.
- Blade-Views arbeiten fast vollständig mit Inline-Styles (Bestandskonvention, bewusst fortgeführt) – funktional, aber langfristig wartungsintensiv.
- Nav-Badges und Dashboard-Karten führen Queries direkt in Blade-Templates aus (`@php`-Blöcke) – Bestandsmuster, verteilt aber Datenzugriff in die View-Schicht.
- `CommissionController::book` hartkodiert die Lexoffice-Kategorie-GUID – bricht leise, falls Lexoffice die Standardkategorie ändert.

---

## 5. Performance-Analyse

| Punkt | Bewertung |
|---|---|
| Seitenlast Admin | Jeder Seitenaufruf führt 4–6 Badge-/Count-Queries aus (Provisionen, Dokumentenanfragen, E-Mail-Vorschläge, Announcements, Notifications). Bei aktueller Größe unkritisch; ab vielen gleichzeitigen Nutzern: kurzer Cache (30–60 s) empfohlen. |
| E-Mail-Sync | Überlappendes Zeitfenster statt UID-Cursor → jede Synchronisation lädt bekannte Mails erneut vom Server (Dedupe erst in der App). Bei großen Postfächern: Umstellung auf UID-Tracking. `limit(50)`/Ordner verhindert Ausreißer. |
| Mail-Versand | Synchron im HTTP-Request (Dokumentenanfrage, Ticket-Antwort, Kampagnen). Ein hängender SMTP-Server blockiert den Mitarbeiter-Request. Queue ist konfiguriert (`database`), wird aber nicht genutzt. |
| Matching | Kandidaten-Pool begrenzt (limit 50) mit indizierbaren Vorfiltern – skaliert ordentlich. Fuzzy-Score läuft nur über den Pool. |
| Dokumente | Streaming-Downloads über Storage – ok. Keine Größenprobleme erkennbar. |
| **Mehrere Unternehmen / viele Benutzer** | Viele Benutzer **einer** Firma: ja, mit den genannten kleinen Maßnahmen (Indexe, Badge-Cache, Queue). Mehrere **Unternehmen**: nein – kein Mandanten-Konzept (siehe 2.); realistischer Weg ist eine Instanz pro Unternehmen. |

---

## 6. UX/UI-Prüfung

### Mitarbeiter
- ✅ Posteingang bündelt alle HITL-Entscheidungen, Ein-Klick-Bestätigung ohne Seitenwechsel, Kunden-Autocomplete, Dashboard-Karte „Wartet auf Ihre Entscheidung", Nav-Badges. Klick-Wege der neuen Kernprozesse: 1–2 Klicks.
- ⚠️ Aufgaben aus Workflows verlinken Kunde, aber nicht Vertrag/E-Mail (kein `contract_id`, kein Link zur Quell-Mail in der Task-Ansicht) – Mitarbeiter müssen Kontext teils suchen.
- ⚠️ Provisions-Buchung: Ablehnen-Button ohne Begründungsfeld (Commission hat kein `rejection_note`), Nachvollziehbarkeit nur über Audit-Log.

### Kundenportal
- ✅ Statuskarten mit Frist/Farblogik, Upload direkt an der Anfrage, Dashboard-Hinweis, Wiederhochladen nach Zurückweisung mit Begründung. Mobile: flex-wrap-Layouts, keine festen Breiten in den neuen Komponenten.
- ⚠️ Familienverwaltung: Details in Abschnitt 10 – die Bearbeiten-Funktion existiert, ist aber missverständlich; Löschen fehlt.
- ⚠️ Mehrsprachigkeit: Bestands-Mails können Arabisch (`preferred_lang`), die neue `DocumentRequestMail` und alle neuen Portal-Texte sind rein Deutsch.

---

## 7. Automatisierungsprüfung – nächste Kandidaten

1. **Fristen-Watchdog Dokumentenanfragen:** `deadline` wird gespeichert und angezeigt, aber **kein Job erinnert** Kunde oder Mitarbeiter bei Ablauf (Plan Abschnitt 14 sieht Erinnerungs-Mail vor). Geringer Aufwand, hoher Nutzen.
2. **PDF-Textextraktion** für Fonds-Finanz-/Provisions-Anhänge (aktuell nur Mail-Text) – danach greifen die bestehenden Parser unverändert.
3. **OAuth-Flows Gmail/M365** – einzige fehlende Säule der E-Mail-Plattform.
4. **Gast-Ticket-Nachverknüpfung** bei bestätigter E-Mail-Zuordnung.
5. **Queue-Versand** aller Mails (Infrastruktur vorhanden).
6. **KI-Interpretationsstufe** (Plan 12) auf dem fertigen HITL-Fundament + `ai_decisions`-Protokoll.
7. Lexoffice-Kontakt-Abgleich als geplanter Job statt manuellem Import.

---

## 8. Fehler- und Risikoliste (priorisiert)

### 🔴 Kritisch
*Keine Befunde mit akutem Datenverlust-, Ausfall- oder Fremdzugriffsrisiko.*

### 🟠 Hoch
| # | Befund | Bereich |
|---|---|---|
| H1 | E-Mail-Anhänge werden bei unbestätigtem Matching (suggested) am möglicherweise falschen Kunden gespeichert und bei Ablehnung nicht entfernt | Datenintegrität/DSGVO |
| H2 | `EmailInboxController::confirm/reject` ohne `canAccessCustomer`-Prüfung (assign hat sie) | Zugriffskontrolle |
| H3 | Kundenlöschung entfernt physische Dokument-/Anhang-Dateien nicht von der Festplatte | DSGVO Art. 17 |
| H4 | Klartext-Passwörter in Willkommens-Mails (Alt-Befund, weiterhin offen) | Sicherheit |

### 🟡 Mittel
| # | Befund | Bereich |
|---|---|---|
| M1 | Gmail/M365 nur Platzhalter – als Anbieter wählbar, aber nicht funktionsfähig | Funktion |
| M2 | Synchroner Mail-Versand im Request (inkl. neuer DocumentRequestMail) | Performance/Robustheit |
| M3 | Kein Erinnerungs-Job für Dokumentenanfrage-Fristen | Funktionslücke |
| M4 | Gast-Tickets werden nach Zuordnungs-Bestätigung nicht nachverknüpft | Funktionslücke |
| M5 | `Task` ohne `contract_id` – Workflow-Aufgaben ohne Vertragsbezug | Datenmodell |
| M6 | Posteingang-Liste ohne Portfolio-Filter für eingeschränkte Support-Nutzer | Zugriff (bewusst entscheiden) |
| M7 | Fehlende Indexe auf `email_messages.match_status/processed_at` | Performance (ab Volumen) |
| M8 | `contracts.type`-Enum zu eng für reale Versicherungssparten | Datenmodell |
| M9 | Familien-Löschen im Portal fehlt vollständig (Abschnitt 10) | UX/Funktion |
| M10 | Lexoffice-Kategorie-GUID hartkodiert; createVoucher nie gegen echte API verifiziert | Integration |

### 🟢 Niedrig
| # | Befund |
|---|---|
| N1 | Announcements ohne Bearbeiten/Deaktivieren; kein kundengerichtetes Banner-Modul (Abschnitt 10) |
| N2 | Badge-Count-Queries je Seitenaufruf ohne Cache |
| N3 | IMAP-Sync mit Überlappungsfenster statt UID-Cursor |
| N4 | Provisions-Ablehnung ohne Begründungsfeld |
| N5 | Neue Portal-/Mail-Texte nicht mehrsprachig (Bestand teils ar/de) |
| N6 | Lexoffice-Key im Settings-Formular sichtbar (Alt-Befund) |
| N7 | `relation`-Werte in `customer_family` inkonsistent (Portal: `kind`, Alt-/Admin-Daten teils `Kind`) – Icon/Label-Mapping und der 15-Jahre-Reminder-Job matchen nur exakt |

---

## 9. Abschlussbericht

**Aktueller Systemstatus:** Das System ist funktional breit ausgebaut und in gutem Zustand. Alle 9 beauftragten Prioritäten sind umgesetzt, die Testsuite (168 Tests, 518 Assertions) ist vollständig grün, Migrationen laufen sauber auf SQLite/MySQL-kompatiblem Schema, `route:cache` funktioniert.

**Was funktioniert:** Kern-CRM (Kunden/Verträge/Dokumente/Tickets/Aufgaben), Self-Service mit Genehmigungsworkflow, IMAP-E-Mail-Plattform mit regelbasierter Verarbeitung, Score-Matching mit HITL-Stufen, automatische Kundenanlage mit Duplikatsschutz, Fonds-Finanz- und Provisions-Workflows mit menschlicher Freigabe, Dokumentenanfragen Ende-zu-Ende, zentraler Posteingang, DSGVO-Löschkonzept für E-Mails.

**Was fehlt:** OAuth (Gmail/M365), PDF-Textextraktion, Fristen-Erinnerungen, Task↔Vertrag-Verknüpfung, zentrale Timeline, KI-Stufe, Mandantenfähigkeit, Familien-Löschen im Portal, Banner-/Announcement-Pflege.

**Sicherheitsbewertung:** Solide Grundlage (Verschlüsselung, Rollen, private Storage, Audit-Trail, HITL). Vier Hoch-Befunde (H1–H4) sind klar umrissen und mit geringem Aufwand behebbar; keiner ist von außen ohne Konto ausnutzbar.

**Technische Risiken:** Kein akutes Risiko. Größte strukturelle Grenze ist die fehlende Mandantenfähigkeit; größtes Integrationsrisiko die ungetestete echte Lexoffice-Belegbuchung.

**Performance:** Für den aktuellen und mittleren Maßstab gut. Drei gezielte Maßnahmen (Indexe, Queue-Versand, Badge-Cache) tragen deutlich weiter.

**UX:** Mitarbeiter-Workflows sind klick-effizient; Portal ist verständlich und mobiltauglich. Konkrete Schwächen sind benannt (Familienverwaltung, fehlende Vertrags-Links in Aufgaben).

**Empfohlene nächste Schritte (Reihenfolge):**
1. Hoch-Befunde H1–H3 beheben (Anhang-Speicherung erst nach Bestätigung; Zugriffscheck in confirm/reject; Datei-Löschung bei Kundenlöschung) – zusammen wenige Stunden Aufwand.
2. M3 + M4 (Fristen-Reminder, Ticket-Nachverknüpfung) – kleine, hochwirksame Lückenschlüsse.
3. Familien-Löschen als Change-Request-Typ + Announcement-Bearbeiten (Abschnitt 10).
4. Queue-Versand aktivieren, Indexe ergänzen.
5. OAuth-Flows, dann PDF-Extraktion, dann KI-Stufe gemäß Plan.

---

## 10. Zusätzliche UX/UI-Prüfung

### 10.1 Familienmitglieder im Kundenportal

**Problem (gemeldet):** Kunde kann Familienmitglieder nicht bearbeiten und nicht löschen.

**Ursachenanalyse (Code-Befund):**

*Bearbeiten:* Die Funktion **existiert technisch vollständig** – Button „✏️ Änderung beantragen" auf jeder Mitgliedskarte, Modal, Route `portal/family/{id}/change`, `ChangeRequestService::applyFamily` wendet genehmigte Änderungen an. Warum es als „nicht möglich" wahrgenommen wird:
1. **Änderungen wirken nicht sofort** – sie erzeugen nur einen Change Request; die Karte zeigt bis zur Mitarbeiter-Freigabe unverändert die alten Daten (nur ein Badge „Prüfung ausstehend"). Ohne Erklärung wirkt das wie „funktioniert nicht".
2. **Noch nicht genehmigte neue Mitglieder** (beantragt, pending) haben **keinen** Bearbeiten-Button – wer direkt nach dem Anlegen korrigieren will, kann es nicht.
3. **Datenaltbestand:** Admin-seitig/historisch gespeicherte `relation`-Werte (z. B. „Kind" großgeschrieben) passen nicht zum Portal-Enum (`ehepartner|kind|andere`) – Icon/Label fallen auf Generisch zurück und die Vorauswahl im Änderungs-Modal greift nicht.
4. Das Feld `geschlecht` existiert am Modell, ist aber weder im Portal-Formular noch in der `applyFamily`-Whitelist – Kunden können es nie pflegen.

*Löschen:* **Fehlt vollständig und Ende-zu-Ende** – keine Portal-Route, kein Change-Request-Typ, `applyFamily` kennt nur Anlegen/Ändern. Es ist **keine bewusste Berechtigungslogik**, sondern eine nicht gebaute Funktion (Admin-seitig existiert `destroyFamily` mit sofortiger Löschung).

**Bewertung – empfohlenes Rechtemodell (konsistent zum bestehenden HITL-Prinzip):**
| Aktion | Kunde | Freigabe nötig? |
|---|---|---|
| Hinzufügen | beantragen | ✅ Mitarbeiter (bereits so umgesetzt) |
| Bearbeiten | beantragen | ✅ Mitarbeiter (bereits so umgesetzt) |
| Entfernen | beantragen | ✅ Mitarbeiter – Löschung ist irreversibel und kann versicherungsrelevant sein (mitversicherte Personen); niemals Sofort-Löschung durch den Kunden |

**Empfohlene Lösung (kein Parallelsystem, ~kleiner Eingriff):**
1. Neuen Change-Request-Typ `family_delete` im **bestehenden** `CustomerChangeRequest`-System ergänzen (Konstante, `applyFamily`-Erweiterung um Delete-Zweig, Portal-Button „Entfernen beantragen" mit Bestätigungsdialog).
2. Bearbeiten-Button auch für pending-Mitglieder bzw. „Antrag zurückziehen" anbieten.
3. Einmalige Daten-Normalisierung der `relation`-Werte (Migration: `Kind`→`kind` usw.) + `geschlecht` in Formular und Whitelist aufnehmen.
4. Hinweistext an den Karten: „Änderungen werden nach Prüfung durch unser Team sichtbar."
5. Änderungsprotokoll: läuft automatisch über den bestehenden `ChangeRequestService` (Audit-Log + Benachrichtigung) – keine neue Infrastruktur nötig.

**Priorität:** Löschen-Funktion **Mittel** (M9); Wahrnehmungs-/Datenprobleme (Hinweistext, relation-Normalisierung) **Niedrig–Mittel**.

### 10.2 Banner-/Werbeanzeigen-Verwaltung

**Problem (gemeldet):** Banner können nur gelöscht/deaktiviert werden, Bearbeiten fehlt.

**Ursachenanalyse (Code-Befund):** Ein kundengerichtetes **Banner-/Werbeanzeigen-Modul existiert im Portal-System nicht**. Das einzige verwandte Modul ist **„Ankündigungen" (`announcements`)** – interne Team-Mitteilungen im Admin-Bereich mit den Feldern `title`, `body`, `priority`, `expires_at`. Der Ist-Zustand ist sogar schmaler als gemeldet:
- Aktionen: nur **Erstellen** und **Löschen** (`TarifrechnerController::storeAnnouncement/destroyAnnouncement`).
- **Kein Bearbeiten**, **kein explizites Aktivieren/Deaktivieren** (nur indirekt über das Ablaufdatum), **kein Bild, kein Link, keine Zielgruppe, kein Zeitraum-Beginn** – die Datenstruktur kennt diese Felder nicht.

*Hinweis:* Sollten mit „Banner" Werbeflächen auf der WordPress-Website gemeint sein, liegen diese außerhalb dieses Systems.

**Notwendige Anpassungen (falls das Ankündigungs-Modul zur Banner-Verwaltung ausgebaut werden soll):**
1. **Datenstruktur:** Migration ergänzt `image_path` (privater/öffentlicher Storage), `link_url`, `starts_at`, `is_active` (bool), `audience` (z. B. `staff|customers|all`); `expires_at` bleibt als Ende-Datum.
2. **Backend:** `update`-Route + Methode (bislang nicht vorhanden), Toggle-Route für aktiv/inaktiv, Bild-Upload mit derselben Validierung wie Dokumente.
3. **UI:** Bearbeiten-Modal analog zum neuen Partner-Modal (Muster existiert bereits), Vorschau, Statusbadge aktiv/geplant/abgelaufen.
4. **Anzeige-Logik:** Wenn `audience=customers`, Ausspielung im Portal-Dashboard (derzeit sehen Kunden Ankündigungen gar nicht).
5. **Berechtigungen:** Pflege wie bisher für alle Staff-Rollen oder bewusst auf admin/manager einschränken (Empfehlung: admin/manager, da künftig kundensichtbar).

**Priorität:** **Niedrig** (N1) – Komfort-/Ausbaufunktion ohne Sicherheits- oder Datenrisiko; Aufwand überschaubar (1 Migration, 1 Controller-Erweiterung, 1 Modal).

---

*Ende des Prüfberichts. Es wurden keine Änderungen am Code vorgenommen.*
