# Audit & Ueberarbeitung Benachrichtigungssystem (Juli 2026)

Vollstaendige Pruefung und Haertung des internen Benachrichtigungssystems
("Glocke" / Notification Center) im Dienstly24-Portal. Ziel: schnell,
zuverlaessig, ohne Duplikate, ohne Datenverlust und sauber erweiterbar.

## 1. Ausgangslage (Ist-Analyse)

Das System besteht aus einer Tabelle `internal_notifications` und einem
Notification Center (eine Glocke im CRM, eine im Kundenportal), das per
Polling (60 s) aktualisiert wird. Ein Eintrag kann sein:

- **Mention** (`message_id`) – @-Erwaehnung im internen Chat/Notizen,
- **Kundenaenderung** (`change_request_id`),
- **Systemmeldung** (`title`/`body`/`link`) – Tickets, Nachrichten,
  Dokumente, Import usw.,
- **ungelesene interne Chat-Unterhaltung** (zur Laufzeit aus dem
  Teilnehmer-Lesestand berechnet).

Benachrichtigungen wurden an **15 verstreuten Stellen** per
`InternalNotification::create([...])` erzeugt (Services, Controller, Jobs,
Console-Commands). Es gab keine gemeinsame Schicht mit einheitlichen Regeln.

Getestete Basis vor dem Umbau: `php artisan test` -> **864 Tests gruen**.

## 2. Gefundene Probleme

| # | Schweregrad | Problem | Wirkung |
|---|-------------|---------|---------|
| P1 | **Hoch** | `title`/`body` wurden ungekuerzt gespeichert. Mehrere Aufrufer haengen dynamische Inhalte an (Dateinamen, Anfrage-Titel). In Produktion (MySQL strict) fuehrt Ueberlaenge zu `SQLSTATE[22001] Data too long`. | Die Benachrichtigung geht **verloren**, obwohl die ausloesende Aktion erfolgreich war. Genau dieser Fehler war fuer den Import bereits belegt (`ImportNotificationTest`), betraf aber ungeschuetzt auch Dokumente/Anfragen. |
| P2 | **Hoch** | Keine Duplikat-Vermeidung. Doppel-Submit, wiederholte Ticket-Antworten oder mehrfach ausgeloeste Jobs erzeugten mehrere identische Eintraege. | Die Glocke wird geflutet, Ungelesen-Zaehler wird unglaubwuerdig. |
| P3 | **Hoch (Performance)** | Das Notification Center lud fuer jede ungelesene Chat-Unterhaltung den **kompletten Nachrichtenverlauf** (`with('conversation.messages')`) und sortierte in PHP, nur um die letzte Nachricht anzuzeigen. | Speicher- und Laufzeit wachsen linear mit der Chat-Historie (N+1-/Speicher-Problem) – langsame Glocke bei aktiven Teams. |
| P4 | Mittel | Keine Kategorisierung (`type`). | Filter, Priorisierung und weitere Kanaele (E-Mail/Push/SMS) nur schwer umsetzbar. |
| P5 | Mittel | Keine gemeinsame Erzeugungsschicht (15 Copy-&-Paste-Stellen). | Regeln (Kuerzen, Dedup) mussten pro Stelle wiederholt werden – fehleranfaellig, inkonsistent. |
| P6 | Niedrig | Sortier-Index fehlte. Der Abruf sortiert nach `created_at` je Empfaenger; vorhanden war nur `(user_id, read_at)`. | Voller Scan der Empfaenger-Zeilen beim Laden des Centers. |
| P7 | Niedrig (UX) | Nur 60-s-Polling, kein Refresh bei Tab-Fokus. | Bis zu 60 s Verzoegerung; beim Zurueckkehren auf den Tab veraltete Anzeige. |

## 3. Umgesetzte Loesung

### 3.1 Zentraler `NotificationService` (Modular / SOLID) – behebt P1, P2, P5

Neu: `app/Services/Notifications/NotificationService.php` mit Facade
`App\Support\Facades\Notify` (registriert als Singleton in
`AppServiceProvider`). **Eine** Stelle, an der Benachrichtigungen entstehen:

- `Notify::push(int $userId, array $attrs)` – ein Empfaenger,
- `Notify::pushMany(iterable $userIds, array|callable $attrs)` – Fan-out;
  entfernt doppelte Empfaenger-IDs (Betreuer, der zugleich Admin ist,
  bekommt genau **eine** Benachrichtigung), erlaubt Attribute pro Empfaenger
  per Callback.

Eingebaute Regeln fuer **alle** Aufrufer:

- **Sicheres Kuerzen** von `title` (255) und `body` (500) mit Ellipse ->
  P1 strukturell ausgeschlossen.
- **Duplikat-Vermeidung** ueber `dedup_key`: existiert bereits ein
  **ungelesener** Eintrag mit gleichem Schluessel, wird er aufgefrischt
  (Inhalt + Zeitpunkt) statt dupliziert. Nach dem Lesen erzeugt das naechste
  Ereignis korrekt wieder eine sichtbare Benachrichtigung ->
  P2 behoben und zugleich "intelligente Gruppierung" (mehrere Antworten auf
  dasselbe Ticket = ein frischer Eintrag).
- **Whitelist der Attribute**: nur bekannte Felder erreichen die DB
  (Schutz vor Tippfehlern in Aufrufern).

Alle **15 Erzeugungsstellen** rufen jetzt ausschliesslich `Notify::…` auf:
`TicketNotifier` (5x), `CustomerMessageNotifier` (2x), `ChangeRequestService`,
`InternalMessageController` (Mentions), `DocumentIntakeService`,
`SmartDocumentUploadController`, `PortalController` (2x), `TicketController`,
`ChangeRequestReviewController`, `ImportCustomersJob`,
`AutoCloseResolvedTickets`, `RemindDocumentRequests`.

