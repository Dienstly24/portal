# Provisionsmanagement (Betreiber-Auftrag 02.09.2026)

Aus dem bisherigen Bereich „Vermittler-Abrechnung“ + „Interne Provisionen“
wird **ein** zentrales Provisionsmanagement: eine Wahrheit fuer alle
Provisionen, aus beliebig vielen Pools.

## 1. Warum umgebaut und nicht danebengebaut

Die Tabellen des Provisions-Imports (26.08.2026) trugen den Kern bereits:
eine Provision je Vorgang, die Rohzeile je Import, ein Protokoll je
Aenderung. Was fehlte, waren zwei Dinge:

* **Die Pool-Ebene.** Der Import kannte Dateiformate, aber keine Quellen mit
  eigenen Spielregeln. „Was hat uns CHECK24 gebracht?“ war eine Suche ueber
  Dateinamen - und die aendern sich mit jedem Export.
* **Die Zeit.** Zwischen Abschluss und Geld liegen Monate. Ohne Frist gibt es
  kein „ueberfaellig“, und eine ausbleibende Provision faellt nie auf; sie ist
  einfach nicht da.

Ein zweites Datenmodell daneben haette dieselbe Provision zweimal gefuehrt -
genau der Fehler, den das Provisionsmanagement verhindern soll.

## 2. Die Schichten

```
POOL  ->  IMPORTER  ->  NORMALISIERUNG  ->  MATCHING  ->  KUNDE/VERTRAG
                                                     ->  PROVISIONSBUCHUNG
                                                     ->  ZUSTAND (Fristen)
                                                     ->  AUSWERTUNG  ->  UI
```

| Schicht | Ort |
|---|---|
| Lesen (CSV/XLSX/XLS, Kodierung, Trennzeichen) | `App\Services\CommissionImport\TableReader` & Leser |
| Spaltenzuordnung (Mapping-System, §28) | `CommissionImport\ColumnMap` |
| Quellen-Erkennung | `CommissionImport\CommissionSourceProfile` |
| Zuordnung zum Vertrag | `CommissionImport\CommissionMatcher` + `Provisionsmanagement\ReferenceLinkService` |
| Schreiben (zweistufig) | `CommissionImport\CommissionImportService` |
| Pools und Fristen | `Provisionsmanagement\PoolRegistry`, Tabelle `commission_pools` |
| Zustand je Vertrag | `Provisionsmanagement\CommissionStatusEngine` |
| Fehlende Provisionen + Nachverfolgung | `Provisionsmanagement\MissingCommissionService` |
| Zahlen | `Provisionsmanagement\CommissionAnalytics` |
| Oberflaeche | `ProvisionsmanagementController` + `ContractCommissionController` |

**Keine Sonderlogik in der UI (§33):** die Views bekommen fertige Zahlen und
Klartexte. Statuswerte stehen nie als Zeichenkette in einer View, sondern in
`App\Support\CommissionStatus` (Buchung), `App\Support\ContractCommissionStatus`
(Vertrag) und `App\Support\CommissionKind` (Provisionsart).

## 3. Drei Wahrheiten, die sich nie ueberschreiben

| Was | Wo | Beispiel |
|---|---|---|
| Laeuft der Vertrag? | `contracts.status` / `stage` | aktiv, gekuendigt |
| Ist DIESE Buchung bezahlt? | `contract_commissions.status` | offen, bezahlt, storniert |
| Ist der Vertrag verguetet? | `contracts.commission_status` | erwartet, fehlt, erhalten |

Ein Vertrag kann laufen und trotzdem „Provision fehlt“ sein. Eine Provision
kann storniert sein, waehrend der Vertrag Bestand hat.

## 4. Die Fristen (§17)

Je Pool zwei Zahlen, gepflegt unter **Provisionsmanagement → Einstellungen**:

* `expected_months` – ab wann eine Provision faellig waere,
* `check_months` – ab wann sie als **fehlend** gilt.

Die Uhr beginnt am **Abschluss** (`signing_date` → `application_date` →
`start_date` → Anlagedatum), nicht am Lieferbeginn. Fehlt jedes Datum, wird
nichts geraten: der Vertrag bleibt `neu` und landet in keiner Mahnliste.

`provisionen:status-aktualisieren` (taeglich 04:10) rechnet nach - denn der
Zustand aendert sich auch, wenn niemand etwas tut. Nach jedem bestaetigten
Import laufen zusaetzlich die beruehrten Vertraege durch dieselbe Rechnung;
eine spaeter eingegangene Provision macht aus „fehlt“ damit von selbst
„erhalten“ (§7).

**Welche Vertraege ueberhaupt:** nur solche mit Pool oder mit mindestens
einer Buchung. Sonst meldete die Liste „Provision fehlt“ fuer den halben
Bestand und waere wertlos.

## 5. CHECK24: Referenz-Nr. ↔ Pool-Id (§14/§15)

```
Abschluss   ->  wir kennen die Referenz-Nr. (REF-12345)
1. Datei    ->  fuehrt Referenz-Nr. UND Id (987654)
                => Paar wird gespeichert, Id wandert an den Vertrag
spaetere    ->  fuehrt nur noch die Id
Datei           => ueber das gespeicherte Paar wird der Vertrag gefunden
```

