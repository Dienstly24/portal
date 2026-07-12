# E-Mail-Marketing – Verbesserungsplan (2026-07-12)

**Status: ENTWURF – wartet auf Freigabe. Noch nichts implementiert.**

Dieser Plan basiert auf einer Analyse des bestehenden E-Mail-Marketing-Moduls
(`EmailMarketingController`, `EmailCampaign`, `CampaignMail`, `ContractExpiryMail`,
`resources/views/admin/email_marketing.blade.php`, Scheduler in `routes/console.php`)
sowie den Geschäftsregeln des Inhabers für Wechsel-Erinnerungen pro Vertragssparte.

---

## Inhalt

- [Paket A – Abmeldung (DSGVO), Queue-Versand, E-Mail-Protokoll](#paket-a)
- [Paket B – Entwürfe, Zeitplanung, Vorschau & Testversand](#paket-b)
- [Paket C – Wechsel-Erinnerungs-Engine pro Sparte (NEU, ersetzt 30/14/7)](#paket-c)
- [Umsetzungsreihenfolge](#reihenfolge)
- [ملخص بالعربية – قواعد التذكير](#arabic-summary)

---

<a id="paket-a"></a>
## Paket A – Abmeldung, Queue-Versand, E-Mail-Protokoll (kritisch)

### A1. Abmelde-Link (rechtlich erforderlich, UWG §7 / DSGVO)

Ist-Zustand: Kampagnen-Mails enthalten keinen Abmelde-Link; es gibt kein
Einwilligungs-/Abmeldefeld am Kunden. Alle Kunden werden angeschrieben.

Maßnahmen:

1. **Migration** auf `customers`:
   - `marketing_consent` boolean, default `true`
   - `unsubscribed_at` timestamp, nullable
   - `unsubscribe_token` string(40), unique (für den öffentlichen Link ohne Login)
2. **Öffentliche Route (ohne Auth)**: `GET /abmelden/{token}` →
   `UnsubscribeController@handle`: setzt `unsubscribed_at = now()`,
   `marketing_consent = false`, zeigt eine zweisprachige Bestätigungsseite (de/ar).
3. **`emails/campaign.blade.php`**: Abmelde-Link im Footer
   („Abmelden / إلغاء الاشتراك").
4. **Empfängerfilter**: Kunden mit `unsubscribed_at != null` bzw.
   `marketing_consent = false` werden von Kampagnen ausgeschlossen
   (zusätzlich zum bestehenden `@dienstly24.internal`-Filter).
   Wichtig: Der Filter gilt für **Marketing-Kampagnen**; transaktionale Mails
   (Passwort-Reset, Ticket-Antworten) bleiben unberührt.

### A2. Versand über die Queue

Ist-Zustand: `EmailMarketingController::send()` verschickt synchron in einer
`foreach`-Schleife (`Mail::send()`), blockiert den HTTP-Request und läuft bei
vielen Empfängern in Timeouts. `QUEUE_CONNECTION=database` ist konfiguriert,
wird aber nicht genutzt.

Maßnahmen:

1. Neuer Job **`SendCampaignJob`** (queued): erhält die Kampagnen-ID, lädt die
   Empfänger in `chunk(200)`, versendet, aktualisiert `sent_count` fortlaufend.
2. Kampagnenstatus-Fluss: `draft` → `sending` → `sent`
   (Migration: enum-Wert `sending` ergänzen).
3. Controller legt die Kampagne an und dispatcht nur noch den Job –
   sofortige Rückmeldung an den Berater („Kampagne wird versendet…").

### A3. `email_logs` aktivieren (Zustellprotokoll pro Empfänger)

Ist-Zustand: Die Tabelle `email_logs` existiert seit der Migration
`2026_07_06_200001`, wird aber nirgends beschrieben; es gibt kein Model.
Fehler werden per `catch { continue; }` verschluckt.

Maßnahmen:

1. Neues Model **`EmailLog`** (UUID, wie `EmailCampaign`).
2. Nach jedem Einzelversand einen Log-Eintrag schreiben:
   `campaign_id`, `user_id`, `email`, `subject`, `type` (`campaign` /
   `contract_reminder` / …), `status` (`sent`/`failed`).
3. Fehler zusätzlich mit `Log::warning()` protokollieren (nicht mehr stumm).
4. UI: Abschnitt „Zustellung" pro Kampagne – gesendet / fehlgeschlagen /
   übersprungen (abgemeldet).

---

<a id="paket-b"></a>
## Paket B – Entwürfe, Zeitplanung, Vorschau & Testversand

### B1. Zeitplanung

- Migration: `scheduled_for` timestamp nullable auf `email_campaigns`.
- Formular: Datum/Uhrzeit-Feld + Button „Später senden" → `status='scheduled'`.
- Scheduler (`routes/console.php`): `campaigns:dispatch-scheduled` alle 5 Minuten;
  fällige Kampagnen (`scheduled_for <= now()`, `status='scheduled'`) starten
  den `SendCampaignJob`.

### B2. Entwürfe

- Button „Als Entwurf speichern" → `status='draft'` (enum-Wert existiert bereits).
- Eigener UI-Abschnitt „Entwürfe" mit Bearbeiten/Senden/Löschen.

### B3. Vorschau & Testversand

- „Vorschau": rendert `CampaignMail` serverseitig und zeigt sie im Modal/iframe.
- „Test an mich senden": verschickt genau eine Mail an `auth()->user()->email`;
  ohne Kampagnen-Datensatz, ohne `email_logs`-Eintrag.

---

<a id="paket-c"></a>
## Paket C – Wechsel-Erinnerungs-Engine pro Sparte

### Rechtliche Grundlagen (Deutschland) – geprüft am 12.07.2026

Die Erinnerungslogik richtet sich nach den aktuellen deutschen Kündigungs-
und Wechselregeln. Wichtigste Erkenntnis: **Für Internet- und Energieverträge
gibt es seit 2021/2022 keine automatische Jahres-Verlängerung mehr** – nach der
Erstlaufzeit sind sie jederzeit mit 1 Monat Frist kündbar. Für Versicherungen
(VVG) gilt die Jahres-Verlängerung dagegen weiterhin.

| Sparte | Gesetz | Regel |
|---|---|---|
| Internet / Mobilfunk | [§ 56 TKG](https://www.gesetze-im-internet.de/tkg_2021/__56.html) (seit 01.12.2021) | Erstlaufzeit max. 24 Monate. Danach **unbefristete Fortsetzung, jederzeit mit 1 Monat Frist kündbar** – kein „Gefangen für ein weiteres Jahr" mehr. |
| Strom / Gas (Sonderverträge) | [§ 41b EnWG](https://www.gesetze-im-internet.de/enwg_2005/__41b.html) + Gesetz für faire Verbraucherverträge (seit 01.03.2022, § 309 Nr. 9 BGB) | Nach der Erstlaufzeit **monatlich kündbar, Frist max. 1 Monat**; keine automatische Verlängerung um ein festes Jahr. Grundversorgung: 2 Wochen Frist. |
| Kfz-Versicherung | VVG / AKB ([Stichtag-Praxis](https://www.finanztip.de/kfz-versicherung/kuendigen/)) | Jahresvertrag, Kündigungsfrist **1 Monat zum Ende des Versicherungsjahres**; ohne Kündigung Verlängerung um max. 1 Jahr. Kalenderjahr-Verträge: **Stichtag 30.11.** Sonderkündigungsrecht bei Beitragserhöhung, Schadenfall, Fahrzeugwechsel (1 Monat ab Kenntnis). |
| Gesetzl. Krankenversicherung | [§ 175 Abs. 4 SGB V](https://www.krankenkassen.wiki/cms/gkv/info/grundlagen/gkv/kuendigung-der-gkv-wechsel-der-krankenkasse) | **Bindungsfrist 12 Monate** an die gewählte Kasse. Wechsel wirksam **zum Ablauf des übernächsten Kalendermonats** (Antrag im Monat M → neue Kasse ab 1. des Monats M+3; Beispiel: Antrag Juli → aktiv 1. Oktober). Seit 2021 übernimmt die neue Kasse den Wechsel. Sonderkündigungsrecht bei Zusatzbeitrags-Erhöhung (Bindungsfrist entfällt, gleiche 2-Monats-Frist). |
| Übrige Versicherungen (Hausrat, Privathaftpflicht, Rechtsschutz, …) | [§ 11 VVG](https://www.gesetze-im-internet.de/vvg_2008/__11.html) | Kündigungsfrist 1–3 Monate (üblich: **3 Monate zum Ende des Versicherungsjahres**); stillschweigende Verlängerung max. 1 Jahr; Verträge > 3 Jahre: Kündigung zum Ende des 3. Jahres möglich. |

**Konsequenzen für das System:**

1. **Internet/Strom/Gas nach Erstlaufzeit:** Ein Vertrag, dessen `end_date`
   bereits überschritten ist (Status weiterhin `active`), ist **jederzeit
   wechselbar** (1 Monat Frist). Diese Verträge bleiben dauerhaft im
   Wechsel-Pool und dürfen nicht als „verpasst" gelten. Der optimale
   Wechselzeitpunkt bleibt trotzdem das Ende der Erstlaufzeit
   (Neukundenboni) → daher die 6/3-Monats-Erinnerungen davor.
2. **GKV-Auslöser ist gesetzlich definiert:** wechselberechtigt nach
   12 Monaten Mitgliedschaft → Erinnerung ab `start_date + 12 Monate`
   (nicht an `end_date` gebunden). „Aktiv ab"-Datum immer
   `1. des (Antragsmonat + 3)`.
3. **Kfz-Stichtag 30.11.** ist der wichtigste Massentermin des Jahres
   (Kalenderjahr-Verträge). Verträge mit `end_date` = 31.12. erhalten die
   Erinnerungen automatisch Anfang/Mitte November-Fenster.

### Grundsatzentscheidung

Die bisherige Pauschallogik **„30/14/7 Tage vor Ablauf an alle"** wird für
Wechsel-Erinnerungen **ersetzt**. Sie existiert doppelt (Button
`sendContractReminders()` + Cron 08:30 in `routes/console.php`) und kann
denselben Kunden am selben Tag doppelt anschreiben.

**Geschäftsregel des Inhabers:** Wechsel-Erinnerungen gehen NUR an Kunden mit
Verträgen der Sparten **Kfz, Strom, Gas, Internet, gesetzliche Krankenversicherung
(GKV)**. Alle übrigen Sparten (Rechtsschutz, Privathaftpflicht, Hausrat usw.)
erhalten **bewusst keine** Wechsel-Erinnerung – Bestandserhalt ist dort das
Geschäftsmodell (Bestandsprovision).

### C1. Erinnerungsregeln pro Sparte

| Sparte (`contracts.type`) | 1. Erinnerung | 2. Erinnerung (nur ohne Kundenreaktion) | Besonderheiten |
|---|---|---|---|
| `internet` | 6 Monate vor `end_date` | 3 Monate vor `end_date` | Nach Ablauf der Erstlaufzeit jederzeit mit 1 Monat kündbar (§ 56 TKG) → Vertrag bleibt danach dauerhaft im Wechsel-Pool. |
| `strom_gas` (Strom) | 6 Monate vor `end_date` | 3 Monate vor `end_date` | Nach Erstlaufzeit monatlich kündbar (§ 41b EnWG / faire Verbraucherverträge) → bleibt im Wechsel-Pool. |
| `strom_gas` (Gas) | 6 Monate vor `end_date` | 3 Monate vor `end_date` | wie Strom |
| `kfz` | 2 Monate vor `end_date` | 6 Wochen (1,5 Monate) vor `end_date` | Jahresvertrag, Kündigungsfrist 1 Monat: Der Kunde kann faktisch nur bis Ende des 11. Vertragsmonats handeln, danach verlängert sich der Vertrag automatisch um ein Jahr. **Ausnahme Kalenderjahr-Verträge:** Manche Versicherer laufen fix bis 31.12. → Wechsel ist ausschließlich im **November** möglich; diese Verträge müssen im Oktober/November zuverlässig erfasst werden (bei `end_date` = 31.12. greift die Regel automatisch: 1. Erinnerung ~01.11., 2. Erinnerung ~Mitte November). |
| `krankenversicherung` (nur **GKV**) | ab `start_date + 12 Monate` (Ende der gesetzl. Bindungsfrist, § 175 SGB V) | frühestens 3 Monate nach der 1. Erinnerung, falls keine Reaktion | Kündigungsfrist: **2 volle Kalendermonate**. Der Antragsmonat zählt nicht mit – Antrag irgendwann im Monat M (egal welcher Tag) → neue Kasse aktiv ab **1. des Monats M+3**. Beispiel: Antrag im Juli → aktiv ab 1. Oktober. Diese Rechnung wird im Erinnerungstext und in der Berateransicht automatisch berechnet und ausgewiesen. Sonderkündigungsrecht bei Zusatzbeitrags-Erhöhung (manuell durch Berater auslösbar). **PKV wird nicht angeschrieben.** |
| `andere` (Rechtsschutz, Haftpflicht, Hausrat, …) | **keine** | **keine** | Kündigung wäre 3 Monate vor Ablauf – wird intern über `cancellation_date` verwaltet, aber es geht **keine Wechsel-Mail** an den Kunden. |

### C2. Reaktion des Kunden → raus aus der Zielgruppe

Nach der 1. Erinnerung gilt: **Meldet sich der Kunde, entfällt die 2. Erinnerung.**

Umsetzung:

1. Neue Tabelle **`contract_switch_reminders`**:
   - `id` (uuid), `contract_id`, `stage` (`first` | `followup`),
     `sent_at`, `responded_at` nullable, `status`
     (`sent` | `responded` | `closed`), timestamps
   - Unique-Index auf (`contract_id`, `stage`, Vertragsperiode) →
     verhindert Doppelversand (behebt zugleich das heutige
     Button-vs-Cron-Duplikat).
2. **Reaktion erfassen:**
   - Manuell: Button „Kunde hat reagiert" in der Kundenakte /
     Vertragsansicht (setzt `responded_at`).
   - Automatisch (optional, Phase 2): eingehende E-Mail im Posteingang
     (`EmailInboxController`) oder neues Ticket des Kunden innerhalb der
     Erinnerungsperiode markiert die Erinnerung als `responded`.
3. Der Scheduler prüft vor der 2. Erinnerung: existiert für den Vertrag eine
   `first`-Erinnerung mit `responded_at != null` → überspringen.

### C3. Technische Umsetzung

1. Neuer Service **`ContractSwitchReminderService`** – einzige Quelle der
   Regeln aus C1 (Konfig-Array: Sparte → Offsets für Stufe 1/2).
2. Scheduler-Eintrag (täglich, z. B. 08:30) ersetzt den bisherigen
   30/14/7-Block in `routes/console.php`.
3. Der Button „Erinnerungen jetzt senden" ruft denselben Service auf –
   dank Unique-Index kein Doppelversand mehr.
4. Versand über Queue (Paket A2), Protokoll über `email_logs` (Paket A3),
   Abmeldefilter (Paket A1) gilt auch hier.
5. Neue Mailable **`ContractSwitchMail`** (de/ar wie `ContractExpiryMail`),
   Texte je Sparte und Stufe; bei GKV inkl. korrekt berechnetem
   „aktiv ab"-Datum.

### C4. Offene Punkte (bitte bei Review entscheiden)

1. **GKV-Kennzeichnung:** `contracts.type = 'krankenversicherung'` unterscheidet
   heute nicht zwischen GKV und PKV (der Tarifname aus dem Formular wird nicht
   strukturiert gespeichert). Vorschlag: neues Feld `contracts.subtype`
   (z. B. `gkv` | `pkv`) + Pflege im Vertragsformular. Alternativ: nur Verträge
   mit Tarif „Gesetzliche Krankenversicherung" anschreiben.
2. **GKV-Auslöser: GELÖST** durch § 175 SGB V – wechselberechtigt nach
   12 Monaten Bindungsfrist → Erinnerung ab `start_date + 12 Monate`.
   Voraussetzung: `start_date` muss bei GKV-Verträgen gepflegt sein.
3. **Kfz-Kalenderjahr-Verträge:** Reicht `end_date = 31.12.` als Erkennung, oder
   soll ein explizites Kennzeichen am Vertrag gepflegt werden?
4. **Alte 30/14/7-Logik:** komplett entfernen oder als reine
   „Ablauf-Info" (ohne Wechselaufforderung) für interne Aufgaben behalten?

---

<a id="reihenfolge"></a>
## Umsetzungsreihenfolge

1. **Paket A** – rechtliche Basis + Infrastruktur (Abmeldung, Queue, `email_logs`)
2. **Paket C** – Wechsel-Erinnerungs-Engine (ersetzt 30/14/7, verhindert Doppelversand)
3. **Paket B** – Komfort (Entwürfe, Zeitplanung, Vorschau, Testversand)

Jedes Paket = eigener Commit + Feature-Tests (`tests/Feature/`, analog
`Phase3AutomationTest`).

---

<a id="arabic-summary"></a>
## ملخص بالعربية – قواعد التذكير بتبديل العقود

التذكيرات بالتبديل تُرسل **فقط** لعقود: السيارات، الكهرباء، الغاز، الإنترنت،
والتأمين الصحي الحكومي (GKV). باقي العقود (محامي/Rechtsschutz، ضد الغير/Haftpflicht،
Hausrat وغيرها) **لا تُرسل لها أي تذكيرات** — نستفيد من بقاء الزبون عند نفس الشركة.

| نوع العقد | التذكير الأول | التذكير الثاني (فقط إذا ما تواصل الزبون) | ملاحظات (مع السند القانوني) |
|---|---|---|---|
| إنترنت | قبل 6 أشهر من نهاية العقد | قبل 3 أشهر | **قانونياً (§56 TKG منذ 12/2021):** بعد انتهاء المدة الأولى العقد ما بيتمدد سنة — بيصير قابل للفسخ بأي وقت بمهلة شهر. يعني العقود يلي فات تاريخ نهايتها بتضل بقائمة التبديل دائماً. |
| كهرباء / غاز | قبل 6 أشهر | قبل 3 أشهر | نفس الشي (§41b EnWG + قانون العقود العادلة منذ 03/2022): بعد المدة الأولى فسخ شهري. |
| سيارات (Kfz) | قبل شهرين | قبل شهر ونصف | العقد سنوي ومهلة الفسخ شهر (VVG/AKB): آخر فرصة نهاية الشهر الـ11 وبعدها يتمدد سنة. عقود السنة الميلادية → **اليوم الحاسم 30.11** (أهم موعد جماعي بالسنة). في حق فسخ استثنائي عند رفع السعر أو حادث أو تبديل سيارة. |
| التأمين الصحي الحكومي (GKV) | بعد 12 شهر من بداية العضوية (§175 SGB V — مدة الالتزام القانونية) | بعد 3 أشهر إذا ما في رد | مهلة الفصل **شهرين كاملين**: شهر تقديم الطلب غير محسوب — طلب بشهر 7 (بأي يوم) → التأمين الجديد فعّال من 1 الشهر 10. النظام بيحسب تاريخ "فعّال من" تلقائياً. حق فسخ استثنائي عند رفع الاشتراك الإضافي. التأمين الخاص (PKV) لا يُراسَل. |
| باقي العقود (Hausrat، Haftpflicht، Rechtsschutz...) | لا شيء | لا شيء | قانونياً (§11 VVG): الفسخ عادةً قبل 3 أشهر من نهاية سنة التأمين، والتمديد الصامت سنة كحد أقصى. يُدار داخلياً عبر `cancellation_date` **بدون مراسلة الزبون**. |

**قاعدة التواصل:** إذا تواصل الزبون معنا بعد التذكير الأول → يخرج من قائمة
المستهدفين ولا يصله التذكير الثاني (زر "الزبون تواصل" بملف الزبون، ولاحقاً ربط
تلقائي مع البريد الوارد والتذاكر).

**أسئلة مفتوحة للمراجعة:** (1) كيف نميّز GKV عن PKV — مقترح حقل `subtype` جديد.
(2) ~~محفّز تذكير الـ GKV~~ **انحلّت قانونياً**: التذكير بعد 12 شهر من بداية
العضوية (§175 SGB V) — بس لازم يكون `start_date` مسجّل بعقود الـ GKV.
(3) هل يكفي تاريخ النهاية 31.12 لتمييز عقود السيارات الميلادية.
(4) هل نحذف نظام 30/14/7 القديم كلياً أم نبقيه كتنبيه داخلي فقط.
