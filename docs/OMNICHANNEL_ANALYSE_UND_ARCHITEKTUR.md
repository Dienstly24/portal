# Omnichannel-Messaging: Bestandsaufnahme, Architektur, Migrationsplan

Stand 06.09.2026. Dieses Dokument ist **Phase 1 + Phase 2** des
Betreiber-Auftrags ("Laravel Omnichannel Messaging System"): erst das
bestehende System verstehen, dann den Umbau vorschlagen. **Es wurde noch
keine Zeile Produktionscode geaendert** - das ist Absicht (Auftrag
Abschnitt 47).

---

## 1. Was heute existiert (Bestandsaufnahme)

### 1.1 Der wichtigste Befund: es gibt gar keine Kunden-Conversation

Der Auftrag geht von einem vorhandenen `Conversation`-Modell fuer Kunden
aus. Das trifft **nicht** zu. `customer_messages` ist ein FLACHER Strom
je Kunde:

```
customer_messages
  id (uuid), customer_id, sender_id, body,
  from_staff (bool), ai_generated (bool), read_at, email_mode
```

Es gibt keine `conversation_id`, keinen Status, keinen Zustaendigen, kein
`last_message_at`. "Die Unterhaltung" ist eine ABFRAGE
(`where customer_id = X order by created_at`), kein Datensatz. Genau
deshalb kann heute auch nichts zugewiesen, geschlossen oder archiviert
werden - es gibt kein Objekt, an dem das haengen koennte.

Praktische Folge fuer den Umbau: die Conversation muss **neu entstehen**
und rueckwirkend fuer den Bestand erzeugt werden (Backfill). Das ist
weniger riskant als ein Umbau eines vorhandenen Modells, aber es ist der
Schritt, an dem Datenverlust entstehen KANN, wenn man ihn falsch macht.

### 1.2 Die vier bestehenden Kommunikationsstraenge

| Strang | Tabellen | Richtung | Zuweisung | Bemerkung |
|---|---|---|---|---|
| Portal-Chat | `customer_messages`, `customer_message_attachments` | Kunde <-> Team | keine | Herz der Kundenkommunikation, KI-faehig |
| Tickets | `tickets`, `ticket_messages`, `ticket_events` | Kunde <-> Team | `tickets.assigned_to` | hat bereits Status + Zuweisung + Ereignisprotokoll |
| E-Mail | `email_messages` | eingehend | `customer_id` | nur admin/manager/support |
| Interner Chat | `internal_conversations`, `_participants`, `_messages` | Mitarbeiter <-> Mitarbeiter | Teilnehmerliste | hat als EINZIGER bereits eine echte Conversation-Struktur |

Dazu `internal_messages` (interne Notizen zum Kunden, mit Soft-Deletes)
und `customer_notes`.

### 1.3 `CustomerConversationService` = "Omnichannel Phase A"

Existiert bereits und fuehrt die Straenge chronologisch zu EINER Ansicht
zusammen (`timeline()`), **ohne die Datenhaltung anzufassen**. Der Kommentar
im Code nennt das ausdruecklich "Omnichannel, Phase A".

Bewertung: das ist die richtige Vorarbeit und liefert die UI-Semantik
(`kind`, `style`, `own`, `icon`, `tag`) bereits fertig. Es ist aber ein
**Lese-Zusammenschluss ohne Persistenz**: er kann nicht zuweisen, nicht
filtern, nicht paginieren und skaliert nicht (er laedt je Kunde ALLE
Nachrichten, Tickets, Ereignisse, Mails, Dokumente und Notizen in den
Speicher und sortiert in PHP). Fuer eine Inbox ueber ALLE Kunden und
Kanaele ist er strukturell nicht geeignet - genau das ist die Luecke, die
der Auftrag schliesst.

### 1.4 Betreuer: existiert bereits - aber als N:M

Der Auftrag (Abschnitt 7) verlangt `customer.betreuer_employee_id`, also
GENAU EINEN Betreuer. Das System hat aber seit dem Anfang:

```php
// Customer.php
public function betreuer() {
    return $this->belongsToMany(User::class, 'employee_customers', 'customer_id', 'user_id');
}
```

