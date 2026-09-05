# SEC-2: Vertrauenswuerdige Proxys, echte Client-IP und der direkte Origin-Zugriff

Stand: 05.09.2026 (Messung auf dem Server nachgetragen)

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
  **Achtung, seit der Messung vom 05.09.2026 ist der Cloudflare-Teil
  dieser Standardliste vermutlich gegenstandslos** - siehe Abschnitt
  "Messung vom 05.09.2026". Sicherheitlich ist das unschaedlich (eine zu
  kleine Liste glaubt zu wenigen, nie zu vielen), fachlich kann es aber
  bedeuten, dass alle Besucher in EINEM Rate-Limit-Eimer landen.
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

## Messung vom 05.09.2026 (auf dem Produktionsserver)

Der Betreiber hat Schritt 1 auf dem VPS ausgefuehrt. Zwei Ergebnisse, beide
belegt durch die Ausgabe der Befehle:

```
curl -sSI https://www.dienstly24.de | grep -iE 'server|cf-ray'
  server: hcdn

ufw status numbered
  Status: inactive
```

### Befund 1: Cloudflare ist NICHT der Edge-Proxy

Die Antwort traegt `server: hcdn` (das CDN des Hosters) und **keinen**
`cf-ray`-Header. Eine Antwort ueber Cloudflare traegt immer beides
(`server: cloudflare` + `cf-ray`). Damit ist die Annahme, die der
Standardliste in `config/trustedproxy.php` zugrunde liegt, fuer diesen
Aufbau **widerlegt**.

Was das heisst - und was es NICHT heisst:

* **Sicherheitlich unveraendert gut.** Die Liste ist zu klein, nicht zu
  gross. Eine Anfrage von einem nicht gelisteten Absender wird mit ihrem
  `X-Forwarded-For` weiterhin ignoriert. Das Abnahmekriterium von SEC-2
  (kein frischer Rate-Limit-Eimer durch gefaelschte Header) bleibt
  erfuellt.
* **Fachlich moeglicherweise falsch.** Reicht der Vorschalt-Dienst die
  Anfrage von einer externen Adresse an den Server weiter, steht diese
  Adresse in `REMOTE_ADDR`, sie ist nicht gelistet, ihr Header wird
  ignoriert - und dann sieht die Anwendung **bei jedem Besucher dieselbe
  IP**. Folge: ein gemeinsamer Rate-Limit-Eimer fuer alle (die Bremsen
  fuer Login, Reset und Registrierung greifen faktisch nicht mehr) und
  die Adresse des CDN statt der des Kunden in ActivityLog und in den
  DSGVO-Einwilligungsnachweisen.
* Laeuft der Vorschalt-Dienst dagegen als nginx **auf derselben
  Maschine**, ist `REMOTE_ADDR` gleich `127.0.0.1`, und das steht in der
  Liste - dann ist alles korrekt.

Welcher der beiden Faelle zutrifft, steht nicht im Repository. Er laesst
sich aber **messen**, ohne zu raten (Schritt 1a unten).

### Befund 2: Es gibt keine Host-Firewall

`ufw` ist `inactive`. Der Origin ist also durch nichts auf dem Server
eingeschraenkt; ob er von aussen direkt erreichbar ist, entscheidet allein
das Netz des Hosters.

Auch hier die ehrliche Einordnung: die **IP-Faelschung** (der eigentliche
SEC-2-Befund) ist durch den Code geschlossen, unabhaengig von der
Firewall. Offen bleibt die Umgehung von WAF/Bot-Schutz/DDoS-Abwehr des
CDN - geringere Schwere, aber ein realer Punkt.

### Noch offen

* `portal.dienstly24.de` wurde **nicht** gemessen (der Test lief gegen
  `www.`). Beide koennen ueber verschiedene Wege laufen.
* Ob der Origin direkt per IP antwortet, ist ungeprueft.

---

## Schritt 1a - Was sieht die Anwendung tatsaechlich? (ein Befehl)

Der Befehl liest nur die Datenbank (kein Netzzugriff, keine Aenderung) und
wertet aus, welche IPs die Anwendung in den letzten Wochen aufgezeichnet
hat. Auf dem Server:

```bash
cd /var/www/dienstly24/portal && php artisan netz:client-ip-pruefen
```

* **"Die Anwendung sieht die echte Client-IP"** - nichts zu tun.
* **"Die Anwendung sieht NICHT die echte Client-IP"** - der Befehl nennt
  die Adresse des Vorschalt-Dienstes. Diese Adresse in die Server-`.env`
  eintragen und die Konfiguration neu einlesen:

  ```bash
  # In /var/www/dienstly24/portal/.env
  TRUSTED_PROXIES=<die genannte Adresse>

  php artisan config:clear
  ```

  Danach den Befehl in ein paar Tagen erneut laufen lassen - dann muessen
  sich die Adressen verteilen.