Tabelle `commission_reference_links`. Zwei verschiedene Referenzen zu einer
Id heissen: **nichts zuordnen** – das ist ein Fall fuer die Pruefliste, keiner
fuer eine Wahl per Zufall.

## 6. Vier Grundregeln (unveraendert aus dem Vermittler-Abgleich)

1. **Nie raten.** Zwei Treffer, ein Widerspruch, eine zu kurze Kennung →
   „manuelle Pruefung“.
2. **Nie Vertragsdaten aendern.** Einzige Ausnahme: eine LEERE Kennung (und
   der Pool) darf ergaenzt werden – das ist der Sinn der Bruecke.
3. **Nie doppelt.** `dedupe_key` unique aus Kennung + Art + Datum + Betrag +
   Position in der Datei. Dieselbe Datei zweimal → keine zweite Buchung.
4. **Der Name zaehlt nie** fuer die Zuordnung.

Dazu, seit dem Ausbau: **nichts geht verloren.** Eine nicht zugeordnete Zeile
wird trotzdem gebucht (ohne Vertrag) und steht unter „Unklare Zuordnungen“.

## 7. Storno und Korrektur (§13)

Beide Buchungen bleiben stehen – nie wird eine bestehende ueberschrieben.
`AP +3,07` und `APStorno -3,66` ergeben netto `-0,59`. Das **Netto ist die
Summe**, nicht „Brutto minus Storno“: Rueckbuchungen stehen in den Dateien
bereits als negative Betraege; sie noch einmal abzuziehen, haette sie doppelt
gezaehlt.

Ein negativer Betrag ohne Bezeichnung gilt als Storno – im
Provisionsgeschaeft gibt es dafuer keinen anderen Grund. Ein unbekannter
Text wird dagegen `sonstige`, nie geraten.

## 8. Originaldaten (§5)

An jeder Buchung stehen: Datei (`source_file`), Zeile (`source_row`), Pool,
Quelle, Import-Lauf (`import_id`) und die **Original-Spaltenwerte** (`raw`).
Die Frage „aus welcher Datei und welcher Zeile stammt diese Provision?“ ist
damit beantwortbar, ohne die Datei zu suchen.

## 9. Sicherheit (§30)

* Recht `provisionen-verwalten` (Admin **oder** ausdruecklich vergebenes
  Recht `users.can_manage_commissions`) – geprueft an der **Route**, im
  **Controller** und in der **Vertragsakte-Box**.
* Es gibt bewusst **keine Beziehung von `Customer`** zu den Provisionen: ein
  `with()` im Portal kann sie gar nicht mitladen.
* Eigenes Protokoll `commission_audit_logs` (hier stehen Betraege) – **ohne
  Loeschweg** aus der Oberflaeche (§26/§27).
* Tests halten fest: Kunde im Portal, Mitarbeiter und Support ohne Recht
  sehen weder Betraege noch Pool-Ids noch Wirtschaftlichkeitsdaten – auch
  beim direkten Aufruf der URL.

## 10. Wo was liegt (Oberflaeche)

`/admin/provisionsmanagement`

| Punkt | Inhalt |
|---|---|
| Dashboard | Monat/Jahr/Vorjahr, Vertragszahlen, Probleme, Abgleich, letzte Importe |
| Importe | Import-Historie, jeder Lauf mit seinen Zahlen (§24) |
| Abrechnungen | die bestaetigten Laeufe mit Summen |
| Provisionsbuchungen | die bestehende Liste `/admin/interne-provisionen` |
| Vertraege | Vertrag + Fristen + Zustand, Detail mit Provisionshistorie (§23) |
| Fehlende Provisionen | Mahnliste mit Filtern und Bearbeitungsstand (§18/§19) |
| Unklare Zuordnungen | ohne Vertrag / unklarer Status |
| Auswertungen | nach Pool, Produkt, Art, Gesellschaft, Monat, Kunde + CSV-Export |
| Einstellungen | Pools und ihre Fristen |

## 11. Was bewusst NICHT gebaut wurde

* **Kein Loeschweg** fuer importierte Daten. Ein Fehler wird durch eine
  Korrekturbuchung geheilt, nicht durch Verschwinden (§27).
* **Keine automatische Zuordnung bei Mehrdeutigkeit** – lieber eine Zeile
  mehr in der Pruefliste als Geld am falschen Vertrag.
* **Kein Zusammenlegen** mit `provisions` (AUSGANG an eigene Mitarbeiter) und
  `vermittler_settlements` (der eine Vermittler TARIFCHECK24). Drei Straenge,
  drei Wahrheiten; sie zu vereinen hiesse, eine davon zu verbiegen.

Tests: `tests/Feature/ProvisionsmanagementTest.php` (Abnahmefaelle 1–12 der
Spezifikation), dazu unveraendert `ContractCommissionImportTest`,
`VermittlerAbrechnungTest`, `ProvisionManagementTest`.
