# Navigation der Beraterwelt - Informationsarchitektur (03.09.2026)

## Warum umgebaut wurde

Die Seitenleiste war ueber Monate gewachsen: **8 Gruppen mit 31 flachen
Punkten**, alle offen. Jeder neue Bereich wurde unten angehaengt. Die Folgen
waren nicht kosmetisch:

- **Ein Vorgang lag an drei Orten.** `Provisionen`, `Vermittler-Abrechnung`
  und `Provisionsmanagement` waren drei Hauptpunkte fuer EINE Frage
  ("bekommen wir unser Geld?"). Wer sie nicht auswendig kannte, klickte
  alle drei durch.
- **Dieselbe Nachricht lag in zwei Gruppen.** `Kundenkommunikation` und
  `Tickets` unter "Kommunikation", `Posteingang` unter "E-Mail" - ein
  Mitarbeiter musste WISSEN, ueber welchen Kanal ein Kunde geschrieben hat,
  bevor er nachsehen konnte.
- **Technik stand mitten im Arbeitsweg.** `Systemzustand`, `Fehler`,
  `Aktivitaetslog` ruft man ein paar Mal im Monat auf; sie standen jeden Tag
  zwischen den Punkten, die man staendig braucht.
- **Aktionen standen zwischen Orten.** "Verfassen" ist kein Bereich,
  sondern ein Knopf.
- **Badges waren Dekoration.** Gezaehlt wurden auch "37 aktive
  Ankuendigungen" und ALLE offenen Aufgaben (auch die fuer naechsten Monat).
  Wenn dauerhaft acht Zahlen leuchten, sieht niemand mehr die eine, die
  wirklich etwas verlangt.

Leitsatz des Umbaus: **einfach -> strukturiert -> vorhersehbar ->
professionell.** Die Reihenfolge der Bereiche IST die Information.

## Die neue Struktur

```
● Dashboard                                (immer sichtbar, nie zugeklappt)

▾ POSTFACH            offen     alles, was von aussen hereinkommt
    Kundenchat                  🔴 ungelesene Kundennachrichten
    Tickets                     🟡 neue, nicht uebernommene Tickets
    Anfragen
    E-Mail                      🟡 Zuordnung zu bestaetigen
        └ Registerkarten: Posteingang | Verfassen | Vorlagen | Postfach-Konten
    Team-Chat                   🔴 ungelesen

▾ MEIN TAG            offen     der Arbeitstag
    Aufgaben                    🟡 HEUTE faellig oder ueberfaellig
    Termine                     🟡 heutige Termine

▾ KUNDEN              offen     der CRM-Kern in Arbeitsreihenfolge
    Kunden                      └ Dubletten, "Kinder werden 15"
    Interessenten
    Vertraege
    Aenderungsantraege          🟡 wartende Freigaben

▾ DOKUMENTE           offen
    Eingang                     🟡 nicht zugeordnet
    Anforderungen               🟡 hochgeladen, zu pruefen

▸ VERTRIEB            zu        Steuerung, kein Tagesgeschaeft
    Provisionen                 🟡 zu pruefen
        └ Registerkarten: Dashboard | Importe | Abrechnungen | Buchungen |
          Vertraege | Fehlende | Unklare | Auswertungen | Einstellungen |
          Auszahlungen an eigene Vermittler | TARIFCHECK24-Abgleich
    Partner
    Vergleichsportale
    Berichte

▸ MARKETING           zu        alles nach aussen Sichtbare
    Website-Medien | Newsletter | Ankuendigungen |
    Banner | Werbeanzeigen | Leistungsseiten        (die letzten drei: admin/manager)

▸ ADMINISTRATION      zu        Technik & Konfiguration (nur admin/manager)
    Mitarbeiter | Zeiten & Aktivitaet | Aktivitaetslog | KI-Wissensbasis |
    Systemzustand | Fehler | Import / Export | Einstellungen (nur admin)
```

Ohne Zutun sichtbar: **Dashboard + 13 Punkte** statt bisher 31.

## Die Regeln dahinter

1. **Reihenfolge = Haeufigkeit.** Taegliche Arbeit oben und offen, Steuerung
   darunter und zu, Technik ganz unten und zu.
