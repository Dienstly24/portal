# Sicherung und Wiederherstellung

Stand: 15.09.2026 (Audit "Full System Remediation")

---

## Warum dieses Dokument existiert

Vor dem Audit gab es `scripts/backup.sh`, und es lief. Aber:

1. **Die Sicherung lag auf demselben Server wie das Original.**
   Ein Plattenfehler, eine geloeschte VM oder eine Verschluesselungs-
   Erpressung haette Original UND Sicherung auf einmal getroffen. Eine
   Kopie neben dem Original ist kein Backup, sondern eine zweite Datei.

2. **Sie lag im Klartext** - inklusive einer vollstaendigen Kopie der
   `.env`. Das Archiv enthaelt IBANs, Ausweisdaten, Gesundheitsdaten
   (Art. 9 DSGVO) und saemtliche Zugangsdaten. Wer die Datei bekam,
   hatte den Betrieb.

3. **"Gelaufen" hiess nicht "brauchbar".** Geprueft wurde nie, ob sich
   die Datei wieder oeffnen laesst. Ein stiller Fehlschlag faellt erst
   bei der Wiederherstellung auf - also genau dann, wenn man ihn nicht
   gebrauchen kann.

4. **Die Wiederherstellung war nie geuebt.** Sie stand als Satz in einem
   anderen Dokument. Ein Ablauf, den niemand durchgespielt hat, ist eine
   Hoffnung, keine Sicherung.

Alle vier Punkte sind behoben. Dieses Dokument beschreibt den Zustand
danach.

---

## Was die Sicherung heute tut

`scripts/backup.sh`, taeglich per Cron:

| Schritt | Was passiert | Warum |
|---|---|---|
| 1 | `mysqldump --single-transaction` | Dump ohne Tabellensperre |
| 2 | Groessen- und gzip-Pruefung | Ein leerer Dump ist ein stiller Totalausfall |
| 3 | `tar` ueber `storage/app` | Dokumente, Nachweise, Medien-Originale |
| 4 | `.env`-Kopie **nur wenn verschluesselt wird** | Sonst laege der Generalschluessel daneben |
| 5 | **GPG AES-256** | Das Archiv ist ohne Passwort wertlos |
| 6 | **Wieder entschluesseln und lesen** | Beweis statt Annahme |
| 7 | Optional: `rclone` an einen zweiten Ort + Gegenprobe | Schutz vor Serverausfall |
| 8 | Rotation (`BACKUP_KEEP_DAYS`, Standard 14), lokal UND extern | Sonst waechst der Speicher unbegrenzt |
| 9 | Statusdatei schreiben - **immer**, auch beim Abbruch | Damit ein Fehlschlag sichtbar wird |

### Zwei harte Regeln im Skript

- **Ohne `BACKUP_PASSPHRASE` geht NICHTS nach draussen.** Ist ein
  `BACKUP_REMOTE` gesetzt, aber kein Passwort, bricht das Skript ab.
  Unverschluesselte Kundendaten an einen fremden Speicher zu geben waere
  schlimmer als gar keine externe Kopie.
- **Uebertragen wird erst NACH der Pruefung.** Eine kaputte Datei nach
  draussen zu kopieren ergibt dort nur eine kaputte Datei.

---

## Einrichtung auf dem VPS

```bash
# 1) Passwort erzeugen und AUSSERHALB des Servers verwahren
#    (Passwortmanager). Ohne dieses Passwort ist KEINE Sicherung mehr
#    lesbar - es gibt keine Hintertuer.
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# 2) In die Server-.env (NICHT ins Repository):
#    BACKUP_PASSPHRASE=<der erzeugte Wert>
#    BACKUP_REMOTE=hetzner:dienstly24-backups      # optional, s. u.

# 3) gnupg sicherstellen
apt install -y gnupg

# 4) Cron
crontab -e
30 2 * * * cd /var/www/dienstly24/portal && bash scripts/backup.sh \
  >> /var/log/dienstly24-backup.log 2>&1
```

### Zweiter Speicherort (empfohlen)

`rclone` mit einem Anbieter **in der EU** (Auftragsverarbeitung!), z. B.
eine Hetzner Storage Box:

