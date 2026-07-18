# Konzept: AI Workflow Engine (Insurance Assistant)

Status: Architektur-Blueprint (Betreiber-Vorgabe v2). Wird schrittweise
umgesetzt; dieses Dokument ist der verbindliche Bauplan.

## Leitprinzip

Das System wird NICHT als "CRM mit KI" gebaut, sondern als **Plattform mit
einer generischen Workflow-Engine im Kern**. Die KI ist nur das **Gehirn**,
das diese Engine steuert (Absicht erkennen, Felder extrahieren, Texte
entwerfen) - sie fuehrt selbst nichts aus. Jede echte Aenderung an Kundendaten
laeuft ueber die bestehende Freigabe-Logik (Mensch bestaetigt).

## Generische Hierarchie (nicht auf Krankenversicherung beschraenkt)

```
Branch (Sparte)      ->  Service (Workflow-Definition, versioniert)  ->  Steps
Krankenversicherung  ->  Adresse aendern / Neues Kind / Karte verloren -> [...]
KFZ                  ->  Fahrzeugwechsel                              ->  [...]
Rechtsschutz         ->  Schaden melden                              ->  [...]
```

Derselbe Engine-Kern bedient alle Sparten. Eine neue Dienstleistung = ein
neuer Datensatz in der Wissensdatenbank (Workflow-Definition), **kein neuer
Kern-Code**.

## Datenmodell (neue Tabellen)

| Tabelle | Zweck |
|---|---|
| `workflow_definitions` | Wissensdatenbank: pro (branch, service, **version**) die Schritt-Liste, benoetigte Dokumente, Extraktionsfelder, Prompt-Referenzen, Intent-Beispiele. `active` markiert die geltende Version. |
| `workflow_prompts` | Editierbare Prompt-Vorlagen je Definition + Typ (`system`, `intent`, `extraction`, `reply`, `validation`). Aus dem Admin pflegbar, **kein Deploy noetig**. |
| `workflow_runs` | Eine laufende Instanz je Ticket/Kunde: `definition_key`, `version`, `status`, `memory` (encrypted:array = AI-Gedaechtnis), Gesamt-`confidence`. |
| `workflow_step_runs` | Ein Schritt einer Instanz: `step_key`, `type`, `status`, `confidence`, `output` (encrypted:array), `decided_by`/`decided_at` (Human Override). |
| `ai_action_logs` | Chronik JEDER KI-/System-Entscheidung mit Zeitstempel, Akteur (`ai`/`staff`), Aktion, Detail, Konfidenz. |

### Wiederverwendung (bereits vorhanden - NICHT neu bauen)

| Saeule | Vorhandener Baustein |
|---|---|
| Kundennachricht / Ticket | `Ticket`, `TicketMessage`, `TicketEvent` |
| Dokument anfordern + Mahnung | `DocumentRequest` + `document-requests:remind` |
| OCR + Extraktion (frei zuerst) | `DocumentAnalyzer`, `HeuristicDocumentClassifier`, `ValidatesExtractedFields` |
| Kunde/Dokument zuordnen | `DocumentIntakeService`, `CustomerMatchingService` (Tiers auto/confirm/manual) |
| Datenaenderung mit Freigabe | `CustomerChangeRequest` + `ChangeRequestService::submit()/apply()` |
| Freigabe-Gateway (Approve/Reject) | `AiDecision` (suggested -> accepted/rejected) |
| Audit-Trail | `ActivityLog` |
| Familie / Kind | `CustomerFamily` |

## Die 10 Saeulen -> konkrete Umsetzung

1. **Generische Engine** — Hierarchie oben; Engine-Kern `WorkflowEngine`
   kennt nur Definitionen + Schritt-Typen, keine sparten-spezifische Logik.
2. **AI Intent Detection** — `IntentDetector` liest den Ticket-Text und
   mappt ihn (per LLM + Intent-Beispielen aus der Definition) auf einen
   `service_key`; startet den Run als Vorschlag (Human bestaetigt Start).
3. **Dynamic Questions** — Schritt-Typ `ask_customer`: die Engine postet eine
   Rueckfrage als `TicketMessage`, setzt den Run auf `waiting_customer`, und
   fuehrt nach der Kundenantwort fort (kein starres Formular).
4. **Confidence Score** — jeder `workflow_step_run` traegt `confidence`.
   Faellt sie unter die Schwelle (Default 90, pro Definition einstellbar),
   stoppt die Engine den Schritt auf `needs_review` (Mitarbeiter-Freigabe).
5. **Human Override** — je Schritt: **editieren** (`output` anpassen),
   **neu ausfuehren** (`rerun`), **ueberspringen** (`skip`), **manuell
   erledigen** (`complete`). Alles ueber `WorkflowController`, protokolliert.
6. **AI Memory** — `workflow_runs.memory` haelt: angefordert / erhalten /
   fehlt / letzte Nachricht / letztes Dokument / letzte Entscheidung. Die
   Engine liest sie vor jedem Schritt -> keine doppelten Anfragen.
