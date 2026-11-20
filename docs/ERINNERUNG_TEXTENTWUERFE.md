# Erinnerungs-Texte: Entwuerfe fuer pausierte Sparten (NUR ZUR VORLAGE)

Stand 20.11.2026. Diese Texte sind **nicht** freigegeben und werden **nicht**
verschickt. Strom, Gas, Internet und GKV sind pausiert
(`ERINNERUNG_SPARTEN_AKTIV`, Standard nur `kfz`), bis der Betreiber einen
Text freigibt.

Regeln fuer jeden Text (Rolle Tippgeber, keine Erlaubnis nach § 34d GewO):
erinnern, auf die Wechselmoeglichkeit hinweisen, Kontakt zu einem Partner
anbieten. Nicht beraten, nicht empfehlen, keine Ersparnis versprechen, nicht
"vermitteln", nicht "begleiten".

**So wird eine Sparte freigeschaltet** (Code-Stellen):
1. `App\Support\ErinnerungsSparte::SPARTEN[<sparte>]['vorlage']` setzen.
2. Absaetze A/B (DE + AR) in `App\Support\ErinnerungsTexte::TEXTE` eintragen.
3. Sparte in `ERINNERUNG_SPARTEN_AKTIV` (Server-`.env`) aufnehmen.
4. Bei Strom/Gas/Internet heisst der Vertragspartner "Anbieter"
   (steht bereits in `ErinnerungsSparte`).

Platzhalter wie bei Kfz: `{anbieter}`, `{ende}`, `{frist}`.

---

## Strom / Gas (Energie ist keine Versicherung)

**Betreff:** Ihr Stromvertrag: Die Mindestlaufzeit endet am {ende}

**A (Frist offen)**
- Die Mindestlaufzeit Ihres Stromvertrags bei {anbieter} endet am {ende}.
- Wenn Sie zu diesem Termin wechseln möchten, muss Ihre Kündigung in der Regel spätestens am {frist} beim Anbieter eingegangen sein. Maßgeblich sind die Bedingungen Ihres Vertrags.
- Auf Wunsch stellen wir für Sie den Kontakt zu einem Partner her, der Sie unverbindlich informiert.

**B (Frist abgelaufen)**
- Die Mindestlaufzeit Ihres Stromvertrags bei {anbieter} endet am {ende}. Die Kündigungsfrist zu diesem Termin ist bereits abgelaufen.
- Gut zu wissen: Nach Ende der Mindestlaufzeit ist ein Energievertrag in vielen Fällen mit einer Frist von einem Monat kündbar. Maßgeblich sind die Bedingungen Ihres Vertrags.
- Wenn Sie möchten, stellen wir für Sie den Kontakt zu einem Partner her, der Sie unverbindlich informiert.

**AR (A)**
- تنتهي مدة الالتزام الدنيا لعقد الكهرباء الخاص بك لدى {anbieter} في {ende}.
- إذا رغبت في التغيير اعتباراً من هذا الموعد، فيجب عادةً أن يصل إشعار الإلغاء إلى المزوّد في موعد أقصاه {frist}. وتبقى شروط عقدك هي المرجع.
- عند الطلب نضعك على تواصل مع شريك يقدّم لك المعلومات دون أي التزام.

(Gas identisch mit "Gasvertrag" / "عقد الغاز".)

## Internet

**Betreff:** Ihr Internetvertrag: Die Mindestlaufzeit endet am {ende}

**A**
- Die Mindestlaufzeit Ihres Internetvertrags bei {anbieter} endet am {ende}.
- Danach ist der Vertrag in der Regel monatlich kündbar (§ 56 TKG). Maßgeblich sind die Bedingungen Ihres Vertrags.
- Auf Wunsch stellen wir für Sie den Kontakt zu einem Partner her, der Sie unverbindlich informiert.

**AR (A)**
- تنتهي مدة الالتزام الدنيا لعقد الإنترنت الخاص بك لدى {anbieter} في {ende}.
- بعد ذلك يمكن عادةً إلغاء العقد شهرياً (المادة 56 من قانون الاتصالات). وتبقى شروط عقدك هي المرجع.
- عند الطلب نضعك على تواصل مع شريك يقدّم لك المعلومات دون أي التزام.

## Gesetzliche Krankenversicherung (besonders vorsichtig)

Kein Datum "neue Kasse aktiv ab", keine Aussage zu Beiträgen, keine Kasse
genannt ausser der eigenen.

**Betreff:** Information zu Ihrer Krankenkasse

- Sie sind seit mehr als zwölf Monaten Mitglied bei {anbieter}. Nach den gesetzlichen Regeln (§ 175 SGB V) ist ein Kassenwechsel damit grundsätzlich möglich.
- Ob ein Wechsel für Sie in Frage kommt, hängt von Ihrer persönlichen Situation ab. Maßgeblich sind die Regeln Ihrer Krankenkasse.
- Wenn Sie Fragen haben, stellen wir auf Wunsch den Kontakt zu einem Partner her, der Sie unverbindlich informiert.

**AR**
- أنت عضو لدى {anbieter} منذ أكثر من اثني عشر شهراً. ووفقاً للقواعد القانونية (المادة 175 من الكتاب الخامس من قانون الضمان الاجتماعي) يكون تغيير الصندوق ممكناً من حيث المبدأ.
- يتوقف ما إذا كان التغيير مناسباً لك على وضعك الشخصي. وتبقى قواعد صندوقك الصحي هي المرجع.
- إذا كانت لديك أسئلة، نضعك عند الطلب على تواصل مع شريك يقدّم لك المعلومات دون أي التزام.
