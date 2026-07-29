# Auftrag zuerst, Vertrag später – ein Vorgang, ein Vertrag

**Betreiber-Vorgabe 29.07.2026.** Im Alltag entsteht ein Vertrag in zwei
Schritten:

1. **Auftrag / Antrag** wird hochgeladen (z. B. der EWE-Strom-Auftrag, ein
   DSL-Auftrag, ein Privathaftpflicht-Antrag, ein Beratungsprotokoll). Er
   trägt schon viele Daten – aber **keine Bestätigung**: „Der Stromvertrag
   wird mit Erhalt der Vertragsbestätigung wirksam."
2. **Vertragsbestätigung / Police** kommt Wochen später beim Kunden an und
   wird ebenfalls hochgeladen. Erst sie bringt **Vertragsnummer**,
   **Kundennummer**, MaLo-ID, endgültigen Lieferbeginn, Laufzeitende und den
   festgelegten **Abschlag**.

Vorher entstanden daraus **zwei Verträge**, weil die harte Identitäts-Suche
(Vertragsnummer → FIN/Kennzeichen → MaLo/Zähler) beide Dokumente nicht
verbinden konnte: der EWE-Auftrag nennt **nur die Zählernummer**, die
Bestätigung **nur die MaLo-ID** – kein einziges gemeinsames Merkmal.

Jetzt erkennt das System denselben Vorgang und **ergänzt den vorhandenen
Vertrag**.

## Die Vertragsstufe (`contracts.stage`)

| Wert       | Bedeutung                                                        |
|------------|------------------------------------------------------------------|
| `antrag`   | Aus einem Auftrag/Antrag entstanden, wartet auf die Bestätigung   |
| `vertrag`  | Bestätigt (Police/Vertragsbestätigung liegt vor)                  |
| `null`     | Altbestand / manuell angelegt – **die Automatik fasst ihn nie an** |

In der Kundenakte, der Vertragsliste und auf der Bearbeiten-Seite steht bei
einem Antrag der Hinweis **„📝 Antrag – wartet auf Bestätigung"**. Im
Vertrags-Formular lässt sich die Stufe auch von Hand setzen – so wird auch ein
manuell erfasster Auftrag später automatisch vervollständigt.

## Woher weiß das System, was ein Dokument ist?

`Document::contractStageFor()` entscheidet in dieser Reihenfolge:

1. **Ausdrückliche Angabe der Extraktion** (`versicherung.document_stage`).
   Die Gratis-Parser setzen sie fest (EWE-Auftrag/DSL-Auftrag/PHV-Antrag =
   `antrag`, EWE-Vertragsbestätigung = `vertrag`), die KI liefert sie im JSON
   mit.
2. **Eindeutige Dokumenttypen**: Police, Versicherungsschein, KFZ-/E-Scooter-
   Vertrag = `vertrag`.
3. **Antrags-Typen** (Auftrag, Beratungsprotokoll, Beitrittserklärung …):
   **mit** Vertragsnummer = `vertrag`, **ohne** = `antrag`. So wird die
   EWE-Vertragsbestätigung erkannt, obwohl sie denselben Dokumenttyp
   `energieauftrag` trägt wie der Auftrag.
4. Sonst: eine Vertragsnummer belegt einen bestätigten Vertrag, ansonsten
   bleibt die Stufe offen (`null`) – die Automatik hält sich raus.

## Wann wird ein Antrag ergänzt statt verdoppelt?

`DocumentIntakeService::findApplicationContractForConfirmation()` ist bewusst
streng. Alle Bedingungen müssen erfüllt sein:

- Das neue Dokument ist eine **Bestätigung** (`stage = vertrag`).
- Der Bestandsvertrag hat die Stufe **`antrag`** (nie Altbestand/manuell).
- **Gleiche Sparte** – Strom und Gas vermischen sich nie (nur die
  Alt-Sammelsparte `strom_gas` gilt zusätzlich als passend).
