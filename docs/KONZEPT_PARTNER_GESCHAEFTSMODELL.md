# Konzeptstudie: Partner-Geschäftsmodell (Abo & Provisionen)

> Status: **Entwurf zur Diskussion – NICHT implementiert.**
> Grundlage: Firmen registrieren sich als Partner, nutzen die Plattform für
> IHRE Kunden und zahlen dafür – per Monats-Abo (Pakete) ODER per Provision
> (pro geworbenem Kunden), individuell je Partner konfigurierbar.

## 1. Bausteine (auf vorhandenem Fundament)

Bereits vorhanden (PR #8): role=partner, Partnerportal-Grundgerüst
(Dashboard, Meine Kunden, Provisionen, Firmenprofil + Logo),
customers.partner_id, strikte Datenscopes, partner:create-login.

Neu zu bauen:

**a) Vergütungsmodell je Partner** – `partner_plans`
- partner_id, model (`subscription` | `commission` | `hybrid`)
- subscription_package (z. B. Basis/Pro/Enterprise), monthly_fee
- commission_scheme: JSON-Regeln, z. B.
  `{ "per_customer": 25.00, "per_contract": { "kfz": 15, "leben": 40 } }`
- valid_from / valid_to (Historie: Konditionen ändern sich)
- **individuell je Partner** – der Admin legt pro Partner fest, wer mehr
  oder weniger bekommt.

**b) Abrechnungslauf** (monatlich, Scheduler)
- zählt je Partner: neu geworbene Kunden, vermittelte Verträge (detailliert:
  WER, WANN, WELCHER Vertrag – nachvollziehbar pro Zeile)
- erzeugt eine `partner_settlements`-Abrechnung (Positionen + Summe)
- optional: Lexoffice-Beleg automatisch (Infrastruktur existiert)

**c) Partner-Adminbereich** ("gleiche Rechte wie wir – für SEINE Kunden")
Stufenweise freischaltbare Rechte (Flags je Partner, wie bei Mitarbeitern):
- Kunden anlegen/bearbeiten (nur eigene)
- Verträge anlegen/bearbeiten (nur eigene Kunden)
- Dokumente hochladen/einsehen
- Tickets/Aufgaben für eigene Kunden
- Statistiken: eigene Kundenzahl, Vertragsbestand, Provisionshistorie
- Team: eigene Unterbenutzer (Mitarbeiter des Partners) – Phase 2

**d) Registrierung & Onboarding**
- öffentliche Partner-Anfrage (Formular) -> Admin prüft -> Freischaltung
- Paketwahl + Vertrag/AGB für Partner
- Branding: Logo (fertig), später Farbschema/Subdomain optional

## 2. Datenmodell-Skizze

```
partners            (vorhanden: user_id, logo_path, …)
partner_plans       (Modell + Konditionen, Historie)
partner_settlements (Abrechnung je Monat: Positionen, Summe, Status)
partner_permissions (Feature-Flags je Partner: can_create_customers, …)
```

`customers.partner_id` (vorhanden) bleibt die eine Quelle der Wahrheit
für "wessen Kunde" – Zählung, Scoping und Abrechnung hängen daran.

## 3. Sicherheits-/Abgrenzungsregeln

- Partner sieht/bearbeitet NUR Kunden mit seiner partner_id (bereits erzwungen).
- Keine Sicht auf interne Notizen, andere Partner, Mitarbeiterdaten.
- Jede Partner-Aktion ins Audit-Log (Infrastruktur vorhanden).
- Kundenlöschung durch Partner: NICHT direkt – nur Antrag an Admin
  (gleiches Vier-Augen-Muster wie Familienlöschung im Kundenportal).

## 4. Offene Fragen zur Abstimmung

1. Pakete: Wie viele, welche Preise, welche Leistungsunterschiede?
2. Provisionsbasis: pro Kunde, pro Vertrag, pro Sparte – oder Mischform?
3. Auszahlung: Gutschrift via Lexoffice, SEPA, manuell?
4. Dürfen Partner Kunden IMPORTIEREN (CSV) oder nur einzeln anlegen?
5. Unterbenutzer des Partners in Phase 1 oder später?
6. Mindestlaufzeit/Kündigung der Abos?