2. **Ein Vorgang, ein Ort.** Was zusammen bearbeitet wird, ist EIN Modul mit
   Registerkarten - keine Geschwisterpunkte in der Seitenleiste.
3. **Orte in die Navigation, Aktionen ins Modul.** "Verfassen" ist eine
   Registerkarte des E-Mail-Moduls, "Kinder werden 15" ein Knopf an der
   Kundenliste.
4. **Ein Badge ist eine Aufforderung, keine Statistik.** Gezaehlt wird nur
   Unerledigtes mit Faelligkeit. Zwei Toene, mehr waere keine Rangfolge:
   **Gold** = wartet auf uns, **Rot** = ein Mensch wartet auf Antwort.
   Zugeklappte Gruppen zeigen die SUMME - zuklappen darf nichts verstecken.
5. **Kein Punkt fuehrt in ein 403.** Die Sichtbarkeit spiegelt die Route
   (ein Test prueft das fuer jede Rolle). Sie ist aber nie der Schutz
   selbst - der bleibt an Middleware und Gate.
6. **Zusammenlegen heisst nie loeschen.** Jeder Bereich, der seinen
   Hauptpunkt verloren hat, hat einen sichtbaren Weg bekommen (Test
   `test_zusammengelegte_bereiche_bleiben_erreichbar`).

## Aufbau im Code

| Datei | Aufgabe |
|---|---|
| `app/Support/Navigation/AdminNavigation.php` | **Die eine Quelle der Struktur.** Gruppen, Reihenfolge, Rollen, Vorgabe offen/zu. |
| `app/Support/Navigation/NavGroup.php` | Gruppe: aktive Seite haelt sie offen, Summe der Badges. |
| `app/Support/Navigation/NavItem.php` | Punkt: Ziel, Aktiv-Muster, Badge, Ton. |
| `app/Support/Navigation/NavBadges.php` | Die Zahlen - je Aufruf genau einmal ermittelt, Portfolio-Scope wie ueberall. |
| `app/Support/Navigation/NavIcons.php` | SVG-Pfade, damit die Struktur lesbar bleibt. |
| `resources/views/components/admin/sidebar-nav.blade.php` | Rahmen |
| `resources/views/components/admin/nav-group.blade.php` | Gruppe (Aufklapp-Knopf, aria) |
| `resources/views/components/admin/nav-item.blade.php` | Punkt (Icon, Label, Badge) |

Einen Bereich hinzufuegen heisst: **eine Zeile in `AdminNavigation`** - und
man sieht dabei die ganze Struktur. Vorher lagen Rolle, Zaehler, Icon und
Aktiv-Muster je Punkt ineinander im Layout.

### Aktiver Zustand

`NavItem::isActive()` fragt `request()->routeIs(...)` mit den Mustern des
Punktes - ein Unterpfad (`admin.customer.show`) markiert weiterhin "Kunden".
Der aktive Punkt bekommt `aria-current="page"` und die Akzentkante.

### Zugeklappt/aufgeklappt merken

- Der Server rendert den Vorgabe-Zustand (`openByDefault`) - die Leiste
  springt beim Laden nicht.
- Danach ueberschreibt der gemerkte Zustand des Nutzers
  (`localStorage`, Schluessel `nav-group:<key>`). Gemerkt werden **beide
  Richtungen** (`1` zu, `0` offen): nur "zugeklappt" zu speichern hiesse,
  dass ein bewusst geoeffneter Bereich beim naechsten Aufruf wieder zufaellt.
- Die Gruppe der **aktiven Seite** bleibt immer offen (`data-has-active`) -
  ein aktiver Punkt, den man nicht sieht, waere schlimmer als eine Gruppe
  zu viel.

## Tests

`tests/Feature/AdminNavigationTest.php` haelt die Entscheidungen fest, die
man einer fertigen Navigation nicht mehr ansieht: Reihenfolge und
Vorgabe-Zustand, Technik nur in der Administration, die drei
Provisions-Bereiche als EIN Punkt, Badge-Regel, Rollen ohne 403 und die
Erreichbarkeit der zusammengelegten Bereiche.
