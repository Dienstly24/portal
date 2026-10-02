# KFZ-Fachregeln

Jede fachliche KFZ-Regel des Systems steht hier mit Standardwert, Quelle,
Pruefstatus und Ort der Konfiguration (Betreiber-Auftrag 01.10.2026).

**Grundsaetze**

- Keine Regel wird geraten. Ist eine Rechtsquelle nicht am Wortlaut
  geprueft, steht sie als **zu pruefen** da - der Standardwert gilt dann
  nur als Vorschlag bzw. Warnung, nie als stille Entscheidung.
- Rangfolge der Quellen: **AKB des jeweiligen Versicherers** vor den
  **GDV-Musterbedingungen (AKB)** vor VVG/BGB-Standardwerten. Eine
  Abweichung je Versicherer wird konfiguriert, nicht in den Code geschrieben.
- Die technische Quelle ist `config/kfz_rules.php`. Jede Regel-ID dort muss
  in dieser Datei stehen - `tests/Unit/KfzSfRegelnTest.php` prueft das.
- Status: `geprueft` (am Wortlaut belegt), `zu_pruefen` (Standardwert,
  Wortlaut-Abgleich ausstehend), `intern` (Fachlogik des Betriebs, keine
  Rechtsregel).
- Offener Abgleich: der Betreiber liefert die GDV-Musterbedingungen AKB und
  die AKB der wichtigsten Versicherer als PDF nach; dann werden die Ziffern
  eingetragen und die Regeln auf `geprueft` gesetzt.

## Phase 1 - SF-Sondereinstufung mit Bezugsfahrzeug (umgesetzt)

| ID | Regel | Standard | Quelle | Status | Konfiguration |
|---|---|---|---|---|---|
| `SF-VORSCHLAG-FUEHRERSCHEIN` | Vorschlag fuer die tatsaechliche (uebertragbare) SF-Klasse einer Sondereinstufung nach Fuehrerscheindauer. Nur Vorschlag im Formular, nie automatisch gespeichert. | Fuehrerschein >= 3 Jahre -> SF 1/2, sonst SF 0; Stichtag heute, ganze Jahre ab Fuehrerscheindatum | GDV-Musterbedingungen AKB, Anhang "Einstufung in Schadenfreiheitsklassen" (Ersteinstufung SF 1/2 bzw. SF 0) - Ziffer zu pruefen; AKB des Versicherers vorrangig | zu pruefen | `config/kfz_rules.php` -> `rules.SF-VORSCHLAG-FUEHRERSCHEIN.werte` |
| `SF-BEZUG-GRUENDE` | Welche Gruende einer Sondereinstufung ein Bezugsfahrzeug (Erstwagen) brauchen | Zweitwagenregelung, Drittwagenregelung, Uebernahme innerhalb der Familie | Interne Fachlogik | intern | `config/kfz_rules.php` -> `rules.SF-BEZUG-GRUENDE.werte.gruende` |
| `SF-BEZUG-PFLICHT` | Fehlender Bezug bei diesen Gruenden: immer Warnung; blockierend nur mit Einstellung und nur bei Stufe Antrag/Vertrag | AUS (nur Warnung) | Interne Fachlogik | intern | Einstellungen -> KFZ, SystemSetting `sf_reference_required_on_submit` |
| `SF-BEZUG-BENACHRICHTIGUNG` | Wann der Betreuer eines Zweitwagens benachrichtigt wird (Glocke + eine offene Aufgabe) | Rueckstufung des Erstwagens, Erstwagen gekuendigt/nicht mehr aktiv, Erstwagen geloescht. Die jaehrliche Hoeherstufung loest NICHTS aus. | Interne Fachlogik | intern | `config/kfz_rules.php` -> `rules.SF-BEZUG-BENACHRICHTIGUNG` |

**Bewusst NICHT als Regel umgesetzt**: versichererspezifische
Zweitwagenregelungen (welche Klasse ein Zweitwagen bei welchem Versicherer
bekommt, Mindest-SF des Erstwagens, Halterbedingungen). Sie unterscheiden
sich je Versicherer und Tarif; das System erfasst die GEWAEHRTE Einstufung
und ihren Bezug, es errechnet sie nicht.

**SF-Rangfolge** (keine Rechtsregel, sondern Ordnung der Klassen fuer
"Rueckstufung ja/nein"): M < S < 0 < 1/2 < 1 < 2 < ... < 50 -
`ContractVehicleDetail::sfRank()`.

## Phase 2 - Vertragsjahr, Fristen, SF-Entwicklung (geplant, noch nicht im Code)

Mit dem Betreiber abgestimmt (01.10.2026). Ziffern werden mit den
gelieferten AKB eingetragen; bis dahin `zu pruefen`.

