#!/usr/bin/env bash
#
# Wiederherstellung einer Sicherung von Dienstly24.
#
# WARUM ES DIESES SKRIPT GIBT (Audit 15.09.2026): das Backup-Skript stand
# seit langem im Repository, die Wiederherstellung dagegen nur als Satz in
# einem Dokument. Ein Ablauf, den niemand geuebt hat, ist im Ernstfall
# keine Sicherung, sondern eine Hoffnung - und der Ernstfall ist der
# schlechteste Zeitpunkt, um herauszufinden, dass das Passwort fehlt oder
# der Dump leer ist.
#
# ZWEI BETRIEBSARTEN
# ------------------
#   scripts/restore.sh --pruefen [STAMP]
#       Entschluesselt und liest die Sicherung, zaehlt Tabellen und
#       Dateien - RUEHRT ABER NICHTS AN. Fuer die regelmaessige Probe.
#       Diese Variante ist auf dem Produktionsserver gefahrlos.
#
#   scripts/restore.sh --wiederherstellen ZIEL_DB [STAMP]
#       Spielt die Sicherung in die ANGEGEBENE Datenbank ein.
#       ZIEL_DB muss ausdruecklich genannt werden und darf NICHT die
#       produktive Datenbank aus der .env sein - das Skript verweigert
#       das. Wer wirklich die Produktion zurueckspielen will, tut das
#       bewusst von Hand (Ablauf unten).
#
# OHNE STAMP wird die JUENGSTE Sicherung genommen.
#
# WIEDERHERSTELLUNG DER PRODUKTION (bewusst von Hand, nicht per Schalter)
# ----------------------------------------------------------------------
#   1. php artisan down                      (App aus dem Verkehr ziehen)
#   2. Aktuellen Stand sichern, BEVOR etwas ueberschrieben wird:
#        bash scripts/backup.sh
#   3. Probe in eine Ausweichdatenbank:
#        bash scripts/restore.sh --wiederherstellen dienstly24_restore
#   4. Stichproben in der Ausweichdatenbank (Kundenzahl, letzte Vertraege,
#      ein Dokumentpfad) - erst wenn die stimmen, weiter.
#   5. Produktive Datenbank umbenennen/sichern, Ausweichdatenbank
#      uebernehmen ODER den Dump gezielt einspielen.
#   6. Dateien: tar -xzf storage-STAMP.tar.gz -C /var/www/dienstly24/portal
#   7. php artisan config:clear && php artisan up
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dienstly24}"
MODUS="${1:---pruefen}"

cd "$APP_DIR"

