# Unified Conversation Platform - Bestandsaufnahme und Architekturvorschlag

Auftrag des Betreibers 17.09.2026 (44 Abschnitte). Dieser Bericht ist die
nach Abschnitt 41 verlangte Vorlage VOR jeder Codeaenderung. Es wurde
bisher **keine Zeile geaendert**.

Die wichtigste Erkenntnis vorweg, weil sie den ganzen Zuschnitt aendert:

> **Die Unified Conversation Platform ist zu rund zwei Dritteln bereits
> gebaut und produktiv.** Der Auftrag beschreibt in den Abschnitten 1-8,
> 14, 23, 24 und 26 im Wesentlichen das, was seit Omnichannel Phase B
> (06.09.2026) und den PRs #310-#316 in Betrieb ist. Was WIRKLICH fehlt,
> sind Abschnitte 10-13 (External Support, Data Scope, Internal Notes),
> 18-21 (Training-Pipeline) und drei Kanaele.

Ein Neubau waere hier also kein Fortschritt, sondern ein Rueckschritt.
Der Vorschlag unten ist deshalb ausdruecklich eine ERWEITERUNG des
bestehenden Kerns, kein Rewrite (Auftrag Abschnitt 42).

---

## A. Current Architecture - was heute wirklich laeuft

### A.1 Der Kern existiert bereits

`app/Services/Messaging/` ist genau die in Abschnitt 3 verlangte
Abstraktion, und sie wird GEMESSEN:

| Baustein | Datei | Zustand |
|---|---|---|
| Conversation als Datensatz | `app/Models/Conversation.php` | produktiv |
| Kanal-Definition mit Faehigkeiten als DATEN | `app/Models/Channel.php` | produktiv |
| Kanal-Konten, `encrypted:array` + `$hidden` | `app/Models/ChannelAccount.php` | produktiv |
| Kanal-Identitaet je Kunde | `app/Models/CustomerChannelIdentity.php` | produktiv |
| Idempotenz-Register | `app/Models/ChannelEvent.php` | produktiv |
| Conversation Engine | `app/Services/Messaging/ConversationEngine.php` | produktiv |
| Identity Resolution | `app/Services/Messaging/CustomerResolver.php` | produktiv |
| Zuweisung + Historie | `app/Services/Messaging/AssignmentService.php` | produktiv |
| Adapter-Vertrag | `Channels/ChannelAdapterInterface.php` | produktiv |
| Vereinheitlichtes Postfach | `Inbox/ConversationInbox.php`, `/admin/postfach` | produktiv |
| KI am EREIGNIS des Kerns | `app/Listeners/Messaging/TriggerAiAssistant.php` | produktiv |

