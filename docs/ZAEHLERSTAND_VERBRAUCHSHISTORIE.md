# Zaehlerstand & Verbrauchshistorie (Energievertraege)

Betreiber-Vorgabe vom 29.07.2026. Ziel: Ein Foto des Stromzaehlers genuegt.
Das System erkennt die **Zaehlernummer**, findet damit den bereits erfassten
**Energievertrag** und den **Kunden**, traegt den abgelesenen **Zaehlerstand
mit dem Zeitpunkt des Uploads** ein - und zeigt daraus, wie viel seit der
letzten Ablesung verbraucht wurde.

## Der Ablauf in einem Satz

Foto hochladen (Kunde im Portal oder Mitarbeiter im Dokumenten-Eingang) ->
Nummer + Stand lesen -> Vertrag/Kunde ueber die Nummer finden -> Ablesung
speichern -> Verbrauch = Differenz zur vorherigen Ablesung.

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `meter_readings` | Eine Zeile je Ablesung - die Historie bleibt vollstaendig erhalten (analog `vehicle_mileage_readings` beim Kilometerstand). |
| `contract_energy_details.meter_reading` | Bleibt als "aktueller Stand" bestehen und wird vom `MeterReadingService` mitgefuehrt (Abwaertskompatibilitaet, Formulare). |
| `contract_energy_details.meter_number_normalized` | Zaehlernummer ohne Trennzeichen, gross - die Grundlage der Zuordnung (indiziert). |

Felder von `meter_readings`: `reading` (Wert), `unit` (kWh bzw. m³ bei Gas),
`register` (OBIS-Zaehlwerk), `reading_date` (Ablesetag), `captured_at`
(exakter Meldezeitpunkt), `source` (`staff` | `customer` | `document`),
`document_id` (das Foto als Beleg), `created_by`, `note`, `meter_number`
(wie abgelesen - nach einem Zaehlerwechsel weicht sie vom Bestand ab).

**Zaehlwerke (`register`, OBIS):** `1.8.0` Bezug (Standard), `1.8.1`/`1.8.2`
HT/NT, `2.8.0` Einspeisung. Der Zweirichtungszaehler einer PV-Anlage zaehlt
Bezug UND Einspeisung - beides wird strikt getrennt gefuehrt, sonst wuerde
die Einspeisung den Verbrauch verfaelschen.

Die Migration uebernimmt bereits erfasste Bestands-Zaehlerstaende als ersten
Historieneintrag (Datum: Vertragsbeginn), damit die erste neue Meldung sofort
einen Verbrauch ergibt.

## Erkennung: kostenlos zuerst

Wie beim Smart Document Upload gilt "kostenlos zuerst". `MeterPhotoReader`
liest aus dem OCR-/Textebenen-Text:

- **Zaehlernummer** - beschriftet (`Zaehlernummer`, `Nr.`,
  `Identifikationsnummer`) oder im genormten Format moderner Zaehler
  (`1` + drei Herstellerbuchstaben + Ziffern, z.B. `1 LOG00 9228 3078`,
  gespeichert als `1LOG0092283078`).
- **Zaehlerstand** - nur mit direkt folgender Einheit (`kWh`/`m³`); die
  OBIS-Kennzahl davor (`1.8.0`, auf Displays oft `180`) bestimmt das
  Zaehlwerk. Deutsche wie englische Zahlschreibweise.

Bewusst konservativ (wie die uebrige Heuristik):

- Die Zaehlerkonstante `R=10.000 Imp/kWh` auf dem Typenschild ist **kein**
  Zaehlerstand und wird nie als solcher gelesen.
- Ein Text laenger als 1.500 Zeichen ist kein Foto - eine Energierechnung
  nennt zwar auch Nummer und Stand, bleibt aber eine `rechnung`.
- Der Stichwort-Katalog des `HeuristicDocumentClassifier` behaelt den
  Vortritt; `zaehlerfoto` greift erst, wenn kein anderer Typ passt.

