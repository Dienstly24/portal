# KI-Verkaufs- und Serviceassistent - Architekturanalyse und Umsetzungsplan

Betreiber-Auftrag vom 18.08.2026 (28 Abschnitte). Dieses Dokument bildet
JEDEN Abschnitt der Spezifikation auf den vorhandenen Bestand ab und legt
fest, was neu gebaut wird - **bevor** Datenmodelle oder Ablaeufe geaendert
werden (Projektregel aus CLAUDE.md).

Grundhaltung wie beim ersten Ausbau: **angebaut, nicht eingebaut**. Der
bestehende Kundenservice (Chat, Tickets, Dokumentenanforderungen) bleibt
unveraendert nutzbar; der Assistent schreibt weiter in dieselben Tabellen.

---

## 1. Was heute schon steht (Ist-Analyse)

| Baustein | Datei | Leistet heute |
|---|---|---|
| Orchestrator | `CustomerAssistantService` | Sperren-Kette, Tool-Dialog, Antwort, Uebergabe, Protokoll |
| Steuerstand | `AiConversation` (1 je Kunde) | `ai_active`, `handover_required`, Zusammenfassung, Zaehler |
| Vorpruefung | `AssistantScopeGuard` | kostenlose Ablehnung: Bereich, Regel-Umgehung, Mitarbeiter-Wunsch |
| Werkzeuge | `AssistantToolRegistry` + 12 Tools | einzige Handlungsmoeglichkeit, keine Kunden-ID im Schema |
| Anbieter | `ClaudeAssistantProvider` / `OpenAiAssistantProvider` | Tool Calling |
| Wissensbasis | `AiKnowledgeEntry`, `/admin/ki-wissensbasis` | freigegebenes Wissen |
| Uebergabe | `HandoverService` | Status + Vorgang + Glocke mit echter Zusammenfassung |
| Protokoll | `AiAssistantLog` | Absicht, Tools, Aktionen, Ergebnis (ohne Nachrichtentext) |
| Diagnose | `ki:pruefen` | Kette pruefen, Stoerungssuche |

**Die Luecke:** der Assistent ist heute *reaktiv* (Frage -> Antwort ->
notfalls Uebergabe). Er fuehrt kein Gespraech, kennt keinen Fortschritt,
sammelt nichts ein und kann keinen Verkaufsvorgang vorbereiten. Genau das
ergaenzt dieser Ausbau.

---

## 2. Abbildung der 28 Abschnitte

| Abschnitt | Umsetzung | Status |
|---|---|---|
| 1 Ziel | Service **und** Vertrieb, erweiterbar um Angebotssuche | neu |
| 2A Bestandskunden | vorhandener Portal-Chat, jetzt mit Vorgangsfuehrung | Ausbau |
| 2B Neue Interessenten | Website-Assistent + `ai_leads` | neu |
| 2C Mitarbeiter | `EmployeeAssistantService` (Zusammenfassung, Fehlendes, naechster Schritt, Antwortvorschlag) | neu |
| 3 interaktiv | `RequirementProfile` + `SlotCollector`: fragt nur, was fehlt | neu |
| 4 natuerliche Sprache | Modell entscheidet, `AcceptanceDetector` als Netz | neu |
| 5 Angebote Phase 1 | Mitarbeiter hinterlegt Angebote, KI praesentiert | neu |
| 6 Angebotssuche spaeter | `OfferSourceInterface` + `ManualOfferSource` | Schnittstelle |
| 7 mehrere Angebote | `ai_offers` je Gespraech, Vergleich im Prompt | neu |
| 8 Zustimmung | Zustand `CUSTOMER_ACCEPTED`, Auswahl gespeichert | neu |
| 9 Vertragsdaten | Slot-Profil `contract_data`, schrittweise | neu |
| 10 stille Verifikation | `InternalVerificationService`, nur PASSED/FAILED | neu |
| 11 kein Datenleck | Werte gehen NIE an das Modell (siehe 3.3) | neu |
| 12 Zustand | `ConversationState` + Spalte `state` | neu |
| 13 Stoerung sichtbar | `status`, `paused_reason`, letzter/aktueller/naechster Schritt | neu |
| 14 Wiederaufnahme | Kontext in `collected` + Zustand, kein Neustart | neu |
| 15 Uebernahme | vorhandene Uebernahme + strukturierte Zusammenfassung | Ausbau |
| 16 Mitarbeiter-Hilfen | `EmployeeAssistantService` | neu |
| 17 Stil lernen | Leitfaden-Kategorie in der Wissensbasis, menschlich freigegeben | neu |
| 18 Wissensquelle | vorhandene Wissensbasis + Kategorien | Ausbau |
| 19 Website-Assistent | oeffentlicher Chat ohne Kundendaten | neu |
| 20 Leads | `ai_leads` mit Pflichtfeldern der Spezifikation | neu |
| 21 Klassifizierung | `category` je Gespraech | neu |
| 22 Berechtigungen | Rollenpruefung, Bankdaten nie in der Oberflaeche | neu |
| 23 Auditlog | `ai_conversation_events`, getrennt vom Chattext | neu |
| 24 Phase 1 | dieser Ausbau | - |
| 25 Phase 2 | Angebotssuche ueber dieselbe Schnittstelle | vorbereitet |
| 26/27/28 Zielbild | siehe Abschnitt 5 | - |

