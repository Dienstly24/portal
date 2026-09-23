# Performance Audit

Stand: 23.09.2026. Nur GEMESSENE Zahlen; Quelle jeweils genannt. In dieser
Sitzung wurde keine neue Messung vorgenommen (reine Bestandsaufnahme).

## Gemessene Verbesserungen (Bestand)

| Stelle | Vorher | Nachher | Quelle |
|---|---|---|---|
| Auswertungs-Dashboard (2000 Vertraege, Median 5 Laeufe) | 322,3 ms | 14,9 ms | `LeistungsmessungTest`, Nachlauf 16.09. |
| Dashboard `verlauf()` | 620,5 ms | 18,7 ms | Audit 15.09. |
| Chat-Abfrage (500 Nachrichten) | 200,1 kB je Abfrage | 0 kB mit Stand, 20 kB erste Seite | `ChatFeedTest` |
| Chat-Abfrage (Produktionsverlauf) | 473 kB | 0,1 kB | Audit 15.09. |
| Admin-Seiten ohne Diagramm | 452 kB | 152 kB (Chart.js nur bei Bedarf) | `AssetLadungTest` |
| Kopfzeilen-Logo | 124 kB | 24 kB | Audit 15.09. |
| JS-Bundle | 45,3 kB | 4,4 kB (Alpine entfernt) | SEC-4 |
| Provisions-Import (3 echte Dateien) | ~160 s | ~13 s (bcrypt-Platzhalter je Lauf gemerkt) | 26.08. |
| Zugriffspruefung | 1,2 ms | 0,7 ms (EXISTS statt IN-Liste) | Audit 15.09. |

## Strukturelle Massnahmen

- Indexe nach Auszaehlung der Bedingungen (ARCH-1, `DatabaseIndexTest`);
  `customers.user_id` war unindiziert.
- `Model::preventLazyLoading` ausserhalb Produktion (N+1 werden in Tests laut).
- Grosse Listen serverseitig paginiert (Vertraege 50, Vorschlaege 100,
  Berichte 50) mit Angabe der Gesamtzahl; CSV-Export gestreamt (`chunkById(500)`).
- Sofort-Suchen statt Komplettlisten in Formularen.
- Lange/externe Vorgaenge als Job; Zeitlimits fuer alle Fremddienste.
- `LargeListPerformanceTest`, `LeistungsmessungTest` pruefen die EIGENSCHAFT
  (waechst nicht ueberproportional), nicht Millisekunden.

## Risiken / Beobachtungen (nicht gemessen)

| Punkt | Einschaetzung |
|---|---|
| Queue/Cache/Session auf `database` | funktioniert; bei Wachstum Redis (vorbereitet, Server-Entscheidung, `docs/ANLEITUNG_REDIS_AR.md`) |
| `mailboxes:sync` alle 2 Minuten | bei vielen Konten Last auf DB/Netz; `withoutOverlapping` vorhanden |
| Grosse Blade-Vorlagen (`documents_inbox` 1636 Z., `customer_show` 1594 Z.) mit ~4900 Inline-Styles projektweit | groesseres HTML je Seite; kein Messwert |
| `DocumentIntakeService` (2229 Z.) | Wartbarkeit eher als Laufzeit |
| `style-src 'unsafe-inline'` noetig wegen Inline-Styles | Sicherheits- und Cache-Nebenwirkung, siehe TD |
| Core Web Vitals der Website | **UNKNOWN** (kein Netzzugang zur Live-Site; siehe SEO-Bericht) |

## Regel

Bei jeder Leistungsfrage: Messung vorher/nachher auf derselben Maschine mit
denselben Daten (Definition of Done, CLAUDE.md), Ergebnis hier eintragen.
