# Energie-Auftrag: Datenerkennung, Kennungen und die Bruecke zur Provision

Betreiber-Auftrag 28.08.2026, ausgeloest an einem hochgeladenen Auftrag der
PLAN B NET ZERO ENERGY (Screenshot der Vertriebsportal-Uebersicht). Gemeldet
war: "Name, Geburtsdatum, Adresse, Telefon und Geschlecht werden erkannt,
andere Angaben fehlen, obwohl sie im Auftrag stehen."

Dieses Dokument haelt fest, was tatsaechlich fehlte, was daran geaendert
wurde, welche Nummer welche Bedeutung hat und wie eine spaeter hochgeladene
Provisionsabrechnung ihren Vertrag wiederfindet.

---

## 1. Befund: was wirklich fehlte

Der Auftrag wurde mit einer nachgestellten OCR-Fassung des gemeldeten
Dokuments durch den Parser geschickt (`EnergiePortalAuftragParser`). Das
Ergebnis war nicht "die Erkennung liest wenig", sondern drei konkrete
Luecken:

| Angabe | Befund | Ursache |
|---|---|---|
| **Lieferbeginn 01.09.2026** | fehlte auch bei perfektem Text | Die Beschriftung heisst hier **"Neueinzug zum"**. Die Erkennung kannte nur die WECHSEL-Beschriftungen ("gew. Lieferdatum", "Lieferdatum"). Ein Neueinzug ist kein Anbieterwechsel - und genau deshalb steht dort ein anderes Wort. |
| **E-Mail** | fehlte im echten Lauf, nicht im sauberen Text | ZWEI Bruchstellen (die zweite zeigte sich erst nach dem Livegang): das `@` als fehleranfaelligstes Zeichen des Screenshots - UND die unterstrichene Beschriftung "Mail:", deren Unterstrich beim Erkennen mit dem Wort verschmilzt. Bricht die Beschriftung, gibt es fuer die Wert-Reparatur gar keine Stelle mehr. Siehe 2.2. |
| **IBAN** | wurde bei EINEM verlesenen Zeichen still verworfen | Die Pruefziffer schlug fehl, und der Parser gab auf - obwohl Kontonummer und BLZ separat und sauber daneben stehen. |

Alles Uebrige (Name, Geburtsdatum, Anschrift, Telefon, Geschlecht, Tarif,
Zaehlernummer, Verbrauch, Arbeits- und Grundpreis, Netzbetreiber,
Auftragsnummer) wurde bereits gelesen und in die Akte uebernommen.

**Die Lehre ist dieselbe wie beim Kontakt-Screenshot (21.08.2026): nicht die
Menge der Felder war das Problem, sondern die Alles-oder-nichts-Regel je
Feld.** Ein Parser, der bei einem verlesenen Zeichen nichts liefert und bei
einer unbekannten Beschriftung nichts findet, sieht bei jedem neuen Anbieter
schlechter aus als beim vorigen.

---

## 2. Was geaendert wurde

### 2.1 Beschriftungen statt EINER Schreibweise

Der Lieferbeginn wird jetzt unter allen ueblichen Beschriftungen gesucht:
`gew. Lieferbeginn`, `gew. Lieferdatum`, `Beginn der Belieferung`,
`Belieferungsbeginn`, `Lieferbeginn`, `Lieferdatum`, `Neueinzug zum`,
`Neueinzug`, `Einzug zum`, `Einzugsdatum`, `Vertragsbeginn`. Ebenso haben
Geburtsdatum (`geboren am` / `Geburtsdatum` / `Geburtstag`) und Telefon
(`Tel` / `Telefon` / `Mobil` / `Mobilnummer` / `Handy`) mehrere Schreibweisen.

Stammt der Beginn aus einer EINZUGS-Beschriftung, sagt die Zusammenfassung
das ausdruecklich ("Neueinzug (Neuanschluss, kein Anbieterwechsel)") - denn
dann gibt es keinen Vorversorger, und die 20-Tage-Regel des
Stadtwerke-Wechsels darf nicht greifen.

Weiterhin gilt: **ein Beginn wird nie geraten.** "schnellstmoeglich" ist kein
Datum. Einzige Ausnahme bleibt der Stadtwerke-Wechsel (14 Tage
Kuendigungsfrist + Bearbeitung).

