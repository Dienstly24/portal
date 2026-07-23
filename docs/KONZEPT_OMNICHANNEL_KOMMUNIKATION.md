# Omnichannel-Kundenkommunikation — Konzept & Phasenplan

Ziel (Vorgabe Betreiber, 23.07.2026): Der Mitarbeiter arbeitet nur noch
"mit dem Kunden", nicht mit Kanaelen. EINE Unterhaltung (Timeline) pro
Kunde; Tickets sind ein STATUS der Unterhaltung, kein eigener
Kommunikationsweg. Bestehende Funktionen weiterverwenden - kein Neuaufbau.

## Phase A (umgesetzt)

- **Kundenkommunikation** (frueher "Kunden-Chat", `/admin/kundenchat`)
  zeigt pro Kunde EINE chronologische Timeline ueber alle vorhandenen
  Kanaele - ohne Aenderung der Datenhaltung
  (`CustomerConversationService` aggregiert nur):
  - Portal-Chat (CustomerMessage) als Blasen - Antworten weiterhin direkt
    aus dem Composer (Portal + E-Mail-Hinweis wie gehabt)
  - Ticket-Nachrichten als Blasen mit Ticket-Tag; Ticket-Ereignisse
    (erstellt, Statuswechsel, Zuweisung ...) als kompakte Karten
    (Quelle: TicketEvent)
  - Eingehende zugeordnete E-Mails (EmailMessage) als Karten mit
    Sprung in den Posteingang (nur Rollen mit Posteingang-Zugriff)
  - Vom Kunden hochgeladene Dokumente als Karten
  - Interne Notizen (CustomerNote + InternalMessage type=note +
    interne Ticket-Notizen) als gelbe, klar markierte Karten
- **Kanal-Filter** (Alle/Chat/Tickets/E-Mail/Dokumente/Intern) und
  **Schnellaktionen** direkt in der Unterhaltung: Ticket-Status des
  juengsten offenen Tickets aendern, interne Notiz anlegen -
  ohne Seitenwechsel (bestehende Endpoints, `back()`-Redirects).
- **Sidebar** neu gruppiert: Kommunikation = Kundenkommunikation,
  Tickets, Anfragen, Interner Chat, Ankuendigungen; eigene Gruppe
  E-Mail = Posteingang, Verfassen, Marketing (Marketing bewusst vom
  operativen Service getrennt).

## Phase B (naechste Schritte, kein Betreiber-Blocker)

- Antworten auf Ticket und E-Mail DIREKT aus der Unterhaltung
  (Kanalwahl im Composer statt nur Portal-Chat).
- Live-Aktualisierung der Nicht-Chat-Elemente (Feed liefert heute nur
  Chat; Timeline-Karten erscheinen nach Neuladen).
- "Anfrage -> Conversation -> optional Ticket": Gast-Anfragen
  (Tickets ohne Kundenakte) beim Verknuepfen mit einem Kunden
  automatisch in dessen Unterhaltung einreihen.
- Kundenakte: Tab "Kommunikation" mit derselben Timeline-Komponente.
- Erwaehnungen (@Name) aus der Unterhaltung heraus in den internen
  Chat teilen (ohne Copy/Paste).

## Phase C (wartet auf Betreiber-Entscheidung)

- **WhatsApp Business**: braucht Meta-Business-Konto + API-Anbieter
  (z. B. Cloud API) und DSGVO-Klaerung (AVV, Einwilligung). Die
  Timeline ist darauf vorbereitet (neuer `kind` = ein weiterer
  Aggregat-Block im Service). NICHT bauen, bevor Konto/Anbieter
  entschieden sind - keine Attrappen.
- Ausgehende Einzel-E-Mails (Composer) in der Timeline: dafuer muss
  der Versand pro Kunde protokolliert werden (heute nur Marketing-Log).

## Leitplanken

- Keine Datenmigration, keine Doppelhaltung: Die Timeline liest nur.
- Jedes Element verlinkt in sein Ursprungsmodul; dort bleibt die
  vollstaendige Funktionalitaet (Tickets, Posteingang usw.).
- Portfolio-Scoping unveraendert (`canAccessCustomer`); E-Mail-Elemente
  nur fuer Rollen mit Posteingang-Zugriff.
