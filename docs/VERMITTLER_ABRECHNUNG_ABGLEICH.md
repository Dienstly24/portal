# Vermittler-Abrechnung: Vertrag und Abrechnung zusammenfuehren

Betreiber-Auftrag vom 20.08.2026. Diese Datei beschreibt, WARUM die
Zuordnung so gebaut ist - die Bedienung steht in der Oberflaeche selbst.

## Das Problem

Zwischen dem Abschluss und dem Geld liegen bis zu 90 Tage und zwei
verschiedene Nummern:

1. Beim Abschluss bestaetigt das Portal den Antrag und nennt eine
   **Referenz-Nr.** (`1477-6741-9200-53`). Sie steht auf der
   Antragsbestaetigung und ist zu diesem Zeitpunkt die einzige Kennung -
   eine Vertragsnummer gibt es noch nicht.
2. Wochen spaeter liefert der Vermittler eine **Abrechnungsdatei**. Dort
   traegt derselbe Vorgang eine voellig andere Nummer: die **`Id`** des
   Vermittlers (`9753224`). Die Referenz-Nr. steht in manchen Dateien mit
   drin, in anderen nicht.

Ohne eine gespeicherte Bruecke zwischen beiden Nummern laesst sich nach
dem ersten Mal nicht mehr sagen, welcher Vertrag abgerechnet wurde, welcher
storniert und welcher gar nicht auftaucht.

## Die Bruecke

```
Referenz-Nr.  ->  Vertrag  ->  Vermittler-ID  ->  Abrechnungszeile  ->  Provision
```

Beide Nummern stehen am Vertrag (`contracts.reference_number` und
`contracts.vermittler_id`). Ist die Verbindung einmal hergestellt, genuegt in
jeder spaeteren Datei die `Id` - die Spalte `Referenz-Nr.` darf fehlen.

## Die Vorgangsliste: der Schritt VOR der Abrechnung

Gemeldeter Fall 21.08.2026: die Uebersicht der OFFENEN Vorgaenge aus dem
Portal wurde als Screenshot in den **Dokumenten-Eingang** geladen, damit das
System jede `Id` mit ihrer Referenz-Nr. verbindet. Das Ergebnis war
"Sonstiges Dokument / Kein Kunde gefunden".

Das war kein Fehler, sondern der falsche Weg: der Dokumenten-Eingang ordnet
IMMER **ein** Dokument **einem** Kunden zu. Eine Liste mit den Vorgaengen
vieler Kunden kann er strukturell nicht verarbeiten - er muesste einen
Kunden waehlen, und jede Wahl waere falsch.

Richtig ist: **Vermittler-Abrechnung -> Vorgangsliste einlesen**. Dort wird
die Liste zeilenweise gelesen und fuer JEDEN Vorgang die Bruecke
`Referenz-Nr. -> Vermittler-ID` hergestellt. Damit findet jede spaetere
Abrechnungsdatei ihren Vertrag ueber die `Id` allein.

Diese Liste ist **keine Abrechnung**. Aus ihr entsteht nie eine Provision,
nie ein Storno und nie ein "Nicht in Abrechnung gefunden" - aus dem Fehlen
eines Vertrags in einer Liste OFFENER Posten laesst sich nichts folgern.
Und weil sie immer aelter ist als eine Abrechnung, stuft sie einen bereits
abgerechneten oder stornierten Vertrag nie zurueck
(`VermittlerStatusMap::mayAdvance`).

### Drei Eingangsformate, zwei Genauigkeiten

| Format | Weg | Genauigkeit |
| --- | --- | --- |
| CSV/TXT | Spalten sind beschriftet | **exakt** - hier kann nichts verrutschen |
| PDF mit Textebene | `pdftotext` | exakt |
| Screenshot / Scan | Texterkennung + Zeilen-Parser | gut, aber pruefbedürftig |

