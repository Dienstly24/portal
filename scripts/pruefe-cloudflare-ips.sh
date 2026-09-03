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

hinterlegt=$(php -r '
$c = require "config/trustedproxy.php";
foreach ($c["cloudflare"] as $r) { echo $r, PHP_EOL; }
' | sort)

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