Erkennt die kostenlose Stufe nichts, eskaliert der `DocumentAnalyzer` wie
gewohnt zur KI (`ClaudeDocumentAiProvider`, Prompt kennt Zaehlerfotos inkl.
Zweirichtungszaehler und liefert `meter_register`/`meter_unit`).

## Zuordnung ueber die Zaehlernummer

`MeterReadingService::locate()` sucht die Nummer ueber **alle** Kunden -
auf einem Zaehler steht kein Name, die Nummer ist die einzige Bruecke.

1. Exakter Treffer auf `meter_number_normalized`.
2. Sonst Teiltreffer: eine Nummer endet auf der anderen (mind. 6 Zeichen).
   Das faengt den Alltag ab, dass im Vertrag die kurze Werksnummer
   (`92283078`) steht, auf dem Zaehler aber die volle
   Identifikationsnummer (`1LOG0092283078`).

Regeln:

- **Mehrere Kunden** -> es wird **nichts** zugeordnet (nie raten); das
  Dokument bleibt mit Vorschlag im Eingang.
- **Ein Kunde, mehrere Vertraege** (der uebliche Anbieterwechsel: alter
  gekuendigt, neuer aktiv) -> der Vertrag, der den Ablesetag abdeckt, sonst
  der aktive, sonst der zuletzt begonnene.
- Trifft die Nummer, ist der Treffer Tier `auto` (hartes Identitaetsmerkmal
  wie eine Vertragsnummer). `AnalyzeDocumentJob` prueft bei `zaehlerfoto`
  daher zuerst die Zaehlernummer und erst danach das Personen-Matching.

## Erfassen der Ablesung

`MeterReadingService::record()`:

- **Idempotent** - derselbe Stand am selben Tag im selben Zaehlwerk wird
  nicht doppelt gespeichert (erneute Analyse derselben Datei, Doppelklick).
- **Rueckwaerts laufender Zaehler**: Der Wert wird als Tatsache gespeichert
  (nie still korrigiert), mit Hinweis versehen ("Zaehlerwechsel?") und
  ueberschreibt den Bestandswert NICHT; die Anzeige markiert ihn.
- **Nichts erfinden**: ohne lesbaren Stand entsteht keine Ablesung, auch
  wenn die Nummer erkannt wurde.
- Der **Upload-Zeitpunkt ist der Ablesezeitpunkt** (`captured_at`) - das
  Foto zeigt den Stand von genau diesem Moment.

## Verbrauchsrechnung

`ContractEnergyDetail::consumptionHistory()` liefert je Ablesung den
Verbrauch seit der vorherigen, den Zeitraum in Tagen und den Tagesschnitt;
`consumptionStatus()` zusaetzlich die Hochrechnung aufs Jahr im Vergleich
zum vereinbarten `consumption_kwh`.

Bewusste Grenzen: die erste Ablesung hat keinen Vorgaenger (kein Verbrauch);
unter 14 Tagen Zeitraum gibt es **keine** Hochrechnung (zu kurz fuer eine
belastbare Aussage). `estimatedCost()` rechnet nur mit dem gepflegten
Arbeitspreis (ct/kWh) - der Grundpreis bleibt aussen vor, er faellt
zeitabhaengig an, nicht je kWh.

## Oberflaechen

**Kundenportal** (`/portal/contracts/{id}`, Karte "Zählerstand & Verbrauch"):
letzter Stand, Verbrauch seit der letzten Ablesung inkl. Tagesschnitt und
geschaetzter Energiekosten, Hochrechnung gegen den vereinbarten Verbrauch,
Verbrauchshistorie mit Balken - und das Meldeformular: Zahl, Datum,
optional Zaehlwerk (nur bei Zweirichtungszaehler) und/oder **Foto des
Zaehlers** (`capture="environment"`, also direkt aus der Handykamera).
Meldet der Kunde nur ein Foto, traegt die Analyse den Stand automatisch
nach. Ein kleinerer Stand als die letzte eigene Meldung wird abgelehnt.

