# Bedienung professionell und eindeutig (Betreiber-Auftrag 09.10.2026)

Ziel: die Bedienung im GESAMTEN System eindeutig und kontrollierbar machen -
zentral gebaut (gemeinsame Bausteine), nicht je Seite geflickt.

## 1. Was gebaut wurde

| Bereich | Loesung | Dateien |
|---|---|---|
| Checkbox/Radio als grauer Balken | `.field input` schliesst Checkbox/Radio aus (`:where()`, Spezifitaet unveraendert); eigene feste Gestalt 20 px, gruen + ✓ wenn gewaehlt, sichtbarer Fokusring. Beschriftung daneben in einer Zeile, ganze Zeile klickbar. | `resources/css/components.css`, `resources/css/bedienung.css` |
| Auswahlkarten | `.wahl` (Zeile/Karte, gruen wenn gewaehlt). Herkunfts-Karten tragen zusaetzlich ein ✓. | `bedienung.css`, `partials/contract_origin_fields` |
| Ja/Nein | `.segment` (Router "Mit / Ohne Router") und `.schalter` (Toggle) | `bedienung.css`, `partials/contract_form_fields` |
| Abgeschnittene Popover | Offene Popover stehen `position:fixed` am Ausloeser (ausserhalb jedes `overflow:hidden`), Hoehe auf den freien Platz begrenzt; `data-pop="dialog"` = mittiger Dialog mit Abdunkelung, `max-height:80vh`, Speichern/Abbrechen immer sichtbar. Esc und Klick ausserhalb schliessen. Gilt automatisch fuer `details.pop`, `details.bz-pick`, `details[data-pop]` und die Zeilenmenues `[data-menu-panel]`. | `resources/js/ui.js` (Abschnitt 7), `partials/beziehung_festlegen` |
| Datum TT.MM.JJJJ | Jedes `<input type="date">` bekommt ein Textfeld mit Maske davor (Punkte automatisch, Backspace normal, Einfuegen tolerant: `1.1.1998`, `01011998`, `01/01/1998`, `1998-01-01`, `1.1.98`), Pruefung (kein 31.02.), Meldung am Feld, Kalender-Knopf 📅. Das ORIGINALFELD bleibt das Formularfeld - Speicherformat ISO unveraendert, "Heute"-Knoepfe und Ablauf-Automatik arbeiten weiter. Opt-out je Feld: `data-datum-nativ`. | `resources/js/datum.js`, `resources/js/datum-logik.js`, `tests/js/datum-logik.test.js` |
| Fehler am Feld | Layouts legen die Server-Meldungen als JSON ab; `ui.js` markiert das Feld rot, schreibt den Text darunter und springt zum ersten Fehler. | `partials/feldfehler`, `ui.js` (Abschnitt 8) |
| Ungespeicherte Aenderungen | `form[data-aenderungen-warnen]`: Warnung beim Verlassen + Hinweis "● Ungespeicherte Änderungen" in der Aktionsleiste. Bewusst nur an grossen Bearbeiten-Formularen (Vertrag anlegen/bearbeiten, Kunde anlegen/bearbeiten). | `ui.js` (Abschnitt 9) |
| Speichern immer erreichbar | `.aktionsleiste` (sticky unten) an denselben Formularen; primaere Aktion gruen. | `bedienung.css` |
| Aktive Zustaende | Reiter der Beraterwelt (`.tab-row .tab.active`) und Faelligkeits-Filter der Aufgaben gruen statt graphit. | `layouts/admin`, `admin/tasks` |
| Vertragsherkunft aendern | Speichern gesperrt, bis die Bestaetigung angehakt ist; Hinweis neben dem Knopf, was fehlt. Erfolgsmeldung "Vertrag aktualisiert." wie bisher; Protokoll (ActivityLog `contract_origin_changed` mit Benutzer + Zeitpunkt, Version History mit `changed_by`) war bereits vorhanden und ist durch `VertragsherkunftTest` belegt. | `partials/contract_origin_fields` |
| Aufgaben: Massenaktionen | Checkbox je Aufgabe, "Alle auf dieser Seite", "Alle X Treffer des Filters"; Leiste mit Erledigt, Status, Verschieben, Zuweisen, Löschen. EIN Request, wenige Abfragen; Berechtigung als Bedingung in der Abfrage. Bestaetigung bei Loeschen und ab 50 Aufgaben; "Rückgängig" 15 Minuten. | `TaskController::bulk/bulkUndo`, `admin/tasks`, `AufgabenSammelaktionTest` |
| Geburtsort optional | Validierung `nullable` (Beraterwelt + Portal), kein Sternchen/`required` mehr. Spalte war bereits nullable - keine Migration. Leere Werte: Kundenakte zeigt den Geburtsort nicht an, Exporte/PDF fuehren ihn nicht - nichts bricht. | `AdminController`, `PortalController`, `customer_edit`, `portal/profile`, `GeburtsortOptionalTest` |
| Grid-Ueberlauf | `.grid-2 > *, .grid-3 > * { min-width: 0 }` - Diagramme drueckten die Ticket-Statistik ueber den Rand. | `components.css` |