### 2.2 E-Mail: zwei Bruchstellen, zwei Stufen

**Nachtrag nach dem ersten Livegang (28.08.2026).** Die erste Runde reparierte
nur den WERT - und die Adresse fehlte im echten Lauf trotzdem. Grund: die
E-Mail kann an ZWEI Stellen brechen.

**(a) Der Wert.** Das `@` liest die OCR je nach Schrift und Hintergrund als
`©`, `®`, `€`, `¢`, `°` oder `¤`, oder sie verdoppelt es. Alle diese Zeichen
sind in einer E-Mail-Adresse **nie zulaessig** - sie duerfen deshalb gefahrlos
zu `@` werden. Ein BUCHSTABE steht bewusst nicht in dieser Liste: "a" statt
"@" liesse sich von einem echten Namensbestandteil nicht unterscheiden, und
aus einer Reparatur wuerde Raten. Zustaendig ist der gemeinsame Baustein
`App\Services\Ai\Concerns\RepairsOcrText` (derselbe wie beim
Kontakt-Screenshot).

**(b) Die Beschriftung.** "Mail:" steht in dieser Portal-Ansicht
**unterstrichen**, und ein Unterstrich verschmilzt beim Erkennen gern mit dem
Wort ("Maii", "Mall", "MaiI"). Bricht die Beschriftung, half die Reparatur aus
(a) gar nicht - die Suche fand ja keine Stelle, an der sie haette reparieren
koennen. Genau das war der verbliebene Ausfall.

Deshalb laeuft die Erkennung jetzt **zweistufig**:

1. **beschrifteter Weg** (`Mail` / `E-Mail`) - eine so gelesene Adresse ist
   BELEGT und gilt als `sicher`;
2. **Suche im ganzen Dokument**, nur wenn Stufe 1 nichts liefert - eine so
   gefundene Adresse ist plausibel, nicht belegt, und gilt immer als
   `pruefen`.

Damit Stufe 2 nie eine fremde Adresse zur Kundenadresse macht, filtert
`istFremdadresse()`:

* typische **Sammelpostfaecher** (`info`, `service`, `kontakt`, `support`,
  `kundenservice`, `noreply`, `datenschutz` ...),
* die **Domain des Anbieters** dieses Auftrags (Vergleich des Domain-Kerns
  gegen den Anbieternamen aus der Kopfzeile),
* unsere **eigene Domain** (`dienstly24`).

Sonst stuende der Kundenservice des Versorgers als Kontakt in der Kundenakte
und bekaeme unsere Post.

### 2.3 IBAN: zwei Quellen, eine Regel

Das Portal druckt die Bankverbindung **zweimal**: als IBAN und - separat -
als Kontonummer + BLZ. Eine deutsche IBAN besteht rechnerisch genau daraus:

```
DE + zwei Pruefziffern + 8-stellige BLZ + 10-stellige Kontonummer
```

Die Pruefziffern werden nach ISO 7064 **gerechnet, nicht abgelesen**. Damit
haengt die Bankverbindung nicht mehr daran, dass die OCR 22 Zeichen am Stueck
fehlerfrei liest - zwei kurze Zahlenfelder verliest sie selten beide zugleich.

Die Entscheidungstabelle ist bewusst streng, weil eine gerechnete IBAN
IMMER eine gueltige Pruefziffer hat - auch wenn die BLZ verlesen wurde. Sie
braucht also eine Gegenprobe:

| abgedruckte IBAN | Konto + BLZ | Ergebnis | Feldstatus |
|---|---|---|---|
| gueltig | gueltig, gleich | uebernommen | **sicher** |
| gueltig | gueltig, abweichend | **nichts uebernommen** | **widerspruch** |
| gueltig | fehlt | uebernommen | sicher |
| unlesbar, deckt sich mit Konto+BLZ | gueltig | gerechnete IBAN uebernommen | **pruefen** |
| unlesbar, deckt sich NICHT | gueltig | **nichts uebernommen** | **widerspruch** |
| unlesbar | fehlt | **nichts uebernommen** | pruefen |
| fehlt | gueltig | gerechnete IBAN uebernommen | **pruefen** |
| fehlt | fehlt | nichts | fehlt |

