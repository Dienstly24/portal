# KI-Assistent im Omnichannel: Bestandsaufnahme und Bauplan

Stand 06.09.2026, zum Betreiber-Auftrag Abschnitte 48-77. Grundregel des
Auftrags (Abschnitt 48): der vorhandene KI-Assistent wird **NICHT neu
gebaut**, sondern in die Unterhaltungs-Architektur eingehaengt.

Diese Bestandsaufnahme haelt fest, was davon **bereits existiert** - denn
die groesste Gefahr bei einem Auftrag dieser Laenge ist, etwas ein zweites
Mal zu bauen, das es schon gibt, und danach zwei Wahrheiten zu haben.

---

## 1. Was von den Abschnitten 48-67 SCHON DA IST

| Auftrag | Zustand | Baustein |
|---|---|---|
| 61 Provider-Abstraktion | **fertig** | `AssistantProviderInterface` (Claude, OpenAI, Null) |
| 56 Uebergabe an Menschen | **fertig** | `HandoverService`, `markHandover()`, 9 Gruende |
| 66 KI pausieren/fortsetzen | **fertig** | `ai_active`, `takeOver()`, `ConversationResumeService` |
| 58 KI-Nachricht erkennbar | **fertig** | `ai_generated` + `sender_type = 'bot'` (Phase B) |
| 59 Protokoll | **fertig** | `ai_assistant_logs`, `ai_conversation_events` |
| 60 Kontext/Wissen | **fertig** | `AssistantToolContext`, `KnowledgeBase`, `ai_knowledge_entries` |
| 57 KI aendert nie den Betreuer | **fertig** | `HandoverService` weist zu, ruehrt den Betreuer nicht an |
| 52 Schalter im Admin | **teilweise** | `AssistantSettings`: 8 Schalter als `SystemSetting` |
| 67 KI als Assistent statt Autopilot | **teilweise** | `EmployeeAssistantService` liefert bereits Antwortvorschlaege |

Der Assistent ist also kein Rohbau. Was fehlt, ist fast ausschliesslich
die **Einbettung** - nicht die Faehigkeit.

## 2. Die zwei Befunde, die den Bauplan bestimmen

### 2.1 Die KI haengt am KUNDEN, nicht an der Unterhaltung

```
ai_conversations.customer_id  ->  UNIQUE
```

Das ist keine Konvention, sondern eine Datenbank-Bedingung: es gibt
**genau einen** KI-Steuerstand je Kunde. Solange der Portal-Chat der
einzige Kanal war, war das richtig.

Mit mehreren Kanaelen ist es falsch, und zwar sichtbar falsch: schreibt
derselbe Kunde ueber WhatsApp *und* Instagram, teilen sich beide
Unterhaltungen einen Zustand. Ein Mitarbeiter, der die KI im
WhatsApp-Vorgang pausiert, schaltet sie damit **auch im
Instagram-Vorgang** ab - ohne es zu sehen und ohne es zu wollen. Auch
`auto_reply_count` (die Kostengrenze) zaehlt dann quer ueber Kanaele
hinweg.

Auftrag Abschnitt 55 verlangt deshalb einen KI-Zustand an der
UNTERHALTUNG. Abschnitt 63 verlangt beides - Kunde UND Unterhaltung -
als getrennte Ebenen der Hierarchie. Beides ist vereinbar: der
Kunden-Zustand bleibt, er wird zur VORGABE; die Unterhaltung bekommt
ihren eigenen Zustand als Ausnahme.

### 2.2 Die KI wird aus dem Kanal heraus angestossen

Heute:

```
PortalMessageController  ->  AnswerCustomerMessageJob  ->  CustomerAssistantService
```

Der Anstoss steht also im Kanal-Weg. Genau das verbietet Abschnitt 75.
Kaeme WhatsApp dazu, muesste der WhatsApp-Weg denselben Anstoss noch
einmal enthalten - und Instagram ein drittes Mal. Beim vierten Kanal
vergisst ihn jemand, und niemand merkt es: es passiert einfach nichts.

Richtig ist der Anstoss am EREIGNIS des Kerns, das seit Phase B
existiert:

```
Channel Adapter -> ConversationEngine -> InboundMessageReceived -> KI
```

Damit gilt die KI fuer JEDEN Kanal, auch fuer jeden zukuenftigen, ohne
eine Zeile im Kanal.

## 3. Namensfalle, die festgehalten werden muss

Es gibt jetzt ZWEI Dinge, die "conversation" heissen:

- `conversations` - die Unterhaltung (Phase B, Omnichannel)
- `ai_conversations` - der KI-STEUERSTAND (was die KI gerade tun darf)

Und `ai_assistant_logs.conversation_id` zeigt auf `ai_conversations`,
**nicht** auf `conversations`. Wer das verwechselt, verknuepft
Protokolle mit der falschen Tabelle, und es faellt erst bei einer
Auswertung auf. Neue Felder heissen deshalb ausdruecklich
`omnichannel_conversation_id`, nie nur `conversation_id`.

## 4. Bauplan

### Stufe 1 - Die KI wird kanalunabhaengig (Abschnitte 48/49/55/75)
- `ai_conversations` bekommt `omnichannel_conversation_id` (nullable),
  das UNIQUE auf `customer_id` faellt.
- Anstoss ueber einen Listener auf `InboundMessageReceived` statt im
  Controller. Der bestehende Portal-Weg bleibt bis zum Umschalten
  parallel bestehen (kein Bruch).

