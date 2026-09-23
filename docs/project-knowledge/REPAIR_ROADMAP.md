# Repair Roadmap (priorisiert)

Stand: 23.09.2026. Reihenfolge nach Risiko fuer Kunden/Daten/Recht, dann nach
Aufwand. Jede Zeile verweist auf Issues ([KNOWN_ISSUES.md](KNOWN_ISSUES.md))
bzw. Altlasten ([TECHNICAL_DEBT.md](TECHNICAL_DEBT.md)). Ein Schritt ist erst
erledigt nach IMPLEMENT -> TEST -> VERIFY -> Wissensbasis nachziehen -> PR.

Legende Aufwand: S (< 1/2 Tag), M (1-2 Tage), L (mehrere Tage).
"Betreiber" = braucht Server-/Konto-/Rechtshandlung, Code allein genuegt nicht.

## Stufe 0 - Betrieb absichern (Betreiber, kein Code)

| R | Aufgabe | Issue | Wer | Aufwand |
|---|---|---|---|---|
| R-01 | Turnstile-Schluessel pruefen/setzen, eine echte Testregistrierung | KI-002 | Betreiber | S |
| R-02 | Sicherung in Betrieb nehmen (Passwort, zweiter Ort, Cron) + `restore.sh --pruefen` auf dem Server | KI-004 | Betreiber | S |
| R-03 | Planer-Cron + Worker `default` UND `lang` pruefen (`/admin/systemzustand`, `queue:health`) | KI-013 | Betreiber | S |
| R-04 | Netz: `scripts/netz-pruefen.sh --vorschlag`, Firewall mit offener Zweitsitzung | KI-003 | Betreiber | S |
| R-05 | Rechtstexte: Hoster, NESA-Angaben, AGB-Haftung pruefen; Turnstile-Absatz (seit 23.09.2026 im Code) anwaltlich gegenlesen; KI-/Signatur-Rechtsfragen | KI-012, KI-008, KI-009 | Betreiber + Anwalt | M |

## Stufe 1 - kleine, sichere Code-Korrekturen (sofort umsetzbar)

| R | Aufgabe | Issue | Aufwand |
|---|---|---|---|
| R-06 | ~~Arabische Uebersetzungen + Waechter-Test~~ **erledigt 23.09.2026** | KI-007, KI-021 | - |
| R-07 | ~~Einladungsmail nur mit vergebenen Rechten~~ **erledigt 23.09.2026** | KI-006 | - |
| R-08 | ~~Standard-Locale `de`~~ **erledigt 23.09.2026** | KI-014 | - |
| R-09 | ~~Waechter-Test interne Kennungen~~ **erledigt 23.09.2026** | KI-010 | - |
| R-10 | ~~Funktionstests Termine/Ankuendigungen/Tarifrechner~~ **erledigt 23.09.2026** (dabei KI-019, KI-020 behoben) | KI-011 | - |

## Stufe 2 - Aufraeumen

| R | Aufgabe | Issue | Aufwand |
|---|---|---|---|
| R-11 | ~~Breeze-Reste und `autoprefixer` entfernen~~ **erledigt 23.09.2026** (Mail-Anbieter-Konfiguration bewusst belassen) | KI-016, KI-017 | - |
| R-12 | ~~CI-Versionen vereinheitlichen~~ **erledigt 23.09.2026** | KI-015 | - |
| R-13 | "Employee & Support Customer Access Architecture": Default `can_see_all_customers=false`, Altkonten-Liste, Spezifikation + Sicherheitstests | KI-001 | M (Betreiber-Entscheidung vorab) |

## Stufe 3 - Struktur (laufend, je PR ein Stueck)

| R | Aufgabe | Bezug | Aufwand |
|---|---|---|---|
| R-14 | `SmartDocumentUploadController` in Admin/Portal aufteilen (ARCH-5-Verfahren) | TD-01 | M |
| R-15 | `DocumentIntakeService` in Zuordnung / Vertragsaktualisierung / Vorgangs-Zusammenfuehrung schneiden | TD-01 | L |
| R-16 | Inline-Styles der groessten Vorlagen in Bausteine ueberfuehren | TD-02 | L |
| R-17 | PHPStan-Baseline abbauen (Ziel 0) | TD-03 | laufend |
| R-18 | Browser-Runde (DE+AR, 390/820/1440, alle Layouts) als Nachweis fuer die Wissensbasis | UI_UX_AUDIT | M |
| R-19 | Klaeren, ob `commissions` (Gutschriften alt) noch beschrieben wird | TD-04 | S |

## Wartet auf Betreiber-Entscheidung (Features)

BIMI-Zertifikat (KI-005) · KI-Assistent live (F-040) · WhatsApp-Konto live (F-032) ·
Partnerportal Vollausbau (F-062) · E-Mail-Einwilligung Variante B ·
Website-DNS-Umzug + Rueckbau statische Seite (TD-09) · Redis.

## Empfohlene naechste Arbeitspakete

Stufe 1 und 2 (ausser R-13) sind am 23.09.2026 erledigt. Als Naechstes:

1. **Betreiber: Stufe 0** (R-01..R-05) - das sind jetzt die groessten Risiken.
2. **R-13** nach Entscheidung des Betreibers (Zugriffsarchitektur).
3. **R-18** Browser-Runde als Nachweis, dann **R-14** (erster Strukturschnitt).
