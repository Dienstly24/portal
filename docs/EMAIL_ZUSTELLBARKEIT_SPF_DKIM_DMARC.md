# E-Mail-Zustellbarkeit: Warum Willkommens-Mails im Spam landen

**Status (14.07.2026): Technische Einrichtung abgeschlossen und bestaetigt.**
mail-tester.com bewertet einen Testversand ueber die App mit **10/10**:
„properly authenticated", „SpamAssassin likes you", „not blocklisted". SPF,
DKIM und DMARC sind korrekt; die Domain steht auf keiner Blockliste. Die
Spam-Einstufung bei Outlook ist damit **kein Code- oder DNS-Fehler**, sondern
ausschliesslich eine Frage der **Reputation der neuen Absender-Domain**
(v. a. Microsoft/Outlook). Diese Anleitung dokumentiert die (erreichte)
Zielkonfiguration und die verbleibenden Reputations-Massnahmen.

## Kurzdiagnose

**Wichtige Aktualisierung (14.07.2026): Die E-Mail-Authentifizierung ist
vollstaendig und korrekt eingerichtet.** Auf dem Produktivserver geprueft:

```
dig +short TXT dienstly24.de
  "v=spf1 include:_spf.mail.hostinger.com ~all"      # SPF korrekt
dig +short TXT _dmarc.dienstly24.de
  "v=DMARC1; p=none"                                   # DMARC vorhanden
# DKIM: hostingermail1._domainkey -> hPanel-Status "Verifiziert"
```

- **SPF** ✅ korrekt (Hostinger-Include vorhanden).
- **DKIM** ✅ aktiv und verifiziert.
- **DMARC** ✅ vorhanden (`p=none`, Monitoring-Modus).

