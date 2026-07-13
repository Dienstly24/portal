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
if [ -f package-lock.json ]; then
  npm ci --no-audit --no-fund
  npm run build
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

# 7) App wieder online (zusätzlich zum trap, damit es sofort passiert).
php artisan up
trap - EXIT

echo "✔ Deploy erfolgreich abgeschlossen: $(date '+%Y-%m-%d %H:%M:%S')"
