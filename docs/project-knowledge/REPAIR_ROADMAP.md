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
| R-05 | Rechtstexte: Turnstile + Hoster in Datenschutzerklaerung, NESA-Angaben, AGB-Haftung; KI-/Signatur-Rechtsfragen | KI-012, KI-008, KI-009 | Betreiber + Anwalt | M |

## Stufe 1 - kleine, sichere Code-Korrekturen (sofort umsetzbar)

| R | Aufgabe | Issue | Aufwand |
|---|---|---|---|
| R-06 | 28 arabische Uebersetzungen + Waechter-Test "jeder Kundentext hat `ar.json`-Eintrag" | KI-007 | S |
| R-07 | Einladungsmail listet nur tatsaechlich vergebene Rechte (+ Test) | KI-006 | S |
| R-08 | Standard-Locale `de` in `config/app.php` und `.env.example` (+ Test fuer Konsolen-Kontext) | KI-014 | S |
| R-09 | Waechter-Test: kein Portal-/Partner-Endpunkt liefert interne Provisions-Kennungen | KI-010 | S |
| R-10 | Funktionstests fuer Termine, Ankuendigungen, Tarifrechner | KI-011 | S |

## Stufe 2 - Aufraeumen

| R | Aufgabe | Issue | Aufwand |
|---|---|---|---|
| R-11 | Breeze-Reste, ungenutzte Mail-Anbieter-Konfiguration und `autoprefixer` entfernen (mit 404-Test) | KI-016, KI-017 | S |
| R-12 | CI-Action-Versionen vereinheitlichen, Kommentar korrigieren | KI-015 | S |
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

1. **R-06 + R-07 + R-08** in einem PR (klein, kundensichtbar, testbar).
2. **R-09 + R-10** (Tests, kein Verhaltenswechsel).
3. **R-11 + R-12** (Aufraeumen).
4. Parallel: Betreiber arbeitet Stufe 0 ab; Ergebnisse hier + in KNOWN_ISSUES eintragen.