Damit ist die frueher vermutete Hauptursache („leerer DKIM-Schluessel")
**widerlegt** – SPF, DKIM und DMARC sind gesetzt. Die Spam-Einstufung bei
Outlook liegt daher **nicht** an fehlender Authentifizierung, sondern an der
**Reputation einer neuen Absender-Domain** – besonders Microsoft/Outlook stuft
neue Absender ohne Sende-Historie anfangs streng als Junk ein.

**Naechster Schritt = messen, nicht raten:** Einen echten Testversand ueber die
App an https://www.mail-tester.com schicken und den Score lesen (siehe
Abschnitt „Verifizieren"). Der Report zeigt, ob doch ein versteckter Fehler
vorliegt (z. B. DKIM-Signatur bricht beim Versand, IP/Domain auf einer
Blockliste) oder ob wirklich nur die Reputation fehlt.

Die folgenden Abschnitte A/B dokumentieren die korrekte Zielkonfiguration
(bereits erreicht) und die Reputations-Massnahmen.

Der Code (Laravel Mailable, HTML-Template) ist in Ordnung. Zusaetzlich wurde
eine Text-Variante der Willkommens-Mail ergaenzt (`multipart/alternative`),
was den Spam-Score weiter senkt – der eigentliche Hebel bleibt aber DNS.

## Versandweg (bestaetigt am 14.07.2026): Variante A – Hostinger-E-Mail

Auf dem Produktivserver geprueft:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_FROM_ADDRESS=noreply@dienstly24.de
MAIL_FROM_NAME="Dienstly24"
```

→ Es gilt **Variante A** (Abschnitt A). DKIM/SPF werden in hPanel verwaltet.
Abschnitt B (eigener VPS-Mailserver) ist hier **nicht** relevant und dient
nur als Referenz, falls der Versandweg spaeter wechselt.

---

## Variante A — Versand ueber Hostinger-E-Mail

### A1. DKIM — bereits erledigt (14.07.2026)

hPanel → **E-Mails → Benutzerdefiniertes DKIM** zeigt fuer `dienstly24.de`
den Eintrag `hostingermail1._domainkey` mit Status **„Verifiziert"**. DKIM ist
damit aktiv; hier ist nichts mehr zu tun. (Die vollstaendige Aktivierung kann
laut Hostinger bis zu 8 Stunden dauern – ist hier bereits abgeschlossen.)

### A2. SPF setzen/ergaenzen

TXT-Eintrag auf `dienstly24.de` (nur **ein** SPF-Record erlaubt):

```
Typ:  TXT
Name: @   (dienstly24.de)
Wert: v=spf1 include:_spf.mail.hostinger.com ~all
```

Falls schon ein SPF existiert, den Hostinger-`include` einfuegen statt einen
zweiten Record anzulegen.

### A3. DMARC setzen — siehe gemeinsamer Abschnitt weiter unten.

---

## Variante B — Eigener Mailserver auf dem VPS (Postfix)

### B1. SPF: VPS-IP erlauben

```
Typ:  TXT
Name: @   (dienstly24.de)
Wert: v=spf1 ip4:DEINE.VPS.IP.ADRESSE ~all
```

Die oeffentliche IP des VPS eintragen. Zusaetzlich muss der PTR/rDNS-Eintrag
der IP auf `dienstly24.de` (bzw. den Mail-Hostnamen) zeigen – das im
Hostinger-VPS-Panel unter „rDNS" setzen.

### B2. DKIM mit OpenDKIM einrichten

```
sudo apt install opendkim opendkim-tools
sudo mkdir -p /etc/opendkim/keys/dienstly24.de
cd /etc/opendkim/keys/dienstly24.de
sudo opendkim-genkey -s default -d dienstly24.de
```

Der oeffentliche Teil steht in `default.txt` und wird als TXT-Record
veroeffentlicht:

```
Typ:  TXT
Name: default._domainkey
Wert: v=DKIM1; k=rsa; p=<langer-oeffentlicher-Schluessel-aus-default.txt>
```

OpenDKIM in Postfix einbinden (`/etc/opendkim.conf`, Socket in
`/etc/postfix/main.cf` via `milter_default_action`, `smtpd_milters`,
`non_smtpd_milters`), dann `systemctl restart opendkim postfix`.

**Wichtig:** Der `p=`-Wert darf nicht leer sein – genau das ist heute das
Problem.

### B3. DMARC — siehe gemeinsamer Abschnitt.

---

## Gemeinsam: DMARC-Eintrag

Zuerst im Beobachtungsmodus (`p=none`) starten, um nichts zu blockieren:

```
Typ:  TXT
Name: _dmarc
Wert: v=DMARC1; p=none; rua=mailto:dmarc@dienstly24.de; adkim=s; aspf=s; pct=100
```

Wenn nach ca. 1–2 Wochen die DMARC-Reports zeigen, dass SPF+DKIM sauber
passen, auf strengere Richtlinie anheben:

```
v=DMARC1; p=quarantine; rua=mailto:dmarc@dienstly24.de; adkim=s; aspf=s; pct=100
```

## Verifizieren (nach DNS-Aenderung 30–60 Min. warten)

```
# SPF
dig +short TXT dienstly24.de

# DKIM (Selector anpassen: default / hostingermail1 ...)
dig +short TXT default._domainkey.dienstly24.de

# DMARC
dig +short TXT _dmarc.dienstly24.de
```

**Empfohlen: Testversand ueber die App** (testet den echten Sendeweg inkl.
DKIM-Signatur durch Hostinger). Auf https://www.mail-tester.com die dort
angezeigte Zieladresse holen und einsetzen:

```
cd /var/www/dienstly24/portal
php artisan tinker --execute='\Mail::raw("Test Zustellbarkeit Dienstly24", function($m){ $m->to("test-XXXX@srv1.mail-tester.com")->subject("Test Zustellbarkeit"); });'
```

Danach auf mail-tester.com den Score ansehen (Ziel: 9–10/10). Der Report zeigt
`SPF`, `DKIM`, `DMARC` einzeln sowie eventuelle Blocklisten-Treffer.

Weitere Pruefungen:

- **Testmail an** `check-auth@verifier.port25.com` – Report mit
  `SPF/DKIM/DMARC: pass`.
- In Gmail: Mail oeffnen → „Original anzeigen" → SPF/DKIM/DMARC = `PASS`,
  und `Authentication-Results` pruefen.

## Reputations-Aufwaermung (neue Domain) – hier der eigentliche Hebel

SPF/DKIM/DMARC sind gesetzt. Was fehlt, ist Sende-Historie. Eine neue
Absender-Domain wird von Outlook/Gmail anfangs streng behandelt:

- Anfangs **kleine Mengen** versenden, langsam steigern (nicht sofort an alle).
- Empfaenger bitten, die erste Mail als **„Kein Spam"** zu markieren und den
  Absender `noreply@dienstly24.de` zu den **Kontakten** hinzuzufuegen. Dieses
  Signal wirkt bei Outlook am schnellsten.
- Bounces/Beschwerden gering halten (nur an echte, gewollte Adressen senden).

### Microsoft/Outlook-spezifisch (wichtigster Empfaenger hier)

Outlook/Hotmail (SmartScreen) ist bei neuen Absendern am strengsten. Direkt bei
Microsoft anmelden:

- **SNDS** (Smart Network Data Services): https://sendersupport.olc.protection.outlook.com/snds/
  – Einblick in Reputation der sendenden IP.
- **JMRP** (Junk Mail Reporting Program): ueber das Microsoft-Postmaster-Portal
  registrieren, um Beschwerden zu erhalten.
- Ggf. **Absender-Freischaltung** anfragen:
  https://sendersupport.olc.protection.outlook.com/pm/

### Optional: DMARC schaerfen

Aktuell `p=none` (Monitoring). Fuer ein etwas staerkeres Vertrauenssignal und
Report-Rueckmeldungen kann ergaenzt werden:

```
Typ:  TXT
Name: _dmarc
Wert: v=DMARC1; p=quarantine; rua=mailto:dmarc@dienstly24.de; adkim=s; aspf=s; pct=100
```

Erst umstellen, wenn der mail-tester-Test SPF+DKIM sicher als `pass` zeigt.

## Checkliste

- [x] Versandweg geklaert – Hostinger-Mail (`smtp.hostinger.com`)
- [x] SPF-TXT gesetzt – `v=spf1 include:_spf.mail.hostinger.com ~all`
- [x] DKIM aktiv und verifiziert – `hostingermail1._domainkey`
- [x] DMARC-TXT gesetzt – `v=DMARC1; p=none`
- [x] **Testversand ueber die App an mail-tester.com** – **10/10** am
  14.07.2026 (authentifiziert, SpamAssassin ok, nicht blockgelistet)
- [ ] **Reputation aufwaermen** – kleine Mengen, „Kein Spam"/Kontakt-Signal
- [ ] **Microsoft SNDS/JMRP** registrieren (Outlook ist der strengste Empfaenger)
- [ ] Optional: DMARC auf `p=quarantine` schaerfen, sobald mail-tester `pass`