- **Gleiche Gesellschaft** (`Contract::insurersLookAlike`). Ein anderer
  Versorger ist ein **Wechsel** und bekommt einen eigenen Vertrag.
- **Kein Widerspruch** in den harten Merkmalen: andere MaLo-ID, andere
  Zählernummer, andere FIN, anderes Kennzeichen → kein Treffer.
  (Die Nummer eines Antrags ist nur vorläufig – eine abweichende
  Vertragsnummer in der Bestätigung ist deshalb **kein** Widerspruch, sondern
  genau die erwartete endgültige Nummer.)
- Der Antrag ist **höchstens 12 Monate alt**.

Bleiben danach **mehrere** Anträge übrig, entscheidet ein zusätzliches Indiz
(gleicher Tarif/gleiches Fahrzeug). Bleibt es mehrdeutig, wird **nicht
geraten**: es entsteht ein eigener Vertrag, und der Mitarbeiter sieht beide.

## Was passiert bei der Übernahme?

- Endgültige **Vertragsnummer** ersetzt eine vorläufige Auftragsnummer
  (z. B. DSL), leere Felder werden ergänzt, **ein leerer neuer Wert löscht
  nie einen bestehenden**.
- Energie-Details: MaLo-ID, Kundennummer beim Versorger, Netzbetreiber,
  Arbeits-/Grundpreis, Abschlag + Zahlweise, Vorversorger. Die
  **Zählernummer aus dem Auftrag bleibt erhalten**.
- Die Stufe wandert `antrag → vertrag` – **nie zurück**.
- **Jede** Änderung steht feldgenau in der Version History
  (`contract_revisions`, Anzeige auf der Vertrags-Bearbeiten-Seite).
- Der Betreuer bekommt einen **Glocken-Hinweis** („Vertragsbestätigung
  übernommen …"), damit die Automatik sichtbar bleibt.
- Kein zweiter Vertrag heißt auch: **keine doppelte Vermittler-Provision**.

## Jedes weitere Dokument zum Vertrag

Nach der Bestätigung trägt der Vertrag alle Merkmale, mit denen spätere Post
automatisch zugeordnet wird (`findExistingContractByIdentity`):

- Vertragsnummer
- FIN / Kennzeichen (umlaut-tolerant normalisiert)
- MaLo-ID und **Zählernummer normalisiert**: auf dem Zähler steht
  „1 LOG00 9228 3078", im Auftrag „1LOG0092283078" – dieselbe Nummer. Ein
  **Zählerfoto** landet damit am richtigen Vertrag und trägt den Zählerstand
  nach.
- **Kundennummer beim Versorger** (nur bei derselben Gesellschaft – die
  Nummer ist nicht global eindeutig).

## Beispiel: die echten EWE-Dokumente

| Angabe             | Auftrag (05.05.2026) | Vertragsbestätigung (24.07.2026) |
|--------------------|----------------------|----------------------------------|
| Vertragsnummer     | –                    | 1004418075                       |
| Kundennummer       | –                    | 22434078                         |
| Zählernummer       | 1LOG0092283078       | –                                |
| MaLo-ID            | –                    | 50307481544                      |
| Lieferbeginn       | –                    | 28.07.2026 (Ende 27.07.2028)     |
| Abschlag           | – (Grundpreis 20,02) | 50,00 €/Monat                    |
| Tarif              | EWE Zuhause+ Grünstrom 24 | EWE Zuhause+ Grünstrom 24   |

Ergebnis: **ein** Vertrag mit allen Angaben aus beiden Dokumenten und einem
lückenlosen Änderungsverlauf.

## Tests

`tests/Feature/DocumentIntake/ContractConfirmationTest.php` (inkl. Wechsel,
Widerspruch, Mehrdeutigkeit, Alt-Antrag, Zählerfoto, Rechnung),
`tests/Feature/Ai/EnergieAuftragParserTest.php`,
`tests/Feature/Ai/EweVertragsbestaetigungParserTest.php`.
