#!/usr/bin/env bash
#
# Produktions-Deploy für Dienstly24 (wird vom GitHub-Actions-Workflow
# per SSH auf dem Server ausgeführt, NACHDEM origin/main ausgecheckt ist).
#
# Sicherheitsnetz: Die App wird in den Wartungsmodus versetzt und beim
# Verlassen des Skripts – auch bei Fehler – IMMER wieder online genommen,
# damit ein fehlgeschlagenes Deploy den Shop nicht dauerhaft offline lässt.
#
# Voraussetzung: composer, php (8.3), npm sind auf dem Server verfügbar,
# und .env liegt bereits produktiv gepflegt vor (wird NICHT überschrieben).

set -euo pipefail

echo "▶ Deploy gestartet: $(date '+%Y-%m-%d %H:%M:%S')"

# Bei jedem Verlassen (Erfolg ODER Fehler) die App wieder online nehmen.
trap 'php artisan up || true' EXIT

# 1) Wartungsmodus (bestehende Requests bekommen kurz Zeit).
php artisan down --retry=15 || true

# 2) PHP-Abhängigkeiten ohne Dev-Pakete, optimiert.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 3) Frontend-Assets bauen (public/build ist nicht im Repo).
#    Sicherheitsnetz: vite leert public/build VOR dem Bauen. Schlaegt der
#    Build fehl, fehlt sonst das manifest.json und JEDE Seite antwortet
#    mit einem 500er. Deshalb vorher sichern, im Fehlerfall zuruecklegen
#    und den Deploy LAUT abbrechen (Migration/Caches laufen dann nicht).
if [ -f package-lock.json ]; then
  rm -rf public/build.bak
  if [ -d public/build ]; then cp -a public/build public/build.bak; fi
  if npm ci --no-audit --no-fund && npm run build; then
    rm -rf public/build.bak
  else
    echo "!! FEHLER: Asset-Build fehlgeschlagen - vorherige Assets werden"
    echo "!! wiederhergestellt, Deploy wird abgebrochen (App geht per trap"
    echo "!! wieder online, laeuft aber mit dem ALTEN Build weiter)."
    if [ -d public/build.bak ]; then rm -rf public/build && mv public/build.bak public/build; fi
    exit 1
  fi
fi

# 4) Datenbank migrieren (additiv; --force = ohne Rückfrage in Produktion).
php artisan migrate --force

# 4b) DSGVO: oeffentliche Ticketanhaenge in den privaten Storage verschieben
#     (idempotent - verschiebt nur, was noch auf der public Disk liegt).
php artisan tickets:attachments-private || true

# 4c) Startinhalt der Leistungsseiten anlegen (idempotent, NICHT-destruktiv:
#     legt fehlende Standardseiten an, ueberschreibt aber keine im Admin
#     gepflegten Inhalte).
php artisan db:seed --class=ServicePageSeeder --force || true

# 5) Produktions-Caches neu aufbauen.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

# 6) Laufende Queue-Worker sauber neu starten, damit sie den neuen Code laden.
php artisan queue:restart

# 6b) OPcache/PHP-FPM neu laden. Ohne diesen Schritt serviert PHP-FPM bei
#     opcache.validate_timestamps=0 weiterhin den ALTEN Bytecode und die alt
#     kompilierten Blade-Views -> Deploy meldet Erfolg, aber im Portal ist
#     keine Aenderung sichtbar. Der erste gefundene FPM-Dienst wird neu
#     geladen; fehlt er, wird der Deploy NICHT abgebrochen (nur ein Hinweis).
if command -v systemctl >/dev/null 2>&1; then
  reloaded=""
  for svc in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl reload "$svc" 2>/dev/null; then
      echo "  OPcache/PHP-FPM neu geladen: $svc"
      reloaded="$svc"
      break
    fi
  done
  if [ -z "$reloaded" ]; then
    echo "  Hinweis: kein PHP-FPM-Dienst neu geladen (OPcache ggf. manuell leeren)."
  fi
fi

# 7) App wieder online (zusätzlich zum trap, damit es sofort passiert).
php artisan up
trap - EXIT

echo "✔ Deploy erfolgreich abgeschlossen: $(date '+%Y-%m-%d %H:%M:%S')"
