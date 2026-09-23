# Changelog

Aenderungsprotokoll ab Anlage der Wissensbasis (23.09.2026). Aeltere
Aenderungen: `git log`, `CLAUDE.md` (datierte Abschnitte) und `docs/*.md`.
Neueste Eintraege oben. Format je Eintrag:
Date · Task · Files Changed · Components Affected · Database Changes ·
API Changes · Potential Side Effects · Tests Performed · Result.

---

## 23.09.2026 - Project Knowledge Base angelegt, Voll-Audit

- **Task**: Betreiber-Auftrag "Projekt als dauerhaftes System mit
  technischem Gedaechtnis fuehren": Voll-Inventur, Wissensbasis,
  Feature-/Issue-Register, Architektur, Audit, Fahrplan.
- **Ausgangsstand**: `main` @ 04c0823 (nach PR #353, BIMI).
- **Files Changed**:
  - neu `docs/project-knowledge/` (README, PROJECT_OVERVIEW, ARCHITECTURE,
    FRONTEND_MAP, BACKEND_MAP, DATABASE_MAP, API_MAP, ROUTES_INVENTORY
    (generiert), AUTH_SYSTEM, USER_FLOWS, FEATURE_MAP, INTEGRATIONS,
    DEPLOYMENT, UI_UX_AUDIT, SECURITY_AUDIT, PERFORMANCE_AUDIT,
    TESTING_STATUS, TECHNICAL_DEBT, KNOWN_ISSUES, REPAIR_ROADMAP, CHANGELOG)
  - neu `scripts/wissensbasis-routen.php` (erzeugt das Routen-Inventar, rein lesend)
  - `CLAUDE.md`: Arbeitsweise Punkt 8 + Verweis unter "Weitere Doku"
- **Components Affected**: keine Laufzeitkomponente (nur Dokumentation +
  ein Hilfsskript, das von keiner Route/keinem Befehl geladen wird).
- **Database Changes**: keine. **API Changes**: keine.
- **Potential Side Effects**: keine im Betrieb. Pint prueft das neue Skript
  (gruen).
- **Tests Performed**: volle Testsuite `php artisan test`: 3044/3044 gruen, 0 uebersprungen (Details in
  [TESTING_STATUS.md](TESTING_STATUS.md)), `vendor/bin/pint --test` fuer das
  Skript, `composer audit`, `npm audit --omit=dev --audit-level=high`,
  `npm run build`, `php artisan route:list`. `composer stan` in dieser
  Umgebung NICHT ausfuehrbar (KI-018) - CI prueft es.
- **Result**: Wissensbasis angelegt; 18 offene Befunde registriert
  (0 CRITICAL, 3 HIGH - alle Betrieb/Recht, keiner im Code), 13 historische
  als VERIFIED uebernommen; Fahrplan R-01..R-19.