Nebenbei behoben: `?tab=<unbekannt>` auf `/admin/tasks` zeigte ALLE Aufgaben,
auch fremde (jetzt Rueckfall auf "Meine Aufgaben", Test); "Heute +N"-Knoepfe
im Aufgabenformular rechneten ueber `toISOString()` in UTC und lieferten kurz
nach Mitternacht den Vortag.

## 2. Woher die vielen automatischen Aufgaben kommen (nur Bericht, nichts geaendert)

Gemessen am Code. Jede automatisch erzeugte Aufgabe traegt `type = email` und
`email_message_id`; der Anteil laesst sich auf dem Server so zaehlen:

```
php artisan tinker --execute="dump(App\Models\Task::where('status','!=','done')->selectRaw('title, count(*) n')->groupBy('title')->orderByDesc('n')->limit(20)->pluck('n','title')->all());"
```

| Quelle | Aufgabe | Befund |
|---|---|---|
| `EmailWorkflowService::dispatchAction` | "Eingereichtes Dokument manuell zuordnen" (Kategorie `dokumente`, kein bestaetigter Kunde) | Wird beim manuellen Zuordnen der Mail (`EmailInboxController::assign`) **nicht** geschlossen - die Aufgabe bleibt offen, obwohl die Arbeit erledigt ist. Wahrscheinlich groesster Posten. |
| dto. | "E-Mail manuell prüfen" (jede nicht erkannte Kategorie) | Entsteht fuer JEDE nicht klassifizierte Mail - auch Newsletter, Benachrichtigungen, Spam. |
| dto. | "Dokument/Information für Versicherung prüfen" / "Energievertrag-Hinweis prüfen" | Eine Aufgabe je Mail; mehrere Mails desselben Absenders/Vorgangs = mehrere Aufgaben. |
| dto. | alle | Bei Status "Vorschlag" (Kunde nur vermutet) wird die Aufgabe OHNE Kunde angelegt und dem Systembenutzer zugewiesen - sie erscheint dann nicht unter "Kunden-Aufgaben" und bei keinem Betreuer. |
| `FondsFinanzImportService::createTask` | "Fonds-Finanz-Mail bearbeiten: …", "Fonds-Finanz-Kunde … manuell zuordnen", "Fonds-Finanz-Dokument manuell zuordnen" | Eine Aufgabe je Mail ohne Zuordnung; Faelligkeit 3 Tage - deshalb so viele ueberfaellig. |