7. **Prompt Templates** — in `workflow_prompts` (DB), nicht im Code. Ein
   `PromptRepository` liest sie (mit Code-Defaults als Seed); Pflege im Admin.
8. **Provider Independent** — `AiProviderInterface` (`complete(AiRequest):
   AiResponse`) abstrahiert das LLM. Claude-Adapter zuerst; OpenAI/Gemini/
   Azure spaeter ohne Engine-Aenderung (Auswahl per `AI_TEXT_PROVIDER`).
9. **Versioning** — `workflow_definitions` sind je `key` mehrfach vorhanden
   (v1, v2, ...); `active` = geltende Version. Laufende Runs behalten ihre
   `version` (reproduzierbar).
10. **AI Action Log** — `ai_action_logs`: jede Erkennung/Anfrage/Extraktion/
    Aenderung/Entwurf mit Zeitstempel und Konfidenz, im Ticket sichtbar.

## Schritt-Typen (generisch, sparten-unabhaengig)

`detect_intent`, `request_document`, `ask_customer`, `extract_data`,
`match_customer`, `compare_data`, `apply_change`, `draft_reply`,
`draft_authority_message` (Kasse/Versicherer), `generate_report`, `review`.
Jeder Typ ist ein Handler (`StepHandlerInterface`); neue Typen sind additiv.

## Provider-unabhaengige KI-Schicht

```
AiProviderInterface            (complete(AiRequest): AiResponse)
  ClaudeTextProvider           (Anthropic Messages API, Vision-faehig)
  [spaeter] OpenAiTextProvider, GeminiTextProvider, AzureOpenAiProvider

Hoehere Dienste (nutzen Provider + PromptRepository + strenge Validierung):
  IntentDetector      -> service_key + confidence
  FieldExtractor      -> validierte Felder (ueber ValidatesExtractedFields)
  ReplyGenerator      -> Kunden-/Behoerden-/Report-Entwuerfe
```

Die bestehende `DocumentAiProviderInterface` (Vision-Dokumentanalyse) bleibt
und wird spaeter auf diese generische Schicht aufgesetzt.

## Sicherheits-/DSGVO-Leitplanken (gelten unveraendert)

- KI schlaegt nur vor (`suggested`/`needs_review`); anwenden erst nach
  Mensch-Freigabe. Keine Auto-Aenderung sensibler Daten ohne Confidence-Gate.
- Kunden-PII in `memory`/`output` verschluesselt (`encrypted:array`).
- Prompt-Injection-Schutz: Kunden-/Dokumentinhalt immer als DATEN, nie als
  Anweisung (bestehendes Muster).
- Jede Aktion in `ai_action_logs` + `ActivityLog` (doppelte Spur).

## Phasenplan (jede Phase = 1 PR, testbar)

- **P1 (erledigt):** Blueprint (dieses Dokument) + `AiProviderInterface` +
  `ClaudeTextProvider` + Value Objects + Registrierung/Config + Tests.
  (Fundament "Provider Independent".)
- **P2 (erledigt):** Engine-Schema (`workflow_definitions`, `workflow_runs`,
  `workflow_step_runs`, `ai_action_logs`, `workflow_prompts`) + Modelle +
  `WorkflowEngine`-Kern (start/advance/Confidence-Gate/Human Override/cancel)
  + `StepHandlerInterface` + `StepHandlerRegistry` + `StepResult` + generischer
  `review`-Handler + Tests. Verschluesselte Spalten (`memory`, `output`,
  `detail`) bewusst als `text` (Lehre aus dem `ai_extracted`-Fehler).
- **P3:** Erste Definition end-to-end: `bankverbindung_aendern`
  (request_document -> extract -> apply_change ueber ChangeRequestService ->
  draft_reply). Minimal-UI im Ticket. Confidence-Gate + Human Override.
- **P4:** `IntentDetector` (Saeule 2) + `ask_customer` (Saeule 3) am
  Beispiel `neues_kind`.
- **P5:** Prompt-Pflege im Admin (Saeule 7) + Versionierung-UI (Saeule 9).
- **P6+:** Weitere Definitionen (Adresse aendern, Karte verloren,
  Fahrzeugwechsel ...) - jeweils nur Wissensdatenbank-Eintraege.

## Was schon existiert (ca. 60% der Plumbing)

Tickets, Dokument-Anforderung+Mahnung, OCR/Extraktion (frei zuerst),
Kunden-Matching, datenschutzkonforme Datenaenderung mit Freigabe, das
Approve/Reject-Gateway und der Audit-Trail sind vorhanden und getestet. Neu
sind vor allem: der **Engine-Kern**, die **Wissensdatenbank** (Definitionen +
Prompts), die **provider-unabhaengige KI-Schicht** und die **generische
Intent-/Confidence-/Memory-/Action-Log-Mechanik**.