`employee_customers` ist laut Migrationskommentar "der Dreh- und
Angelpunkt des Portfolio-Modells". Daran haengen: `getAccessibleCustomers()`,
`canAccessCustomer()`, `visibleCustomerIdsWithSubstitution()` (inkl.
Vertretungsregelung), die Kundenliste, der Kunden-Chat, die Auswertungen
und der Neukunden-Bericht.

**Das ist ein echter Zielkonflikt und die einzige blockierende Frage
dieses Dokuments** - Vorschlag siehe Abschnitt 4.1.

### 1.5 Berechtigungen

Kein Spatie-Permissions-Paket. Stattdessen: `users.role`
(admin/manager/support/employee/partner/customer) plus boolesche
Faehigkeiten (`can_see_all_customers`, `can_manage_tickets`,
`can_send_emails`, `can_manage_commissions` ...) und ein Gate
(`provisionen-verwalten`). Durchgesetzt wird backendseitig ueber
`role:`-Middleware an der Route UND `canAccessCustomer()` im Controller.

Das Rollenbild des Auftrags (Betreuer / Support / Manager) ist damit
bereits vorhanden - `support` sieht heute schon mehr als `employee`. Es
braucht KEIN neues Rollensystem, nur neue Faehigkeiten.

### 1.6 Externe Dienste, die als Vorlage taugen

- `MetaGraphClient` / `MetaPublisher` / `MetaInsightsService`: bereits ein
  funktionierender Meta-Graph-Zugang inkl. Token-als-Bearer-Header,
  Fehlerbehandlung und `EnvFileWriter`. Der WhatsApp-Adapter kann darauf
  aufsetzen - **es entsteht kein zweiter Meta-Zugang.**
- `PublishSocialChannelJob`: das Muster "langsamer externer Dienst laeuft
  als Job, atomarer Marker gegen Doppelversand, `tries = 1`" ist genau
  das, was der ausgehende Nachrichtenweg braucht.
- `ExternalReference`: polymorphe externe Kennung - taugt als Vorbild,
  ist fuer Kanal-Identitaeten aber zu unspezifisch (kein Kanalkonto).

**Es gibt heute keinerlei WhatsApp-, Telegram- oder Messenger-Anbindung.**
Die einzige WhatsApp-Erwaehnung ist ein `wa.me`-Link auf der Website.
Ebenso gibt es **keine Webhook-Route** fuer eingehende Nachrichten.

---

## 2. Was bleibt, was sich aendert, was neu ist

### Bleibt unveraendert (keine Migration, kein Umbau)
- `tickets` samt Nachrichten, Ereignissen, Status und `assigned_to`.
  Ein Ticket ist ein VORGANG, keine Unterhaltung - beides zu verschmelzen
  waere ein Rueckschritt.
- `internal_messages` / `customer_notes` (interne Notizen).
- `email_messages` (eigener Posteingang mit eigener Logik).
- `employee_customers` als Portfolio-Grundlage (siehe 4.1).
- Der gesamte KI-Assistent. Er schreibt ueber `CustomerMessage::create()`;
  solange dieser Weg erhalten bleibt, merkt er vom Umbau nichts.

### Aendert sich (additiv, nullable, ohne Datenverlust)
- `customer_messages` bekommt `conversation_id` (nullable) und die
  Omnichannel-Felder. Alle bestehenden Spalten bleiben.
- `customer_message_attachments` bekommt `mime_type`, `file_size`,
  `type` - heute wird der MIME-Typ aus der Dateiendung GERATEN.
- `AdminCustomerChatController` liest kuenftig aus der Conversation-
  Tabelle statt aus `whereHas('messages')`.

### Ist neu
`channels`, `channel_accounts`, `conversations`, `conversation_assignments`,
`customer_channel_identities`, `channel_events` (Idempotenz), dazu die
Adapter-Schicht und der WhatsApp-Adapter.

---

## 3. Zielarchitektur

