# KI-Kundenassistent — Ist-Architektur und Integrationsplan

Stand: 17.08.2026 · Auftrag des Betreibers (Spezifikation "KI-Kundenassistent
fuer Dienstly24 Kundenportal", Abschnitte 1-35).

Dieses Dokument ist die Vorarbeit, die die Spezifikation ausdruecklich
verlangt: **zuerst die vorhandene Architektur dokumentieren, dann den
Integrationsweg festlegen** — damit Kundenkommunikation, Tickets, Vorgaenge
und Dokumentenverwaltung nicht beschaedigt oder unnoetig veraendert werden.

---

## 1. Ist-Architektur (was schon existiert)

Der Portal-Kern ist bereits vollstaendig vorhanden. Der Assistent wird
**angebaut**, nicht eingebaut: er nutzt ausschliesslich bestehende Modelle
und Dienste.

### 1.1 Kundenkommunikation (Chat)

| Baustein | Datei | Rolle |
|---|---|---|
| `CustomerMessage` | `app/Models/CustomerMessage.php` | Eine Chat-Nachricht. `from_staff` trennt Team von Kunde, `read_at` = Lesezeitpunkt der Gegenseite, `toChatPayload()` ist die EINE Darstellungsquelle fuer Portal-Seite, Portal-Widget und Beraterwelt. |
| `PortalMessageController` | `app/Http/Controllers/PortalMessageController.php` | Kundenseite: `index`, `feed` (JSON-Polling), `store`. Hart auf den eigenen Kundendatensatz gescoped (`getCustomer()` ueber `auth()->id()`). |
| `AdminCustomerChatController` | `app/Http/Controllers/AdminCustomerChatController.php` | Beraterwelt: alle Unterhaltungen, Portfolio-Scope ueber `getAccessibleCustomers()`. |
| `CustomerConversationService` | `app/Services/CustomerConversationService.php` | Omnichannel-Timeline (Chat + Tickets + E-Mail + Dokumente + Notizen) ohne eigene Datenhaltung. |
| `CustomerMessageNotifier` | `app/Services/CustomerMessageNotifier.php` | Glocke + optionale E-Mail in beide Richtungen. |

**Wichtig:** Das Portal pollt (`portal.messages.feed`, throttle 120/min).
Eine asynchron erzeugte KI-Antwort erscheint dadurch von selbst — es braucht
kein Websocket und keine Aenderung am Frontend-Transport.

### 1.2 Vorgaenge und Tickets

Im Dienstly24-Sprachgebrauch ist ein **Vorgang == Ticket**
(`app/Models/Ticket.php`): fortlaufende Nummer `T-JJ#####`, `type` aus
`Ticket::TYPES` (`damage`, `change`, `offer`, `data_update`, `cancellation`,
`complaint`, `other`), `status` aus `Ticket::STATUSES`
(`open`, `in_progress`, `waiting`, `resolved`, `closed`), Prioritaet mit
SLA-Faelligkeit, `TicketMessage` (auch intern), `TicketEvent` (Chronik),
`TicketNotifier` (Glocke an Betreuer + admin/manager).

Daraus folgt fuer die Tool-Namen der Spezifikation (Abschnitt 6):

| Spezifikation | Umsetzung in Dienstly24 | Begruendung |
|---|---|---|
| `getOpenTickets()` / `getOpenProcesses()` | **ein** Tool `getOpenTickets` | Vorgang und Ticket sind dasselbe Objekt. Zwei Tools mit identischem Ergebnis wuerden das Modell nur zu Doppelaufrufen verleiten. |
| `createTicket()` / `createProcess()` | **ein** Tool `createTicket` | dito. |
| `notifyEmployee()` / `escalateToTeam()` | **ein** Tool `escalateToTeam` | Die Uebergabe erzeugt immer beides: Glocke an den Mitarbeiter UND Uebergabe-Status. Getrennte Tools koennten eine Uebergabe ohne Benachrichtigung erzeugen. |
| `sendPortalMessage()` | **kein Tool** — die Antwort des Assistenten IST die Portal-Nachricht | Ein Sende-Tool erlaubte dem Modell, beliebig viele Nachrichten zu schreiben. Genau EINE Antwort je Kundennachricht ist die Kostengrenze und der Duplikat-Schutz. |
| `getDocumentStatus()` / `getRequiredDocuments()` / `getMissingDocuments()` | drei Tools, gemeinsame Datenquelle | Getrennte Fragen des Kunden, getrennte Antworten. |

### 1.3 Dokumentenanforderung und Dokumenteneingang

- `DocumentRequest` (`app/Models/DocumentRequest.php`): Status
  `open -> uploaded -> approved | rejected`, `acceptsUpload()` bestimmt, ob
  der Kunde (noch) hochladen darf; `openForCustomer()` = offen oder
  zurueckgewiesen. Genau das Modell fuer "fehlende Dokumente".
- `DocumentRequestController`: Mitarbeiter-Seite (anlegen, freigeben,
  zurueckweisen) inkl. `DocumentRequestMail` und `ActivityLog`.
- `PortalController::documentRequestUpload`: der Kunde laedt hoch, das
  Dokument wird der Anfrage zugeordnet, Status wird `uploaded`.
- Dokumenteneingang: `Document` + `AnalyzeDocumentJob` + `DocumentAnalyzer`
  ("kostenlos zuerst": PDF-Textebene, dann OCR, dann KI) +
  `DocumentIntakeService` (Kunden-/Vertragszuordnung, Version History).

**Der Assistent baut hier nichts neu.** Er liest diese Stati und legt
Anforderungen ueber dieselbe Tabelle an, die die Mitarbeiter nutzen — der
Upload-Bereich im Portal erscheint dadurch automatisch.

### 1.4 KI-Schicht (bereits provider-unabhaengig)

- `AiProviderInterface` (`complete(AiRequest): AiResponse`) —
  Text/Vision, Auswahl per `AI_TEXT_PROVIDER`. Implementierung:
  `ClaudeTextProvider`.
- `DocumentAiProviderInterface` — Dokumentanalyse, Auswahl per
  `AI_DOCUMENT_PROVIDER`.
- `AiDecision` / `AiActionLog` — Protokoll bestehender KI-Entscheidungen
  (`output`/`detail` als `encrypted:array`).

Die vorhandene `AiProviderInterface` kennt **kein Tool-Calling** (sie ist
fuer einmalige Extraktions-/Klassifikations-Aufrufe gebaut). Der Assistent
braucht einen mehrschrittigen Dialog mit Funktionsaufrufen. Deshalb:
**neue, eigene Schnittstelle** statt Umbau der bestehenden — bestehende
Nutzer (`EmailClassificationService`, Workflow-Engine) bleiben unberuehrt.

### 1.5 Querschnitt

- Benachrichtigungen: `Notify::push/pushMany` (`NotificationService`) mit
  `dedup_key` gegen Glocken-Flut.
- Einstellungen: `SystemSetting::get/set` (key/value).
- Audit: `ActivityLog` (global) + `AiActionLog` (feingranular).
- Rollen/Sichtbarkeit: `User::canAccessCustomer()`,
  `getAccessibleCustomers()` (Portfolio-Scope).

---

## 2. Integrationsplan

### 2.1 Leitentscheidungen

1. **Neuer, getrennter Dienst** unter `app/Services/Ai/Assistant/`. Die
   OpenAI-Anbindung steckt hinter einem eigenen Interface
   (`AssistantProviderInterface`), damit Modell/Anbieter spaeter
   austauschbar bleibt (Spezifikation 28).
2. **Keine neuen Kern-Datenmodelle.** Der Assistent schreibt in
   `customer_messages`, `tickets`, `document_requests` — dieselben
   Tabellen wie die Mitarbeiter. Neu sind nur *Steuer- und
   Protokolltabellen* (siehe 2.2) und **eine** additive Spalte
   `customer_messages.ai_generated` (Kennzeichnung "KI-Assistent" fuer
   Portal und Beraterwelt).
3. **Die Antwort ist genau eine Chat-Nachricht** je Kundennachricht.
4. **Kostenlos zuerst / deterministisch zuerst** (Hausregel dieses
   Projekts): Bereichspruefung und Injection-Erkennung laufen VOR dem
   ersten API-Aufruf. Eine Wetter-Frage kostet damit nichts.
5. **Alles Riskante bleibt Mitarbeiter-Sache.** Der Assistent hat kein
   Tool, das einen Vertrag aendert, kuendigt, Geld bewegt oder ein
   Dokument rechtlich abnimmt.

### 2.2 Neue Tabellen (Migrationen)

| Tabelle | Zweck (Spezifikation) |
|---|---|
| `ai_conversations` | Abschnitt 15/16: je Kunde `ai_active`, `handover_required`, `handover_reason`, `assigned_employee_id`, `last_ai_action`, `last_ai_response`, `auto_reply_count`, `summary` (verschluesselt). Unique auf `customer_id`. |
| `ai_assistant_logs` | Abschnitt 22: `customer_id`, `conversation_id`, `intent`, `tools`, `actions`, `outcome`, `handover`, `employee_id`, Modell, Tokens, Dauer, Fehler. `detail` verschluesselt, **kein Rohtext-Prompt**. |
| `ai_knowledge_entries` | Abschnitt 19: freigegebene Wissensbasis (Titel, Inhalt, Sprache, Stichwoerter, aktiv/inaktiv). |

### 2.3 Neue Bausteine

```
app/Services/Ai/Assistant/
  Contracts/AssistantProviderInterface.php   Tool-Calling-Vertrag
  OpenAiAssistantProvider.php                OpenAI Responses API
  CustomerAssistantService.php               Orchestrierung (der Kern)
  AssistantSettings.php                      Schalter aus SystemSetting
  AssistantScopeGuard.php                    Bereichspruefung + Injection
  AssistantPrompt.php                        System-Prompt (Regeln)
  KnowledgeBase.php                          Wissensbasis-Suche
  HandoverService.php                        Uebergabe + Glocke + Zusammenfassung
  Tools/AssistantToolRegistry.php            Whitelist + Ausfuehrung
  Tools/*.php                                die einzelnen Funktionen
app/Jobs/AnswerCustomerMessageJob.php        asynchrone Antwort
```

### 2.4 Ablauf einer Kundennachricht

```
Kunde schreibt im Portal (PortalMessageController::store, unveraendert im Verhalten)
   -> CustomerMessage (from_staff = false)                [wie bisher]
   -> Glocke an das Team                                  [wie bisher]
   -> NEU: AnswerCustomerMessageJob (nur wenn KI aktiv)
        1. Schalter pruefen (global AN? Auto-Antworten AN? ai_active? handover_required?)
        2. Limits pruefen (Antworten je Vorgang, Rate Limit je Kunde)
        3. Bereichspruefung + Injection-Erkennung  -> ggf. Ablehnung/Uebergabe OHNE API-Aufruf
        4. Kontext bauen (nur Daten DIESES Kunden)
        5. OpenAI-Dialog mit Tool-Calling (max. N Runden, harte Obergrenze)
        6. Antwort als CustomerMessage (from_staff = true, ai_generated = true)
        7. Protokoll in ai_assistant_logs + AiActionLog
   -> Portal-Feed zeigt die Antwort beim naechsten Polling
```

Fehlerfall (Abschnitt 31): jede Ausnahme fuehrt zur Fallback-Nachricht,
zur Uebergabe und zu einer Glocke "KI-Service nicht verfuegbar" —
der Kundenservice faellt nie aus.

### 2.5 Kundendaten-Isolation (Abschnitt 5)

- Die `customer_id` kommt **ausschliesslich** aus dem Job-Kontext, der sie
  aus der Nachricht (und damit aus der authentifizierten Session) erhaelt.
- **Kein Tool-Schema enthaelt eine Kunden-ID.** Das Modell kann sie
  technisch nicht setzen.
- Tools mit Bezugsobjekt (z. B. `getProcessStatus(ticket_number)`) pruefen
  die Zugehoerigkeit und liefern sonst "nicht gefunden" — nie Fremddaten.

### 2.6 Was der Assistent NICHT darf

Kein Tool fuer: Vertrag aendern/kuendigen/verlaengern, Bankdaten aendern,
Adresse aendern (das laeuft ueber `CustomerChangeRequest` mit Nachweis —
siehe `docs/NACHWEIS_KUNDENAENDERUNGEN.md`), Dokument freigeben/ablehnen,
Zahlung/Erstattung, Provision, Mail-Versand, Zugriff auf andere Kunden,
freie SQL-Abfragen.

### 2.7 Konfiguration

`config/services.php` → `openai` (`OPENAI_API_KEY`, `OPENAI_MODEL`, Limits)
und `ai_assistant_provider` (`AI_ASSISTANT_PROVIDER`, Default `openai`,
`none` schaltet ab). Betriebsschalter liegen als `SystemSetting` im
Admin-Bereich (Abschnitt 30), damit der Betreiber ohne Deploy eingreifen
kann.

**Der API-Key gehoert ausschliesslich in die Server-`.env`** (bzw. GitHub
Secret) — nie in Repo, HTML, JavaScript oder Logs.

### 2.8 Reihenfolge der Umsetzung

1. Migrationen + Modelle (nur neue Tabellen, eine additive Spalte).
2. `OpenAiAssistantProvider` + Konfiguration.
3. Tool-Registry mit Lese-Tools, dann Aktions-Tools.
4. Guardrails (Bereich, Injection, Limits, Duplikat-Schutz).
5. Orchestrierung + Job + Einhaengen in `PortalMessageController`.
6. UI: Portal-Kennzeichnung, Mitarbeiter-Panel, Admin-Einstellungen,
   Wissensbasis-Pflege.
7. Tests (Abschnitt 33, Faelle 1-17), danach volle Suite gruen.

---

## 3. Datenschutz (Abschnitt 21)

- **Datenminimierung:** an das Modell gehen nur die Felder, die die
  jeweilige Frage braucht — kein Dump der Kundenakte. Keine IBAN, keine
  vollstaendigen Ausweisdaten, keine Gesundheitsdaten im Prompt.
- **Kein Rohtext-Prompt im Log** (wie bei `AiDecision`): protokolliert
  werden Absicht, Tools, Aktionen, Ergebnis — Details verschluesselt.
- **Zweckbindung:** ausschliesslich Kundenservice.
- **Vor dem Livegang zu klaeren (Betreiber):** Auftragsverarbeitungs-
  vertrag/DPA mit OpenAI, Aufbewahrungsoptionen der API, Ergaenzung der
  Datenschutzerklaerung und des Verarbeitungsverzeichnisses, Hinweis im
  Portal, dass zunaechst ein automatisierter Assistent antwortet
  (Abschnitt 26 — technisch umgesetzt, rechtliche Freigabe offen).