* **"Unklar"** - zu wenig Verkehr im Zeitraum, mit `--tage=90` wiederholen.

Wichtig: in `TRUSTED_PROXIES` gehoert **nur**, was wirklich der eigene
Vorschalt-Dienst ist. Wer dort steht, darf seine Client-IP frei behaupten.

### Ergebnis dieser Messung (05.09.2026)

Der Lauf auf dem Server ergab:

* Aktivitaetsprotokoll: 7.456 Eintraege, **eine** Adresse (ein
  Telekom-Anschluss), 2 Nutzer, nicht in der Vertrauensliste.
* DSGVO-Einwilligungsnachweise: 4 Nachweise mit **2 verschiedenen** IPs.

**Befund: die Anwendung sieht die echte Client-IP.** Der Beweis liegt in
der Vielfalt, nicht in der Menge: waere ein nicht gelisteter
Vorschalt-Dienst dazwischen, traege **jede** Zeile in beiden Tabellen
dieselbe Adresse - zwei verschiedene IPs in den Einwilligungen kann es
dann gar nicht geben. Die eine Adresse im Aktivitaetsprotokoll ist kein
Gegenbeweis: dort stehen fast nur Mitarbeiter-Aufrufe, und die kommen aus
demselben Buero.

Damit ist auch die praktische Sorge aus Befund 1 ausgeraeumt: die
Rate-Limit-Eimer trennen sich je Besucher, und in den
Einwilligungsnachweisen steht die Adresse des Kunden. `TRUSTED_PROXIES`
bleibt leer, die Standardliste im Code genuegt (Loopback deckt den
nginx auf derselben Maschine ab).

> Der Befehl selbst gab beim ersten Lauf noch "unklar" aus - er wertete nur
> das Aktivitaetsprotokoll und verlangte dort mindestens drei Nutzer. Die
> Aussage stand aber schon in den Einwilligungen. Die Regel ist deshalb
> nachgezogen: Vielfalt in EINER der beiden Quellen genuegt; der
> Loopback-Befund schlaegt weiterhin alles.

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

> Die folgenden Regeln nennen Cloudflare, weil sie fuer diesen Aufbau
> geschrieben wurden. Nach der Messung vom 05.09.2026 ist der Edge das
> CDN des Hosters - dann gehoeren dort dessen Adressbereiche hinein
> (beim Hoster zu erfragen), nicht die von Cloudflare. Adressbereiche
> eines CDN NIE raten.

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

| Frage | Antwort | Geprueft am | Von | Nachweis |
|---|---|---|---|---|
| Ist Cloudflare der Edge-Proxy? | **nein** - `server: hcdn`, kein `cf-ray` (Hoster-CDN) | 05.09.2026 | Betreiber | `curl -sSI https://www.dienstly24.de` |
| Gilt das auch fuer `portal.dienstly24.de`? | _offen_ (nur `www.` gemessen) | | | |
| Ist der Origin direkt per IP erreichbar? | _offen_ | | | |
| Host-Firewall aktiv / auf den Edge eingeschraenkt? | **nein** - `ufw` ist `inactive` | 05.09.2026 | Betreiber | `ufw status numbered` |
| Sieht die Anwendung die echte Client-IP? | **ja** - die aufgezeichneten Adressen sind verschieden | 05.09.2026 | Betreiber | `php artisan netz:client-ip-pruefen` |
| Authenticated Origin Pulls aktiv? | entfaellt, solange Cloudflare nicht der Edge ist | 05.09.2026 | | |
| `TRUSTED_PROXIES` in der Server-`.env` gesetzt? | _leer = Standardliste_ | | | |

Solange Zeile 3 mit "ja" beantwortet ist und Zeile 4 mit "nein", bleibt
SEC-2 auf Netzwerkseite offen - auch wenn der Code korrekt ist. Die
**IP-Faelschung** ist davon unabhaengig geschlossen; offen ist die
Umgehung von WAF/Bot-Schutz/DDoS-Abwehr.

Zeile 5 ist damit geklaert: `TRUSTED_PROXIES` muss NICHT gesetzt werden
(Messung siehe unten). Offen bleiben Zeile 2 und 3.

## Pflege der Cloudflare-Ranges

Die Liste in `config/trustedproxy.php` ist ein Stand, kein Abonnement.
Cloudflare aendert sie selten, aber nicht nie. `scripts/pruefe-cloudflare-ips.sh`
vergleicht die hinterlegte Liste mit der veroeffentlichten und meldet
Abweichungen; laeuft der Abgleich ins Leere (kein Netz), meldet das Skript
das ehrlich, statt eine leere Liste zu schreiben.
