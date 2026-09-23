# Testing Status

Stand: 23.09.2026, `main` @ 04c0823.

## Letzter vollstaendiger Lauf (diese Sitzung)

| Lauf | Umgebung | Tests | Gruen | Fehler | Uebersprungen | Dauer |
|---|---|---|---|---|---|---|
| 1 | SQLite, PHP 8.4, ohne tesseract/poppler | 3044 | 3039 | 0 | 5 (OCR) | 155 s |
| 2 | SQLite, PHP 8.4, MIT tesseract-ocr(+deu) + poppler | 3044 | **3044** | 0 | **0** | 160 s |

Die 5 Uebersprungenen in Lauf 1 waren genau die OCR-Faelle
(`tests/Unit/Ocr/TesseractTextExtractorTest` u.a.) - nach
`apt-get install tesseract-ocr tesseract-ocr-deu poppler-utils` liefen alle
64 betroffenen Tests gruen. Zielvorgabe laut CLAUDE.md: **0 uebersprungen**.

Nicht in dieser Sitzung gelaufen: MySQL-Lauf (nur CI, Job `test-mysql`),
`composer stan` (KI-018 - phpstan hier nicht installierbar; CI-Job `qualitaet`).
`vendor/bin/pint --test` fuer neue Dateien: gruen.

## Aufbau der Suite

- `phpunit.xml`: Suiten `Unit` (14 Dateien) und `Feature` (279 Dateien,
  Unterordner `Ai` 54, `Messaging` 19, `Security` 10, `Auth`,
  `DocumentIntake`, `Health`); insgesamt 293 Testdateien, 3044 Tests.
- Testumgebung: SQLite `:memory:`, Mail `array`, Queue `sync`, Cache `array`,
  `BCRYPT_ROUNDS=4`, HIBP-Abgleich aus.
- **Falle**: `APP_KEY` kommt NICHT aus `phpunit.xml`, sondern aus `.env`. Ohne
  Schluessel scheitern ~1800 Tests an "No application encryption key"
  (verschluesselte Spalten, Sitzungen) - das ist ein Umgebungs-, kein
  Codefehler (`php artisan key:generate`). So am 23.09.2026 erlebt.
- Vorab-Pruefung der Maschine: `bash scripts/testumgebung-pruefen.sh`.

## CI-Gates (`.github/workflows/deploy.yml`)

`test` (SQLite + OCR-Pakete) · `test-mysql` (MySQL 8) · `qualitaet` (Pint +
PHPStan 5/Baseline 290) · `audit` (composer + npm). Alle vier sind
Voraussetzung fuer den Deploy.

## Waechter-Tests (halten Architekturregeln fest)

| Regel | Test |
|---|---|
| Keine Route ohne erkennbare Datensatzpruefung | `ZugriffspruefungTest` |
| Sicherheitstests ueberspringen sich nie | `TestumgebungTest` |
| Kein Kanalname im Messaging-Kern | `MessagingArchitectureTest` |
| Parser-Registrierung/-Tests | `ParserPolicyTest` |
| `@push` vor `@stack`, jeder `data-h-*` hat Registrierung | CSP-/Blade-Tests |
| Keine Uhrzeit ohne `->lokal()`, `app.timezone === UTC` | `DisplayTimezoneTest` |
| Keine `<?php` in ausgelieferten Seiten, gueltiges JSON-LD | `StructuredDataTest`, `BladeCompileTest` |
| `retry_after` > Job-Timeout | `QueueTimeoutTest` |
| Farben nur aus Tokens | `DesignSystemTest` |
| Indexe vorhanden | `DatabaseIndexTest` |
| Kein Matomo in Anwendungsbereichen | `MatomoMessungTest` |
| BIMI-Datei regelkonform | `BimiLogoTest` |

## Luecken

- Keine Funktionstests: Termine, Ankuendigungen, Tarifrechner (KI-011).
- Kein Test fuer Vollstaendigkeit von `lang/ar.json` (KI-007).
- Kein Test, dass Portal-JSON keine internen Vertragskennungen liefert (KI-010).
- Browser-Pruefungen sind manuell (Headless-Chromium), nicht Teil der CI.
- Keine Last-/Lasttests; Leistung ueber Eigenschaftstests (`LeistungsmessungTest`).

## Befehle

```
php artisan test                         # volle Suite
php artisan test --filter=NameTest       # gezielt
vendor/bin/pint --test                   # = composer lint
composer stan                            # PHPStan (braucht phpstan-Paket)
```
