# E-Mail-Zustellbarkeit: Warum Willkommens-Mails im Spam landen

**Status:** Hauptursache ist DNS-Konfiguration (kein Code-Fehler). Diese
Anleitung beschreibt die konkreten Schritte, damit Mails von
`noreply@dienstly24.de` bei Outlook/Gmail im Posteingang statt im Spam landen.

## Kurzdiagnose

Outlook markiert die Mail als Junk, weil die Domain `dienstly24.de` sich nicht
vollstaendig authentifiziert. Stand 14.07.2026:

- **DKIM** – kryptografische Signatur der Mail. **In hPanel aktiv und
  „Verifiziert"** (Selector `hostingermail1._domainkey`). Damit ist die
  frueher vermutete Hauptursache („leerer `p=`-Schluessel") **erledigt**.
- **SPF** – legt fest, welche Server fuer `dienstly24.de` senden duerfen.
  Muss den Hostinger-Include enthalten (siehe A2). **Zu pruefen.**
- **DMARC** – Richtlinie, die SPF+DKIM zusammenfasst und Reputation aufbaut.
  Fuer eine neue Domain praktisch Pflicht; Outlook misstraut ohne DMARC.
  **Zu pruefen/anzulegen (siehe unten).**

Verbleibende wahrscheinliche Ursachen: **fehlender/falscher SPF-Eintrag**,
**fehlender DMARC-Eintrag**, sowie die normale **Reputations-Aufwaermung**
einer neuen Absender-Domain.

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

Zusaetzlich zuverlaessig:

- **Testmail an** `check-auth@verifier.port25.com` senden – man erhaelt
  einen Report mit `SPF: pass`, `DKIM: pass`, `DMARC: pass`.
- Oder Testmail ueber **https://www.mail-tester.com** (Ziel: 9–10/10).
- In Gmail: Mail oeffnen → „Original anzeigen" → SPF/DKIM/DMARC muessen
  jeweils `PASS` zeigen.

## Reputations-Aufwaermung (neue Domain)

Selbst mit korrektem SPF/DKIM/DMARC braucht eine neue Absender-Domain etwas
Zeit, bis Outlook/Gmail ihr vertrauen:

- Anfangs **kleine Mengen** versenden, langsam steigern.
- Empfaenger bitten, die erste Mail als „Kein Spam" zu markieren und den
  Absender zu den Kontakten hinzuzufuegen.
- Bounces/Beschwerden gering halten (nur an echte, gewollte Adressen senden).

## Checkliste

- [ ] Versandweg geklaert (Hostinger vs. eigener VPS-Mailserver)
- [ ] SPF-TXT gesetzt (nur ein Record, korrekter `include`/`ip4`)
- [ ] DKIM aktiv, `p=` **nicht leer**, Selector korrekt
- [ ] DMARC-TXT gesetzt (Start `p=none`, spaeter `p=quarantine`)
- [ ] PTR/rDNS passt (nur Variante B / eigener Server)
- [ ] Verifikation via mail-tester / port25 = alles `pass`