| ID (geplant) | Regel | Standard | Quelle | Status |
|---|---|---|---|---|
| `VERTRAGSJAHR-MODUS` | Versicherungsjahr = 12 Monate ab Vertragsbeginn (abweichendes Versicherungsjahr); Kalenderjahr (Ablauf 31.12.) je Vertrag oder als Standard je Versicherer | abweichend | AKB des Versicherers | zu pruefen |
| `KUENDIGUNG-ORDENTLICH` | Kuendigung muss dem Versicherer spaetestens 1 Monat vor Ablauf ZUGEGANGEN sein; Monatsrechnung nach BGB, keine Verschiebung auf Werktage | 1 Monat; Referenzfall: Ablauf 31.12. -> Eingang bis 30.11. | GDV-Musterbedingungen AKB (Abschnitt Vertragsdauer/Kuendigung - Ziffer zu pruefen); §§ 187, 188 BGB | zu pruefen |
| `KUENDIGUNG-MONATSENDE` | Beginn am 29./30./31., Februar, Schaltjahr: bei mehrdeutigem Monatsende gilt das FRUEHERE Datum, Vermerk "zu pruefen" | frueheres Datum | § 188 Abs. 3 BGB (analog, Rueckwaertsrechnung) | zu pruefen |
| `KUENDIGUNG-INTERNE-FRIST` | Interne Frist = rechtliche Frist minus Puffer in Werktagen (Mo-Fr ohne bundesweite Feiertage und Feiertage des konfigurierten Bundeslands, Standard HH inkl. Reformationstag 31.10.) | 5 Werktage, Bundesland HH | Interne Fachlogik | intern |
| `SONDERKUENDIGUNG-BEITRAG` | 1 Monat ab Zugang der Beitragsrechnung/Aenderungsmitteilung; nicht bei Rueckstufung nach eigenem Schaden, Umzug (Regionalklasse) oder vom Kunden veranlasster Aenderung; "versteckte Erhoehung" nur zur manuellen Pruefung | 1 Monat | GDV-Musterbedingungen AKB (Beitragsaenderung - Ziffer zu pruefen); § 40 VVG | zu pruefen |
| `SONDERKUENDIGUNG-SCHADEN` | 1 Monat ab Regulierungsentscheidung (Zahlung oder Ablehnung) | 1 Monat | GDV-Musterbedingungen AKB (Kuendigung nach Schadenfall - Ziffer zu pruefen); § 111 VVG (Haftpflicht) | zu pruefen |
| `HALTERWECHSEL` | Erwerber kann binnen 1 Monat kuendigen; Veraeusserer hat kein Sonderkuendigungsrecht; Erbfall kein Sonderkuendigungsrecht | 1 Monat (Erwerber) | §§ 95, 96 VVG; AKB | zu pruefen |
| `AUSSERBETRIEBSETZUNG` | Ruheversicherung (Status + Enddatum) je Versicherer | je Versicherer | AKB des Versicherers | zu pruefen |
| `SF-HOEHERSTUFUNG` | Vorschlag zur Hoeherstufung zur Hauptfaelligkeit bei schadenfreiem Jahr; Mindestbestand im Kalenderjahr | 6 Monate | GDV-Musterbedingungen AKB, Anhang SF-System - Ziffer zu pruefen; Versicherer-AKB vorrangig | zu pruefen |
| `SF-RUECKSTUFUNG` | Rueckstufung nur ueber importierte, versionierte Tabelle je Versicherer; ohne Tabelle keine Berechnung, sondern Aufgabe | keine Tabelle | AKB des Versicherers (Rueckstufungstabelle) | zu pruefen |

## Phase 3 - Wechsel-Erinnerung (geplant, noch nicht im Code)

| ID (geplant) | Regel | Standard | Quelle | Status |
|---|---|---|---|---|
| `WECHSEL-ZUSTIMMUNG` | Versand nur mit gespeicherter Annahme der Nutzungsbedingungen (`terms_accepted_at` + Version) und ohne Abmeldung; `marketing_consent` zaehlt NICHT. Sonst Anruf-Aufgabe, nur fuer Vertraege im erreichten Erinnerungsfenster | - | Betreiber-Entscheidung 01.10.2026; rechtliche Einordnung (UWG § 7) beim Anwalt | intern |
| `WECHSEL-STUFEN` | 1. Erinnerung 10 Wochen vor Ablauf, 2. Erinnerung 6 Wochen vor Ablauf, letzte 7 Tage vor interner Frist (nur Pipeline "offen"), sofort bei Beitragsrechnung mit Sonderkuendigungsrecht | 10 W / 6 W / 7 T | Interne Fachlogik | intern |
