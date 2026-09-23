# Technical Debt

Stand: 23.09.2026. Altlasten, die heute nichts kaputt machen, aber jede
Aenderung teurer oder riskanter machen. Wird etwas davon zum Fehler, bekommt
es eine KI-Nummer in [KNOWN_ISSUES.md](KNOWN_ISSUES.md).

| ID | Thema | Ort | Warum es zaehlt | Empfehlung | Aufwand |
|---|---|---|---|---|---|
| TD-01 | Sehr grosse Klassen | `DocumentIntakeService` (2229 Z.), `SmartDocumentUploadController` (1397), `AdminController` (1012), `PortalController` (1008), `Contract` (894) | viele Verantwortungen, lange Tests, Merge-Konflikte | Muster ARCH-5 (rein mechanische Aufteilung, Routentabelle vorher/nachher zeichengleich); zuerst `SmartDocumentUploadController` (Admin/Portal trennen) | M-L je Klasse |
| TD-02 | Inline-Styles | ~4900 `style="..."`, 62 `<style>`-Bloecke; groesste Vorlagen `documents_inbox`, `customer_show` | erzwingt `style-src 'unsafe-inline'`, Design-Tokens werden umgangen, Vorlagen schwer lesbar | schrittweise in `components.css` ueberfuehren, beginnend mit den groessten Vorlagen; kein Grossumbau | L (laufend) |
| TD-03 | PHPStan-Baseline | `phpstan-baseline.neon`, 290 Eintraege (Stufe 5) | Altmeldungen verdecken echte Fehler (3 echte Funde am 15.09.) | wer eine Datei anfasst, streicht ihre Eintraege; Ziel 0 | laufend |
| TD-04 | Drei Eingangs-Provisionsstraenge + Ausgang | `commissions`, `vermittler_settlements`, `contract_commissions`, `provisions` | bewusst getrennt (ARCH-3); Risiko: `commissions` (Gutschriften, alt) ueberschneidet sich fachlich mit `contract_commissions` | pruefen, ob `commissions` noch neue Daten bekommt; wenn nicht, als Altbestand kennzeichnen (nie loeschen) | S (Analyse) |
| TD-05 | ~~Breeze-Reste~~ | - | **erledigt 23.09.2026** (KI-016) | - | - |
| TD-06 | Ungenutzte Konfiguration | `config/services.php` postmark/resend/ses/slack (`autoprefixer` erledigt 23.09.2026) | Rauschen | bewusst belassen: gehoeren zu den Standard-Mailern in `config/mail.php` | - |
| TD-07 | ~~Dateien ohne Tests~~ | - | **erledigt 23.09.2026** (KI-011) | - | - |
| TD-08 | `preventSilentlyDiscardingAttributes` aus | ~35 Stellen uebergeben Felder, die nicht in `$fillable` stehen | still verworfene Werte | je Stelle entscheiden (Sicherheitsentscheidung, ARCH-7) | M |
| TD-09 | Statische Uebergangs-Website | `website/` (statisch), `api/website-contact`, `api/website-inquiry` | zweiter Weg fuer dieselbe Anfrage | nach bestaetigtem DNS-Umzug entfernen | S |
| TD-10 | ~~CI-Versionen gemischt~~ | - | **erledigt 23.09.2026** (KI-015) | - | - |
| TD-11 | Git-Historie im Arbeitsklon flach | Klon beginnt 04.09.2026 (158 Commits) | "warum wurde das gebaut" fuer Aelteres nur ueber `CLAUDE.md`/`docs` | bei Bedarf `git fetch --unshallow` | - |
| TD-12 | Keine explizite API-Schicht | JSON-Antworten direkt in Controllern | bei einer spaeteren App/Integration fehlt eine Versionierung | erst bei echtem Bedarf (Resources) | - |

## Bewusst KEINE Schuld (nicht "reparieren")

- 41+ Vorlagen-Parser (Grund fuer "kostenlos zuerst", Regel ARCH-8).
- Eigener PDF-/XLSX-/XLS-Leser statt Fremdpaketen (Sicherheitsupdate-Pfad).
- 4 Policies statt 98 (Pruefung an Route + Controller + Test).
- `app.timezone = UTC`, Umrechnung nur bei der Ausgabe.
- Langsamer WASM-Rueckfall von Tailwind oxide.
- `style-src 'unsafe-inline'` (solange TD-02 besteht).