Beim Bild-Weg gilt die OCR-Lehre aus den uebrigen Parsern: auf
Spaltenabstaende ist kein Verlass. Gelesen wird ueber ANKER - eine
Vorgangs-Id ist eine allein stehende 6- bis 10-stellige Zahl, eine
Referenz-Nr. haengt an ihrer Beschriftung, und sie gehoert zum zuletzt
gesehenen Vorgang. Faellt dabei eine zweite Referenz-Nr. auf denselben
Vorgang, hat die Erkennung die Tabelle spaltenweise gelesen: dann ist KEINE
Paarung mehr belegbar, und der Import verknuepft in dieser Datei
**gar nichts**, sondern stellt alles zur Pruefung. Lieber eine Datei zur
Ansicht als eine Abrechnung am falschen Kunden.

### Der Eingang zeigt jetzt den Weg

`VermittlerVorgangslisteHinweisParser` erkennt eine solche Liste im
Dokumenten-Eingang (gratis, ohne KI-Aufruf), benennt sie als
`vermittler_vorgangsliste` und verlinkt auf die richtige Seite. Die
Erkennung ist bewusst STRENG - verlangt werden mindestens drei Vorgaenge
und mindestens zwei verschiedene Referenz-Nummern. Ein einzelner Antrag,
eine Police oder ein Deckungsauftrag tragen genau EINE Nummer und koennen
diese Huerde gar nicht nehmen; ein Fehlalarm wuerde ein echtes
Kundendokument von seiner Kundenakte fernhalten.

## Die vier Grundregeln

Sie stehen ueber jeder Bequemlichkeit und sind in
`VermittlerAbrechnungImporter` als Kommentar und in
`tests/Feature/VermittlerAbrechnungTest.php` als Test festgehalten.

1. **Nie raten.** Abweichende Referenz-Nr. bei gleicher `Id`, dieselbe
   Referenz-Nr. an zwei Vertraegen, ein unbekannter Status-Code, ein
   Stornogrund an einem nicht stornierten Datensatz - jeder dieser Faelle
   fuehrt zu `Prüfung erforderlich` und **nie** zu einer automatischen
   Zuordnung. Eine falsche Verknuepfung ist teurer als eine offene Zeile.
2. **Nie Vertragsdaten aendern.** Der Import schreibt ausschliesslich die
   Spalten `vermittler_*`. Vertragsnummer, Sparte, Status, Beitrag und
   Gesellschaft bleiben unberuehrt. Einzige Ausnahme: eine LEERE
   Referenz-Nr. wird ergaenzt - Ergaenzen ist kein Ueberschreiben.
3. **Nie loeschen.** Fehlt ein Vertrag in der Abrechnung, heisst das
   `Nicht in Abrechnung gefunden`. Das ist weder "storniert" noch
   "geloescht" - moeglicherweise steht er schlicht in der naechsten Datei.
   Storniert wird ein Vertrag nur, wenn die Abrechnung ihn als storniert
   ausweist.
4. **Nie doppelt.** Natuerlicher Schluessel ist die `Id` des Vermittlers
   (unique). Ein erneuter Import derselben Datei aktualisiert die Zeilen und
   meldet `Bereits importiert`; er legt nie eine zweite an.

## Zwei getrennte Wahrheiten

Der **Abrechnungsstatus** (`contracts.vermittler_status`) und der
**Vertragsstatus** (`contracts.status`/`stage`) sind bewusst getrennt und
duerfen sich nie gegenseitig ueberschreiben. Ein Vertrag kann laufen,
waehrend der Vermittler ihn storniert hat (er zahlt dann keine Provision) -
und umgekehrt.

Abrechnungsstatus (`Contract::VERMITTLER_STATUSES`):

| Schluessel | Bedeutung |
| --- | --- |
| `neu` | noch keine Kennung erfasst |
| `referenz_hinterlegt` | Referenz-Nr. da, Abrechnung steht aus |
| `id_zugeordnet` | Vermittler-ID bekannt |
| `in_abrechnung` | in der Abrechnung bestaetigt |
| `abgerechnet` | ausgezahlt |
| `storniert` | vom Vermittler storniert (mit Stornogrund) |
| `nicht_gefunden` | in der Abrechnung nicht enthalten |
| `pruefung` | Widerspruch - Mitarbeiter entscheidet |

## Status-Codes des Vermittlers

