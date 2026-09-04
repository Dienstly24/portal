# Wann ein eigener Parser - und wann nicht (ARCH-8)

Stand 04.09.2026. Architektur-Entscheidung fuer den Dokumenten-Eingang.

## Was NICHT passiert

Die 41 vorhandenen Vorlagen-Parser werden **nicht** entfernt und **nicht**
umgeschrieben. Sie sind der Grund, warum die Analyse "kostenlos zuerst"
funktioniert: ein erkanntes Formular kostet nichts, ist deterministisch und
liefert bessere Felder als jede KI-Antwort. Sie zusammenzustreichen, nur um
die Dateizahl zu senken, wuerde Genauigkeit gegen Aufraeum-Gefuehl tauschen.

Das Problem ist auch nicht die heutige Zahl, sondern das **unkontrollierte
Wachstum**: ohne Regel entsteht fuer jedes einzelne Dokument, das einmal
falsch gelesen wurde, ein 42., 43., 44. Parser - und jeder davon laeuft ab
dann auf **jedem** hochgeladenen Text mit.

## Die Regel

Ein **eigener Parser** ist gerechtfertigt, wenn **alle fuenf** Punkte
zutreffen:

1. **Menge** - das Formular kommt regelmaessig, nicht einmalig.
2. **Stabiles Format** - es stammt aus einer Software (Versicherer-Portal,
   Maklerpool, Vergleichsrechner) und sieht bei jedem Kunden gleich aus.
   Ein frei getipptes Schreiben erfuellt das nie.
3. **Bezahlbar** - die Regeln lassen sich aus Beschriftungen ableiten. Wer
   drei Sonderfaelle braucht, um ein Feld zu treffen, beschreibt kein
   Format, sondern ein Exemplar.
4. **Genauigkeit zaehlt** - die gelesenen Felder gehen in die Kundenakte
   (Bankverbindung, Vertragsnummer, Beitrag), nicht nur in eine
   Zusammenfassung.
5. **Es rechnet sich** - der Parser spart je Dokument einen KI-Aufruf und
   verhindert Nacharbeit. Bei einem Dokument im Jahr tut er das nicht.

**Trifft auch nur einer nicht zu**, laeuft das Dokument ueber den normalen
Weg: Textebene/OCR -> Heuristik -> KI-Eskalation. Das ist kein
Notbehelf, sondern der vorgesehene Pfad fuer alles Seltene und Variable.

### Die Gegenprobe

> *Wuerde ich diesen Parser auch schreiben, wenn dieses eine Dokument gar
> nicht auf meinem Tisch laege?*

Wenn nein, ist er eine Reaktion auf einen Einzelfall. Dann gehoert der
Einzelfall in einen **Test des bestehenden** Weges, nicht in eine neue
Klasse.

## Was fuer JEDEN Weg gilt

Gleich ob Parser, Heuristik oder KI - das Ergebnis muss dieselbe Form
haben und dieselbe Pruefung durchlaufen, **bevor** etwas gespeichert wird:

- **Feste Form**: `array{type, confidence, summary, title, data}` laut
  `App\Services\Ai\Contracts\DocumentTemplateParser`.
- **Gemeinsame Pruefung**: `App\Services\Ai\Concerns\ValidatesExtractedFields`.
  Dort liegen die Regeln, die fuer alle Quellen gelten muessen - IBAN nur
  mit gueltiger Pruefziffer, Datumsgrenzen, und die EINE Trennregel fuer
  Strasse/Hausnummer.

Diese gemeinsame Pruefung ist der eigentliche Grund, warum die Zahl der
Parser nicht gefaehrlich ist: sie alle muenden in denselben Trichter.
Genau das war die Lehre vom 28.08.2026 - die Hausnummern-Regel lag in
jedem Parser einzeln, die KI-Antwort hatte sie gar nicht, und in der Akte
fehlte die Hausnummer. Seitdem liegt sie an der Stelle, durch die **jede**
Quelle laeuft, und nicht im 25. Parser.

Ein Parser, der diese Pruefung umgeht, ist deshalb kein
Geschmacksunterschied, sondern ein Loch. `ParserPolicyTest` haelt das
fest: jeder Parser, der Felder liefert, muss den gemeinsamen Baustein
benutzen und registriert sein.

## Ein neuer Parser - Checkliste

1. Fuenf Punkte oben pruefen. Nicht alle erfuellt? Dann keinen bauen.
2. `DocumentTemplateParser` implementieren, `ValidatesExtractedFields`
   benutzen.
3. **Erkennung streng halten.** Ein Parser laeuft auf jedem Dokument mit;
   ein Fehlalarm haelt ein echtes Kundendokument von seiner Akte fern.
   Lieber einmal nicht erkennen als einmal falsch zuordnen. Im Zweifel
   `null` zurueckgeben - dann liest die KI das Dokument vollstaendig,
   statt mit einer fast leeren Akte zu "gewinnen".
4. In `AppServiceProvider` registrieren (Reihenfolge = Vorrang).
5. Test mit echtem Text anlegen - **auch** einen Fall, in dem er nicht
   greifen darf.
6. Nie raten: ein unsicheres Feld gehoert in die Zusammenfassung, nicht
   in die Akte.

## Wenn die Zahl doch stoert

Dann ist die Frage nicht "wie werden es weniger Dateien", sondern
**welche Parser tragen nichts mehr bei**. Das ist messbar - ueber die
Trefferquote je Parser im Betrieb. Ein Parser, der ein Jahr lang nicht
angeschlagen hat, kann weg; einer, der taeglich greift, spart taeglich
Geld. Ohne diese Messung ist jede Zusammenlegung geraten.
