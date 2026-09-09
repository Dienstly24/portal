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


---

# TEIL B - COEXISTENCE (09.09.2026)

## Vorab: der Bericht nach Abschnitt 36

Der Betreiber hat sich vor jedem Schritt bei Meta fuenf Angaben
ausbedungen. Hier sind sie. **Bei Meta ist weiterhin nichts geschehen** -
die Nummer ist unberuehrt, der Kanal steht auf inaktiv.

### 1. Was im Code FERTIG ist

| Baustein | Zustand |
|---|---|
| Echo-Schutz (`smb_message_echoes`) | fertig, 12 Tests |
| `channel_accounts.connection_type` (`cloud_api` / `coexistence`) | fertig |
| Verbindungszustand (nicht verbunden / ausstehend / verbunden / Authentifizierungsfehler / Webhook-Fehler / getrennt) | fertig |
| Embedded Signup: Code -> Token AUF DEM SERVER | fertig |
| Pruefung der Nummer mit dem erhaltenen Token | fertig |
| Webhook-Abonnement auf dem WABA | fertig |
| Anbindungsart von Hand richtigstellbar (Bestandsnummern) | fertig |
| Anzeige in `/admin/kanaele` samt Fehlergrund und Pruefzeitpunkt | fertig |

### 2. Was bei META einzurichten ist

In der bestehenden App **Dienstly24** (kein Grund fuer eine zweite):

| Schritt | Wo |
|---|---|
| Produkt "Facebook Login for Business" hinzufuegen | App -> Produkte |
| Eine **Konfiguration** anlegen (ergibt die `config_id`) mit den Assets WhatsApp-Konto + Nummer | Login for Business -> Konfigurationen |
| Berechtigungen `whatsapp_business_management`, `whatsapp_business_messaging`, `business_management` | dieselbe Konfiguration |
| Gueltige OAuth-Redirect-URI und erlaubte Domains eintragen | Facebook Login -> Einstellungen |
| Webhook-URL `https://<domain>/webhooks/whatsapp` + Bestaetigungs-Token | WhatsApp -> Konfiguration |
| Webhook-Felder abonnieren: `messages`, **`smb_message_echoes`**, dazu `history` und `smb_app_state_sync` | WhatsApp -> Webhook-Felder |
| App-Secret ablesen | App -> Einstellungen -> Allgemein |

### 3. Was der BETREIBER selbst tun muss

1. Die drei Werte in die Server-`.env` setzen - **nie ins Repository, nie
   in den Chat**:
   `META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID`.
   Ohne sie wird der Weg gar nicht angeboten (und die CSP-Freigabe fuer
   Meta bleibt weg).
2. Den Coexistence-Weg bei Meta durchlaufen und in der WhatsApp Business
   App auf dem Telefon **bestaetigen** - dieser Schritt passiert auf dem
   Geraet und kann von hier aus weder ausgeloest noch geprueft werden.
3. Zahlungsmethode am WhatsApp Business Account hinterlegen (ueber die
   API nicht pflegbar - derselbe Vorbehalt wie bei den Anzeigen).
4. WhatsApp in Datenschutzerklaerung und Verarbeitungsverzeichnis
   aufnehmen (Empfaenger Meta, Auftragsverarbeitung).
5. **Die Nummer NICHT abmelden und NICHT als gewoehnliche Cloud-API-
   Nummer registrieren**, solange der Coexistence-Weg nicht bestaetigt
   ist.

### 4. Was DIENSTLY automatisch tut

Nach dem Klick auf "WhatsApp Business verbinden":
Code entgegennehmen -> **auf dem Server** gegen ein Token tauschen ->
Nummer mit diesem Token lesen (der Beweis, dass er traegt) -> Konto
anlegen oder ergaenzen -> Webhook auf dem WABA abonnieren -> Zustand
setzen. Zugangsdaten liegen verschluesselt, stehen in `$hidden`, gehen
nie ins Frontend und nie in ein Protokoll.

### 5. Welchen offiziellen Weg das System benutzt

**Embedded Signup** (Facebook Login for Business), also der von Meta
vorgesehene Weg. Ausdruecklich NICHT: WhatsApp-Web-Automatisierung,
QR-Umwege, inoffizielle Schnittstellen, Sitzungs-Abgriff,
Browser-Steuerung oder ein Zwischenanbieter (kein Twilio, kein
360dialog) - die Anbindung laeuft direkt gegen Meta.

Im Fenster gibt es ZWEI Auswahlen, und sie sind nicht dasselbe:
- ohne Zusatz: die Nummer laeuft allein ueber die Cloud API;
- mit `featureType = whatsapp_business_app_onboarding`: der
  **Coexistence**-Weg, die Nummer bleibt zusaetzlich in der Business App.

