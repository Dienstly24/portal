# Nachweispflicht, automatische Prüfung und Mitteilungen an Gesellschaften

Betreiber-Vorgabe 29.07.2026. Betrifft alle Selbstbedienungs-Änderungen im
Kundenportal (`customer_change_requests`).

## Warum

Eine neue Bankverbindung steuert den Geldfluss, eine neue Anschrift Post,
Policen und Beiträge. Bisher konnte der Kunde beides ohne jeden Beleg
beantragen; der Mitarbeiter musste "auf Verdacht" freigeben und danach
selbst daran denken, jede Gesellschaft (Krankenkasse, KFZ-Versicherer …)
zu informieren und den Text jedes Mal neu zu schreiben.

## Was das System jetzt macht

1. **Nachweis ist Pflicht** – ohne Beleg wird der Antrag gar nicht erst
   angelegt (der Kunde bekommt eine klare Fehlermeldung statt einer
   Anfrage, die später zwingend abgelehnt werden müsste):
   - Bankverbindung → Kontonachweis (Bankkarte/Kontoauszug), Ausweis optional
   - Anschrift → Meldebescheinigung, Ausweis mit neuer Anschrift oder
     anderer Nachweis (z. B. Mietvertrag), Rückseite optional
   - Name/Geburtsdatum/Anschrift auf „Meine Daten" → Ausweis oder
     Meldebescheinigung
   Betroffene Formulare: `/portal/bank`, `/portal/addresses` (anlegen und
   ändern), `/portal/profile`.
   Zentrale Regel: `App\Services\ChangeRequest\ChangeProofPolicy`.

2. **„Gültig ab"** – der Kunde erfasst selbst, ab wann die Änderung gilt
   (`customer_change_requests.effective_from`). Steht in der Review-Liste,
   im Kundenportal und in jeder Mitteilung an die Gesellschaften. Fehlt die
   Angabe, wird nichts geraten – die Review-Liste zeigt „Gültig-ab fehlt"
   und die Rückfrage-Schaltfläche liegt daneben.

3. **Automatische Prüfung** (`ChangeProofVerifier`, Job
   `VerifyChangeRequestProofJob`) – gleiche Technik wie der Smart Document
   Upload, „kostenlos zuerst": PDF-Textebene (`pdftotext`), sonst OCR
   (Tesseract). **Kein KI-Aufruf** – die Frage ist nicht „was steht im
   Dokument", sondern nur „steht der BEANTRAGTE Wert drin". Geprüft wird:
   - Bank: IBAN (exakt, zweiter Versuch OCR-tolerant: O/0, I/1, S/5 …),
     Kontoinhaber (optional)
   - Adresse: PLZ, Ort, Straße (umlaut- und „str./straße"-tolerant, mit
     Hausnummer in derselben Zeile), Name (optional)
   - Profil: Name, Anschrift, Geburtsdatum
   Ergebnis je Prüfpunkt: gefunden / nicht gefunden. **Der Rohtext wird
   NICHT gespeichert** (Datenminimierung), nur das Prüfergebnis
   (`proof_status`, `proof_result`).
   Status: `verified` · `partial` · `mismatch` · `unreadable` · `missing`.

4. **Automatische Freigabe (einstellbar)** – Einstellungen →
   „Kundenänderungen": aus / Adresse und Name automatisch (Standard) /
   alles inkl. Bank. Freigegeben wird nur bei `verified`, also wenn ALLE
   Pflichtangaben im Beleg gefunden wurden.
   Wichtig: ein Treffer belegt den INHALT des Dokuments, nicht seine
   ECHTHEIT. Deshalb bleibt die Bankverbindung im Standard beim
   Vier-Augen-Prinzip. Über jede Übernahme informiert die Glocke
   (admin/manager/support) – automatische Freigaben sind in der Liste als
   „🤖 Automatisch freigegeben" gekennzeichnet und stehen im Audit-Log.

5. **Mitteilungen an die Gesellschaften** (`InsurerNotificationBuilder`,
   Tabelle `change_notifications`) – nach der Freigabe entsteht je
   Gesellschaft des Kunden EIN fertiger Entwurf (alle betroffenen
   Vertragsnummern gebündelt, alte/neue Angabe, „gültig ab", Hinweis auf
   den beigefügten Nachweis). Seite:
   `/admin/change-requests/{id}/mitteilungen`.
   Der Mitarbeiter prüft den Text, trägt die E-Mail-Adresse ein und sendet
   (Nachweis optional als Anhang) oder markiert „per Post / im Portal der
   Gesellschaft erledigt". Nach außen geht NIE eine automatische E-Mail;
   Versand erfordert die Composer-Berechtigung und steht in der
   Kundenakte (Timeline). Gesendete Mitteilungen sind unveränderlich.
   Berücksichtigt werden nur laufende Verträge (`active`, `pending`).

6. **Rückfrage mit einem Klick** – in der Review-Liste öffnet
   „❓ Rückfrage" ein Feld mit fertigen Textbausteinen („Ab wann gilt die
   Änderung?", „Nachweis anfordern", „Besseres Foto"). Gesendet wird eine
   normale Chat-Nachricht; danach landet der Mitarbeiter direkt in der
   Unterhaltung mit dem Kunden (`/admin/kundenchat?kunde=…`).
   „💬 Chat öffnen" führt ohne Umweg dorthin.

## Datenschutz / Sicherheit

- Nachweise liegen auf der **privaten Disk** unter
  `customers/{id}/nachweise` – nie per URL erreichbar, Zugriff nur über
  `admin.change_requests.proof` mit Portfolio-Prüfung (`review`-Policy).
  Bei einer Kundenlöschung verschwindet das Verzeichnis mit dem Kunden.
- `proof_result` und alle Antragsdaten sind verschlüsselt gespeichert.
- Der Rohtext des Ausweises wird nie gespeichert.
- Kunden sehen ausschließlich ihre eigenen Anträge und Nachweise.

## Betrieb

- Die automatische Prüfung braucht die kostenlose Lesestufe auf dem
  Server (`OCR_ENABLED=true`, `tesseract-ocr`, `poppler-utils`) – auf dem
  VPS aktiv. Ist sie aus, meldet das System ehrlich „Nicht maschinell
  lesbar – bitte selbst prüfen" statt zu raten, und es gibt keine
  automatische Freigabe.
- „🔁 Nachweis erneut prüfen" wiederholt die Prüfung (z. B. nach einer
  Verbesserung der Lesestufe).
- Tests: `tests/Feature/ChangeRequestVerificationTest.php`.