### 3.2 Datenbank-Haertung – behebt P4, P6

Migration `2026_07_19_090000_harden_internal_notifications.php`:

- `type` (kategorisiert: `ticket`, `message`, `mention`, `change_request`,
  `document`, `import`, `system`) – Grundlage fuer Filter/Priorisierung und
  weitere Kanaele.
- `dedup_key` – zuverlaessige Duplikat-Erkennung.
- Index `(user_id, created_at)` – schnelle, sortierte Abfrage je Empfaenger.
- Index `(user_id, dedup_key)` – schneller Dedup-Lookup.

### 3.3 Performance im Notification Center – behebt P3

Neue Relation `InternalConversation::latestMessage()`
(`hasOne(...)->latestOfMany()`). Der Controller laedt jetzt
`with('conversation.latestMessage')` statt des gesamten Verlaufs – konstanter
Aufwand je Unterhaltung statt linear zur Historie.

### 3.4 Naeher an Echtzeit (UX) – behebt P7

CRM- und Portal-Glocke: Polling von 60 s auf **30 s** verkuerzt und
zusaetzlich **sofortige Aktualisierung bei Tab-Fokus** (`visibilitychange`).
Bewusst weiterhin Polling (kein WebSocket) – siehe Empfehlungen.

## 4. Qualitaetssicherung (Tests)

Neu:

- `tests/Feature/NotificationServiceTest.php` (8 Tests): Kuerzen, Dedup
  (ungelesen/gelesen), Fan-out-Dedup, Callback-Variante, Attribut-Whitelist.
- `tests/Feature/NotificationCenterTest.php` (4 Tests): Aggregation,
  Ungelesen-Zaehler, "alles gelesen", Empfaenger-Scoping und Regressionsschutz
  fuer die `latestMessage`-Optimierung.

Bestehende Regressionstests (`ImportNotificationTest`, Ticket-, Chat-,
Messaging-, SelfService-Tests) bleiben unveraendert gruen.

## 5. Messwerte vorher / nachher

| Kennzahl | Vorher | Nachher |
|----------|--------|---------|
| Testsuite | 864 gruen | **875 gruen** (4 skipped), +11 neue Tests |
| Duplikat-Vermeidung | keine | zentral, ueber `dedup_key` |
| Kuerzen Titel/Text | pro Stelle (meist fehlend) | zentral garantiert |
| Chat-Preview im Center | gesamter Verlauf geladen | nur letzte Nachricht (`latestMessage`) |
| Sortier-Index | nur `(user_id, read_at)` | zusaetzlich `(user_id, created_at)` |
| Aktualisierung | Polling 60 s | Polling 30 s + Refresh bei Tab-Fokus |
| Erzeugungsstellen | 15 direkte `create()` | 1 zentraler Dienst |

## 6. Sicherheit / Zuverlaessigkeit

- Empfaenger-Scoping unveraendert: jeder sieht nur seine eigenen Eintraege
  (`where('user_id', …)`); kundenbezogene Eintraege nur bei bestehender
  Sichtbarkeit (`canAccessCustomer`). Mentions/Change-Requests sind im Portal
  strukturell unerreichbar (`whereNotNull('title')`).
- Ausgabe im Frontend weiterhin HTML-escaped.
- E-Mail-Nebenzustellung (Mention/Kundennachricht) bleibt in `try/catch` –
  ein Mailfehler verhindert nie die Glocke.

## 7. Empfehlungen / offene Themen (Entscheidung Betreiber)

- **Echtzeit per WebSocket** (Laravel Reverb/Pusher) statt Polling: spuerbar
  schneller, erfordert aber Broadcast-Infrastruktur (`BROADCAST_CONNECTION`,
  ggf. Redis) und eine Betriebsentscheidung. Aktuell `BROADCAST_CONNECTION=log`.
- **Queue fuer grosse Fan-outs**: bei wachsendem Team das Erzeugen vieler
  Empfaenger-Eintraege in einen Job auslagern (`QUEUE_CONNECTION=database`
  ist gesetzt). Fuer die heutige Teamgroesse synchron unkritisch.
- **Push/E-Mail/SMS aus derselben Struktur**: das `type`-Feld ist die
  Grundlage; ein kanaluebergreifender Versand kann spaeter an
  `NotificationService` andocken.
- **Priorisierung**: optionales `priority`-Feld fuer Sortierung/Hervorhebung
  dringender Meldungen.
- **Aufbewahrung**: geplanter Cleanup-Command fuer gelesene Eintraege aelter
  als X Tage (Datenminimierung, Tabellengroesse).

## 8. Geaenderte / neue Dateien

Neu:
- `app/Services/Notifications/NotificationService.php`
- `app/Support/Facades/Notify.php`
- `database/migrations/2026_07_19_090000_harden_internal_notifications.php`
- `tests/Feature/NotificationServiceTest.php`
- `tests/Feature/NotificationCenterTest.php`

Geaendert:
- `app/Providers/AppServiceProvider.php` (Singleton-Binding)
- `app/Models/InternalNotification.php` (fillable: `type`, `dedup_key`)
- `app/Models/InternalConversation.php` (`latestMessage`)
- `app/Http/Controllers/InternalNotificationController.php` (N+1-Fix)
- Alle 15 Erzeugungsstellen (Umstellung auf `Notify::…`)
- `resources/views/layouts/admin.blade.php`,
  `resources/views/layouts/portal.blade.php` (Polling 30 s + Tab-Fokus)
