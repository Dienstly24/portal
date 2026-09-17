# Testumgebung: was gebraucht wird und wie man es prueft

Ziel dieses Dokuments: **jeder** soll die Testsuite vollstaendig laufen
lassen koennen, ohne undokumentiertes Wissen ueber eine bestimmte
Maschine. Wenn die Suite nur bei einer Person durchlaeuft, ist sie kein
Massstab.

Schnellpruefung:

```
bash scripts/testumgebung-pruefen.sh
```

Das Skript ist rein lesend, installiert nichts und nennt zu jedem
fehlenden Punkt den Befehl, der ihn beschafft. Exitcode 1 = etwas
Pflichtiges fehlt.

## 1. Pflicht

| Was | Mindestens | Installation (Ubuntu/Debian) |
|---|---|---|
| PHP | 8.3 | `apt install php8.3-cli` |
| PHP-Erweiterungen | gd, zip, pdo_sqlite, mbstring, intl, exif, curl, dom, xml, fileinfo, openssl | `apt install php8.3-{gd,zip,sqlite3,mbstring,intl,curl,xml}` |
| Node | 20 | Node LTS |
| npm | 10 | gehoert zu Node |

**`gd` ist nicht optional.** Ohne die Erweiterung existiert
`imagecreatefromstring()` gar nicht, und ein fehlender Funktionsname ist
ein FATALER Fehler, den kein `try` abfaengt - genau daran scheiterte am
10.09.2026 das Unterschreiben in Produktion. `App\Support\Bildverarbeitung`
prueft das heute frueh; der Test dazu ist `BildverarbeitungFehltTest`.

## 2. Empfohlen: Dokumenterkennung (OCR)

| Was | Wofuer | Installation |
|---|---|---|
| `tesseract-ocr`, `tesseract-ocr-deu` | Texterkennung auf Fotos/Scans | `apt install tesseract-ocr tesseract-ocr-deu` |
| `poppler-utils` (`pdftoppm`, `pdftotext`) | PDF-Textebene und Seitenbilder | `apt install poppler-utils` |

Fehlen sie, laeuft die Suite - aber **fuenf OCR-Faelle ueberspringen sich
still**. Genau das war monatelang der Zustand: in der Ergebniszeile stand
"bestanden", geprueft wurde ein Teil davon nie. Auf dem Produktionsserver
sind beide Pakete installiert (`OCR_ENABLED=true`), die Tests gehoeren
deshalb zur Normalausstattung und nicht in die Kuer.

## 3. Datenbank

Die Tests laufen gegen **SQLite im Speicher** (`phpunit.xml`) - keine
Einrichtung noetig, kein Zugriff auf eine echte Datenbank. Die CI laesst
dieselbe Suite ZUSAETZLICH gegen **MySQL 8** laufen
(`.github/workflows/deploy.yml`, Job "Tests gegen MySQL"), weil SQLite und
MySQL an einigen Stellen verschieden vergleichen - zuletzt aufgefallen bei
`date(COALESCE(...))` im Auswertungs-Dashboard.

Lokal gegen MySQL testen (optional):

```
DB_CONNECTION=mysql DB_DATABASE=portal_test php artisan test
```

## 4. Abhaengigkeiten

```
composer install
npm ci
```

`vendor/` muss sich mit `composer.lock` decken - sonst misst man etwas
anderes als die CI. Das Pruefskript vergleicht das Paket fuer Paket, nicht
nur "gibt es einen vendor-Ordner".

### Netzbeschraenkte Umgebungen

Composer laedt seine Pakete als ZIP von `api.github.com`. Ist dieser Host
gesperrt (in abgeschotteten Container- oder Agenten-Umgebungen der
Normalfall), scheitert `composer install` mit
**"Could not authenticate against github.com"** - und zwar an der
Umgebung, nicht am Projekt. Kennzeichen: die Meldung nennt `[403]` und
eine `zipball`-Adresse.

Erkennen:

```
composer install -vvv 2>&1 | grep -E '\[403\]|authenticate'
```

Ausweg, wenn nur `git` erlaubt ist:

```
composer install --prefer-source
```

Dann holt Composer jedes Paket per `git clone` statt als ZIP. Ausnahme
ist `phpstan/phpstan` (sehr grosses Repository) - hier hilft ein flacher
Klon des passenden Tags in `vendor/phpstan/phpstan`.

**Die CI ist davon nicht betroffen** und ist der Massstab: der Job
"Tests & Build-Verifikation" fuehrt bei JEDEM Push `composer install`,
`npm ci`, `npm run build` und die volle Suite aus.

## 5. Was "gruen" heissen muss

```
bash scripts/testumgebung-pruefen.sh   # vollstaendig
composer install                        # ohne Fehler
npm ci && npm run build                 # ohne Fehler
php artisan test                        # 0 fehlgeschlagen, 0 uebersprungen
composer stan                           # 0 Fehler
composer lint                           # bestanden
composer audit && npm audit             # 0 Schwachstellen
```

**0 uebersprungen ist Teil der Zielvorgabe.** Ein uebersprungener Test
sieht aus wie ein bestandener und wird nie repariert.

## 6. Die Regel, die aus zwei Fehlern entstanden ist

Ein **Sicherheitstest** darf sich NIE selbst ueberspringen. Zwei taten es
und liefen dadurch monatelang als "bestanden":

- `ClientIpIntegrityTest` suchte die Client-IP im Feld `meta` statt in der
  Spalte `ip` - fand nie etwas und uebersprang sich. Damit war der ganze
  Schutz gegen gefaelschte Client-IPs (SEC-2) ungeprueft.
- `ContentSecurityPolicyTest` haengte an `Statuscode === 200`, waehrend die
  Systemzustand-Ansicht voellig regelgerecht 503 liefert, sobald die Ampel
  rot ist - in der Testumgebung der Normalfall.

`TestumgebungTest::test_kein_sicherheitstest_ueberspringt_sich_selbst`
haelt die Regel jetzt fest: in `tests/Feature/Security/` ist
`markTestSkipped` verboten. Anderswo bleibt es erlaubt - ein Entwickler
ohne tesseract soll arbeiten koennen. Bei einer Sicherheitszusicherung
gibt es diesen Spielraum nicht.