Die Architekturregel aus Abschnitt 6 ("Conversation business logic darf
nicht direkt von WhatsApp/Facebook abhaengen") ist nicht nur eine
Absprache, sondern ein Test: `MessagingArchitectureTest` prueft am CODE
(Kommentare abgezogen), dass im Kern und in `Conversation` **kein
Kanalname** vorkommt.

Testbestand heute: **216 Faelle** in `tests/Feature/Messaging/`.

### A.2 Vorhandene Kanaele

| Kanal | Adapter | Zustand |
|---|---|---|
| `portal` (Kundenportal-Chat) | `PortalAdapter` | aktiv |
| `internal` (Team-Chat) | `InternalChatAdapter` | aktiv |
| `whatsapp` | `WhatsAppAdapter` (590 Zeilen: Signatur, Empfang, Medien, Versand, Status, 24-h-Fenster, Coexistence-Echo, Embedded Signup) | gebaut, `is_active = false` - wartet auf Meta-Freigabe des Betreibers |

### A.3 Was HEUTE NICHT ueber den Kern laeuft

Das ist der eigentliche Befund der Bestandsaufnahme:

| System | Tabelle | Warum es (noch) daneben steht |
|---|---|---|
| Tickets | `tickets`, `ticket_messages` | Eigener Lebenszyklus MIT Prioritaet und SLA (`Ticket::PRIORITIES` traegt `sla_hours`), eigene Oberflaeche, Quelle Website/Portal/KI. Ein Ticket ist ein VORGANG, keine Unterhaltung. |
| E-Mail | `email_messages`, `email_accounts` | Vollstaendiger eigener Strang (IMAP/Gmail/Graph, `MailboxSyncService`, `EmailWorkflowService`, KI-Klassifizierung). Kein Adapter. |
| Team-Chat | `internal_conversations` | Eigene Tabellen NEBEN dem Kanal `internal`. |
| Facebook / Messenger / Instagram | - | Nicht vorhanden. `MetaGraphClient` existiert, aber nur fuer Seiten-Posts und Werbung, nicht fuer Nachrichten. |

### A.4 Berechtigungen heute

Eine Achse, nicht zwei. `users.role` kennt
`admin / manager / support / employee / partner / customer`, dazu
Einzelrechte (`can_see_all_customers`, `can_manage_tickets`,
`can_manage_commissions` ...). Sichtbarkeit von Kunden hat seit dem
Audit 15.09.2026 EINE Quelle: `User::canAccessCustomer()` /
`visibleOwnerIds()` / `Customer::scopeVisibleTo()`.

**Es gibt keinen Data Scope.** Wer eine Kundenakte sehen darf, sieht sie
GANZ - Name, Adresse, Telefon, IBAN-Felder, Dokumente. Ein "External
Support" im Sinne von Abschnitt 10 ist heute strukturell nicht
darstellbar: die Rolle `support` ist eine INTERNE Rolle mit Zugriff auf
`/admin`.

### A.5 Drei konkrete Luecken, die beim Lesen aufgefallen sind

1. **Anhang einer Unterhaltung OHNE Kundenakte.**
   `CustomerMessageController::findAccessibleAttachment()` prueft
   `canAccessCustomer($attachment->message->customer_id)`. Bei einem
   unbekannten Kontakt ist das `null` - ein normaler Mitarbeiter sieht
   die Unterhaltung im Postfach (so gewollt), bekommt den Anhang aber
   mit 403. Inkonsistenz zwischen Liste und Datei.
2. **Identity Resolution protokolliert ihre METHODE nicht.**
   `CustomerResolver` ordnet ueber Telefonnummer oder E-Mail zu und
   schreibt die Identitaet fest - ohne festzuhalten, dass es ein INDIZ
   war und kein Beleg. Abschnitt 8 verlangt genau diese Unterscheidung
   plus einen Zustand "Unresolved / Needs Verification".
3. **Kein Internal Note.** Im ganzen Repository kommt kein
   `internal_note` vor. Was heute als interne Notiz dient
   (`customer_notes`), haengt am KUNDEN, nicht an der Unterhaltung.

---

## B. Proposed Architecture

Der Kern bleibt, wie er ist. Ergaenzt werden vier Schichten:

```
                        Kanaele (Adapter)
   Portal  Internal  WhatsApp | Facebook  Messenger  Email
     |        |         |     |    (neu)    (neu)    (neu)
     +--------+---------+-----+------+--------+--------+
                              |
                    ChannelAdapterInterface     <- unveraendert
                              |
                     ConversationEngine         <- unveraendert
                              |
        +---------------------+---------------------+
        |                     |                     |
   Conversation          AI Gateway           Internal Notes
   + Notes/Tags/       (Context Layer,            (neu)
     Priority (neu)     Handoff-Grund)
        |
   +----+--------------------------------+
   |                                     |
 ScopedConversationReader (neu)    Audit / Observability (neu)
   |
   +-- AccessScope x DataScope  <- die eigentliche neue Idee
        |
   Employee | Internal Support | External Support | Admin
```

Vier Erweiterungen, jede fuer sich lieferbar:

1. **Conversation-Erweiterung** - Prioritaet, Tags, Internal Notes,
   erweiterter Lebenszyklus, Team.
2. **Zweiachsige Berechtigung** - `AccessScope` (WEN sehe ich?) x
   `DataScope` (WAS sehe ich von ihm?). Das ist der Kern von
   Abschnitt 10-11 und der groesste echte Neubau.
3. **Drei weitere Adapter** - Facebook, Messenger, Email.
4. **Training-Pipeline** - getrennte Tabellen, nie aus Rohdaten.

---

## C. Data Model

### C.1 Vorhanden - wird NICHT angefasst

`conversations`, `channels`, `channel_accounts`,
`customer_channel_identities`, `channel_events`,
`conversation_assignments`, `customer_messages` (erweitert),
`customer_message_attachments`.

Die Feldnamen des Auftrags (Abschnitt 5) bilden sich darauf ab:

| Auftrag | Bestand |
|---|---|
| `Conversation.reference id` | `conversations.id` (UUID) |
| `Conversation.first/last channel` | `channel_id` + Kanal je Nachricht |
| `Message.direction / channel / external id` | vorhanden |
| `Participant / Identity` | `customer_channel_identities` |
| `Channel.type / provider / configuration` | `channels` + `channel_accounts` |

**Anmerkung zu "first channel / last channel":** heute haengt eine
Unterhaltung an GENAU EINEM Kanal. Der Auftrag (Abschnitt 2/7) will eine
Unterhaltung, die WhatsApp + Portal umfasst. Das ist eine bewusste
Entscheidung, die der Betreiber treffen muss - siehe Abschnitt I,
Risiko 1.

### C.2 Neu

```
conversation_notes          (Internal Notes, Abschnitt 13)
  conversation_id, author_id, body, visibility, created_at
  - KEIN updated_at: eine interne Notiz wird nicht still gepflegt
    (dieselbe Regel wie signature_events)
  - visibility: internal | support_visible
    -> genau die Unterscheidung aus Abschnitt 13

conversation_tags           (Abschnitt 33)
  conversation_id, tag, created_by

teams / team_members        (Abschnitt 26)
  conversations.assigned_team_id (nullable)
  - Team UND Agent, nicht entweder-oder

conversation_identity_links (Abschnitt 8)
  customer_channel_identity_id, method, confidence, verified_by,
  verified_at, note
  - method: identity | phone_exact | email_exact | manual
  - ohne verified_by gilt eine Indiz-Zuordnung als "needs verification"

user_scopes                 (Abschnitt 11)
  user_id, access_scope, data_scope, allowed_channels (json)

conversation_audit_events   (Abschnitt 31)
  conversation_id, user_id, action, meta, created_at
  - append-only, kein Loeschweg, NIE der Nachrichteninhalt

ai_training_examples        (Abschnitt 18-21)
  source_conversation_id (nullOnDelete), redacted_input,
  redacted_output, intent, status(pending|approved|rejected),
  reviewed_by, reviewed_at, redaction_report (json)
  - die Rohnachricht wird NIE kopiert
```

Ergaenzungen an `conversations`: `priority`, `assigned_team_id`,
`first_response_at`, `resolved_at` (SLA-Grundlage, Abschnitt 29 -
Formeln aus `Ticket::PRIORITIES` wiederverwenden, nicht neu erfinden).

---

## D. Permission Model

Die zweiachsige Regel aus Abschnitt 11, abgebildet auf den Bestand:

| Rolle | AccessScope | DataScope | Bemerkung |
|---|---|---|---|
| admin | ALL | FULL | unveraendert |
| manager | ALL | FULL | unveraendert |
| employee | ASSIGNED (Portfolio + Vertretung) | FULL | **unveraendert** - `canAccessCustomer()` bleibt exakt wie es ist |
| support (intern) | TEAM | OPERATIONAL | neu: Adresse/IBAN/Dokumente ausgeblendet |
| **external_support** | CONVERSATION_ONLY | MINIMAL | **neu** |
| partner | eigener Bestand | wie heute | unveraendert |
| customer | eigene Unterhaltungen | eigene | unveraendert |

**Regel, die alles traegt:** Der DataScope wird an EINER Stelle
durchgesetzt (`ScopedConversationReader` / ein `CustomerPresenter`), nicht
in den Views. Eine Maskierung, die im Blade passiert, ist keine
Maskierung - sie faellt beim naechsten JSON-Endpunkt weg.

Fuer `external_support` gilt strukturell (Abschnitt 10/35):

- Eigener Pfad `/support`, eigenes Layout, **nicht** unter `/admin`.
- `isStaff()` gibt fuer diese Rolle **false** zurueck - damit ist der
  gesamte `/admin`-Bereich, die Kundensuche, der Export und der
  Team-Chat schon durch die bestehende Middleware zu, ohne dass eine
  einzige Navigation versteckt werden muss.
- Kein `Customer`-Endpunkt, keine Suche ueber Kunden, kein Export.
- Anhaenge laufen ueber die Unterhaltung, nie ueber die Kundenakte.

Das ist die einzige Stelle, an der der Auftrag die bestehende
Zugriffsarchitektur beruehrt - und zwar ADDITIV: keine bestehende Rolle
verliert oder gewinnt etwas. Der Nachweis gehoert in
`ZugriffspruefungTest` (der geht heute schon alle Personal-Routen durch).

---

## E. Channel Architecture

Unveraendert: ein neuer Kanal = eine Umsetzung von
`ChannelAdapterInterface` + eine Zeile in `channels`. Kein Eingriff in
Conversation, Message, Inbox, Zuweisung oder KI.

| Kanal | Aufwand | Besonderheit |
|---|---|---|
| Facebook / Messenger | mittel | Derselbe Graph-Webhook wie WhatsApp; Signaturpruefung und Idempotenz sind bereits gebaut. Faehigkeiten: kein 24-h-Fenster, aber ein 7-Tage-Fenster. |
| Instagram | mittel | wie Messenger |
| **Email** | **gross** | Kein Webhook, sondern Abholung (`MailboxSyncService` existiert). Die eigentliche Arbeit ist die Zusammenfuehrung mit `email_messages` - siehe Migration. |
| Website-Chat | klein | wie `portal` |

**Empfehlung:** Facebook/Messenger zuerst (der Weg ist durch WhatsApp
erprobt), E-Mail zuletzt (dort steht ein gewachsenes System, das
funktioniert).

---

## F. AI Architecture

Der Ablauf aus Abschnitt 15/16 ist im Wesentlichen vorhanden:

- KI haengt am Ereignis des Kerns, nicht am Kanal.
- Fuenf Betriebsarten (`AiMode`), Hierarchie Unterhaltung -> Kunde ->
  Konto -> Kanal -> Global (`AiSettingsResolver`).
- `AI_ASSIST` ist genau der "Suggested Reply + Human Review"-Weg.
- Der Context Layer aus Abschnitt 16 ist gebaut:
  `AssistantToolRegistry` ist eine Whitelist, **kein Tool-Schema
  enthaelt eine Kunden-ID**, die Akte kommt aus der Sitzung.
- Handoff mit Grund: `HandoverService`, `ai_conversation_events`.
- Prompt-Injection: `AssistantScopeGuard` prueft VOR dem Modellaufruf.

Zu ergaenzen:

1. **Generate / Edit / Regenerate / Approve / Reject** als benannte
   Aktionen im Postfach (heute gibt es den Vorschlag, aber keinen
   protokollierten Freigabeweg).
2. **AI Gateway respektiert den DataScope des AGENTEN** (Abschnitt 36,
   letzter Punkt): ein External Support darf ueber die KI nicht mehr
   erfahren, als er selbst sehen darf. Das ist heute nicht geprueft,
   weil es die Rolle nicht gibt.
3. **Evaluation Dataset** (Abschnitt 21) - getrennt vom Training.

---

## G. Training Data Pipeline

Vorgeschlagen genau als die Kette aus Abschnitt 18, mit einer
Verschaerfung:

```
Conversation (Rohdaten, bleiben wo sie sind)
   -> PiiDetector      (deterministisch: IBAN Mod-97, E-Mail, Telefon,
                        Geburtsdatum, Kundennummer, Vertragsnummer,
                        Kennzeichen, FIN, Versichertennummer)
   -> Redactor         ([NAME] [EMAIL] [PHONE] [IBAN] [CONTRACT] ...)
   -> QualityFilter    (zu kurz, Abbruch, Fallback-Antwort raus)
   -> IntentClassifier
   -> Human Review     -> ai_training_examples.status
   -> Export           NUR status = approved
```

Drei Regeln, die nicht verhandelbar sind:

1. **Internal Notes gehen NIE in den Datensatz** - auch nicht
   redigiert. Sie sind per Definition nicht fuer den Kunden bestimmt.
2. **Die Rohnachricht wird nie kopiert.** `ai_training_examples` traegt
   nur den redigierten Text. Ein Datensatz, der beides enthaelt, ist
   ein zweiter Kundendatenbestand mit eigener Loeschpflicht.
3. **Kein automatisches Lernen** (Abschnitt 20). Das entspricht der
   bestehenden Haltung: "es gibt kein Nachtrainieren und kein
   selbsttaetiges Lernen" (CLAUDE.md, Wissensluecken 18.08.2026). Der
   erste Nutzen ist ohnehin nicht ein Modell, sondern die
   WISSENSBASIS - `ai_knowledge_gaps` sammelt bereits, was fehlt.

Rechtlich: Die Verwendung echter Kundenkommunikation zur
KI-Verbesserung ist ein eigener Verarbeitungszweck (Art. 5 Abs. 1 lit. b
DSGVO). Das gehoert VOR dem Bau in Datenschutzerklaerung und
Verarbeitungsverzeichnis - siehe Abschnitt I, Risiko 5.

---

## H. Migration Plan

Leitsatz aus Abschnitt 32/42: was funktioniert, wird nicht gebrochen.

| Schritt | Vorgehen | Rueckweg |
|---|---|---|
| Neue Tabellen | rein additiv, alle Spalten nullable | `down()` wirft sie weg |
| Teams | leer starten, `assigned_team_id` nullable | kein Einfluss ohne Team |
| Scopes | Bestandsnutzer bekommen exakt ihre heutige Wirkung (`employee` -> ASSIGNED/FULL). **Kein bestehender Nutzer aendert sein Verhalten.** | Spalten ignorieren |
| Tickets | bleiben eigenstaendig. Nur eine VERKNUEPFUNG `tickets.conversation_id` - kein Umbau | Spalte nullable |
| E-Mail | zweistufig: erst LESEND im Postfach spiegeln, erst nach einer Bewaehrungszeit schreibend | Spiegel abschalten |
| Team-Chat | bleibt getrennt (Abschnitt 12 verlangt das ausdruecklich) | - |
| Backfill | wie `messaging:unterhaltungen-nachtragen`: idempotent, `--probelauf`, loescht nichts, ein kaputter Datensatz beendet nie den Lauf | erneut laufen lassen |

**Keine destruktive Migration.** Keine der bestehenden Tabellen wird
geloescht oder umbenannt.

---

## I. Risks

1. **Multi-Channel-Conversation (Abschnitt 2/7) ist ein Datenmodell-
   Bruch.** Heute: eine Unterhaltung = ein Kanal. Der Auftrag will eine
   Unterhaltung ueber WhatsApp + Portal. Das ist machbar (Kanal wandert
   von der Unterhaltung an die Nachricht, `conversation_channels` als
   Verknuepfung), beruehrt aber JEDE Abfrage im Postfach und die
   Reply-Routing-Logik. **Groesstes Einzelrisiko des Auftrags.**
   Empfehlung: eigene Phase, nach allem anderen, und erst wenn der
   Betrieb mit zwei echten Kanaelen arbeitet und der Bedarf belegt ist.
2. **External Support ist ein Datenschutz-Thema, kein UI-Thema.** Eine
   fremde Person bekommt Zugriff auf Kundenkommunikation. Das braucht
   einen AV-Vertrag, eine Rollenbeschreibung und eine Aufnahme ins
   Verarbeitungsverzeichnis - unabhaengig davon, wie gut die technische
   Trennung ist.
3. **E-Mail-Zusammenfuehrung.** `email_messages` ist gewachsen und
   funktioniert (KI-Klassifizierung, Workflow, Anhangsanalyse). Eine
   Migration in `customer_messages` wuerde diesen Strang anfassen. Das
   ist der Punkt, an dem "Rewrite everything" am verlockendsten und am
   gefaehrlichsten ist.
4. **Der DataScope ist nur so gut wie seine engste Stelle.** Ein
   einziger vergessener JSON-Endpunkt, ein `with()` zu viel, ein
   Anhang-Link - und die Maskierung ist umgangen. Deshalb: EINE
   Durchsetzungsstelle plus ein Test, der ALLE `/support`-Routen
   durchgeht (Muster: `ZugriffspruefungTest`).
5. **Training-Daten.** Der gefaehrlichste Fehler waere nicht ein
   schlechtes Modell, sondern ein zweiter, unbemerkter Kundendaten-
   bestand ohne Loeschkonzept.
6. **Umfang.** 44 Abschnitte sind mehrere Monate Arbeit. Alles auf
   einmal zu bauen hiesse, das produktive Postfach monatelang in einem
   halbfertigen Zustand zu halten.

---

## J. Implementation Phases

Angepasst an den tatsaechlichen Code (Abschnitt 40 erlaubt das
ausdruecklich). Jede Phase ist fuer sich lieferbar, getestet und
deploybar.

| Phase | Inhalt | Warum hier |
|---|---|---|
| **0** | Dieser Bericht | Abschnitt 41 |
| **1** | Die drei Luecken aus A.5: Anhang bei unbekanntem Kontakt, Identity-Link-Protokoll mit "needs verification", Internal Notes an der Unterhaltung | klein, sofort nuetzlich, keine Migration ausser additiv |
| **2** | Conversation-Erweiterung: Prioritaet, Tags, Teams, Lebenszyklus, `tickets.conversation_id`, Audit-Ereignisse | Grundlage fuer alles Weitere |
| **3** | **Zweiachsige Berechtigung** + `/support`-Arbeitsbereich fuer External Support, inkl. IDOR-/Enumeration-/Export-Tests | der eigentliche Auftrag; braucht Phase 2 |
| **4** | KI im Postfach: Generate/Edit/Regenerate/Approve/Reject protokolliert; AI Gateway respektiert den DataScope des Agenten | braucht Phase 3 |
| **5** | Facebook + Messenger Adapter | Weg durch WhatsApp erprobt |
| **6** | Observability: Metriken, Kennzahlen je Kanal, SLA-Grundlage | misst, was in 1-5 entstand |
| **7** | Training-Pipeline (PII-Erkennung, Redaktion, Review, Export) - **erst nach der datenschutzrechtlichen Freigabe** | Risiko 5 |
| **8** | E-Mail als Kanal, zweistufig (erst lesend) | Risiko 3 |
| **9** | Multi-Channel-Conversation - **nur auf ausdrueckliche Entscheidung** | Risiko 1 |

**Sofort umsetzbar ohne jede Entscheidung des Betreibers:** Phase 1.
**Braucht eine Entscheidung vorher:** Phase 3 (Rollenmodell),
Phase 7 (Datenschutz), Phase 9 (Datenmodell).

---

## Was der Betreiber entscheiden muss

1. **Reihenfolge**: Ist External Support (Phase 3) wirklich das
   Dringendste, oder zuerst Facebook/Messenger (Phase 5)? Der Auftrag
   legt Phase 3 nahe, aber das ist die aufwendigere Haelfte.
2. **External Support - konkret**: Wie viele Personen, welche Firma,
   welche Sprache, und was genau duerfen sie sehen? "Minimal" braucht
   eine Feldliste, keine Absichtserklaerung.
3. **Multi-Channel-Conversation** (Risiko 1): jetzt, spaeter oder nie?
4. **Training-Daten**: Freigabe durch Datenschutzbeauftragten, bevor
   Phase 7 beginnt.
5. **WhatsApp**: Der Kanal ist gebaut und steht auf inaktiv. Die
   offenen Schritte bei Meta liegen weiterhin beim Betreiber
   (`docs/WHATSAPP_POSTFACH_UND_COEXISTENCE.md`, Teil B, Abschnitt 3).

Erst nach diesen Antworten beginnt Phase 1 bzw. die gewaehlte Phase.

---

# NACHTRAG 17.09.2026 - Entscheidungen des Betreibers

Der Betreiber hat die vier offenen Fragen beantwortet. Damit aendert
sich die Reihenfolge, und der Trainings-Teil bekommt einen konkreten
Zuschnitt.

## Die Entscheidungen

| Frage | Antwort |
|---|---|
| Reihenfolge | **External Support ist das LETZTE**, nicht das erste |
| Multi-Channel-Conversation | **direkt nach Phase 1** |
| Trainingsdaten | eigener Bereich IM SYSTEM: Verlauf hochladen -> KI lernt daraus -> Kompetenz pruefen -> **ausdrueckliche Freigabe** fuer echte Kundengespraeche |
| External Support | wird umgesetzt, aber zuletzt |

## Der wichtigste Punkt vorweg: was "Training" hier heissen kann

Der Wunsch ist voellig richtig verstanden - und er ist umsetzbar. Nur
nicht so, wie das Wort es nahelegt, und das muss VOR dem Bau klar sein,
sonst wartet der Betreiber auf eine Wirkung, die nie eintritt.

**Ein Modell wie Claude wird durch Hochladen nicht nachtrainiert.** Wir
rufen eine API auf; die Gewichte des Modells sind unveraenderlich. Das
steht auch schon so in dieser Datei (Wissensluecken, 18.08.2026): "es
gibt kein Nachtrainieren und kein selbsttaetiges Lernen".

Was den gewuenschten Effekt TATSAECHLICH erzeugt - und zwar besser,
schneller und ueberpruefbar:

| Was der Betreiber will | Wie es wirklich entsteht |
|---|---|
| "die KI lernt aus unseren Gespraechen" | Aus den Verlaeufen werden **Frage-Antwort-Paare** gezogen und nach menschlicher Freigabe zu `ai_knowledge_entries`. Der Assistent antwortet ab dann mit UNSEREN Antworten. |
| "sie soll klingen wie wir" | `ki:leitfaden-entwurf` misst bereits Laenge, Ansprache, Begruessung, Rueckfragen an echten Mitarbeiter-Antworten (Kategorie `leitfaden`). |
| "ich will sehen, ob sie es kann" | **Nachspielen**: die hochgeladenen Gespraeche laufen gegen die KI, ihre Antwort wird mit der ECHTEN Mitarbeiter-Antwort verglichen. Ergebnis ist eine Zahl, kein Gefuehl. |
| "erst dann darf sie an echte Kunden" | Ein Schalter, der sich **erst oeffnen laesst, wenn die Messung bestanden ist**. |

Der Unterschied ist nicht akademisch: ein nachtrainiertes Modell kann
man nicht befragen, warum es etwas gesagt hat, und nicht zurueckdrehen.
Eine freigegebene Wissensbasis kann man lesen, aendern und einzeln
abschalten. Fuer einen Versicherungsmakler ist das zweite die einzig
vertretbare Bauform.

## Der Ablauf, wie ihn der Betreiber bedient

Vier Schritte, eine Seite, kein Fachwissen noetig:

```
1. HOCHLADEN     /admin/ki-training  ->  "Verlauf hochladen"
                 WhatsApp-Export (.txt/.zip), CSV, E-Mail-Export
                        |
2. LESEN         automatisch: Wer hat wann was geschrieben?
                 Kunde oder Mitarbeiter? -> Vorschau VOR dem Speichern
                        |
3. PRUEFEN       PII wird geschwaerzt, Paare vorgeschlagen,
                 Mensch gibt frei / lehnt ab (Sammelaktion)
                        |
4. MESSEN        "Kompetenz pruefen" -> KI spielt die Gespraeche nach
                 Ergebnis: 82 % uebereinstimmend, 11 % uebergeben,
                           7 % abweichend  (jede Abweichung lesbar)
                        |
5. FREIGEBEN     Knopf "Fuer echte Kundengespraeche freigeben"
                 -> gesperrt, solange die Messung nicht bestanden ist
```

### Schritt 1-2: Hochladen und lesen

**Das Fundament dafuer existiert bereits und ist geprueft.**
`CustomerMessage::SOURCE_HISTORICAL` ist genau dieser Fall: eine
nachgelieferte Nachricht wird gespeichert, ist sichtbar und
durchsuchbar, aber sie ist **kein Ereignis** - sie stoesst die KI nicht
an, erzeugt keinen Ungelesen-Stand, holt keine Unterhaltung nach oben
und sendet nichts. 14 Tests halten das fest
(`WhatsAppHistoryImportTest`).

Neu ist nur der WEG hinein: heute kommt Historie ueber das
`history`-Webhook von Meta, kuenftig zusaetzlich ueber einen Upload.
Format WhatsApp-Export (`[17.09.26, 14:03] Mohamad: Text`) - das ist
der Knopf "Chat exportieren" im Telefon, mehr muss der Betreiber nicht
tun.

**Vorschau vor dem Speichern** (dieselbe Regel wie beim Provisions-
Import, 26.08.2026): erst zeigen, was erkannt wurde - wie viele
Nachrichten, wie viele Gespraeche, welcher Kunde, was nicht lesbar war -
dann erst schreiben. Ein Import, der sein Ergebnis erst zeigt, NACHDEM
er geschrieben hat, laesst dem Betreiber keine Wahl mehr.

### Schritt 3: Schwaerzen und freigeben

Unveraendert die Kette aus Abschnitt G. Zwei Regeln bleiben hart:

- **Die Rohnachricht wird nie in den Trainingsbestand kopiert** - nur
  der geschwaerzte Text.
- **Nichts wird ohne einen Menschen zur Auskunft.** Jeder Vorschlag
  entsteht INAKTIV, genau wie heute bei `ki:wissensbasis-vorschlag`.

### Schritt 4: Die Kompetenzmessung - das eigentlich Neue

Hier liegt der Kern des Betreiber-Wunsches, und dafuer gibt es heute
nichts.

Die hochgeladenen Gespraeche werden **geteilt**: ein Teil wird zur
Wissensbasis, ein anderer Teil wird **zurueckgehalten** und dient
ausschliesslich als Pruefung (Abschnitt 21: Evaluation Dataset getrennt
vom Training). Ohne diese Trennung prueft man die KI an genau den
Antworten, die man ihr vorher gegeben hat - das Ergebnis waere immer
gut und immer wertlos.

Gemessen wird je Gespraech:

| Kennzahl | Bedeutung |
|---|---|
| uebereinstimmend | KI sagt fachlich dasselbe wie der Mitarbeiter |
| uebergeben | KI erkennt, dass sie es nicht weiss -> **das ist ein ERFOLG**, kein Fehler |
| abweichend | KI sagt etwas anderes -> im Klartext lesbar, mit beiden Antworten nebeneinander |
| erfunden | KI nennt eine Angabe, die nirgends belegt ist -> **K.-o.-Kriterium** |

"Erfunden" ist bewusst ein Ausschlusskriterium und keine Prozentzahl:
eine KI, die einem Kunden eine Deckung zusagt, die es nicht gibt, ist
nicht "zu 95 % gut".

### Schritt 5: Die Freigabe

Ein Schalter, aber mit Bedingung davor:

- Er ist **gesperrt**, solange keine bestandene Messung vorliegt.
- Er nennt **immer**, worauf er sich stuetzt: "Freigabe auf Basis der
  Messung vom 20.09.2026, 214 Gespraeche, 0 erfundene Angaben".
- Er wirkt ueber die **bestehende** Hierarchie (`AiMode`:
  Unterhaltung -> Kunde -> Konto -> Kanal -> Global). Es entsteht kein
  zweiter Schalter neben dem, den es gibt - sonst haette die Frage "ist
  die KI an?" zwei Antworten.
- Er ist **je Kanal** zu vergeben: fuer den Portal-Chat freigegeben
  heisst nicht fuer WhatsApp freigegeben. Der Ton und die Erwartung
  sind dort andere.
- **Zurueckziehen jederzeit, mit einem Klick** - die bestehende
  Notbremse bleibt, wo sie ist.

## Rechtlicher Vorbehalt bleibt bestehen

Echte Kundenkommunikation zur KI-Verbesserung zu verwenden ist ein
eigener Verarbeitungszweck (Art. 5 Abs. 1 lit. b DSGVO). Das gehoert in
Datenschutzerklaerung und Verarbeitungsverzeichnis, BEVOR der erste
echte Verlauf hochgeladen wird - unabhaengig davon, wie gut geschwaerzt
wird. Der Bau selbst kann vorher beginnen; die Benutzung mit echten
Daten nicht.

## Neue Reihenfolge

| Phase | Inhalt | Status |
|---|---|---|
| **1** | Die drei Luecken aus A.5 + Internal Notes | **startklar, keine Entscheidung noetig** |
| **2** | Multi-Channel-Conversation (Betreiber-Entscheidung: direkt nach Phase 1) | Datenmodell-Aenderung, groesstes Einzelrisiko |
| **3** | Verlauf-Upload + Vorschau + Schwaerzung + Freigabe von Wissenseintraegen | baut auf `SOURCE_HISTORICAL` |
| **4** | Kompetenzmessung (Nachspielen, vier Kennzahlen) + Freigabe-Schalter je Kanal | der Kern des Betreiber-Wunsches |
| **5** | Conversation-Erweiterung: Prioritaet, Tags, Teams, Lebenszyklus, Audit | |
| **6** | KI im Postfach: Generate/Edit/Regenerate/Approve/Reject protokolliert | |
| **7** | Facebook + Messenger Adapter | |
| **8** | Observability, Kennzahlen je Kanal, SLA | |
| **9** | E-Mail als Kanal, zweistufig | |
| **10** | **External Support** (zweiachsige Berechtigung, `/support`) | zuletzt, auf Betreiber-Entscheidung |

Phase 2 vor Phase 3 zu setzen hat einen sachlichen Grund, nicht nur den
Wunsch: wenn die Unterhaltung spaeter mehrere Kanaele traegt, aendert
sich die Form der Daten, auf denen die Kompetenzmessung rechnet. Erst
das Datenmodell, dann das Messen darauf - andersherum misst man zweimal.