"Deckt sich" heisst: der KONTOTEIL beider Nummern ist gleich, wenn man
typische OCR-Verwechslungen (B/8, O/0, I/1, S/5 ...) zulaesst. Verglichen
wird nur der Kontoteil - die Pruefziffern der gerechneten IBAN sind neu
bestimmt und taugen nicht zum Beleg.

Unveraendert bleibt die aeltere Regel: **die Bankverbindung wird nur
uebernommen, wenn der Block "Anschrift des Kontoinhaber" auf DIESELBE Person
laeuft.** Steht dort ein fremder Name, bleibt sie draussen.

### 2.4 Erkennungssicherheit je Feld

Neu ist `App\Support\FieldRecognition`. Das Analyse-Ergebnis traegt unter
`data.feldstatus` je Feld einen von vier Zustaenden:

| Zustand | Bedeutung |
|---|---|
| `sicher` | Beschriftet gefunden, alle Pruefungen bestanden |
| `pruefen` | Uebernommen, aber es musste etwas repariert oder abgeleitet werden |
| `fehlt` | Im Dokument nicht gefunden. **Kein Wert - es wird nichts geraten** |
| `widerspruch` | Zwei Angaben im Dokument sagen Verschiedenes. **Nichts uebernommen** |

Die Schluessel sind `gruppe.feld` (`person.email`, `bank.iban`,
`versicherung.start_date` ...) und spiegeln damit exakt den Aufbau von
`data` - die Oberflaeche haengt jeden Status ohne Uebersetzungstabelle an
sein Feld.

Im Review-Dialog des Dokumenten-Eingangs:

* hinter jedem Feld ein kleines Kennzeichen, **nur wo es etwas sagt**
  ("sicher" an jedem Feld waere Ziergrafik und wuerde die zwei Felder
  verstecken, um die es geht),
* der Kasten oben nennt getrennt: widerspruechlich / bitte pruefen / nicht
  gelesen.

Damit muss der Mitarbeiter nur noch die unsicheren Angaben kontrollieren -
frueher gab es nur EINE Konfidenz fuer das ganze Dokument und eine feste
Liste von vier Standardfeldern, also faktisch die Aufforderung, alles zu
pruefen.

Liegt keine feldgenaue Bewertung vor (aeltere Analysen, KI-Ergebnisse),
bleibt das bisherige Verhalten unveraendert.

---

## 3. Welche Nummer ist welche?

Im gemeldeten Auftrag stehen zwei Nummern, die in der Rueckfrage
vermischt wurden. Zur Klarstellung:

| Nummer im Beispiel | Was sie ist | Wo sie landet |
|---|---|---|
| `1687519` | **Auftragsnummer des Vertriebsportals** - die Kennung des VORGANGS | `contracts.reference_number` |
| `DE09430500010104356605` | **die IBAN des Kunden** - keine Vertragsnummer | Bankdaten des Kunden |
| `0104356605` / `43050001` | Kontonummer / BLZ - dieselbe Bankverbindung, zweite Schreibweise | dienen der Gegenprobe (siehe 2.3) |
| `1EBZ0103716819` | **Zaehlernummer** (Geraet an der Lieferstelle) | `contract_energy_details.meter_number` |
| (hier nicht vorhanden) | **MaLo-ID**, 11-stellig - die Marktlokation | `contract_energy_details.malo_id` |

**Ein AUFTRAG hat keine Vertragsnummer.** Die gibt es erst mit der
Vertragsbestaetigung des Versorgers; sie landet dann in
`contracts.contract_number`. Die Auftragsnummer wird deshalb NIE als
Vertragsnummer gespeichert - sie steht im eigenen Feld `reference_number`.

Insgesamt fuehrt ein Vertrag bis zu **drei** Nummern aus drei Systemen, die
sich nie ueberschreiben:

| Feld | Herkunft | Beispiel |
|---|---|---|
| `contract_number` | Gesellschaft / Versorger | `POL-4711` |
| `reference_number` | Vorgang der Antragsstrecke / des Vertriebsportals | `1687519` |
| `internal_contract_number` | Maklerpool | `V19613073` |
| `vermittler_id` | Vorgangs-Id des Vermittlers (TARIFCHECK24) | `9753224` |

