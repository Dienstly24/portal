# Automatischer Deploy (CI/CD)

Ablauf: **Push auf `main` → GitHub Actions baut & testet → nur bei grünem Ergebnis wird automatisch auf den Server deployed.**
Pull Requests lösen nur die Tests aus (kein Deploy). Definiert in `.github/workflows/deploy.yml`, der eigentliche Server-Ablauf in `scripts/deploy.sh`.

## Einmalige Einrichtung

### 1. GitHub Secrets anlegen
`GitHub → Repo Settings → Secrets and variables → Actions → New repository secret`.
Diese Werte liegen verschlüsselt bei GitHub – **niemals im Code, niemals im Chat**:

| Secret | Beispiel | Bedeutung |
|---|---|---|
| `SSH_HOST` | `123.45.67.89` oder `server.hostinger.com` | Adresse des Produktionsservers |
| `SSH_USER` | `dienstly` | SSH-Benutzer |
| `SSH_PORT` | `22` | SSH-Port (bei Hostinger oft `65002`) |
| `SSH_PRIVATE_KEY` | *(Inhalt des privaten Schlüssels)* | Deploy-Key, siehe unten |
| `DEPLOY_PATH` | `/home/dienstly/portal` | Projektpfad auf dem Server |

### 2. Deploy-SSH-Key erzeugen (einmalig, lokal)
```bash
ssh-keygen -t ed25519 -C "github-deploy" -f deploy_key -N ""
```
- **Öffentlichen** Teil (`deploy_key.pub`) auf den Server in `~/.ssh/authorized_keys` eintragen.
- **Privaten** Teil (`deploy_key`) als Secret `SSH_PRIVATE_KEY` bei GitHub hinterlegen.
- Danach die lokalen Schlüsseldateien löschen.

### 3. Environment „production" (empfohlen)
`Settings → Environments → New environment → production`. Dort optional **Required reviewers** setzen – dann muss ein Deploy nach grünen Tests noch einmal per Klick bestätigt werden (zusätzliche Sicherheitsstufe). Der Workflow referenziert dieses Environment bereits.

### 4. Server-Voraussetzungen (einmalig)
- PHP 8.3, Composer, Node/npm installiert.
- Repository ist als Git-Clone unter `DEPLOY_PATH` ausgecheckt und zeigt auf `origin` = dieses GitHub-Repo.
- `.env` ist **produktiv gepflegt** (wird vom Deploy NICHT überschrieben) – inkl. `APP_ENV=production`, `APP_DEBUG=false`, DB-Zugang, Mail, und den optionalen Integrationsschlüsseln aus `.env.example`.
- **Zwei Dauerprozesse** über systemd/Supervisor (der Deploy startet die Queue nur neu, er hält die Prozesse nicht am Leben):
  ```
  php artisan schedule:work     # Cronjobs (Mail-Sync, Prune, Reminder)
  php artisan queue:work        # verschickt die Queue-Mails
  ```
  Beispiel systemd-Unit für die Queue:
  ```ini
  [Unit]
  Description=Dienstly Queue Worker
  After=network.target
  [Service]
  User=dienstly
  Restart=always
  WorkingDirectory=/home/dienstly/portal
  ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
  [Install]
  WantedBy=multi-user.target
  ```

## Was beim Deploy passiert (`scripts/deploy.sh`)
1. Wartungsmodus an (`php artisan down`) – mit **Trap**, der die App bei jedem Ausgang (auch Fehler) wieder online nimmt.
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build` (Frontend-Assets)
4. `php artisan migrate --force`
5. `config:cache` / `route:cache` / `view:cache` / `event:cache`
6. `php artisan queue:restart` (Worker laden neuen Code)
7. Wartungsmodus aus.

## Sicherheit
- Deploy läuft **nur** nach grüner Testsuite (`needs: test`) und **nur** bei Push auf `main` (nicht bei PRs).
- `concurrency` verhindert parallele Deploys.
- Fehlgeschlagenes Deploy lässt die App dank Trap nicht offline zurück; die Migration ist additiv.
- Rollback im Notfall: auf dem Server `git reset --hard <letzter-guter-commit>` + `bash scripts/deploy.sh`.

<!-- Erster automatischer Deploy-Test: 2026-07-11 -->
