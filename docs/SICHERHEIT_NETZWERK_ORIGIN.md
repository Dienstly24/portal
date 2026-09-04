# SEC-2: Vertrauenswuerdige Proxys, echte Client-IP und der direkte Origin-Zugriff

Stand: 03.09.2026

## Worum es geht

Diese Anwendung entscheidet an vielen Stellen anhand der **Client-IP**:

| Stelle | Datei | Wirkung |
|---|---|---|
| Rate-Limit Login (E-Mail + IP) | `app/Http/Requests/Auth/LoginRequest.php` | Bremst Passwort-Raten |
| Rate-Limit Login (nur IP) | `routes/auth.php` | Bremst Password-Spraying |
| Rate-Limit Passwort-Reset | `routes/auth.php` | Bremst Mail-Bombing / Adress-Probing |
| Rate-Limit Registrierung | `routes/auth.php` | Bremst Massen-Anlage von Konten |
| Rate-Limit 2FA-Eingabe | `app/Http/Controllers/Auth/TwoFactorController.php` | Bremst Code-Raten |
| ActivityLog | `app/Services/Activity/ActivityTracker.php` | Wer war wann von wo aktiv |
| DSGVO-Einwilligungsnachweis | `CustomerConsent.ip_address` (Registrierung, Portal, Website) | **Beweismittel** nach Art. 7 DSGVO |

Alle diese Stellen lesen `$request->ip()`. Was `$request->ip()` zurueckgibt,
haengt ausschliesslich davon ab, **welchen Proxys die Anwendung den
`X-Forwarded-For`-Header glaubt**.

## Der Befund

`bootstrap/app.php` stand auf:

```php
$middleware->trustProxies(at: '*', headers: ... HEADER_X_FORWARDED_FOR ...);
```

`'*'` heisst: **jede** Anfrage darf ihre eigene Client-IP behaupten - auch
eine, die gar nicht ueber den Proxy kam. Wer die Origin-IP des VPS kennt,
konnte damit:

```
curl -H 'X-Forwarded-For: 1.2.3.4' https://<origin-ip>/login
curl -H 'X-Forwarded-For: 1.2.3.5' https://<origin-ip>/login
...
```

Jeder Aufruf landet in einem **anderen** Rate-Limit-Eimer - die Bremsen
fuer Login, Reset und Registrierung sind damit wirkungslos. Und in
ActivityLog wie in den Einwilligungsnachweisen steht eine **vom Absender
frei gewaehlte** IP, also ausgerechnet dort eine erfundene Angabe, wo sie
im Streitfall etwas belegen soll.

## Was im Code behoben wurde

`trustProxies` bekommt jetzt eine **explizite Liste**
(`App\Support\TrustedProxies::resolve()`, Werte in
`config/trustedproxy.php`):

* Standard: veroeffentlichte **Cloudflare-Ranges** + **Loopback**
  (`127.0.0.1`, `::1`) fuer den nginx auf derselben Maschine.
* Ueberschreibbar per `TRUSTED_PROXIES` in der Server-`.env`
  (kommagetrennte IPs/CIDRs).
* `'*'` bleibt moeglich, ist aber eine **bewusste, dokumentierte
  Entscheidung** und nicht mehr der Standard.

Diese Standardliste ist in **jedem** plausiblen Aufbau korrekt:

| Aufbau | REMOTE_ADDR aus Sicht von PHP | Ergebnis |
|---|---|---|
| Client → Cloudflare → nginx → php-fpm | Cloudflare-IP | XFF wird geglaubt → **echte** Client-IP |
| Client → nginx → php-fpm (ohne CF) | `127.0.0.1` | XFF wird geglaubt → **echte** Client-IP |
| Client → nginx direkt, kein XFF | Client-IP | nichts zu glauben → **echte** Client-IP |
| Angreifer direkt am Origin, XFF gesetzt | Angreifer-IP (nicht in der Liste) | XFF wird **ignoriert** → echte Angreifer-IP |

**Damit ist das Abnahmekriterium erfuellt**: Ein Angreifer, der direkt den
Origin erreicht und `X-Forwarded-For: beliebige-ip` sendet, bekommt
**keinen** neuen Rate-Limit-Eimer mehr - seine tatsaechliche IP zaehlt.
Das gilt unabhaengig davon, ob die Firewall den Origin abschirmt.
Abgesichert durch `tests/Feature/Security/ProxySpoofingTest.php`.

## Was im Code NICHT behoben werden kann

Ob der Origin **direkt aus dem Internet erreichbar** ist, ist eine Frage
der Netzwerkkonfiguration und steht nicht im Repository. Der Code-Fix
oben nimmt dem direkten Zugriff zwar die IP-Faelschung, aber nicht die
uebrigen Nachteile:

* Cloudflare-WAF, Bot-Management und DDoS-Schutz werden umgangen.
* Die Origin-IP wird durch Scans/Zertifikats-Transparenz auffindbar.
* Ein `ExtraBasicAuth`-Schutz vor `/admin` ist dann die einzige Schicht,
  die noch dazwischensteht.

