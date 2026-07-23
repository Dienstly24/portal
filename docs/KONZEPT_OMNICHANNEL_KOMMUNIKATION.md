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

## Phase B (umgesetzt)

- **Kanalwahl im Composer**: Portal-Chat (AJAX, wie gehabt) ODER
  Antwort ins juengste offene Ticket (klassischer POST an den
  bestehenden Reply-Endpoint inkl. Anhaenge; Berechtigung
  `can_manage_tickets` beruecksichtigt). E-Mail ueber den Shortcut
  "E-Mail verfassen" (Smart-Composer, Kunde vorausgewaehlt).
- **Live-Hinweis fuer Nicht-Chat-Kanaele**: der Feed liefert eine
  `timeline_version` (Fingerabdruck aus Tickets/E-Mails/Dokumenten/
  Notizen); aendert sie sich, erscheint "Neue Ereignisse -
  aktualisieren". Der Chat selbst bleibt voll live.
- **Kundenakte: Tab "Kommunikation"** mit derselben Timeline
  (gemeinsames Partial `admin/partials/conversation_timeline`).
- **Intern teilen mit @Erwaehnungen**: das Notiz-Formular der
  Unterhaltung kann wahlweise eine Kundenakten-Notiz anlegen ODER
  eine Nachricht in den internen Bereich der Akte schreiben
  (bestehender Endpoint mit @Mention-Aufloesung + Benachrichtigung).
- Gast-Anfragen erscheinen automatisch in der Unterhaltung, sobald
  das Ticket einer Kundenakte zugeordnet wird (Timeline liest ueber
  customer_id - kein Zusatzschritt noetig).

## Phase C - WhatsApp (Betreiber-Entscheidung 23.07.2026)

- Es gibt ein WhatsApp-BUSINESS-KONTO, aber AUSDRUECKLICH KEINE
  API-Anbindung an das System (weder Cloud API noch Drittanbieter).
- Umgesetzt ist deshalb nur eine API-freie Bruecke: der Button
  "WhatsApp" in der Unterhaltung oeffnet wa.me/<Kundennummer>
  (Mobil, sonst Festnetz; 0 -> 49 normalisiert) im Business-Konto
  des Teams. Das System speichert, sendet und liest dabei NICHTS.
- WhatsApp-Gespraeche bei Bedarf manuell festhalten: Schnellaktion
  "Notiz" in der Unterhaltung (erscheint als interne Karte in der
  Timeline).
- Sollte spaeter doch eine API-Anbindung gewuenscht sein: neuer
  Aggregat-Block im Service (eigener `kind`), Meta-Konto/Anbieter
  und DSGVO (AVV, Einwilligung) vorher klaeren.

## Offen (spaetere Phasen)

- Ausgehende Einzel-E-Mails (Composer) in der Timeline: dafuer muss
  der Versand pro Kunde protokolliert werden (heute nur Marketing-Log).
- Voll-Live-Rendering der Nicht-Chat-Karten ohne Neuladen.

## Leitplanken

- Keine Datenmigration, keine Doppelhaltung: Die Timeline liest nur.
- Jedes Element verlinkt in sein Ursprungsmodul; dort bleibt die
  vollstaendige Funktionalitaet (Tickets, Posteingang usw.).
- Portfolio-Scoping unveraendert (`canAccessCustomer`); E-Mail-Elemente
  nur fuer Rollen mit Posteingang-Zugriff.
