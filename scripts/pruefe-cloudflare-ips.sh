#!/usr/bin/env bash
#
# Vergleicht die in config/trustedproxy.php hinterlegten Cloudflare-Ranges
# mit der veroeffentlichten Liste (https://www.cloudflare.com/ips/).
#
# Aendert NICHTS von selbst: eine stillschweigend ueberschriebene
# Proxy-Liste ist genau die Art Aenderung, die niemand bemerkt. Das Skript
# meldet Abweichungen, die Pflege bleibt eine bewusste Entscheidung.
#
# Exitcode 0 = deckungsgleich, 1 = Abweichung, 2 = Liste nicht abrufbar.

set -uo pipefail

cd "$(dirname "$0")/.."

echo "▶ Cloudflare-Ranges abgleichen"

v4=$(curl -fsS --max-time 20 https://www.cloudflare.com/ips-v4 2>/dev/null || true)
v6=$(curl -fsS --max-time 20 https://www.cloudflare.com/ips-v6 2>/dev/null || true)

if [ -z "$v4" ] || [ -z "$v6" ]; then
  echo "!! Die Cloudflare-Liste ist nicht abrufbar (kein Netz / Sperre)."
  echo "!! Es wird NICHTS geaendert - eine leere Liste waere schlimmer als"
  echo "!! eine veraltete."
  exit 2
fi

veroeffentlicht=$(printf '%s\n%s\n' "$v4" "$v6" | tr -d '\r' | grep -v '^$' | sort)

# config/trustedproxy.php ruft env() auf - das ist ein Laravel-Helfer und
# ohne Autoloader nicht definiert. Ein blosses `require` waere hier also
# ein Fatal Error, die Liste kaeme LEER zurueck, und der Abgleich haette
# jede Cloudflare-Range als "fehlt" gemeldet. Deshalb ein Notbehelf-env().
hinterlegt=$(php -r '
if (!function_exists("env")) {
    function env($schluessel, $standard = null) {
        $wert = getenv($schluessel);
        return $wert === false ? $standard : $wert;
    }
}
$c = require "config/trustedproxy.php";
foreach ($c["cloudflare"] as $r) { echo $r, PHP_EOL; }
' | sort)

# Eine leere hinterlegte Liste ist immer ein Skriptfehler, nie ein echter
# Befund - sonst meldet der Abgleich lautstark "alles fehlt" und niemand
# glaubt ihm beim naechsten Mal noch.
if [ -z "$hinterlegt" ]; then
  echo "!! Die Liste aus config/trustedproxy.php liess sich nicht lesen."
  echo "!! Das ist ein Fehler DIESES Skripts, kein Befund - es wird nichts gemeldet."
  exit 2
fi

fehlend=$(comm -23 <(echo "$veroeffentlicht") <(echo "$hinterlegt"))
ueberzaehlig=$(comm -13 <(echo "$veroeffentlicht") <(echo "$hinterlegt"))

if [ -z "$fehlend" ] && [ -z "$ueberzaehlig" ]; then
  echo "✔ Die hinterlegte Liste ist deckungsgleich mit der veroeffentlichten."
  exit 0
fi

if [ -n "$fehlend" ]; then
  echo "!! In config/trustedproxy.php FEHLEN (neue Cloudflare-Ranges):"
  echo "$fehlend" | sed 's/^/     /'
  echo "   Folge: Anfragen ueber diese Ranges verlieren die echte Client-IP"
  echo "   (alle Nutzer dahinter landen in EINEM Rate-Limit-Eimer)."
fi

if [ -n "$ueberzaehlig" ]; then
  echo "!! In config/trustedproxy.php stehen Ranges, die Cloudflare nicht"
  echo "   mehr nennt:"
  echo "$ueberzaehlig" | sed 's/^/     /'
  echo "   Folge: wer eine dieser Adressen uebernimmt, darf X-Forwarded-For"
  echo "   setzen - also die Client-IP faelschen."
fi

exit 1