**Vorschlaege** (zur Entscheidung):
1. Beim Zuordnen einer Mail zu einem Kunden die offenen "… manuell zuordnen"-Aufgaben DIESER Mail automatisch erledigen (mit Vermerk).
2. Duplikate vermeiden: offene Aufgabe gleichen Titels + gleicher Absender/Kunde in den letzten X Tagen wird ergaenzt statt neu angelegt (wie beim KI-Assistenten fuer Vorgaenge).
3. "E-Mail manuell prüfen" nur fuer Mails mit Kundenbezug oder Anhang; reine Benachrichtigungen ohne Aufgabe.
4. Aufgaben ohne Kunde nicht dem Systembenutzer, sondern einer festen Rolle/Queue zuweisen (z. B. Support), damit sie jemand sieht.
5. Einmalige Bereinigung des Bestands mit der neuen Sammelaktion (Filter "Überfällig" + Suche "manuell zuordnen" -> Alle Treffer -> Erledigt; 15 Minuten Rückgängig).

## 3. Weitere Pflichtfelder, die im Alltag oft unbekannt sind (Liste zur Entscheidung - nicht geaendert)

| Formular | Feld | Regel heute |
|---|---|---|
| Kunde bearbeiten (Beraterwelt) | Nationalität | `sometimes|required` + Sternchen |
| Kundenportal Profil | Nationalität | `sometimes|required` + Sternchen |
| Kundenportal Profil | Geburtsdatum | `sometimes|required` |
| Kundenportal Profil | Strasse, Hausnummer, PLZ, Ort | `sometimes|required` (Hausnummer fehlt z. B. bei Hofnamen/Postfach) |
| Kunde bearbeiten / anlegen | Vorname, Nachname | Pflicht (sinnvoll) |

## 4. Systemweiter UX-Check (Ergebnis)

Messverfahren: alle 84 Admin-Seiten ohne Pfad-Parameter plus Kundenakte,
Kunde bearbeiten, zwei Vertraege bearbeiten, 15 Portalseiten - mit
Headless-Chromium bei 1280 px, 820 px (Tablet) und 390 px (Portal):
JavaScript-Fehler, sichtbare Checkbox/Radio breiter als 30 px, waagerechter
Ueberlauf, nicht umgestelltes Datumsfeld. Ergebnis nach den Aenderungen: **0
Befunde** (einzige Meldung: `admin/import/template` ist ein Download).

| Prio | Befund | Stand |
|---|---|---|
| hoch | Checkbox/Radio in `.field` als grauer Balken, Zustand unsichtbar | behoben |
| hoch | "Beziehung festlegen" abgeschnitten, Speichern unerreichbar | behoben (Dialog) |
| hoch | Popover/Menues in Tabellen konnten abgeschnitten werden | behoben (schwebend) |
| hoch | Datumsanzeige je nach Browser-Sprache (06/10/2026) | behoben |
| hoch | Aufgaben nur einzeln bearbeitbar | behoben |
| hoch | Herkunftsaenderung: Fehler erschien nur oben, Speichern "tat nichts" | behoben (Sperre + Hinweis + Feldfehler) |
| hoch | Fehlermeldungen nur gesammelt oben, nicht am Feld | behoben (zentral) |
| hoch | `?tab=…` zeigte fremde Aufgaben | behoben |
| mittel | Ticket-Statistik lief bei 1280 px waagerecht ueber | behoben (Grid) |
| mittel | Warnung bei ungespeicherten Aenderungen fehlt noch an: Einstellungen, Mitarbeiter bearbeiten, Partnerakte | offen - per `data-aenderungen-warnen` nachziehbar |
| mittel | Sammelaktionen fehlen noch: Dokumenten-Eingang, Aenderungsantraege, Postfach, Interessenten (Tickets und Dubletten haben sie) | offen |
| mittel | Loeschen-Rueckfragen nutzen den Browser-`confirm()` (funktional, aber nicht im Markendesign) | offen |
| mittel | Rund 4.800 Inline-Stile: Knoepfe/Abstaende sind seitenweise uneinheitlich (z. B. `btn-primary` graphit vs. `btn-emerald` gruen als Hauptaktion) | offen - schrittweise auf `.btn-emerald` als Primaeraktion |
| niedrig | Erfolgsmeldung steht oben auf der Folgeseite, kein schwebender Hinweis | offen |
| niedrig | Sortierung per Spaltenkopf fehlt in den meisten Listen | offen |
