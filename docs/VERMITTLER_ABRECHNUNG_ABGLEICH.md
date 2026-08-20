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
| Kennungen normalisieren (nur zum Vergleich) | `App\Services\Vermittler\VermittlerReference` |
| Status-Codes | `App\Services\Vermittler\VermittlerStatusMap` |
| Manuelle Zuordnung + Historie am Formular | `App\Services\Vermittler\VermittlerLinkService` |
| Auswertung | `App\Services\Vermittler\VermittlerReportService` |
| Oberflaeche | `/admin/vermittler-abrechnung` (nur admin/manager) |
| Box in der Vertragsakte | `admin/partials/contract_vermittler_box.blade.php` |
| Tests | `tests/Feature/VermittlerAbrechnungTest.php` |

## Bedienung in drei Schritten

1. **Vertrag anlegen** - Referenz-Nr. der Antragsbestaetigung im Feld
   "Referenz-/Vorgangsnummer" erfassen. Die Vermittler-ID bleibt leer.
2. **Abrechnung einlesen** - `/admin/vermittler-abrechnung`, CSV hochladen.
   Das Ergebnis erscheint sofort: zugeordnet, neu verknuepft, nicht
   gefunden, Prüfung, storniert, bereits importiert.
3. **Prüfliste abarbeiten** - alles, was nicht eindeutig war, mit einem
   Klick dem richtigen Vertrag zuordnen. Nichts davon passiert automatisch.
