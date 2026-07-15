# Upload schlaegt mit "413 Request Entity Too Large" fehl

## Symptom

Beim Hochladen eines Dokuments (Portal -> Dokumente -> Dokument hochladen)
erscheint statt einer Erfolgsmeldung:

```
413 Request Entity Too Large
nginx/1.24.0 (Ubuntu)
```

## Ursache

Das ist **kein Code-Fehler**. Die Anwendung erlaubt Dateien bis 10 MB
(`max:10240` in der Validierung). nginx blockt die Anfrage aber schon
**vor** PHP, weil `client_max_body_size` standardmaessig nur 1 MB ist.
Eine groessere Test-PDF wird deshalb sofort mit 413 abgewiesen.

Zusaetzlich muessen die PHP-Limits (`upload_max_filesize`, `post_max_size`)
mindestens so gross sein wie das App-Limit.

## Loesung auf dem Server (einmalig)

### 1) nginx-Limit anheben

In der Server-Konfiguration (z. B. `/etc/nginx/sites-available/portal`)
im `server { ... }`-Block ergaenzen:

```
client_max_body_size 12M;
```

Danach:

```
sudo nginx -t && sudo systemctl reload nginx
```

### 2) PHP-Limits anheben

In der passenden `php.ini` (FPM, z. B.
`/etc/php/8.3/fpm/php.ini`) setzen:

```
upload_max_filesize = 12M
post_max_size = 13M
```

Danach PHP-FPM neu laden:

```
sudo systemctl reload php8.3-fpm
```

## Warum 12M / 13M

Das App-Limit ist 10 MB. Puffer nach oben (12M nginx, 12M
`upload_max_filesize`) vermeidet, dass eine Datei knapp unter 10 MB an
Multipart-Overhead scheitert. `post_max_size` sollte etwas groesser sein
als `upload_max_filesize`.

## Gegenprobe

Nach der Aenderung eine ~5 MB grosse PDF hochladen -> muss durchgehen und
als neues Dokument erscheinen. Kommt weiterhin 413, greift eine zweite
nginx-Konfiguration (z. B. globaler `http`-Block in `/etc/nginx/nginx.conf`)
oder ein vorgelagerter Proxy/Cloudflare mit eigenem Limit.
