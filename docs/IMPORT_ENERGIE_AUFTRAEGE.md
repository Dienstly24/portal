# Import: Strom-/Gas-Auftraege aus dem Plattform-CSV

Kommando: `php artisan energie:import <pfad-zur-csv>`

Importiert den CSV-Export der Energie-Fremdplattform (Spalten mit `;`
getrennt, Windows-1252-kodiert) als **Kunden + Energievertraege** ins Portal.

## Ablauf pro Zeile

- **Kunde** (Quelle `import`): Name (ohne Anrede), Geschlecht (aus Herr/Frau),
  Geburtsdatum, Telefon, strukturierte Anschrift (Strasse/Hausnr./PLZ/Ort).
  Mehrere Auftraege desselben Kunden landen an **einer** Kundenakte
  (Zusammenfuehrung ueber Geburtsdatum + Name + Adresse/Telefon via
  `CustomerMatchingService`). Kundennummer wird reglar vergeben (JJ + 5-stellig).
- **Vertrag** (`type = strom_gas`): Anbieter (`insurer`), Tarif/Produkt,
  Status, Zaehlernummer, Verbrauch (kWh), Start- (Anlagedatum) und Stornodatum.
- **Auftragsnummer** wird als Vertragsnummer und zusaetzlich als externe
  Referenz (`energie_auftragsnummer`) gespeichert -> der Import ist
  **idempotent** (ein zweiter Lauf legt nichts doppelt an).

## Spalten-Zuordnung

| CSV-Spalte            | Ziel                                   |
|-----------------------|----------------------------------------|
| Auftr.-Nr.            | Vertragsnummer + externe Referenz      |
| Anlagedatum           | Vertrag: Startdatum                     |
| Auftr.-Status (Code)  | Vertrag: Status (siehe unten)          |
| Kunden                | Name + Geschlecht (Herr/Frau)          |
| Anschrift             | Strasse / Hausnr. / PLZ / Ort          |
| Geburtsdatum          | Kunde: Geburtsdatum                     |
| Telefonnummer         | Kunde: Telefon                          |
| Zaehlernummer         | Energie-Detail: Zaehlernummer          |
| Verbrauch             | Energie-Detail: Verbrauch (kWh)        |
| Verbrauch NT          | Notiz (nur falls > 0)                   |
| Tarif/Produkt         | Anbieter (`insurer`) + Tarif           |
| VAP-Datum / RL / RL-Datum / Wiederanschaltung | Vertragsnotiz          |
| Stornodatum           | Vertrag: Kuendigungsdatum              |

**Ignoriert** (Betreiber-Vorgabe): VP-Name, Auftr.-Statustext, VAP (Ja/Nein),
VP Nummer, UVP Nummer.

## Status-Abbildung (Auftr.-Status-Code -> Vertragsstatus)

- `7100` (verprovisioniert) -> **active**
- `< 7100` (in Bearbeitung/Kluerung, z. B. 3100/2500/500) -> **pending**
- `>= 9000` (jegliche Storno-Stufe) -> **cancelled**

## Nutzung auf dem Server

```
# CSV auf den Server laden (NICHT ins Repo committen - enthaelt Kundendaten!)
scp order.csv <server>:/var/www/dienstly24/portal/storage/app/

cd /var/www/dienstly24/portal
php artisan energie:import storage/app/order.csv --dry-run   # erst simulieren
php artisan energie:import storage/app/order.csv             # dann echt
rm storage/app/order.csv                                     # danach loeschen
```

Optionen: `--dry-run` (nur simulieren), `--limit=N` (nur N Zeilen, zum Testen).
