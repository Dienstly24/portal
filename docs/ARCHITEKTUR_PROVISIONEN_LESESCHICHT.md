# Provisionen: eine Leseschicht, drei Fachbereiche (ARCH-3)

Stand 04.09.2026. Ergaenzt `docs/PROVISIONSMANAGEMENT.md`; die dortigen
Regeln gelten unveraendert weiter.

## Die Frage

Das Portal fuehrt Provisionen an drei Stellen. Der Auftrag war zu pruefen,
ob das eine Doppelung ist - und die Doppelung zu beseitigen, **ohne** die
Fachbereiche zusammenzuzwingen.

## Die Antwort: verschiedene Tatsachen, gleiche Fragen

Die drei Tabellen beschreiben **verschiedene** Dinge:

| Tabelle | Was sie beschreibt | Richtung |
|---|---|---|
| `provisions` | Was wir eigenen Mitarbeitern und Partnern zahlen | **Ausgang** |
| `contract_commissions` | Was Pools uns zahlen - beliebig viele Quellen, fremde Spalten, mehrere Kennungen | **Eingang** |
| `vermittler_settlements` | Der EINE Vermittler (TARIFCHECK24), festes Format, eine Kennung | **Eingang** |

Sie in eine Tabelle zu zwingen hiesse, eine der drei Wahrheiten zu
verbiegen. Zwei Beispiele, die das konkret machen:

- `vermittler_settlements` traegt bewusst eine **Klartext-Kopie** von
  Vertrag und Kunde und ueberlebt deshalb das Loeschen des Vertrags. Bei
  einer Rueckfrage zu einem Storno ist damit belegbar, dass der Vertrag
  existierte. In der Pool-Tabelle gaebe es diese Kopie nicht.
- `provisions` entsteht beim **Verkauf** und folgt dem rohen Vertragsstatus;
  `contract_commissions` entsteht beim **Geldeingang**, Monate spaeter.
  Ein gemeinsamer Status muesste beides bedeuten und wuerde beides
  ungenau treffen.

Doppelt war nicht die Speicherung, sondern das **Lesen**: jede Auswertung
hat sich ihre Summen selbst zusammengesucht.

## Was gebaut wurde

`App\Services\Commission\CommissionReadService` - ausschliesslich lesend.
Geschrieben wird weiterhin ueber die jeweiligen Fachdienste.

- `CommissionSource` - Schnittstelle je Quelle (`key`, `label`,
  `direction`, `entries`).
- Drei Adapter unter `Sources/` - einer je Tabelle.
- `CommissionEntry` - schmales Lese-Objekt: Datum, Betrag, Waehrung,
  **Richtung**, Zustand *in der Sprache der Quelle*, Vertrag, Kunde,
  Gegenpartei, Kennung.
- `CommissionQuery` - die Filter, die **alle** Quellen verstehen
  (Zeitraum, Vertrag, Kunde, Auswahl der Quellen, Grenze). Was nur EINE
  Quelle kann - Storno-Grund des Vermittlers, Faelligkeitsstufe des
  Maklerpools - gehoert bewusst **nicht** hierher, sondern bleibt auf der
  Fachseite dieser Quelle.

Neu moeglich ist damit die Frage, fuer die es bisher keinen Weg gab:
**alle Buchungen zu EINEM Vertrag** (`forContract`). Ein Vertrag kann
gleichzeitig eine Ausgangsprovision an den Werber, eine Abrechnung des
Maklerpools und eine Zeile beim Vermittler haben - wer das sehen wollte,
musste drei Seiten oeffnen.

### Zwei Regeln, die der Dienst erzwingt

**Eingang und Ausgang werden nie zu einer Zahl.** `totals()` liefert beide
Werte getrennt und bewusst keine Gesamtsumme. Die Ausgangsprovision eines
Vertrags und die Eingangsprovision desselben Vertrags fallen Monate
auseinander, und beide Seiten sind unvollstaendig, solange nicht alle
Pools abgerechnet haben. Eine Differenz daraus waere ein "Gewinn", der
keiner ist. Wer sie sehen will, bildet sie ausdruecklich - und weiss dann,
was er da rechnet. Ein Test haelt das fest.

**Der Dienst ist keine Berechtigungsschicht.** Hier stehen Betraege; der
Aufrufer muss das Recht `provisionen-verwalten` bereits geprueft haben.
Die Pruefung an Route und Controller bleibt, wo sie ist.

## Die Protokolltabellen bleiben getrennt

Geprueft wurde auch, ob `provision_audit_logs` und `commission_audit_logs`
dasselbe meinen. **Sie tun es nicht** - deshalb wurden sie nicht
zusammengelegt:

1. `provision_audit_logs.provision_id` ist **nicht nullbar**: jeder Eintrag
   haengt an genau einer Provision. `commission_audit_logs` protokolliert
   auch Vorgaenge **ohne** einzelne Buchung - einen Datei-Import etwa.
2. Nur das Provisions-Protokoll verlangt eine **Begruendung** (`reason`);
   eine Betragsaenderung ohne Grund soll es dort nicht geben.
3. Nur das Pool-Protokoll kennt **Datei und Importlauf**
   (`source_file`, `import_id`) - die Herkunft bis auf die Zeile.
4. Nur das Pool-Protokoll haelt eine **Klartext-Kopie des Handelnden**
   (`user_label`) fest: es soll das Loeschen des Benutzerkontos ueberleben.

Eine gemeinsame Tabelle muesste `provision_id` nullbar machen und
`reason` optional - genau die beiden Zusagen, wegen derer das
Provisions-Protokoll existiert. Der Test
`CommissionReadServiceTest::test_die_beiden_protokolle_haben_unterschiedliche_bedeutung`
haelt diese Begruendung an den Daten fest, damit sie nicht nur eine
Behauptung im Commit ist.

## Wenn eine vierte Quelle dazukommt

Adapter schreiben (`CommissionSource` implementieren), im
`AppServiceProvider` registrieren. Berichte, die ueber den Lesedienst
gehen, kennen sie danach automatisch. Die bestehenden Fachseiten und
Import-Wege bleiben unberuehrt.
