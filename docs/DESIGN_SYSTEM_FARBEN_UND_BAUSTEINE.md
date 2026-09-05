# Design-System: Farben und Bausteine (UX-1 / UX-2, 05.09.2026)

Kurzfassung fuer den Alltag: **Markenfarben stehen in
`resources/css/brand.css`, Bausteine in `resources/css/components.css`.
Kein neuer Hex-Wert einer Markenfarbe in einer Blade-Datei.**

## Warum das noetig war

Der Farbsatz "Smaragd & Gold" (Betreiber-Entscheidung 22.07.2026) stand in
**14 Blade-Dateien noch einmal**, und zwar unter **sieben verschiedenen
Namen** fuer dieselben zwei Farben:

| Alter Name | Wert | Bedeutete dort | Wo |
|---|---|---|---|
| `--gold` | `#17A65B` | **Gruen** | Beraterwelt, Kundenportal, Partnerportal |
| `--gold` | `#B8A16B` | Gold | Anmeldeseiten |
| `--akzent` | `#B8A16B` | Gold | Beraterwelt, Portale |
| `--green` | `#17A65B` | Gruen | Anmeldung, Leistungsseiten, Hilfe |
| `--emerald` | `#17A65B` | Gruen | Fehlerseiten, Rechtsseiten |
| `--petrol` | `#131A17` | Gruen-Graphit | ueberall (Name laut Hoheitsregel verboten) |
| `--gold-soft` | `#d9f4e6` / `#D1C18F` | Gruen **oder** Gold, je Datei | Portal / Fehlerseiten |

Der teuerste Fall war `--gold`. Wer "gold" liest und Gold meint, baut
zwangslaeufig eine gruene Zierlinie - und umgekehrt. Genau das verhindert
die Betreiber-Regel "Gold ist AKZENT, Smaragd ist AKTION"; unter EINEM
Namen laesst sie sich gar nicht einhalten. Dieselbe Luege trug die Klasse
`.btn-gold`: sie war an 58 Stellen im Einsatz und immer smaragdgruen.

## Die Namen

Sie sind **nicht neu erfunden**: `public/website-assets/site.css` fuehrte
die Marke bereits sauber als `--emerald*` / `--gold*`. Die Anwendung folgt
jetzt der Website - eine eigene Konvention haette eine achte ergeben.

```
--emerald  --emerald-bright  --emerald-deep  --emerald-mint  --emerald-soft  --emerald-ink
--gold     --gold-soft       --gold-line
--graphite --graphite-deep   --graphite-black
--canvas   --canvas-warm     --surface  --surface-soft  --line  --ink  --ink-soft
--glass-line                                  (Glasflaechen der Anmeldung)
--status-success  --status-danger  --status-warning  --status-info
```

**Status ist bewusst KEINE Markenfarbe.** Wuerde "Erfolg" auf Smaragd
zeigen, aenderte ein spaeterer Markenwechsel die Bedeutung von
"erfolgreich" mit. Eine Ampelfarbe ist eine Aussage, kein Logo.

**Keine Alias-Namen auf den alten Bestand.** Ein `--gold: var(--emerald)`
als Uebergangshilfe haette genau den Fehler konserviert, um den es geht.

## Wo Farben trotzdem als Hex stehen - und warum

| Datei | Grund |
|---|---|
| `errors/404`, `errors/500` | laden kein Vite-Bundle. Eine Fehlerseite muss auch dann im Markendesign erscheinen, wenn das Build-Manifest fehlt - genau die Lage, die einen 500er erzeugt. |
| `legal/page`, `admin/provision_report_print` | werden ohne Bundle ausgeliefert (Website-Hosts bzw. Druckansicht). |
| `partials/favicon`, `layouts/portal` (`theme-color`) | ein HTML-Attribut kann kein `var()` aufloesen. |
| `partials/cookie_consent` | erscheint auch auf Website-Hosts ohne Bundle. |
| `resources/views/emails/**` | E-Mail-Vorlagen bleiben tabellenbasiert mit Inline-Styles (Gmail/Outlook entfernen `<style>`). Eigenes Thema, siehe CLAUDE.md. |
| `public/website-assets/site.css` | die statische Website hat ihren eigenen, bereits korrekten Tokensatz. |

## Diagramme (Canvas)

Chart.js malt Pixel, kein CSS - `backgroundColor: 'var(--emerald)'` ergibt
dort **keine** Farbe. `public/js/brand.js` stellt deshalb
`brandColor('emerald')` bereit; die Funktion liest denselben CSS-Token zur
Laufzeit aus. Bewusst ein klassisches Skript, kein Modul: die
Diagramm-Bloecke stehen eingebettet in der Seite und laufen sofort, ein
Modul liefe spaeter.

## Bausteine (`components.css`)

`.card`, `.btn`, `.badge`, `.field`, `.alert-*`, `.item-row`, `.grid-2/3`,
`.page-title`, `.metric-*` waren **dreimal** definiert - je einmal im
`<style>`-Block von Beraterwelt, Kundenportal und Partnerportal.

Die Unterschiede sind **zum Teil Absicht**: das Kundenportal ist die
Oberflaeche fuer Telefone und arbeitet mit groesseren Flaechen. Alles auf
eine Groesse zu ziehen waere eine stille Neugestaltung gewesen. Deshalb:

- **Struktur** (Aufbau, Zustaende, Farben, Rundungen) steht einmal in
  `components.css`.
- **Masse** stehen in Tokens mit dem Beraterwelt-Wert als Standard
  (`--btn-pad`, `--badge-size`, `--card-mb`, ...). Portal und Partner
  ueberschreiben nur diese Tokens in ihrem Layout.

Damit bleibt jede Oberflaeche pixelgleich, und es gibt trotzdem nur eine
Stelle, an der ein Baustein definiert ist.

Dazu kommen sprechende Kleinteile fuer die haeufigsten Inline-Wiederholungen:
`.muted`, `.muted-sm/-xs/-2xs`, `.num`, `.num-strong`, `.nowrap`,
`.scroll-x`, `.card-flush`, `.card-head-bar`, `.modal-close`. Sie heissen
nach ihrer BEDEUTUNG, nicht nach ihrem Wert (`.muted-sm`, nicht `.fs13`) -
sonst waere es nur Inline-CSS mit Umweg.

## Regel fuer neue Oberflaechen

1. Markenfarbe? -> Token aus `brand.css`, nie ein neuer Hex.
2. Wiederkehrender Baustein? -> Klasse aus `components.css`; weicht nur die
   GROESSE ab, ist es ein Token, keine zweite Klasse.
3. Wirklich dynamischer Wert (Fortschritt, Prozent)? -> bleibt inline, das
   ist kein Verstoss (`style="width:{{ $p }}%"`).
4. Neue Statusfarbe? -> `--status-*`, nicht die Markenfarbe.