Alle vier sind in der globalen Suche und in `Contract::scopeSearch` findbar.
Sie werden nur **ERGAENZT**, nie ueberschrieben: eine Bruecke, die man kappt,
fehlt spaeter.

Im Review-Dialog steht jetzt ausdruecklich "Vertrags-Nr." bzw.
"Auftrags-/Referenz-Nr." statt eines gemeinsamen "Nr." - zwei Nummern unter
einer Beschriftung sind die Vorstufe zur Verwechslung.

---

## 4. Die Bruecke zur Provisionsabrechnung

Ziel: eine spaeter hochgeladene Abrechnung soll ohne Handarbeit sagen
koennen, zu welchem Kunden und welchem konkreten Energievertrag sie gehoert.

### 4.1 Reihenfolge nach Trennschaerfe

`CommissionMatcher` probiert die Kennungen in dieser Reihenfolge (die erste,
die trifft, gewinnt):

1. `internal_contract_number` - meint genau EINEN Vertrag
2. `vermittler_id`
3. `reference_number` (Referenz-/Vorgangsnummer)
4. `order_number` (Auftr.-Nr. des Vertriebsportals) -> `reference_number`
5. `external_contract_number` -> `contract_number`
6. **`meter_number` (Zaehlernummer)** - neu
7. **`malo_id` (MaLo-ID)** - neu

Die beiden Energie-Kennungen stehen bewusst am ENDE: sie identifizieren eine
**Lieferstelle**, nicht einen Vertrag. An derselben Marktlokation koennen
Strom und Gas haengen, und ueber die Jahre mehrere Vertraege nacheinander.

Sie sind aber unverzichtbar: die Abrechnung eines Energie-Vertriebsportals
fuehrt haeufig gar keine Vertragsnummer, **weil es zum Zeitpunkt des Auftrags
noch keine gab**. Die Zaehlernummer ist dort die einzige dauerhafte Kennung -
und sie steht seit dem Auftrag in der Akte.

Verglichen wird ueber `ContractEnergyDetail::normalizeMeter()` bzw.
`normalizeMalo()`: auf dem Zaehler steht "1 EBZ0 1037 16819", im Auftrag
"1EBZ0103716819" - dieselbe Nummer.

### 4.2 Die vier Grundregeln gelten unveraendert

1. **Nie raten.** Trifft eine Kennung mehr als einen Vertrag (typisch:
   Strom + Gas an derselben Lieferstelle), wird NICHTS zugeordnet und die
   Pruefliste nennt den Grund im Klartext.
2. **Nie einen Vertrag anlegen** (ausser der Admin setzt in der Vorschau
   ausdruecklich den Haken).
3. **Nie Vertragsdaten aendern** - einzige Ausnahme ist das Ergaenzen einer
   LEEREN Kennung.
4. **Der NAME zaehlt nie** als Zuordnungsmerkmal. Name und Adresse werden nur
   als Klartext mitgefuehrt; eine Zuordnung ueber sie wuerde eine Provision
   an den falschen Kunden haengen.

Eine Zeile ohne Treffer geht **nicht verloren**: sie wird als Provision ohne
Vertrag gespeichert und steht in der Liste "Nicht zugeordnet", jederzeit von
Hand verknuepfbar.

### 4.3 Der Weg im Ganzen

```
Auftrag hochladen
  -> OCR / Textebene
  -> EnergiePortalAuftragParser liest Person, Bank, Tarif, Kennungen
     (jedes Feld mit Erkennungssicherheit)
  -> Mitarbeiter prueft im Review NUR die unsicheren Felder
  -> Kunde erkannt oder angelegt
  -> Vertrag (Stufe 'antrag') mit reference_number + Zaehlernummer + MaLo
  -> Werber am KUNDEN (acquired_by / acquired_by_partner_id) -> Provision
  ... Wochen spaeter ...
  -> Vertragsbestaetigung ERGAENZT denselben Vertrag (echte Vertragsnummer)
  ... Wochen spaeter ...
  -> Provisionsabrechnung hochladen
  -> Zuordnung ueber die Kennungen (4.1)
  -> Provisionsstatus am Vertrag: offen / faellig / bezahlt / storniert / unklar
```