---

## 3. Entwurfsentscheidungen (die vier wichtigen)

### 3.1 Der Zustand ist ein Datenfeld, nicht der Nachrichtenverlauf

`ai_conversations.state` fuehrt genau EINEN Zustand je Gespraech:

```
NEW -> IDENTIFYING_CUSTOMER -> COLLECTING_REQUIREMENTS -> COLLECTING_ADDRESS
    -> WAITING_FOR_OFFER -> OFFER_PRESENTED -> WAITING_FOR_CUSTOMER_DECISION
    -> CUSTOMER_ACCEPTED -> COLLECTING_CONTRACT_DATA -> VERIFYING_DATA
    -> VERIFICATION_PASSED -> CONTRACT_READY -> COMPLETED
```

Quer dazu: `HUMAN_REQUIRED` (Mitarbeiter zustaendig). Die erlaubten
Uebergaenge stehen in `ConversationState::TRANSITIONS` - ein Sprung von
`NEW` direkt nach `CONTRACT_READY` ist damit unmoeglich, auch wenn das
Modell es versuchen wuerde. **Regel: der Zustand aendert sich nur ueber
Werkzeuge, nie durch freien Text des Modells.**

### 3.2 Nie zweimal dieselbe Frage

`RequirementProfile` beschreibt je Absicht die benoetigten Angaben
(Reihenfolge, Beschriftung DE/AR/EN, Pflicht/optional). `SlotCollector`
haelt fest, was bereits vorliegt. Der Prompt bekommt in jeder Runde eine
kurze Liste "bereits bekannt / noch offen" - das Modell fragt dadurch nur
noch nach Offenem. Bekannte Angaben aus der Kundenakte (Adresse eines
Bestandskunden) gelten als vorhanden und werden nur noch bestaetigt.

### 3.3 Sensible Werte erreichen das Modell NIE

Der Betreiber will Vertragsdaten im Chat einsammeln (Abschnitt 9), das
Projekt verbietet gleichzeitig IBAN/Geburtsdatum & Co. im Modellkontext.
Beides ist erfuellbar, wenn die Reihenfolge stimmt:

```
Kundennachricht
   -> SlotExtractor (deterministisch, serverseitig, KOSTENLOS)
        erkennt IBAN/Geburtsdatum/E-Mail/Telefon, speichert sie
        verschluesselt und ERSETZT sie im Text durch [IBAN erfasst]
   -> Modell sieht nur noch: "IBAN: liegt vor", niemals den Wert
```

Damit gilt weiter: keine IBAN, kein Geburtsdatum, keine Ausweisdaten im
Prompt - und der Kunde kann sie trotzdem im Chat senden. Dasselbe gilt fuer
die Verifikation (3.4).

### 3.4 Stille Verifikation

`InternalVerificationService` vergleicht die eingegangenen Angaben mit dem
Bestand und liefert **nur** `VERIFICATION_PASSED` / `VERIFICATION_FAILED` /
`VERIFICATION_PENDING`. Weder der Grund noch der Bestandswert gehen an das
Modell oder an den Kunden - sonst waere der Chat ein Orakel, mit dem sich
gespeicherte Daten erraten liessen (Abschnitt 11). Der Mitarbeiter sieht
die Pruefpunkte, der Kunde bekommt bei Misserfolg nur den Hinweis, dass
das Team sich meldet.

---

## 4. Neue Tabellen

| Tabelle | Zweck |
|---|---|
| `ai_leads` | Interessent aus dem Website-Assistenten (Abschnitt 20) |
| `ai_offers` | Angebote je Gespraech, vom Mitarbeiter hinterlegt (5/7) |
| `ai_conversation_events` | Auditlog getrennt vom Chattext (23) |

Erweiterungen an `ai_conversations`: `state`, `intent`, `category`,
`channel`, `lead_id`, `collected` (verschluesselt), `verification_status`,
`selected_offer_id`, `status`, `paused_reason`, `last_successful_step`,
`current_step`, `next_action`.

Keine Aenderung an `customers`, `contracts`, `tickets`,
`document_requests`, `customer_messages` - der Bestand bleibt unberuehrt.

---

## 5. Zielbild und Phase 2

Phase 1 endet mit `CONTRACT_READY`: alle Angaben liegen vor, geprueft, der
Mitarbeiter schliesst ab. Phase 2 ersetzt lediglich `ManualOfferSource`
durch eine API-Implementierung derselben Schnittstelle - Zustandsmaschine,
Werkzeuge, Oberflaeche und Protokoll bleiben unveraendert.

---

## 6. Was bewusst NICHT gebaut wird

- **Kein automatischer Vertragsabschluss.** Verbindliches bleibt
  Mitarbeiter-Sache (Regel aus dem ersten Ausbau, unveraendert).
- **Kein Nachtrainieren des Modells auf Mitarbeiter-Chats** (Abschnitt 17).
  Gelernt wird als Leitfaden in der Wissensbasis, von einem Menschen
  freigegeben - alles andere waere datenschutzrechtlich unhaltbar und
  wuerde Fehler der Vergangenheit einbrennen.
- **Keine Angebotssuche** (Abschnitt 6 ist ausdruecklich Phase 2).
