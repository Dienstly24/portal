# Partnerportal: was ist wirklich gebaut? (UX-4, Stand 05.09.2026)

## Anlass

`CLAUDE.md` fuehrte "Partner-Portal" unter **"Offene Themen / wartet auf den
Betreiber"** - zusammen mit dem Hinweis "noch nicht bauen". Die Pruefung
zeigt: das trifft nur auf den **VOLLAUSBAU** zu. Ein lesendes Partnerportal
ist seit laengerem **in Betrieb, erreichbar und getestet**.

Das ist kein Schoenheitsfehler in der Doku, sondern ein Sicherheitsrisiko
der Einordnung: eine Oberflaeche, die im Verzeichnis als "nicht gebaut"
steht, wird bei Audits, Rechte-Reviews und Abhaengigkeits-Updates
uebersehen - obwohl sie fremden Firmen echte Kundendaten zeigt.

## Bestandsaufnahme

| Baustein | Zustand |
|---|---|
| `PartnerPortalController` (6 Methoden) | **produktiv** |
| `layouts/partner.blade.php` | **produktiv** |
| `partner/dashboard` (Kennzahlen + letzte Provisionen) | **produktiv** |
| `partner/customers` (Liste, blaetterbar) | **produktiv** |
| `partner/customer_show` (Vertraege eines Kunden) | **produktiv, rein lesend** |
| `partner/commissions` (Provisionshistorie) | **produktiv** |
| `partner/profile` (Stammdaten lesen, Logo hochladen) | **produktiv** - einzige schreibende Aktion |
| `partner:create-login` (CLI) | **produktiv** |
| Schreibende Kundenaktionen ("volle Rechte im Umgang mit ihren Kunden") | **NICHT gebaut** - das ist der Teil, der auf die Abstimmung mit dem Betreiber wartet |
| Dokumenten-Upload, Vertragspflege, Vorgaenge durch den Partner | **NICHT gebaut** |

`resources/views/partner/customer_show.blade.php` sagt dem Partner das im
Klartext ("werden im naechsten Ausbauschritt ergaenzt") - die Oberflaeche
war also ehrlicher als das Verzeichnis.

## Sicherheitspruefung (wie fuer jede aktive Oberflaeche)

Geprueft am 05.09.2026, Ergebnis: **keine Befunde.**

| Punkt | Befund |
|---|---|
| Routen-Middleware | `['auth', 'role:partner']` auf der ganzen Gruppe. `EnsureUserRole` wirft zusaetzlich deaktivierte Konten (`is_active`) sofort aus der Sitzung. |
| Partnerprofil | `PartnerPortalController::partner()` ist der EINE Einstieg: `Partner::where('user_id', auth()->id())`, sonst 403. Ein `role=partner`-Konto ohne Partnerdatensatz kommt nirgendwohin (Test vorhanden). |
| Kundenliste | ausschliesslich ueber die Relation `$partner->customers()` - die `partner_id`-Bedingung steht in der Abfrage, nicht in einem Filter danach. |
| Einzelner Kunde | `$partner->customers()->where('customers.id', $id)->firstOrFail()` -> fremde ID ergibt 404, kein Objektzugriff ueber die ID (kein IDOR). |
| Provisionen | nur `$partner->commissions()`. Es gibt KEINEN Weg zu `contract_commissions`, `vermittler_settlements` oder `provisions` - die internen Provisionsstraenge sind strukturell unerreichbar. |
| Profil-Aenderung | schreibt ausschliesslich `logo_path` des EIGENEN Partners; der Pfad kommt aus dem eigenen Datensatz, nie aus der Anfrage. Stammdaten sind lesend (Aenderung nur durch Dienstly24). |
| Zweiter Faktor | `User::requiresTwoFactor()` schliesst `partner` ausdruecklich ein - ein Partner sieht fremde personenbezogene Daten. `EnsureTwoFactor` und `EnsurePasswordChanged` haengen in der Web-Gruppe, gelten also auch hier. |
| Rollen-Trennung | Partner kommt weder in die Beraterwelt noch ins Kundenportal, Kunde/Mitarbeiter nicht ins Partnerportal (beides getestet). |
| Blade-Ausgabe | keine Betraege oder Kennungen fremder Partner; die Vertragsliste zeigt Sparte/Gesellschaft/Vertragsnummer/Status des eigenen Kunden. |

**Abgedeckt durch `tests/Feature/PartnerPortalTest.php`** (11 Tests):
eigener Bestand sichtbar, fremder nicht, fremder Kunde -> 404, fremde
Provisionen unsichtbar, Rollen-Trennung in beide Richtungen, Konto ohne
Profil -> 403, Logo-Upload, CLI-Anlage, Zuordnung durch den Admin.

## Was bewusst offen bleibt

- **Vollausbau (schreibende Rechte)**: wartet weiter auf die Entscheidung
  des Betreibers - Konzept in `docs/KONZEPT_PARTNER_GESCHAEFTSMODELL.md`.
  Erst dort entsteht die eigentliche Rechtefrage (darf ein Partner
  Vertraege aendern? Dokumente sehen? Vorgaenge anlegen?).
- **Kein Zaehler/Badge** in der Partner-Navigation: das Partnerportal hat
  keine Aufforderungs-Zahlen, es ist eine Uebersicht.

## Folgerung fuer die Doku

`CLAUDE.md` nennt das Partnerportal jetzt bei den **Wichtigen Bausteinen**
(mit dem Vermerk "lesend, produktiv") und laesst unter "Offene Themen" nur
noch den Vollausbau stehen. Eine aktive Oberflaeche gehoert ins
Verzeichnis der gebauten Dinge - sonst prueft sie niemand.