**Aus dem Fenster kommt ein kurzlebiger CODE, kein Token.** Der Tausch
laeuft ueber `GET /{version}/oauth/access_token` mit `client_id`,
`client_secret` und `code` - deshalb auf dem Server: ein App-Secret im
Browser waere ein Dauerschluessel fuer jeden, der die Seite oeffnet.

**Was nicht aus dem Repository stammt und geprueft wurde**: die
Feldnamen und der Ablauf sind gegen Metas Dokumentation und mehrere
Anbieter-Dokumentationen abgeglichen worden, nicht aus dem Gedaechtnis
geschrieben. Bestaetigt: das Webhook-FELD heisst `smb_message_echoes`,
die Nachrichten darin stehen unter dem Schluessel `message_echoes`,
`from` ist die Geschaeftsnummer und `to` der Kunde. **Widerspruechlich
in den Quellen** ist das Enddatum der alten Signup-Fassung (8. bzw.
15. Oktober 2026) - deshalb steht hier kein Datum als Tatsache. Wir
setzen ohnehin die aktuelle Fassung ein.

## Der Zustand ist zweigeteilt - und das ist der Punkt

`connection_type` (WIE angebunden) und `connection_status` (WIE es
steht) sind zwei Spalten, nicht eine. Eine einzige haette
frueher oder spaeter einen Wert `connected_coexistence` bekommen, und
die Frage "steht die Verbindung?" waere nur noch ueber eine Liste von
Sonderwerten zu beantworten.

**Ein gruener Verbindungstest macht aus einem Cloud-API-Konto NIE ein
Coexistence-Konto** (Auftrag 35, als Test festgehalten). Der Test setzt
den ZUSTAND; die ART aendert nur der ausdrueckliche Weg oder ein Mensch.

## Zwei Waechter haben mitgeredet

**Der Architektur-Test** hat den Onboarding-Code beanstandet: er nennt
Meta beim Namen und lag im KERN. Zu Recht - eine Anbindung ist
Plattformwissen. Er liegt jetzt unter `Channels/Onboarding/`, dort, wo
Plattformwissen hingehoert.

**Der Fremdressourcen-Test** hat das Meta-SDK gemeldet. Ebenfalls zu
Recht, und es war mehr als eine Formalie: unsere eigene
Inhaltsrichtlinie haette das Skript **blockiert** - die Anbindung haette
im Browser stumm nicht funktioniert. Meta bietet fuer Embedded Signup
ausschliesslich ein JavaScript-SDK an; ein serverseitiger Redirect mit
`config_id` liess sich nicht belegen. Also eine ENGE, benannte Ausnahme:
`connect.facebook.net` im `script-src`, **nur wenn der Weg eingerichtet
ist**, und nur auf einer Seite der Beraterwelt, die ein Admin oeffnet -
nie im Kundenportal, nie auf der Website.

## Ein Fehler, den nur die Testsuite zeigen konnte

Die Methode hiess zuerst `setConnection()`. **Die gibt es in Eloquent
bereits** - sie setzt den Namen der Datenbankverbindung. Ueberschrieben
schrieb jedes Laden eines Modells in die Datenbank und rief sich selbst
auf; PHP starb am ueberlaufenden Stack. Die Meldung lautete nur
"Premature end of PHP process", ohne Datei und ohne Zeile. Sie heisst
jetzt `markConnection()`. Ein Name, den das Framework schon vergeben
hat, ist nie nur ein Namensstreit.

## Weiterhin NICHT gebaut

- **Ausgehende Mediendateien** und **genehmigte Vorlagen** ausserhalb des
  24-Stunden-Fensters.
- Die `history`- und `smb_app_state_sync`-Ereignisse (frueherer
  Schriftwechsel und Kontakte aus der Business App) werden noch nicht
  ausgewertet - sie sind abonnierbar, aber der Kern verarbeitet sie
  nicht. Bis dahin beginnt die Unterhaltung im Postfach mit der ersten
  Nachricht NACH der Anbindung.
- **Coexistence gilt erst als eingerichtet, wenn Meta sie erteilt hat.**
  Das System zeigt an, was es weiss - es behauptet es nicht.

Tests: `WhatsAppOnboardingTest` (14 Faelle).


---

# TEIL B2 - DER VERLAUF AUS DER BUSINESS APP (09.09.2026)

Betreiber-Vorgabe: der bisherige Schriftwechsel aus der WhatsApp
Business App soll NICHT draussen bleiben. Ein Mitarbeiter braucht den
Zusammenhang; ohne ihn beginnt jede Kundenbeziehung im Portal bei null.