```
Webhook-Route (duenn: nur verifizieren + Job werfen)
        |
        v
ChannelManager -> ChannelAdapterInterface (je Kanal eine Umsetzung)
        |
        v   normalisiert
InboundMessage (DTO) - kennt keine Plattform mehr
        |
        v
ConversationEngine
  |- CustomerResolver      (customer_channel_identities -> Customer)
  |- ConversationLocator   (channel_account + external_conversation_id)
  |- AssignmentService     (Betreuer-Regel, Takeover, Historie)
  \- MessageWriter         (idempotent ueber external_message_id)
        |
        v
Domain-Events -> Glocke, KI-Assistent, Inbox
```

**Die eine Regel, an der sich alles messen laesst**: in
`app/Services/Messaging/` (Engine) darf das Wort `whatsapp` NICHT
vorkommen. Ein Test wird genau das pruefen - eine Architekturregel, die
nicht gemessen wird, haelt keine sechs Monate.

### 3.1 Tabellen (Kurzfassung)

**`channels`** - `key` (internal/whatsapp/...), `name`, `driver`,
`is_active`, `capabilities` (JSON). Faehigkeiten sind DATEN, nicht Code:
`supportsMedia`, `supportsReadReceipts`, ... Die Engine fragt die
Faehigkeit ab, statt den Kanal zu kennen.

**`channel_accounts`** - `channel_id`, `name`, `external_account_id`,
`credentials` (verschluesselt, `encrypted:array`), `token_expires_at`,
`settings`, `is_active`. Mehrere Konten je Kanal moeglich.

**`conversations`** - `customer_id` (nullable), `channel_id`,
`channel_account_id` (nullable), `external_conversation_id`,
`external_user_id`, `assigned_employee_id`, `status`, `subject`
(fuer den internen Chat), `last_message_at`, `closed_at`, `archived_at`,
`reopened_at`, `locked_by`/`locked_at` (Mehrbenutzer-Betrieb).

**`customer_channel_identities`** - `customer_id`, `channel_id`,
`channel_account_id`, `external_user_id`, `external_username`, `metadata`.
Unique auf `(channel_account_id, external_user_id)`. Damit bleibt der
`Customer` frei von `whatsapp_id`, `instagram_id`, ... - **das ist der
Kern von Omnichannel**: ein Kunde, viele Identitaeten.

**`conversation_assignments`** - Historie: `from_employee_id`,
`to_employee_id`, `changed_by_employee_id`, `reason`, `created_at`.

**`channel_events`** - `channel_account_id`, `external_event_id`,
`processed_at`. Unique -> ein zweimal geliefertes Webhook erzeugt keine
zweite Nachricht.

### 3.2 Nachrichten: KEINE neue Tabelle

Der Auftrag (Abschnitt 13) beschreibt ein vereinheitlichtes
`messages`-Modell. Vorschlag: **`customer_messages` erweitern statt eine
zweite Nachrichtentabelle zu bauen.** Begruendung:

- Eine neue `messages`-Tabelle hiesse, den Bestand zu KOPIEREN. Solange
  beide Tabellen existieren, hat jede Frage zwei Antworten - und der
  KI-Assistent, die Glocke, die Suche und der Portal-Chat muessten alle
  gleichzeitig umgestellt werden. Das ist ein Grossrisiko ohne Gegenwert.
- Die vorhandenen Spalten bilden das Zielmodell fast ab: `from_staff` ist
  `direction`, `sender_id` + `ai_generated` sind `sender_type`/`sender_id`,
  `read_at` ist eine Statuszeit.

Neue Spalten (alle nullable): `conversation_id`, `direction`,
`sender_type`, `external_message_id`, `message_type`, `status`, `metadata`,
`sent_at`, `delivered_at`, `failed_at`, `failure_reason`.
Unique auf `(conversation_id, external_message_id)`.

`from_staff` bleibt und wird aus `direction` mitgeschrieben (eine Quelle,
zwei Lesarten) - so laeuft jeder bestehende Aufrufer weiter.

---

## 4. Die Entscheidungen, die dem Betreiber gehoeren

### 4.1 Betreuer: 1:1 oder N:M? (blockierend fuer Phase 3 und 5)

