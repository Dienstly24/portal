#!/usr/bin/env bash
#
# Taegliches Backup fuer Dienstly24.
#
#   1. Datenbank-Dump (mysqldump --single-transaction: kein Lock)
#   2. Dateien: storage/app (private Dokumente/Nachweise/Medien-Originale)
#   3. VERSCHLUESSELN (AES256) - Audit 15.09.2026
#   4. PRUEFEN, ob sich das Ergebnis wieder entschluesseln und lesen laesst
#   5. Optional: Kopie an einen ZWEITEN Ort ausserhalb des VPS
#   6. Rotation + Statusdatei fuer die Ueberwachung
#
# WAS SICH AM 15.09.2026 GEAENDERT HAT UND WARUM
# ----------------------------------------------
# Vorher lagen alle Sicherungen UNVERSCHLUESSELT im lokalen Verzeichnis
# desselben Servers - inklusive einer Kopie der .env mit saemtlichen
# Zugangsdaten. Drei Luecken auf einmal:
#
#   a) Ein Server-Totalausfall, eine geloeschte VM oder eine
#      Verschluesselungs-Erpressung vernichtet Original UND Sicherung.
#      Eine Sicherung auf derselben Maschine ist streng genommen nur
#      eine Kopie, kein Backup.
#   b) Das Archiv enthaelt IBANs, Ausweis- und Gesundheitsdaten (Art. 9
#      DSGVO) im Klartext. Wer die Datei bekommt, hat den Bestand.
#   c) "Backup gelaufen" hiess nur, dass das Skript nicht abgebrochen
#      ist - nicht, dass die Datei lesbar ist. Ein stilles Scheitern
#      faellt erst bei der Wiederherstellung auf, also im schlechtesten
#      Moment.
#
# EINRICHTUNG (auf dem VPS, als Deploy-Benutzer)
# ----------------------------------------------
#   1. Passwort erzeugen und SICHER ausserhalb des Servers verwahren
#      (Passwortmanager). Ohne dieses Passwort ist KEINE Sicherung mehr
#      lesbar - es gibt keine Hintertuer:
#        php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
#   2. In die Server-.env (NICHT ins Repository):
#        BACKUP_PASSPHRASE=<der erzeugte Wert>
#        BACKUP_REMOTE=hetzner:dienstly24-backups     # optional
#   3. Cron:
#        30 2 * * * cd /var/www/dienstly24/portal && bash scripts/backup.sh \
#          >> /var/log/dienstly24-backup.log 2>&1
#   4. Wiederherstellung EINMAL ueben: scripts/restore.sh --pruefen
#      Ein Backup, dessen Wiederherstellung nie geprobt wurde, ist eine
#      Annahme, keine Sicherung.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dienstly24}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date '+%Y%m%d-%H%M')"
STATUS_DATEI="${BACKUP_STATUS_FILE:-$APP_DIR/storage/app/private/backup-status.json}"

cd "$APP_DIR"

