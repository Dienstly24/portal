#!/usr/bin/env bash
#
# Pruefen, ob diese Maschine die Testsuite VOLLSTAENDIG ausfuehren kann.
#
# Warum es das gibt (Audit-Nachlauf 16.09.2026): "die Tests laufen" ist
# keine Aussage, solange niemand sagt, WELCHE laufen. Fuenf OCR-Faelle
# haben sich monatelang stillschweigend uebersprungen, weil tesseract
# fehlte - in der Liste stand trotzdem "bestanden".
#
# Das Skript ist REIN LESEND. Es installiert nichts und aendert nichts;
# es nennt, was fehlt, und mit welchem Befehl man es bekommt.
#
# Aufruf:  bash scripts/testumgebung-pruefen.sh
# Exitcode 0 = vollstaendig, 1 = etwas Pflichtiges fehlt.

set -uo pipefail

fehler=0
warnung=0

titel() { printf '\n== %s\n' "$1"; }
ok()    { printf '  [ok]     %s\n' "$1"; }
fehlt() { printf '  [FEHLT]  %s\n     -> %s\n' "$1" "$2"; fehler=$((fehler+1)); }
warn()  { printf '  [Hinweis] %s\n     -> %s\n' "$1" "$2"; warnung=$((warnung+1)); }

titel "PHP"
if command -v php >/dev/null 2>&1; then
    version=$(php -r 'echo PHP_VERSION;')
    if php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
        ok "PHP $version (verlangt >= 8.3)"
    else
        fehlt "PHP $version ist zu alt" "composer.json verlangt php ^8.3"
    fi
else
    fehlt "PHP fehlt" "apt install php8.3-cli"
fi

titel "PHP-Erweiterungen (Pflicht)"
# Die Liste ist deckungsgleich mit TestumgebungTest::test_die_voraussetzungen_sind_benannt.
for ext in gd zip pdo_sqlite mbstring intl exif curl dom xml fileinfo openssl; do
    if php -m 2>/dev/null | grep -qix "$ext"; then
        ok "$ext"
    else
        fehlt "PHP-Erweiterung $ext" "apt install php8.3-$ext"
    fi
done

titel "Systemwerkzeuge fuer die Dokumenterkennung"
# Ohne sie ueberspringen sich OCR-Tests - die Suite ist dann UNVOLLSTAENDIG,
# aber nicht kaputt. Deshalb Hinweis statt Fehler.
if command -v tesseract >/dev/null 2>&1; then
    ok "tesseract $(tesseract --version 2>&1 | head -1 | awk '{print $2}')"
else
    warn "tesseract fehlt - 5 OCR-Testfaelle laufen nicht" \
         "apt install tesseract-ocr tesseract-ocr-deu"
fi
for werkzeug in pdftoppm pdftotext; do
    if command -v "$werkzeug" >/dev/null 2>&1; then
        ok "$werkzeug"
    else
        warn "$werkzeug fehlt (poppler-utils)" "apt install poppler-utils"
    fi
done

titel "Node / Frontend"
if command -v node >/dev/null 2>&1; then
    ok "node $(node -v)"
else
    fehlt "node fehlt" "Node 20 oder neuer installieren"
fi
if command -v npm >/dev/null 2>&1; then
    ok "npm $(npm -v)"
else
    fehlt "npm fehlt" "gehoert zu Node"
fi

titel "Abhaengigkeiten"
if [ -f vendor/autoload.php ]; then
    ok "vendor/ vorhanden"
    # Die eigentliche Frage ist nicht "gibt es vendor", sondern
    # "passt es zum Lockfile" - sonst misst man etwas anderes als die CI.
    if php -r '
        $lock = json_decode(file_get_contents("composer.lock"), true);
        $inst = json_decode(file_get_contents("vendor/composer/installed.json"), true);
        $da = [];
        foreach ($inst["packages"] as $p) { $da[$p["name"]] = $p["version"]; }
        foreach (array_merge($lock["packages"], $lock["packages-dev"]) as $p) {
            if (($da[$p["name"]] ?? null) !== $p["version"]) { exit(1); }
        }
        exit(0);
    ' 2>/dev/null; then
        ok "vendor/ deckt sich mit composer.lock"
    else
        fehlt "vendor/ weicht von composer.lock ab" "composer install"
    fi
else
    fehlt "vendor/ fehlt" "composer install"
fi

if [ -d node_modules ]; then
    ok "node_modules/ vorhanden"
else
    fehlt "node_modules/ fehlt" "npm ci"
fi

titel "Konfiguration"
if [ -f .env ]; then
    ok ".env vorhanden"
else
    warn ".env fehlt" "cp .env.example .env && php artisan key:generate"
fi
if [ -f phpunit.xml ]; then
    ok "phpunit.xml vorhanden (Tests laufen gegen SQLite im Speicher)"
else
    fehlt "phpunit.xml fehlt" "gehoert ins Repository"
fi

printf '\n'
if [ "$fehler" -gt 0 ]; then
    printf 'ERGEBNIS: %d Pflichtpunkt(e) fehlen, %d Hinweis(e).\n' "$fehler" "$warnung"
    printf 'Die Testsuite laeuft so NICHT vollstaendig. Details: docs/TESTUMGEBUNG.md\n'
    exit 1
fi

if [ "$warnung" -gt 0 ]; then
    printf 'ERGEBNIS: vollstaendig lauffaehig, aber %d Testgruppe(n) ueberspringen sich.\n' "$warnung"
    printf 'Details: docs/TESTUMGEBUNG.md\n'
    exit 0
fi

printf 'ERGEBNIS: vollstaendig. Alle Tests koennen laufen.\n'
exit 0
