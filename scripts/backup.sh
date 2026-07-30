#!/usr/bin/env bash
#
# Taegliches Backup fuer Dienstly24 (Arbeitsauftrag P2-7):
#   1. Datenbank-Dump (mysqldump, --single-transaction: kein Lock)
#   2. Dateien: storage/app (private Dokumente/Nachweise/Medien-Originale)
#      + public/storage-Inhalte (Medien-Varianten) + .env-KOPIE (chmod 600)
#   3. Rotation: Backups aelter als BACKUP_KEEP_DAYS (Standard 14) loeschen
#
# Einrichtung auf dem VPS (als Deploy-Benutzer):
#   crontab -e  ->  30 2 * * * cd /var/www/dienstly24/portal && bash scripts/backup.sh >> /var/log/dienstly24-backup.log 2>&1
#
# WICHTIG: Ein Backup ist erst dann ein Backup, wenn die Wiederherstellung
# GETESTET wurde - Ablauf siehe docs/WEBSITE_MERGE_UMSETZUNG.md
# ("Restore-Test"). Empfohlen: zusaetzlich taeglich per rclone/rsync auf
# einen ZWEITEN Speicherort ausserhalb des VPS kopieren (EU-Anbieter,
# z. B. Hetzner Storage Box) - ein Backup auf derselben Maschine schuetzt
# nicht vor Server-Totalausfall.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dienstly24}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date '+%Y%m%d-%H%M')"

cd "$APP_DIR"

# .env einlesen (nur DB_*-Variablen, ohne die Datei auszufuehren).
env_val() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'; }
DB_HOST="$(env_val DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_val DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_val DB_DATABASE)"
DB_USER="$(env_val DB_USERNAME)"
DB_PASS="$(env_val DB_PASSWORD)"

if [ -z "$DB_NAME" ]; then
  echo "!! FEHLER: DB_DATABASE nicht in .env gefunden - Abbruch." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

echo "▶ Backup gestartet: $(date '+%Y-%m-%d %H:%M:%S') -> $BACKUP_DIR"

# 1) Datenbank (Passwort per Umgebungsvariable, taucht nicht in ps auf).
MYSQL_PWD="$DB_PASS" mysqldump \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --routines --triggers --no-tablespaces \
  "$DB_NAME" | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
echo "  DB-Dump: $(du -h "$BACKUP_DIR/db-$STAMP.sql.gz" | cut -f1)"

# 2) Dateien: private Ablage (Dokumente, Nachweise, Medien-Originale)
#    und oeffentliche Medien-Varianten. Caches/Sessions bleiben draussen.
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" \
  --exclude='storage/framework' --exclude='storage/logs' \
  storage/app
echo "  Dateien: $(du -h "$BACKUP_DIR/storage-$STAMP.tar.gz" | cut -f1)"

# 3) .env-Kopie (enthaelt Zugangsdaten -> nur root/Deploy-User lesbar).
cp .env "$BACKUP_DIR/env-$STAMP"
chmod 600 "$BACKUP_DIR/env-$STAMP"

# 4) Rotation.
find "$BACKUP_DIR" -maxdepth 1 \( -name 'db-*.sql.gz' -o -name 'storage-*.tar.gz' -o -name 'env-*' \) \
  -mtime +"$KEEP_DAYS" -delete

echo "✔ Backup fertig: $(date '+%Y-%m-%d %H:%M:%S') (Rotation: ${KEEP_DAYS} Tage)"