# .env einlesen (nur einzelne Variablen, ohne die Datei auszufuehren).
# "|| true": fehlt eine optionale Variable, liefert grep Status 1 - ohne
# das wuerde set -e/-o pipefail den ganzen Lauf abbrechen.
env_val() { { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'; } || true; }

DB_HOST="$(env_val DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_val DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_val DB_DATABASE)"
DB_USER="$(env_val DB_USERNAME)"
DB_PASS="$(env_val DB_PASSWORD)"
PASSPHRASE="${BACKUP_PASSPHRASE:-$(env_val BACKUP_PASSPHRASE)}"
REMOTE="${BACKUP_REMOTE:-$(env_val BACKUP_REMOTE)}"

# Ergebnis IMMER festhalten - auch beim Abbruch. Ohne diese Datei heisst
# "kein Fehler im Log" nur, dass niemand hingesehen hat; die
# Systemzustand-Seite und /gesundheit lesen sie aus.
ERGEBNIS="fehler"
MELDUNG="Abbruch vor dem ersten Schritt"
status_schreiben() {
  mkdir -p "$(dirname "$STATUS_DATEI")" 2>/dev/null || true
  local verschluesselt="false" extern="false"
  [ -n "${PASSPHRASE:-}" ] && verschluesselt="true"
  [ -n "${REMOTE:-}" ] && extern="true"
  printf '{"status":"%s","zeitpunkt":"%s","meldung":"%s","verschluesselt":%s,"extern":%s}\n' \
    "$ERGEBNIS" "$(date -Iseconds)" "${MELDUNG//\"/}" \
    "$verschluesselt" "$extern" \
    > "$STATUS_DATEI" 2>/dev/null || true
  chmod 600 "$STATUS_DATEI" 2>/dev/null || true
}
trap status_schreiben EXIT

fehler() { MELDUNG="$1"; echo "!! FEHLER: $1" >&2; exit 1; }

[ -n "$DB_NAME" ] || fehler "DB_DATABASE nicht in .env gefunden"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

echo "▶ Backup gestartet: $(date '+%Y-%m-%d %H:%M:%S') -> $BACKUP_DIR"

# ---------------------------------------------------------------- 0) Schutz
#
# OHNE PASSWORT WIRD NICHT VERSCHLUESSELT - und dann geht auch NICHTS
# nach draussen. Unverschluesselte Kundendaten an einen fremden
# Speicherort zu schicken waere schlimmer als gar keine externe Kopie.
VERSCHLUESSELN=1
if [ -z "$PASSPHRASE" ]; then
  VERSCHLUESSELN=0
  echo "  ⚠ BACKUP_PASSPHRASE fehlt - Sicherung bleibt UNVERSCHLUESSELT und LOKAL."
  if [ -n "$REMOTE" ]; then
    fehler "BACKUP_REMOTE gesetzt, aber BACKUP_PASSPHRASE fehlt - es werden keine unverschluesselten Kundendaten uebertragen."
  fi
elif ! command -v gpg >/dev/null 2>&1; then
  fehler "gpg fehlt (apt install gnupg) - ohne Verschluesselung wird nicht gesichert."
fi

# Das Passwort geht ueber einen Dateideskriptor an gpg, nie ueber die
# Kommandozeile: Argumente sind fuer jeden Prozess auf dem Server in
# `ps` sichtbar.
verschluesseln() { # $1 = Klartextdatei -> $1.gpg, Original wird geloescht
  [ "$VERSCHLUESSELN" -eq 1 ] || return 0
  printf '%s' "$PASSPHRASE" | gpg --batch --yes --quiet \
    --passphrase-fd 0 --pinentry-mode loopback \
    --symmetric --cipher-algo AES256 --compress-algo none \
    --output "$1.gpg" "$1"
  rm -f "$1"
  chmod 600 "$1.gpg"
}

entschluesseln_nach_stdout() { # $1 = .gpg-Datei
  printf '%s' "$PASSPHRASE" | gpg --batch --yes --quiet \
    --passphrase-fd 0 --pinentry-mode loopback --decrypt "$1"
}

# ------------------------------------------------------------- 1) Datenbank
MELDUNG="Datenbank-Dump fehlgeschlagen"
MYSQL_PWD="$DB_PASS" mysqldump \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --routines --triggers --no-tablespaces \
  "$DB_NAME" | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"

# Ein leerer oder winziger Dump ist ein STILLER Totalausfall: das Skript
# laeuft durch, die Datei existiert, und beim Wiederherstellen ist die
# Datenbank leer. Deshalb eine Untergrenze und eine echte gzip-Pruefung.
DB_BYTES="$(stat -c%s "$BACKUP_DIR/db-$STAMP.sql.gz")"
[ "$DB_BYTES" -gt 1024 ] || fehler "DB-Dump ist nur $DB_BYTES Byte gross - das kann nicht stimmen."
gzip -t "$BACKUP_DIR/db-$STAMP.sql.gz" || fehler "DB-Dump ist kein lesbares gzip."
echo "  DB-Dump: $(du -h "$BACKUP_DIR/db-$STAMP.sql.gz" | cut -f1)"

# -------------------------------------------------------------- 2) Dateien
MELDUNG="Dateisicherung fehlgeschlagen"
# Exit-Code 1 ("file changed as we read it") wird toleriert: bei laufenden
# Uploads schreibt die App parallel in storage/app. Nur >=2 ist ein echter
# Fehler. storage/framework und storage/logs bleiben draussen (Caches).
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" \
  --exclude='storage/framework' --exclude='storage/logs' \
  --exclude='storage/app/private/backup-status.json' \
  storage/app || { rc=$?; [ "$rc" -le 1 ] || exit "$rc"; \
  echo "  Hinweis: tar meldete geaenderte Dateien waehrend des Laufs (rc=1) - Archiv dennoch erstellt."; }
gzip -t "$BACKUP_DIR/storage-$STAMP.tar.gz" || fehler "Dateiarchiv ist kein lesbares gzip."
echo "  Dateien: $(du -h "$BACKUP_DIR/storage-$STAMP.tar.gz" | cut -f1)"

# ---------------------------------------------------------------- 3) .env
#
# Die .env traegt SAEMTLICHE Zugangsdaten (Datenbank, Mail, API-Keys).
# Sie wird nur mitgesichert, WENN verschluesselt wird - eine
# Klartext-Kopie neben dem Datenbestand waere der Generalschluessel
# direkt daneben.
MELDUNG="env-Sicherung fehlgeschlagen"
if [ "$VERSCHLUESSELN" -eq 1 ]; then
  cp .env "$BACKUP_DIR/env-$STAMP"
  chmod 600 "$BACKUP_DIR/env-$STAMP"
  verschluesseln "$BACKUP_DIR/env-$STAMP"
else
  echo "  ⚠ .env wird OHNE Passwort nicht mitgesichert (sie enthaelt alle Zugangsdaten)."
fi

# ------------------------------------------------------- 4) Verschluesseln
MELDUNG="Verschluesselung fehlgeschlagen"
verschluesseln "$BACKUP_DIR/db-$STAMP.sql.gz"
verschluesseln "$BACKUP_DIR/storage-$STAMP.tar.gz"

ENDUNG=""
[ "$VERSCHLUESSELN" -eq 1 ] && ENDUNG=".gpg"
DB_DATEI="$BACKUP_DIR/db-$STAMP.sql.gz$ENDUNG"
ST_DATEI="$BACKUP_DIR/storage-$STAMP.tar.gz$ENDUNG"

# ------------------------------------------------------------- 5) PRUEFEN
#
# Der wichtigste Schritt. "Das Skript lief durch" ist keine Zusicherung -
# geprueft wird, ob sich das ERGEBNIS wieder oeffnen laesst. Dafuer wird
# entschluesselt, entpackt und im SQL nach einer Zeile gesucht, die in
# jedem echten Dump steht.
MELDUNG="Pruefung der Sicherung fehlgeschlagen"

# ACHTUNG pipefail: `grep -q` und `head -c` beenden sich, SOBALD sie genug
# gesehen haben. Das schickt dem vorgelagerten gpg/gzip ein SIGPIPE, und
# unter `set -o pipefail` gilt die ganze Pipeline dann als fehlgeschlagen -
# AUSGERECHNET dann, wenn die Pruefung erfolgreich war. Genau in diese
# Falle lief die erste Fassung: die Sicherung war einwandfrei, das Skript
# meldete trotzdem "laesst sich nicht lesen". Deshalb wird gezaehlt statt
# frueh abgebrochen.
dump_lesen() {
  if [ "$VERSCHLUESSELN" -eq 1 ]; then
    entschluesseln_nach_stdout "$DB_DATEI" 2>/dev/null | gzip -dc 2>/dev/null || true
  else
    gzip -dc "$DB_DATEI" 2>/dev/null || true
  fi
}

TABELLEN="$(dump_lesen | grep -c '^CREATE TABLE' || true)"
[ "${TABELLEN:-0}" -gt 0 ] \
  || fehler "Die Sicherung laesst sich nicht lesen oder enthaelt keine einzige Tabelle."

if [ "$VERSCHLUESSELN" -eq 1 ]; then
  entschluesseln_nach_stdout "$ST_DATEI" 2>/dev/null | tar -tzf - >/dev/null 2>&1 \
    || fehler "Das verschluesselte Dateiarchiv laesst sich nicht lesen."
else
  tar -tzf "$ST_DATEI" >/dev/null 2>&1 || fehler "Das Dateiarchiv laesst sich nicht lesen."
fi
echo "  Pruefung: entschluesselbar und lesbar ($TABELLEN Tabellen im Dump)."

# ------------------------------------------------- 6) Zweiter Speicherort
#
# Erst JETZT, nach der Pruefung: eine kaputte Datei nach draussen zu
# kopieren wuerde dort nur eine kaputte Datei ergeben.
MELDUNG="Uebertragung an den zweiten Speicherort fehlgeschlagen"
if [ -n "$REMOTE" ]; then
  command -v rclone >/dev/null 2>&1 || fehler "BACKUP_REMOTE gesetzt, aber rclone fehlt."
  rclone copy "$DB_DATEI" "$REMOTE/" --quiet
  rclone copy "$ST_DATEI" "$REMOTE/" --quiet
  [ -f "$BACKUP_DIR/env-$STAMP$ENDUNG" ] && rclone copy "$BACKUP_DIR/env-$STAMP$ENDUNG" "$REMOTE/" --quiet
  # Gegenprobe: liegt die Datei dort wirklich? Ein `copy` ohne Fehler
  # heisst noch nicht, dass am Ziel etwas angekommen ist.
  rclone lsf "$REMOTE/" 2>/dev/null | grep -q "$(basename "$DB_DATEI")" \
    || fehler "Die Sicherung ist am zweiten Speicherort nicht auffindbar."
  echo "  Zweiter Speicherort: uebertragen und gegengeprueft ($REMOTE)"

  # Rotation auch dort - sonst waechst der externe Speicher unbegrenzt.
  rclone delete "$REMOTE/" --min-age "${KEEP_DAYS}d" --quiet || \
    echo "  Hinweis: Rotation am zweiten Speicherort nicht moeglich."
else
  echo "  ⚠ Kein BACKUP_REMOTE gesetzt - die Sicherung liegt NUR auf diesem Server."
fi

# ------------------------------------------------------------ 7) Rotation
MELDUNG="Rotation fehlgeschlagen"
find "$BACKUP_DIR" -maxdepth 1 \
  \( -name 'db-*.sql.gz*' -o -name 'storage-*.tar.gz*' -o -name 'env-*' \) \
  -mtime +"$KEEP_DAYS" -delete

ERGEBNIS="ok"
# ACHTUNG set -e: eine Zuweisung, deren Kommando-Ersetzung mit einem
# Status != 0 endet, BRICHT DAS SKRIPT AB. Ein
#   MELDUNG="...$([ -n "$REMOTE" ] && echo ' extern')"
# beendete den Lauf hier lautlos, wenn kein zweiter Speicherort gesetzt
# war - die Statusdatei meldete dank der bereits gesetzten Variable
# trotzdem "ok", und die Abschlusszeile im Log fehlte einfach. Genau die
# Sorte stiller Fehler, wegen der es diese Pruefung ueberhaupt gibt.
# Deshalb: ein gewoehnliches if, keine Bedingung in der Zuweisung.
if [ -n "$REMOTE" ]; then
  MELDUNG="Sicherung erstellt, geprueft und extern abgelegt"
else
  MELDUNG="Sicherung erstellt und geprueft (nur lokal)"
fi
echo "✔ Backup fertig: $(date '+%Y-%m-%d %H:%M:%S') (Rotation: ${KEEP_DAYS} Tage)"