## Was Meta wirklich liefert - geprueft, nicht angenommen

| Angabe | Stand |
|---|---|
| Webhook-Feld | `history` |
| Umfang | **hoechstens die letzten 180 Tage** |
| Zeitpunkt | einige Minuten NACH dem Onboarding, **einmalig** |
| Bedingung | der Betrieb muss das Teilen **bestaetigen** (er kann ablehnen) |
| Form | in ABSCHNITTEN (`phase`, `chunk_order`, `progress`), nach Faeden gruppiert (`threads[].id` = Nummer des Kunden) |
| Kontakte | eigenes Feld `smb_app_state_sync` |

**Das ist ausdruecklich KEIN vollstaendiger WhatsApp-Verlauf.** Er ist
zeitlich begrenzt, an eine Zustimmung gebunden und kann teilweise
ankommen. Die Oberflaeche behauptet deshalb nie Vollstaendigkeit: der
Fortschritt (`chunks`, `phase`, `progress`, Zeitpunkt des letzten
Abschnitts) wird am Konto vermerkt, damit "teilweise verfuegbar" eine
belegbare Aussage ist und keine Vermutung.

## Historisch ist nicht Live

`customer_messages.source` (`live` / `historical`). Der Bestand ist
`live` - ein neuer Weg muss sich ausdruecklich als historisch ausweisen.

Ohne diese Unterscheidung waere jede nachgelieferte Kundennachricht ein
frischer Eingang: **beim Anschalten der Anbindung bekaeme der Kunde eine
Antwortlawine** - KI-Antworten auf Fragen von vor drei Monaten, dazu
Glocken und Zuweisungen fuer erledigte Vorgaenge.

Eine historische Nachricht ist deshalb:

| | |
|---|---|
| sichtbar in der Unterhaltung | **ja** |
| durchsuchbar (dieselbe Suche) | **ja** |
| am richtigen Kunden und an der richtigen Unterhaltung | **ja** |
| mit ihrer urspruenglichen externen Kennung | **ja** |
| Zusammenhang fuer die KI | **ja** - sie steht an Kunde und Unterhaltung |
| ungelesen | nein (`read_at` gesetzt) |
| Ausloeser fuer die KI | **nein** |
| Ausloeser fuer eine Glocke | nein |
| Ausloeser fuer eine Zuweisung | **nein** |
| Ausloeser fuer einen Versand | **nein** |
| holt die Unterhaltung nach oben | nein |
| oeffnet eine geschlossene Unterhaltung | nein |

Der KERN kennt dafuer wieder nur eine allgemeine Tatsache
(`InboundMessage::$historical`), keinen Plattform-Sonderfall: dass ein
Kanal eine Vorgeschichte mitbringt, ist nichts WhatsApp-Eigenes.

## Der echte Zeitpunkt, nicht der des Imports

Historische Nachrichten bekommen den Zeitstempel, den sie hatten. Ohne
ihn stuende der halbe Verlauf unter dem Datum der Anbindung, und die
Reihenfolge im Verlauf waere Zufall. `last_message_at` der Unterhaltung
wird von einer historischen Nachricht nur gesetzt, wenn es noch keinen
Wert gibt - eine alte Nachricht darf keine Unterhaltung im Postfach nach
oben holen.

## Was der Mitarbeiter sieht

```
──── WhatsApp-Historie (vor der Anbindung) ────
19.02.2025  Kunde:     Hallo, ich brauche eine Kfz-Police
19.02.2025  Team:      Gerne, ich melde mich
──── Mit Dienstly verbunden ────
09.09.2026  Kunde:     Neue Frage ...
[Antwort] [Anhang]
```

Ohne diese Trennung liest jemand eine Frage von vor drei Monaten wie
eine von heute - und antwortet darauf.

## Doppelte Zustellung

Der Verlauf laeuft durch dieselben zwei Schutzschichten wie jede andere
Zustellung: das Ereignis-Register (`msg:<externe Kennung>`) und der
eindeutige Index auf (Unterhaltung, externe Kennung). Ein zweiter
Verlaufs-Empfang erzeugt weder Kunden noch Unterhaltungen noch
Nachrichten doppelt - als Test festgehalten.

## Noch offen

`smb_app_state_sync` (die Kontakte aus der Business App) wird noch nicht
ausgewertet. Es ist abonnierbar, der Kern verarbeitet es nicht - und
solange das so ist, steht es hier und nicht als erledigt in einer
Tabelle.

Tests: `WhatsAppHistoryImportTest` (14 Faelle).