```bash
apt install -y rclone
rclone config          # Remote anlegen, z. B. Name "hetzner"
# danach in der .env:  BACKUP_REMOTE=hetzner:dienstly24-backups
```

**Datenschutz:** Die Dateien sind vor der Uebertragung mit AES-256
verschluesselt; der Anbieter sieht nur eine unlesbare Datei. Trotzdem
gehoert der Speicheranbieter in das Verarbeitungsverzeichnis und braucht
einen AV-Vertrag.

---

## Wird ein Fehlschlag bemerkt?

Ja - an drei Stellen, ohne dass jemand ein Log oeffnen muss:

1. **`/admin/systemzustand`** hat einen Abschnitt "Sicherung":
   letzter Lauf, Zeitpunkt, verschluesselt ja/nein, zweiter Ort ja/nein.
2. **`/gesundheit`** (externe Ueberwachung) fuehrt `backup` in den
   Pruefpunkten; ein Fehlschlag macht die Antwort zu **HTTP 503**, und
   der Ueberwachungsdienst alarmiert.
3. **Ein ALTER Erfolg gilt nicht als Erfolg:** ist der letzte Lauf
   aelter als 48 Stunden, wird der Abschnitt ROT. Sonst bliebe die
   Anzeige nach einem ausgefallenen Cron fuer immer auf "ok" stehen.

---

## Wiederherstellung

### Regelmaessige Probe (gefahrlos, aendert nichts)

```bash
cd /var/www/dienstly24/portal
bash scripts/restore.sh --pruefen
```

Das Skript entschluesselt die juengste Sicherung, zaehlt die Tabellen,
prueft ausdruecklich auf `customers`, `contracts`, `users`, `documents`
und `tickets` und listet das Dateiarchiv. Es schreibt **nichts**.

> **Mindestens vierteljaehrlich ausfuehren** - und nach jeder Aenderung an
> Datenbank, Server oder Backup-Einstellungen. Ein Backup, dessen
> Wiederherstellung nie geprobt wurde, ist nicht geprueft.

### Probe-Wiederherstellung in eine Ausweichdatenbank

```bash
bash scripts/restore.sh --wiederherstellen dienstly24_restore
```

Das Skript **verweigert** die produktive Datenbank aus der `.env`.

### Ernstfall: Produktion zuruecksetzen

Bewusst **von Hand**, nicht per Schalter:

1. `php artisan down`
2. **Aktuellen Stand zuerst sichern:** `bash scripts/backup.sh`
3. Probe in die Ausweichdatenbank (siehe oben)
4. Stichproben dort: Kundenzahl, letzte Vertraege, ein Dokumentpfad
5. Erst dann die produktive Datenbank ersetzen
6. Dateien: `tar -xzf storage-STAMP.tar.gz -C /var/www/dienstly24/portal`
   (bei verschluesselten Archiven vorher `gpg --decrypt`)
7. `php artisan config:clear && php artisan up`

### Wenn das Passwort fehlt

Dann ist die Sicherung **nicht wiederherstellbar**. Es gibt keine
Hintertuer und keinen Zweitschluessel. Deshalb: das Passwort gehoert in
einen Passwortmanager ausserhalb des Servers, und mindestens zwei
Personen muessen herankommen.

---

## Aufbewahrung

| Was | Wie lange | Wo |
|---|---|---|
| Taegliche Sicherung | `BACKUP_KEEP_DAYS` (Standard 14 Tage) | lokal + extern |
| Rotation extern | derselbe Wert, per `rclone delete --min-age` | extern |

Laengere Aufbewahrung (Monats-/Jahresstaende) ist bewusst NICHT
automatisiert: sie ist eine Aufbewahrungs- und Loeschfristen-Entscheidung
nach DSGVO, die der Betreiber treffen muss - nicht das Skript.

---

## Tests

`tests/Feature/BackupHealthTest.php` haelt fest:
Fehlschlag wird rot, ein veralteter Erfolg wird rot, eine
unverschluesselte Sicherung wird rot, ein fehlender zweiter Speicherort
warnt, eine unlesbare Statusdatei stuerzt nicht ab, der Zustand erscheint
im externen Endpunkt - und die Statusdatei enthaelt nie ein Geheimnis.