`VermittlerStatusMap` ist die EINE Stelle dafuer (TARIFCHECK24-Export):
`1` = bestaetigt, `2` = storniert, `4` = abgerechnet/ausgezahlt. Ein
**unbekannter Code wird nie geraten** - er fuehrt zu `Prüfung erforderlich`.
Ein anderer Vermittler mit anderen Codes = eine Zeile mehr in dieser Klasse.

## Der Abgleich in die Gegenrichtung

Nach dem Import werden Vertraege gesucht, die in der Datei FEHLEN. Das ist
bewusst eng gefasst, damit kein Vorgang aus einer ganz anderen Quelle
(Energieportal, Maklerpool) faelschlich als "nicht abgerechnet" dasteht.
Markiert wird nur, was

* noch nie in einer Abrechnung stand,
* keine Vermittler-ID traegt,
* eine Referenz-Nr. im **Format dieser Datei** hat (gleiche Laenge, reine
  Ziffern - der Massstab kommt aus der Datei selbst) und
* aelter ist als der juengste Datensatz der Datei (spaeter angelegte
  Vertraege koennen noch gar nicht enthalten sein).

Abschaltbar ueber die Checkbox im Import-Formular.

## Historie ueberlebt das Loeschen

`vermittler_settlements` und `vermittler_match_events` haengen mit
`nullOnDelete` am Vertrag und tragen Referenz-Nr., Vermittler-ID sowie eine
Klartext-Kopie von Vertrag und Kunde. Wird der Vertrag geloescht, bleibt
belegbar, dass er existierte und wie der Vermittler ihn behandelt hat -
genau das braucht man bei einer Rueckfrage zu einem Storno.

## Was wo liegt

| Bereich | Ort |
| --- | --- |
| Datei lesen (tolerant, DE-Zahlen, Latin-1) | `App\Services\Vermittler\VermittlerCsvReader` |
| Zuordnung + Gegen-Abgleich | `App\Services\Vermittler\VermittlerAbrechnungImporter` |
| Vorgangsliste lesen (CSV/PDF/Bild) | `App\Services\Vermittler\VermittlerListeReader` |
| Vorgangsliste: Tabelle aus OCR-Text | `App\Services\Vermittler\VermittlerVorgangslisteParser` |
| Vorgangsliste: Bruecke herstellen | `App\Services\Vermittler\VermittlerVorgangslisteImporter` |
| Nachschlagewerk beider Importe | `App\Services\Vermittler\VermittlerContractIndex` |
| Hinweis im Dokumenten-Eingang | `App\Services\Ai\TemplateParsers\VermittlerVorgangslisteHinweisParser` |
| Kennungen normalisieren (nur zum Vergleich) | `App\Services\Vermittler\VermittlerReference` |
| Status-Codes | `App\Services\Vermittler\VermittlerStatusMap` |
| Manuelle Zuordnung + Historie am Formular | `App\Services\Vermittler\VermittlerLinkService` |
| Auswertung | `App\Services\Vermittler\VermittlerReportService` |
| Oberflaeche | `/admin/vermittler-abrechnung` (nur admin/manager) |
| Box in der Vertragsakte | `admin/partials/contract_vermittler_box.blade.php` |
| Tests | `tests/Feature/VermittlerAbrechnungTest.php`, `tests/Feature/VermittlerVorgangslisteTest.php` |

## Bedienung in drei Schritten

1. **Vertrag anlegen** - Referenz-Nr. der Antragsbestaetigung im Feld
   "Referenz-/Vorgangsnummer" erfassen. Die Vermittler-ID bleibt leer.
   (Alternativ: die Vorgangsliste des Portals einlesen - sie traegt die
   Vermittler-ID an jedem Vertrag nach, dessen Referenz-Nr. erfasst ist.)
2. **Abrechnung einlesen** - `/admin/vermittler-abrechnung`, CSV hochladen.
   Das Ergebnis erscheint sofort: zugeordnet, neu verknuepft, nicht
   gefunden, Prüfung, storniert, bereits importiert.
3. **Prüfliste abarbeiten** - alles, was nicht eindeutig war, mit einem
   Klick dem richtigen Vertrag zuordnen. Nichts davon passiert automatisch.