Der Auftrag verlangt EINEN Betreuer je Kunde. Das System hat MEHRERE.

**Empfehlung: `employee_customers` bleibt unveraendert** (Sichtbarkeit /
Portfolio - daran haengt zu viel, inklusive Vertretungen) und bekommt
eine Spalte `is_primary`. Der "Betreuer" im Sinne des Auftrags ist dann
der PRIMAERE Eintrag, abrufbar ueber `Customer::betreuerPrimary()`.

Damit gilt beides gleichzeitig: genau ein Verantwortlicher fuer die
automatische Zuweisung, und weiterhin mehrere Sichtberechtigte. Der
Alternativweg (neue Spalte `customers.betreuer_employee_id`) fuehrt zu
ZWEI Wahrheiten darueber, wer zustaendig ist - dieselbe Klasse Fehler,
die im Provisionsbereich bereits geregelt wurde.

### 4.2 WhatsApp-Coexistence

Der Auftrag will die bestehende Nummer weiterbetreiben. Ob Meta die
Coexistence fuer genau dieses Konto und diese Nummer freigibt, steht
nicht im Repository und laesst sich hier nicht pruefen. Die Architektur
setzt das deshalb NICHT voraus: `channel_accounts` traegt
Phone-Number-ID und WABA-ID, die Engine kennt Coexistence gar nicht.
Vom Betreiber zu klaeren, bevor Phase 6 startet.

### 4.3 Reihenfolge

Vorschlag: Phase 3 (Datenbank + Backfill) und Phase 7 (Inbox) BRINGEN
BEREITS NUTZEN ohne WhatsApp - die Kundenkommunikation bekommt Status,
Zustaendigkeit und Historie. Erst danach WhatsApp. So laeuft nicht der
gesamte Umbau auf eine externe Freigabe zu, die noch aussteht.

---

## 5. Migrationsplan (jeder Schritt fuer sich lauffaehig)

| Schritt | Inhalt | Risiko |
|---|---|---|
| 3a | Neue Tabellen anlegen. Nichts liest sie. | keine |
| 3b | `channels` + Kanal `internal` und `portal` befuellen (Seeder). | keine |
| 3c | Spalten an `customer_messages` (alle nullable). | keine |
| 3d | **Backfill**: je Kunde mit Nachrichten eine `conversation` (Kanal `portal`), `conversation_id` nachtragen, `last_message_at` setzen. Idempotent, wiederholbar. | mittel - eigener Test |
| 3e | Interner Chat: `internal_conversations` bekommt eine Conversation-Zeile als Spiegel; die alten Tabellen bleiben LESEND wie sie sind. | gering |
| 4 | Adapter-Schicht + `InternalChatAdapter` + `PortalAdapter` (beide ohne externe API - sie beweisen, dass die Abstraktion ohne WhatsApp traegt). | keine |
| 5 | Betreuer, automatische Zuweisung, Takeover, Historie, Rechte. | mittel |
| 6 | WhatsApp Cloud API. | extern |
| 7 | Inbox liest aus `conversations` statt aus `whereHas('messages')`. | mittel |
| 8 | Tests, Nebenlaeufigkeit, Sicherheit, Lastpfade. | - |

Alte Spalten und alter Code werden **erst entfernt, wenn ein Lauf in
Produktion gezeigt hat, dass der neue Weg vollstaendig ist** - nicht im
selben Schritt.

## 6. Sicherheit (gilt ab dem ersten Schritt)

- Zugangsdaten NUR in `channel_accounts`, `encrypted`-Cast, nie im
  Repository, nie im Frontend, nie im Log. Ein Test prueft, dass ein
  Token weder in einer Ausnahme noch in einer Antwort auftaucht.
- Webhook-Signatur wird geprueft, BEVOR irgendetwas gespeichert wird.
- Jede Zuweisungsaenderung ins Protokoll (`conversation_assignments`
  plus `ActivityLog`).
- Portfolio-Scope gilt weiter: ein Mitarbeiter sieht in der Inbox nie
  mehr Kunden als in seiner Kundenliste.