env_val() { { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'; } || true; }
DB_HOST="$(env_val DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_val DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_USER="$(env_val DB_USERNAME)"
DB_PASS="$(env_val DB_PASSWORD)"
DB_PROD="$(env_val DB_DATABASE)"
PASSPHRASE="${BACKUP_PASSPHRASE:-$(env_val BACKUP_PASSPHRASE)}"

fehler() { echo "!! FEHLER: $1" >&2; exit 1; }

lesen() { # $1 = Datei (mit oder ohne .gpg) -> Klartext nach stdout
  if [[ "$1" == *.gpg ]]; then
    [ -n "$PASSPHRASE" ] || fehler "BACKUP_PASSPHRASE fehlt - die Sicherung ist verschluesselt und ohne sie NICHT lesbar."
    printf '%s' "$PASSPHRASE" | gpg --batch --yes --quiet \
      --passphrase-fd 0 --pinentry-mode loopback --decrypt "$1"
  else
    cat "$1"
  fi
}

# Juengste Sicherung finden (oder den uebergebenen Zeitstempel nehmen).
finde() { # $1 = Praefix (db|storage), $2 = optionaler STAMP
  local muster
  if [ -n "${2:-}" ]; then
    muster="$BACKUP_DIR/$1-$2".*
  else
    muster="$BACKUP_DIR/$1-"*
  fi
  # shellcheck disable=SC2012
  ls -1t $muster 2>/dev/null | head -1
}

case "$MODUS" in
# ---------------------------------------------------------------- PRUEFEN
--pruefen)
  STAMP="${2:-}"
  DB_DATEI="$(finde db "$STAMP")"
  ST_DATEI="$(finde storage "$STAMP")"

  [ -n "$DB_DATEI" ] || fehler "Keine DB-Sicherung in $BACKUP_DIR gefunden."
  [ -n "$ST_DATEI" ] || fehler "Keine Datei-Sicherung in $BACKUP_DIR gefunden."

  echo "▶ Probe (es wird NICHTS veraendert)"
  echo "  DB-Sicherung    : $DB_DATEI"
  echo "  Datei-Sicherung : $ST_DATEI"

  # 1) Laesst sich der Dump entschluesseln und entpacken?
  TABELLEN="$(lesen "$DB_DATEI" | gzip -dc | grep -c '^CREATE TABLE' || true)"
  [ "$TABELLEN" -gt 0 ] || fehler "Im Dump steht keine einzige Tabelle - die Sicherung ist unbrauchbar."
  echo "  Tabellen im Dump: $TABELLEN"

  # 2) Stehen die WICHTIGEN Tabellen drin? Ein Dump kann technisch
  #    einwandfrei und trotzdem unvollstaendig sein (falsche Datenbank,
  #    eingeschraenkte Rechte).
  INHALT="$(lesen "$DB_DATEI" | gzip -dc | grep '^CREATE TABLE' || true)"
  for tabelle in customers contracts users documents tickets; do
    echo "$INHALT" | grep -q "\`$tabelle\`" \
      || fehler "Tabelle '$tabelle' fehlt im Dump - die Sicherung ist unvollstaendig."
  done
  echo "  Pflichttabellen : vollstaendig (customers, contracts, users, documents, tickets)"

  # 3) Laesst sich das Dateiarchiv lesen, und ist die private Ablage drin?
  DATEIEN="$(lesen "$ST_DATEI" | tar -tzf - | wc -l)"
  [ "$DATEIEN" -gt 0 ] || fehler "Das Dateiarchiv ist leer."
  echo "  Dateien im Archiv: $DATEIEN"

  echo "✔ Probe bestanden: die Sicherung ist entschluesselbar, lesbar und vollstaendig."
  ;;

# ------------------------------------------------------- WIEDERHERSTELLEN
--wiederherstellen)
  ZIEL="${2:-}"
  STAMP="${3:-}"

  [ -n "$ZIEL" ] || fehler "Ziel-Datenbank fehlt: scripts/restore.sh --wiederherstellen ZIEL_DB [STAMP]"

  # SCHUTZ: niemals versehentlich ueber die Produktion. Wer das wirklich
  # will, macht es bewusst von Hand (Ablauf im Kopf dieser Datei).
  [ "$ZIEL" != "$DB_PROD" ] \
    || fehler "'$ZIEL' ist die PRODUKTIVE Datenbank. Bitte in eine Ausweichdatenbank einspielen und pruefen."

  DB_DATEI="$(finde db "$STAMP")"
  [ -n "$DB_DATEI" ] || fehler "Keine DB-Sicherung gefunden."

  echo "▶ Spiele $DB_DATEI in die Datenbank '$ZIEL' ein ..."
  MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    -e "CREATE DATABASE IF NOT EXISTS \`$ZIEL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  lesen "$DB_DATEI" | gzip -dc | MYSQL_PWD="$DB_PASS" mysql \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$ZIEL"

  ANZAHL="$(MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    -N -B -e "SELECT COUNT(*) FROM customers;" "$ZIEL")"
  echo "✔ Eingespielt. Kunden in '$ZIEL': $ANZAHL"
  echo "  Dateien getrennt einspielen:"
  echo "    tar -xzf $(finde storage "$STAMP") -C /pfad/zum/ziel"
  ;;

*)
  echo "Aufruf:"
  echo "  scripts/restore.sh --pruefen [STAMP]"
  echo "  scripts/restore.sh --wiederherstellen ZIEL_DB [STAMP]"
  exit 1
  ;;
esac