### Warum das hier nicht geprueft werden konnte

Der Versuch, das aus der Entwicklungsumgebung heraus festzustellen,
scheitert nachweisbar an der Umgebung selbst - **nicht** an fehlendem
Willen:

1. **DNS ist synthetisch.** Drei aufeinanderfolgende Aufloesungen von
   `dienstly24.de` lieferten drei verschiedene Adresspaare
   (`147.79.105.130 / 91.108.127.73`, dann `77.37.42.128 / 89.116.213.35`,
   dann `147.79.105.124 / 89.116.213.103`). Keine davon liegt in einer
   Cloudflare-Range. Eine Aufloesung, die sich bei jedem Aufruf aendert,
   ist keine Grundlage fuer eine Sicherheitsaussage.
2. **Ausgehende Verbindungen zum Zielhost sind gesperrt.**
   `curl -I https://www.dienstly24.de` endet mit
   `CONNECT tunnel failed, response 403` am Agent-Proxy. Es lassen sich
   also weder Edge-Header (`server: cloudflare`, `cf-ray`) noch ein
   direkter Origin-Zugriff pruefen.
3. **Kein SSH-Zugang zum VPS** aus dieser Umgebung - Firewall-Regeln
   (`ufw status`, `iptables -L`, Hostinger-Firewall) sind nicht einsehbar.

Deshalb wird SEC-2 hier **ausdruecklich nicht** als "Netzwerk geprueft"
abgehakt. Der Code-Teil ist erledigt und getestet; der Netzwerk-Teil ist
die folgende Aufgabe fuer DevOps/den Betreiber.

## Aufgabe fuer DevOps / den Betreiber

> Dieselben Schritte auf Arabisch (Abschnitt 4):
> `docs/ANLEITUNG_SICHERHEIT_AR.md`

### Schritt 1 - Feststellen, ob der Origin direkt erreichbar ist

Auf einem beliebigen Rechner **ausserhalb** des VPS:

```bash
# 1. Zeigt die Domain ueberhaupt auf Cloudflare?
dig +short www.dienstly24.de
curl -sSI https://www.dienstly24.de | grep -iE 'server|cf-ray'
#    -> "server: cloudflare" + "cf-ray: ..."  = Cloudflare ist der Edge

# 2. Die ECHTE Origin-IP steht in der .env / beim Hoster. Damit:
curl -sSI --resolve www.dienstly24.de:443:<ORIGIN-IP> https://www.dienstly24.de
#    -> Antwortet die App:            Origin ist DIREKT erreichbar (Handlungsbedarf)
#    -> Timeout / connection refused: Origin ist abgeschirmt (gut)
```

### Schritt 2 - Direkten Zugriff schliessen (falls Schritt 1 ihn zeigt)

Auf dem VPS, Ports 80/443 nur fuer Cloudflare oeffnen:

```bash
# Cloudflare-Ranges frisch holen und als ufw-Regeln setzen
for ip in $(curl -s https://www.cloudflare.com/ips-v4) \
          $(curl -s https://www.cloudflare.com/ips-v6); do
  ufw allow from "$ip" to any port 80,443 proto tcp comment 'Cloudflare'
done

# Danach den allgemeinen Zugang auf 80/443 entfernen:
ufw delete allow 80/tcp  || true
ufw delete allow 443/tcp || true
ufw status numbered
```

Zusaetzlich in Cloudflare **Authenticated Origin Pulls** aktivieren
(Cloudflare stellt dem Origin ein Client-Zertifikat vor) - damit ist der
Origin auch dann geschuetzt, wenn sich Cloudflare-Ranges aendern.

Wichtig: **Port 22 (SSH) nicht** ueber Cloudflare freigeben, sonst ist der
Server nach der Regel nicht mehr administrierbar. SSH bleibt auf die
Admin-IP bzw. das bestehende Regelwerk beschraenkt.

### Schritt 3 - Ergebnis hier eintragen

| Frage | Antwort | Geprueft am | Von |
|---|---|---|---|
| Ist Cloudflare der Edge-Proxy? | _offen_ | | |
| Ist der Origin direkt per IP erreichbar? | _offen_ | | |
| Firewall auf Cloudflare-Ranges eingeschraenkt? | _offen_ | | |
| Authenticated Origin Pulls aktiv? | _offen_ | | |
| `TRUSTED_PROXIES` in der Server-`.env` gesetzt? | _leer = Standardliste_ | | |

Solange Zeile 2 mit "ja" beantwortet ist und Zeile 3 mit "nein", bleibt
SEC-2 auf Netzwerkseite offen - auch wenn der Code korrekt ist.

## Pflege der Cloudflare-Ranges

Die Liste in `config/trustedproxy.php` ist ein Stand, kein Abonnement.
Cloudflare aendert sie selten, aber nicht nie. `scripts/pruefe-cloudflare-ips.sh`
vergleicht die hinterlegte Liste mit der veroeffentlichten und meldet
Abweichungen; laeuft der Abgleich ins Leere (kein Netz), meldet das Skript
das ehrlich, statt eine leere Liste zu schreiben.
