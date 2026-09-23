# User Flows

Stand: 23.09.2026. Je Ablauf: Einstieg -> Schritte -> beteiligte Teile ->
Sicherungen. Feature-IDs siehe [FEATURE_MAP.md](FEATURE_MAP.md).

## 1. Interessent (ohne Konto)

**1a Kontakt ueber die Website** (F-080, F-085)
`/` bzw. `/ar` -> Leistungsseite `/leistungen/{slug}` -> Anfrageformular
(`services.submit`, throttle 8/min) oder `/kontakt` -> `Ticket (source=website)`
+ Einwilligungsnachweis (`consent_given_at/ip/text`) + Bestaetigungsmail
(DE/AR) -> `/kontakt/danke`. Unkonvertierte Leads loescht
`tickets:purge-website-leads` nach 6 Monaten.

**1b Website-KI-Assistent** (F-041)
Chatfenster -> `POST api/website-assistent` -> `AssistantBudget` (IP/Sitzung/Tag)
-> Tools `searchKnowledge` (nur oeffentliche Kategorien), `saveLeadInformation`,
`requestHumanContact` -> `ai_leads` + genau EIN Vorgang bei Uebergabe.

**1c Telefon/WhatsApp** - Knoepfe (`data-cta`), Zaehlung ueber Matomo nach Einwilligung.

## 2. Kunde

**2a Konto entsteht**
- Durch Mitarbeiter: Kunde anlegen -> Kundennummer -> "Einladen"
  (`PortalAccessService`): Startpasswort = Geburtsdatum (ohne Geburtsdatum:
  Setz-Link, Warnung im System) -> `CustomerWelcomeMail` mit Magic-Login (90 Tage).
- Selbst: `/register` (Turnstile) -> `pending_registrations` -> Mail ->
  `register/bestaetigen/{token}` -> Konto + Kundennummer + Anmeldung.

**2b Erster Login**: `/login` -> `EnsurePasswordChanged` -> `/passwort-festlegen`
(eigenes Passwort >= 12, HIBP) -> `/portal`.

**2c Portal-Alltag** (F-060): Dashboard (Vertraege, offene Anforderungen,
Banner) -> Vertraege (Detail, Kilometerstand, Zaehlerstand/Foto) ->
Dokumente (Mehrseiten-Scanner -> `AnalyzeDocumentJob`, Statusabfrage) ->
Anforderungen hochladen -> Tickets -> Nachrichten (Chat, Feed `?seit=`,
optional KI-Antwort) -> Self-Service (Familie, Adressen, Kontakte, Bank ->
**nur Aenderungsantrag**, sensible Aenderungen NUR mit Nachweis) -> Profil,
Sprache DE/AR, Passwort.

**2d Passwort vergessen**: `/forgot-password` (E-Mail ODER Kundennummer ODER
email2) -> immer gleiche Antwortseite -> Link 60 Min -> neues Passwort.

**2e Unterschreiben** (auch ohne Konto, F-024): Mail-Link
`/unterschreiben/{token}` -> Code an die Mail (bzw. Geburtsdatum) ->
Dokument ansehen (Seitenbilder) -> EINE Zeichnung je Signaturgruppe ->
Zustimmung -> fertiges PDF als Mail-Anhang; Zugang widerrufen.

**2f Abmelden vom Newsletter**: `/abmelden/{token}` bzw. Ein-Klick-POST (RFC 8058).

## 3. Mitarbeiter (employee/support)

**3a Anmeldung**: Einladung (signierter Link, 14 Tage) -> Passwort >= 14 ->
2FA einrichten (`/sicherheit/zwei-faktor`, Ersatzcodes) -> `/admin`.
Optional zusaetzlich Basic-Auth vor `/admin`.

**3b Dokumenten-Eingang** (F-020): Upload/Mail/WhatsApp -> Analyse
(Textebene -> OCR -> Parser -> Heuristik -> KI) -> Eingang zeigt Typ,
Felder mit Erkennungssicherheit, Zuordnungs-Vorschlaege mit Grund ->
Mitarbeiter bestaetigt/korrigiert -> Kunde + Vertrag angelegt oder
ERGAENZT (Version History, nie Duplikat), Antrag -> Police ergaenzt
denselben Vertrag. Sonderfall Vermittler-Vorgangsliste -> Knopf "einlesen".

**3c Kommunikation**: Postfach (`/admin/postfach`, alle Kanaele, Filter,
Zuweisen, Zustand, interne Notiz, Antwortweg-Wahl) · Kundenchat mit KI-Panel
(Uebernehmen/KI aus/an) · Tickets · E-Mail-Composer mit Vorlagen · Team-Chat ·
Glocke.

**3d Tagesarbeit**: Aufgaben/Wiedervorlagen (+ geplante Auto-Mail),
Termine, Aenderungsantraege pruefen (Nachweisstatus) -> Mitteilungen an
Gesellschaften, Signaturanfrage anlegen (Kunde ODER externe Person) ->
Editor (Felder setzen) -> Senden -> Status/Erinnern/Stornieren.

## 4. Leitung (admin/manager)

- Mitarbeiter anlegen/Rechte (nur eigene Rechte weitergebbar), Vertretungen.
- Berichte/Auswertungs-Dashboard (Filter als Links), Neukunden + Werber.
- Provisionen: Datei hochladen -> Vorschau (Quelle erkannt, 5 Zahlen,
  Spaltenzuordnung) -> bestaetigen -> Zuordnung, optional Neuanlage ->
  Status-Engine -> "fehlende Provisionen" -> Nachverfolgung.
  (Recht `provisionen-verwalten`.)
- Marketing: Banner -> Social-Formate -> posten/planen (Meta) -> Insights ->
  Anzeige (PAUSED, Budgetdeckel).
- KI: Wissensbasis-Entwuerfe freigeben, Wissensluecken beantworten,
  KI-Training (Export hochladen -> Schwaerzung -> Paare einzeln freigeben).
- Betrieb: `/admin/systemzustand`, `/admin/fehler` (Erledigt/Wieder oeffnen).
- Nur admin: Einstellungen, Kanaele (WhatsApp-Onboarding), KI-Anbieter,
  E-Mail-Konten, Loeschen von Kunden (max. 30 je Aktion).

## 5. Partner

Login + 2FA -> `/partner`: Uebersicht, Meine Kunden (nur Vertraege),
Provisionen, Firmenprofil (Logo). Fremder Kunde -> 404; ohne Partnerdatensatz -> 403.

## 6. System (ohne Mensch)

Planer (siehe [BACKEND_MAP.md](BACKEND_MAP.md)): Postfach-Sync alle 2 Min,
Nachlaeufe fuer Analyse/KI alle 10 Min, Erinnerungen morgens, Aufraeumen
nachts, Vertragsenden, Familien-Uebergaenge, Provisionsstatus, Signaturablauf.
Jeder Lauf in `scheduled_task_runs`, jeder Defekt in `error_events`.