### Stufe 2 - Betriebsarten und Hierarchie (Abschnitte 50/51/63)
- `AiMode`: `disabled` / `enabled` / `ai_first` / `human_only`.
- `AiSettingsResolver`: Global -> Kanal -> Kanalkonto -> Kunde ->
  Unterhaltung. Eine Ebene wirkt NUR, wenn sie ausdruecklich gesetzt ist
  (`null` = erben). Deterministisch und als Test festgehalten.

### Stufe 3 - Verwaltung im Admin (Abschnitte 52/68-72/77)
- Kanalkonten anlegen/bearbeiten, Zugangsdaten verschluesselt,
  Verbindungstest, Webhook-Status, KI je Konto.
- KI-Einstellungen: Anbieter, Modell, Systemtext, Grenzen,
  Geschaeftszeiten, Textbausteine.

### Stufe 4 - WhatsApp (frueherer Abschnitt 30)

## 5. Was bewusst NICHT passiert

- Kein zweiter Assistent, kein zweiter Anbieter-Vertrag, keine zweite
  Wissensbasis.
- `CustomerAssistantService` behaelt seine Stufenleiter (Schalter ->
  Zustand -> Grenzen -> kostenlose Vorpruefung -> Modell). Ergaenzt wird
  nur, WOHER die Schalter kommen.
- Die Sales-/Website-Assistenten bleiben unberuehrt.


---

## 6. Umgesetzt (Stufe 1 + 2, 06.09.2026)

### Die KI haengt jetzt am Kern, nicht am Kanal
`TriggerAiAssistant` lauscht auf `InboundMessageReceived`. Damit gilt
die KI fuer JEDEN Kanal, auch fuer jeden zukuenftigen, ohne eine Zeile
im Adapter. `MessagingArchitectureTest` haelt die Gegenrichtung fest:
im Kanal-Ordner darf kein `assistant`, `openai`, `anthropic`, `claude`,
`prompt` oder `aiconversation` vorkommen.

Der bestehende Portal-Weg (`PortalMessageController` ->
`AnswerCustomerMessageJob`) bleibt vorerst DANEBEN bestehen. Er wird
erst entfernt, wenn ein Lauf in Produktion gezeigt hat, dass der neue
Weg vollstaendig traegt - der Duplikat-Schutz ueber
`ai_assistant_logs.customer_message_id` sorgt dafuer, dass ein Kunde
auch dann nur EINE Antwort bekommt, wenn beide Wege anlaufen.

### Steuerstand je Unterhaltung
`AiConversation::forConversation()` neben `forCustomer()`. Der
kundenweite Steuerstand bleibt als VORGABE: eine dauerhafte
Abschaltung ("KI deaktivieren", `auto_resume = false`) wird an neue
Unterhaltungen vererbt, eine offene Uebergabe NICHT - sie gehoert zum
alten Vorgang, sonst startete jede neue Unterhaltung blockiert.

### Fuenf Betriebsarten, eine Hierarchie
`App\Support\AiMode`: `off`, `auto_reply`, `ai_first`, `ai_assist`,
`human_only` (Abschnitt 88). `AiSettingsResolver` loest sie auf:

```
Unterhaltung -> Kunde -> Kanalkonto -> Kanal -> Global
```

`null` heisst ERBEN, nicht "aus". `explain()` gibt zusaetzlich die
EBENE zurueck, die den Wert gesetzt hat - eine Hierarchie, die man
nicht ablesen kann, wird beim ersten Widerspruch zur Ratearbeit; der
Grund steht deshalb auch im Protokoll, wenn die KI schweigt.

**Der Bestand aendert sich nicht.** Solange keine Betriebsart
ausdruecklich gewaehlt ist, wird die globale aus den BESTEHENDEN
Schaltern abgeleitet (`enabled` aus -> `off`; `auto_reply` an ->
`auto_reply`; sonst -> `ai_assist`). Nach dem Deploy verhaelt sich der
Portal-Chat exakt wie davor.

**Der Hauptschalter bleibt die Notbremse** und steht ueber der ganzen
Hierarchie - ein Notaus, den eine Kundeneinstellung aushebeln kann, ist
kein Notaus.

### Unbekannte Absender
Eine Nachricht ohne Kundenakte bekommt NIE eine KI-Antwort
(Betreiber-Entscheidung 06.09.2026): es gibt keine Daten, aus denen
sich etwas belegen liesse, und keine dokumentierte Einwilligung. Die
Unterhaltung entsteht trotzdem und liegt im Posteingang.

### Tests
`AiChannelIntegrationTest` (12 Faelle) plus der erweiterte
`MessagingArchitectureTest`. Die 72 bestehenden KI-Tests
(`CustomerAssistantTest`, `AssistantResumeTest`) laufen unveraendert
durch - das ist der eigentliche Nachweis zu Abschnitt 103.

## 7. Offen (naechste Stufen)

- **Stufe 3 - Verwaltung im Admin** (Abschnitte 86/95-99/104): Kanal-
  und Kanalkonten-Pflege, Zugangsdaten verschluesselt in der Datenbank
  statt in der `.env`, Verbindungstest, Webhook-Status, Anbieter- und
  Modellwahl, Geschaeftszeiten, Textbausteine.
- **Stufe 4 - WhatsApp Cloud API.**
- Der Vorschlags-Modus (`ai_assist`) nutzt bereits
  `EmployeeAssistantService`; was fehlt, ist der Knopf im Panel.
