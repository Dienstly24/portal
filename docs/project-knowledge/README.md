# Project Knowledge Base - Dienstly24 Portal

Dauerhaftes technisches Gedaechtnis des Projekts. Ziel: niemand (Mensch
oder KI) muss das System bei jeder Aufgabe neu entdecken.

Angelegt am 23.09.2026 (Stand `main` = 04c0823). Gepflegt nach dem
Grundsatz **CONTINUOUS AUDIT**: jede Aenderung am Code, die eine dieser
Dateien betrifft, zieht die Datei im SELBEN Pull Request mit.

## Rangfolge der Quellen

1. **Der Code** ist die Wahrheit. Widerspricht eine Datei hier dem Code,
   wird die Datei korrigiert - nie umgekehrt.
2. **`CLAUDE.md`** (Wurzel) ist die verdichtete Liste der Regeln und
   Lehren ("warum ist das so gebaut"). Sie bleibt die erste Datei, die
   eine Sitzung liest.
3. **Diese Wissensbasis** ist die STRUKTURIERTE Sicht (Karten, Register,
   Befunde, Fahrplan). Sie wiederholt die Lehren aus `CLAUDE.md` nicht,
   sondern verweist darauf.
4. **`docs/*.md`** sind die ausfuehrlichen Fachberichte je Thema
   (66 Dateien, deutsch; `*_AR.md` = arabische Betreiber-Anleitungen).

## Dateien

| Datei | Inhalt |
|---|---|
| [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md) | Was ist das System, fuer wen, womit, Ende-zu-Ende |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Schichten, Komponenten, Abhaengigkeiten, Querschnitt |
| [FRONTEND_MAP.md](FRONTEND_MAP.md) | Layouts, Views, CSS/JS, CSP-Muster |
| [BACKEND_MAP.md](BACKEND_MAP.md) | Controller, Services, Jobs, Befehle, Planer |
| [DATABASE_MAP.md](DATABASE_MAP.md) | Tabellen nach Fachbereich, Beziehungen, Regeln |
| [API_MAP.md](API_MAP.md) | Routen-Gruppen, JSON-Endpunkte, Webhooks, oeffentliche Pfade |
| [AUTH_SYSTEM.md](AUTH_SYSTEM.md) | Rollen, Rechte, Sichtbarkeit, 2FA, Passwoerter |
| [USER_FLOWS.md](USER_FLOWS.md) | Die wichtigsten Ablaeufe je Nutzergruppe |
| [FEATURE_MAP.md](FEATURE_MAP.md) | **Feature Registry** (F-xxx) mit Status |
| [INTEGRATIONS.md](INTEGRATIONS.md) | Externe Dienste, Schluessel, Ausfallverhalten |
| [DEPLOYMENT.md](DEPLOYMENT.md) | CI, Deploy, Server, Betriebsbefehle |
| [UI_UX_AUDIT.md](UI_UX_AUDIT.md) | Stand Oberflaeche, Sprachen, Responsive |
| [SECURITY_AUDIT.md](SECURITY_AUDIT.md) | Schutzschichten + offene Punkte |
| [PERFORMANCE_AUDIT.md](PERFORMANCE_AUDIT.md) | Messungen + Risiken |
| [TESTING_STATUS.md](TESTING_STATUS.md) | Testsuite, Gates, Luecken |
| [TECHNICAL_DEBT.md](TECHNICAL_DEBT.md) | Bekannte Altlasten |
| [KNOWN_ISSUES.md](KNOWN_ISSUES.md) | **Issue Registry** (KI-xxx), einzige Quelle fuer Befunde |
| [REPAIR_ROADMAP.md](REPAIR_ROADMAP.md) | Priorisierter Fahrplan |
| [CHANGELOG.md](CHANGELOG.md) | Aenderungsprotokoll ab Anlage der Wissensbasis |

## Ablauf zu Beginn jeder Sitzung

1. `CLAUDE.md` lesen (wird automatisch geladen).
2. Hier: `PROJECT_OVERVIEW.md`, `ARCHITECTURE.md`, `FEATURE_MAP.md`,
   `KNOWN_ISSUES.md`, `REPAIR_ROADMAP.md`.
3. `git fetch origin main && git log --oneline origin/main -15` - was ist
   seit dem letzten Eintrag in `CHANGELOG.md` gemergt worden?
4. Fuer JEDEN Merge seit dem letzten Eintrag pruefen, ob er eine Datei
   hier veraltet hat; wenn ja, zuerst nachziehen.
5. Erst dann die eigentliche Aufgabe - und nur die betroffenen Teile
   neu lesen (Feature-Eintrag nennt Dateien, Tabellen, Tests).

## Ablauf bei jeder Aufgabe

1. Feature in `FEATURE_MAP.md` suchen -> Dateien/Tabellen/Tests/Issues.
2. Offene Issues dazu in `KNOWN_ISSUES.md` lesen.
3. "What depends on this?" - Abhaengigkeiten aus `ARCHITECTURE.md` /
   `DATABASE_MAP.md`.
4. Umsetzen; Test, der ohne den Fix scheitert (Definition of Done in
   `CLAUDE.md`).
5. `php artisan test`, `composer stan`, `composer lint` gruen.
6. Diese Wissensbasis nachziehen: Feature-Status, Issue-Status
   (OPEN -> FIXED -> VERIFIED, nie loeschen), ggf. Architektur,
   `CHANGELOG.md`-Eintrag.
7. PR gegen `main` (nie direkt deployen).

## Konventionen

- Sprache: Deutsch, ASCII-Umschreibung (ae/oe/ue/ss) wie im uebrigen Repo.
- Kennungen: Features `F-NNN`, Issues `KI-NNN`, Fahrplan `R-NN`. Eine
  vergebene Kennung wird nie wiederverwendet.
- "UNKNOWN" heisst: aus Repository und Code nicht feststellbar (meist
  Server-/Netzzustand). Nie raten.