**Beraterwelt** (`admin/partials/contract_energy_cockpit.blade.php` auf der
Vertrags-Bearbeiten-Seite): Kennzahlen (Verbrauch, pro Tag, Hochrechnung),
vollstaendige Ablese-Historie mit Quelle, Bearbeiter und Link zum Foto,
getrennter Block fuer die Einspeisung sowie die Erfassung von Hand (z.B.
telefonische Meldung). Loeschen einer fehlerhaften Ablesung ist
**admin/manager** vorbehalten; danach faellt der Bestandswert auf die dann
juengste Ablesung zurueck. Die Kundenakte zeigt je Energievertrag den
letzten Stand samt Verbrauch.

**Dokumenten-Eingang**: Ein erkanntes Zaehlerfoto zeigt im Review-Modal den
erkannten Stand und den Hinweis, dass er beim Zuordnen in die
Verbrauchshistorie uebernommen wird.

## Upload eines Handy-Fotos (wichtig)

Zwei Stolpersteine, die den Upload frueher **still** scheitern liessen -
beide sind behoben:

1. **Dateigroesse.** Handy-Fotos sind schnell 5-12 MB. Das Formular
   verkleinert das Bild daher **im Browser** auf max. 2000 px und kodiert es
   als JPEG (q 0.85) - aus 10 MB / 4000x3000 werden rund 0,5 MB / 2000x1500.
   Fuer das Ablesen mehr als ausreichend, spart mobiles Datenvolumen und
   umgeht die Server-Limits (`client_max_body_size`, `post_max_size`), die
   sonst eine rohe **413**-Fehlerseite erzeugen (siehe
   `docs/UPLOAD_413_NGINX_PHP_LIMITS.md`). Bleibt eine Datei danach ueber
   10 MB, erscheint eine verstaendliche Meldung statt der 413-Seite.
2. **Content-Security-Policy.** Die Bildverarbeitung nutzt
   `URL.createObjectURL()` (`blob:`-URLs). Die CSP erlaubte in `img-src`
   nur `self data: https:` - der Browser verweigerte das Laden ("Refused to
   load the image"), die Verkleinerung brach **still** ab. `blob:` ist jetzt
   in `img-src` erlaubt (`SecurityHeaders`). Das betraf nicht nur das
   Zaehlerfoto, sondern auch den **Dokumenten-Scanner im Kundenportal** und
   die Banner-Vorschau in der Beraterwelt. Regression-Guard:
   `AuditE2EFixesTest::test_csp_header_present_on_html_response`.

## Betrieb

Die kostenlose Leseebene haengt an der OCR-Konfiguration
(`OCR_ENABLED=true`, auf dem VPS aktiv). Ist sie aus und kein KI-Anbieter
konfiguriert, wird das Foto trotzdem in der Kundenakte abgelegt und das
Team per Glocke informiert - der Stand kann dann von Hand erfasst werden.

**Queue-Worker ist Pflicht.** Das Auslesen laeuft als Job
(`AnalyzeDocumentJob`, `QUEUE_CONNECTION=database`). Ohne laufenden
`php artisan queue:work` bleibt das Dokument auf `ai_status = pending` und
es entsteht nie eine Ablesung - der Kunde haette sonst nur die Zusage "wir
lesen den Stand aus" gesehen und nie ein Ergebnis. Die Portal-Karte weist
solche Fotos deshalb offen aus ("wird gerade ausgewertet" bzw. "konnten wir
nicht automatisch auslesen"), damit der Zustand sichtbar ist. Betrieb siehe
`docs/DEPLOYMENT.md` (zwei Dienste: `queue:work` **und** `schedule:work`).

## Tests

`tests/Feature/MeterReadingTest.php` (21 Tests): Erkennung inkl. der
Fallstricke (Zaehlerkonstante, lange Rechnung), Zuordnung (exakt, Teil-
treffer, mehrdeutig, Anbieterwechsel), Historie und Verbrauch,
Einspeisungs-Trennung, Idempotenz, Rueckwaertslauf, Dokumenteneingang,
Portal- und Beraterwelt-Routen samt Rollenschutz.
