# WhatsApp im Postfach + Business-App-Coexistence

Bestandsaufnahme nach Auftrag Abschnitt 33 und Bericht nach Abschnitt 36.
**Vor** jeder Aenderung bei Meta geschrieben - genau das war die Bedingung
des Betreibers.

## 0. Der wichtigste Befund zuerst

**Es gibt kein Postfach, in das man WhatsApp einhaengen koennte.**

"Postfach" ist heute eine NAVIGATIONSGRUPPE mit fuenf getrennten Seiten
(Kundenchat, Tickets, Anfragen, E-Mail, Team-Chat). Jede hat einen eigenen
Controller, eine eigene Abfrage, eine eigene Liste. Es gibt keine
gemeinsame Liste, kein "Alle", kein "Ungelesen", kein "Meine", keinen
Kanal-Filter - diese Begriffe existieren im Code nicht.

Und die naechstliegende Seite, der **Kundenchat, ist nach KUNDE
sortiert, nicht nach Unterhaltung**:

```php
$user->getAccessibleCustomers()->whereHas('messages') ...
```

Daraus folgt eine Luecke, die man erst beim Hinsehen bemerkt: eine
WhatsApp-Nachricht von einer **unbekannten Nummer** hat keine Kundenakte -
und ist damit in der gesamten Oberflaeche **unsichtbar**. Der Kern legt
sie korrekt an (das war Absicht: "eine verworfene Nachricht bekaeme
niemand zurueck"), aber niemand sieht sie. Genau diese Nachricht soll ein
Mitarbeiter zuordnen.

Das Postfach ist deshalb keine Zutat zur WhatsApp-Anbindung, sondern die
Voraussetzung dafuer, dass sie im Alltag ueberhaupt benutzbar ist.

## 1. Was JETZT SCHON fertig ist

Der Unterbau steht vollstaendig - er wurde in den PRs #310, #313, #314
und #315 gebaut und ist auf `main`.

| Baustein | Zustand |
|---|---|
| `conversations` als Datensatz (Status, Zuweisung, Sperre, Archiv) | fertig |
| `channels` mit **Faehigkeiten als DATEN** (`capabilities`) | fertig |
| `channel_accounts` mit `encrypted:array` + `$hidden` | fertig |
| `customer_channel_identities` (EIN Kunde, VIELE Kennungen) | fertig |
| `channel_events` (Idempotenz ueber `dedupe_key`) | fertig |
| Conversation Engine, `CustomerResolver` (nie raten) | fertig |
| `AssignmentService` + Historie, Betreuer != Zustaendigkeit | fertig |
| WhatsApp-Adapter: Signatur, Empfang, Medien, Versand, Status | fertig |
| 24-Stunden-Fenster **im Adapter**, nicht im Kern | fertig |
| Eingehende Mediendateien werden geholt und privat gespeichert | fertig |
| KI ueber das Ereignis des Kerns, fuenf Betriebsarten, Hierarchie | fertig |
| Zustellung/Lesen/Fehlschlag, Status nur vorwaerts | fertig |
| `/admin/kanaele` (Kanal + Konto anlegen, Verbindung testen) | fertig |
| Webhook `/webhooks/whatsapp` (Verifizierung, Idempotenz) | fertig |

Die Faehigkeiten stehen bereits als Daten am Kanal - `supportsMedia`,
`supportsTemplates`, `supportsReadReceipts` ... Der Composer kann sich
also darauf stuetzen, ohne ein einziges `if ($channel === 'whatsapp')`.

## 2. Was FEHLT

### Teil A - Postfach

1. **Die vereinheitlichte Liste** ueber alle Kanaele: Alle / Ungelesen /
   Meine, sortiert nach letzter Aktivitaet, ueber `conversations` statt
   ueber Kunden.
2. **Kanal-Navigation aus der DATENBANK.** Heute steht jeder Punkt fest
   im Code (`AdminNavigation::postfach()`). Solange das so bleibt,
   kostet jeder neue Kanal eine Code-Aenderung - der Auftrag verlangt
   ausdruecklich das Gegenteil.
3. **Kanal-Kennzeichen** an jeder Zeile, Filter (Kanal, Betreuer,
   Zustaendiger, Status, ungelesen, Zeitraum, Kunde, Konto).
4. **EINE Suche** ueber Kunde, Nachrichtentext, Telefon, E-Mail,
   externe Kennungen, Unterhaltungs-Kennung.
5. **Unterhaltungs-Ansicht + Composer**, die den Kanal aus der
   Unterhaltung ableiten - der Mitarbeiter waehlt nie einen Kanal.
6. **Zaehler**, die auf Eingang, Lesen, Antwort, Zuweisung, Archiv,
   Schliessen und Wiederoeffnen reagieren.
7. **Unterhaltungen OHNE Kundenakte** sichtbar machen (siehe Abschnitt 0).

### Teil B - Coexistence

8. **Echo-Schutz (Auftrag 25) - der gefaehrlichste offene Punkt.**
   In der Coexistence meldet Meta auch Nachrichten, die ein Mitarbeiter
   in der **WhatsApp Business App** getippt hat. Der Adapter macht heute
   aus JEDEM Eintrag unter `messages[]` eine Kundennachricht. Ohne
   Aenderung entsteht:

   ```
   Business App -> Meta -> Webhook -> "Kundennachricht"
       -> KI antwortet -> Echo -> KI antwortet -> ...
   ```

   Eine Schleife, die beim Kunden ankommt. Dieser Punkt muss VOR der
   Freigabe stehen, nicht danach.
9. **`connection_type`** am Konto (`cloud_api` / `coexistence`) und ein
   ehrlicher **Verbindungszustand** (nicht verbunden / ausstehend /
   verbunden Cloud API / verbunden Coexistence / Authentifizierungs-
   fehler / Webhook-Fehler / getrennt).
10. **Embedded Signup** statt der heutigen Eingabemaske fuer Token und
    Kennungen.
11. **Vorlagen-Nachrichten** ausserhalb des 24-Stunden-Fensters.
12. **Ausgehende Anhaenge**.

## 3. Was bei META einzurichten ist

Die vorhandene App **Dienstly24** (Typ Business, WhatsApp bereits
hinzugefuegt) wird weiterverwendet - ein technischer Grund fuer eine
zweite App ist nicht erkennbar.

| Schritt | Wo |
|---|---|
| Facebook Login for Business konfigurieren (Embedded Signup) | App -> Produkte |
| Gueltige OAuth-Redirect-URI eintragen | Facebook Login -> Einstellungen |
| Berechtigungen: `whatsapp_business_management`, `whatsapp_business_messaging`, `business_management` | App-Review |
| Webhook-URL + Bestaetigungs-Token | WhatsApp -> Konfiguration |
| Webhook-Felder abonnieren: `messages` **und die Coexistence-Felder fuer Echos** | WhatsApp -> Webhook-Felder |
| App-Secret fuer die Signaturpruefung | App -> Einstellungen |
| Zahlungsmethode am WhatsApp Business Account | Business Manager |

**Die genauen Feldnamen und der genaue Ablauf des Coexistence-Onboardings
sind aus dem Repository NICHT belegbar** - sie stehen in Metas aktueller
Dokumentation und aendern sich. Sie werden vor der Umsetzung von Teil B
gegen die offizielle Dokumentation geprueft und hier nachgetragen. Was
hier steht, ist der Rahmen, nicht die Abschrift.

## 4. Was der BETREIBER selbst tun muss

1. In der App **Dienstly24** die oben genannten Produkte und
   Berechtigungen freischalten und ggf. das App-Review starten.
2. Die **Coexistence-Freigabe** fuer die bestehende Nummer bei Meta
   durchlaufen (Zustimmung im Business Manager bzw. in der Business App).
3. **Die Nummer NICHT abmelden und NICHT als gewoehnliche Cloud-API-
   Nummer registrieren**, solange der Coexistence-Weg nicht bestaetigt
   ist - eine abgemeldete Nummer laesst sich nicht ohne Weiteres
   zurueckholen.
4. Zahlungsmethode hinterlegen (nicht ueber die API pflegbar).
5. WhatsApp in die Datenschutzerklaerung und das
   Verarbeitungsverzeichnis aufnehmen (Empfaenger Meta, Auftrags-
   verarbeitung).

## 5. Was DIENSTLY automatisch tut

1. Kanal und Konto anlegen bzw. aktualisieren, sobald die Autorisierung
   zurueckkommt - inklusive WABA-ID und Phone-Number-ID.
2. Zugangsdaten verschluesselt ablegen; nie im Repository, nie im
   Frontend, nie im Protokoll, jederzeit erneuerbar und loeschbar.
3. Webhook pruefen und den Zustand ehrlich anzeigen.
4. Verbindung testen und den Zustand fuehren - **Cloud API und
   Coexistence bleiben getrennte Zustaende** (Auftrag 35): ein
   funktionierendes Token beweist NIE die Coexistence.
5. Eingehende Nachrichten normalisieren, Kunden zuordnen (nie raten),
   Unterhaltung fuehren, Betreuer zuweisen, KI anstossen.
6. Echos der Business App als AUSGEHEND fuehren - nie als Kundenfrage,
   nie an die KI.
7. Ausgehende Antworten von Mitarbeiter und KI zustellen.

## 6. Reihenfolge

Teil A zuerst. Zwei Gruende: das Postfach ist ohne jede Meta-Aenderung
umsetzbar und sofort nuetzlich (auch fuer Portal-Chat und die spaeteren
Kanaele), und der Echo-Schutz laesst sich nur dann sinnvoll pruefen,
wenn man sieht, was im Postfach ankommt.

Kein Schritt bei Meta, bevor Teil A steht und der Echo-Schutz gebaut und
getestet ist.


---

# UMSETZUNG TEIL A (09.09.2026)

Die drei Punkte, die der Betreiber vor jedem Schritt bei Meta sehen
wollte, sind gebaut und geprueft.

## 1. Ein Postfach, viele Kanaele

`/admin/postfach` liest ausschliesslich `conversations` ueber
`ConversationInbox`. Sicht (Alle / Ungelesen / Meine), Kanal-Filter,
Betreuer, Zustaendigkeit, Zustand, Zeitraum, Konto und die Suche kommen
aus DIESER einen Schicht - und die Zaehler ebenfalls, damit Liste und
Zahl nicht auseinanderlaufen koennen.

Tickets, Anfragen, E-Mail und Team-Chat bleiben unangetastet auf ihren
Seiten. Sie sind keine Kanal-Unterhaltungen, und sie umzubauen war
ausdruecklich nicht der Auftrag.

## 2. Die Navigation kommt aus der Datenbank

Der feste Punkt "Kundenchat" ist weg - nicht der Ort, nur seine
Verdrahtung. Jeder aktive Kanal mit `supportsCustomers` erzeugt einen
Punkt, der auf dieselbe Liste zeigt, vorgefiltert. "Kundenchat" ist
seitdem der NAME des Portal-Kanals in der Datenbank. WhatsApp erscheint,
sobald der Kanal aktiv ist; Instagram, Telegram und TikTok spaeter
genauso, ohne dass jemand diese Datei anfasst.

Ein Test misst, dass in Inbox, Filtern und Controller KEIN Kanalname im
Code steht (Kommentare abgezogen) - dieselbe Messweise wie im Kern.

## 3. Unbekannte Kontakte

Der Befund aus Abschnitt 0 ist behoben. Eine Nachricht ohne Kundenakte
steht als "Unbekannter Kontakt" mit ihrer Kennung in der Liste und ist
fuer JEDEN Mitarbeiter sichtbar - sie gehoert niemandem, und unsichtbar
fuer alle war der Fehler. Aus der Unterhaltung heraus laesst sie sich
mit einer bestehenden Akte verknuepfen oder zu einer neuen machen; die
Unterhaltung bleibt dabei DIESELBE, der Verlauf also erhalten. Dabei
entsteht die Kanal-Identitaet - ab dann findet jede weitere Nachricht
dieser Kennung den Kunden von selbst.

## 4. Echo-Schutz

Siehe Abschnitt 2, Punkt 8 - jetzt gebaut. Der Kern kennt dafuer EINE
allgemeine Tatsache (`InboundMessage::fromBusiness`), keinen
Plattform-Sonderfall; der Adapter erkennt den Fall am eigenen Feld der
Coexistence UND daran, dass als Absender unsere eigene Nummer steht.

## Was das fuer Meta bedeutet

Nichts hat sich geaendert: es wurde **kein Schritt bei Meta getan**, die
Nummer ist unberuehrt, der Kanal steht weiterhin auf inaktiv. Der
Bericht in Abschnitt 3/4/5 gilt unveraendert. Teil B beginnt erst nach
ausdruecklicher Freigabe - und der Abschnitt "Official Meta onboarding
flow" wird davor gegen Metas aktuelle Dokumentation geprueft, statt aus
dem Gedaechtnis geschrieben.

## Tests

`PostfachTest` (20), `CoexistenceEchoTest` (12), `WhatsAppEndToEndTest`
(5) - dazu unveraendert `WhatsAppChannelTest`, `WhatsAppDeliveryTest`,
`WhatsAppAbnahmeTest`, `AiChannelIntegrationTest`,
`ConversationAssignmentTest`, `MessagingArchitectureTest`.