---

## 5. Provision ist ausschliesslich intern

Die Trennung besteht bereits und wurde um einen weiteren Nachweis ergaenzt.
Sie liegt **nicht** im Frontend, sondern in der Struktur:

* **Kein Beziehungspfad vom Kunden zur Provision.** `Customer` hat bewusst
  KEINE Beziehung zu `contract_commissions` - ein `with()` im Portal kann sie
  gar nicht versehentlich mitladen.
* **Eigenes Recht** `provisionen-verwalten` (Gate: admin ODER
  `users.can_manage_commissions`), geprueft an der ROUTE **und** im
  Controller **und** in der Vertragsakte-Box.
* **Eigenes Protokoll** `commission_audit_logs` (nicht der allgemeine
  ActivityLog - hier stehen Betraege). Kein Loeschweg aus der Oberflaeche.
* **Tests** sichern ab, dass Betrag, Empfaenger, interne Vertragsnummer und
  Vermittler-Id im Kundenportal NIRGENDS im HTML stehen - in der
  Vertragsliste und (neu) auf der Detailseite eines Energievertrags.

Intern und damit nie in der Kundenansicht: Vermittler, Provision,
Provisionshoehe, Abrechnung, Status, Auszahlungsdatum, interne
Auftragsnummern, interne Notizen, Matching-Informationen.

Fuer den Kunden bestimmt und sichtbar: Tarif, Produkt, Preise, Verbrauch,
Zaehlernummer, Lieferbeginn, Vertragsnummer des Versorgers.

---

## 6. Tests

* `tests/Feature/Ai/EnergiePortalAuftragParserTest.php`
  - zweite Bauform (Neueinzug) des Portals, vollstaendig gelesen
  - Lieferbeginn unter fuenf weiteren Beschriftungen
  - `@` als `©`/`®`/`@®`/`(at)` verlesen -> Adresse trotzdem erkannt, Status "pruefen"
  - Beschriftung "Mail:" verlesen ("Maii"/"Mall") oder ganz fehlend ->
    Adresse ueber die Rueckfallebene gefunden, Status "pruefen"
  - fremde Adressen (Sammelpostfach, Anbieter-Domain, eigenes Haus) werden
    NIE zur Kundenadresse
  - IBAN aus Konto + BLZ nachgerechnet; Widerspruch -> nichts uebernommen;
    ohne zweite Quelle bleibt es beim strengen Verhalten
  - Feldstatus benennt gelesene UND fehlende Angaben
* `tests/Feature/ContractCommissionImportTest.php`
  - Abrechnung ohne jede Vertragsnummer findet den Vertrag ueber die
    Zaehlernummer bzw. die MaLo-ID
  - Zaehlernummer an zwei Vertraegen -> nichts zugeordnet, Grund im Klartext
  - Vertragsnummer schlaegt Zaehlernummer (Trennschaerfe)
  - Energievertrag im Portal zeigt dem Kunden keine Abrechnungsdaten

---

## 7. Was bewusst NICHT geaendert wurde

* **Die Bildvorstufe.** Staerkeres Hochskalieren oder Graustufen mit
  angehobenem Kontrast wurde am Kontakt-Screenshot bereits gemessen und
  verworfen: es repariert die IBAN und zerlegt dafuer die Postleitzahl. Mehr
  Vorverarbeitung verschiebt die Fehler nur - die Robustheit gehoert in den
  Parser.
* **Ein Sonderfall fuer PLAN B.** Es wurde keine einzige Regel auf diesen
  Anbieter zugeschnitten. Geaendert wurden Beschriftungs-Vokabular,
  OCR-Reparatur und die Zwei-Quellen-Regel bei der IBAN - alles davon gilt
  fuer jeden Anbieter derselben Portal-Ansicht.
* **Namen und Adressen als Zuordnungsmerkmal der Abrechnung.** Sie bleiben
  ausgeschlossen (Grundregel 4).
* **Automatische Vertragsanlage aus einer Abrechnung.** Sie bleibt an den
  ausdruecklichen Haken in der Vorschau gebunden.
