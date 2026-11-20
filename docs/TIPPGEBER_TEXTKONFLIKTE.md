# Tippgeber-Rolle: kollidierende Kundentexte (Rohliste, 20.11.2026)

Grundlage fuer PR 3. NICHTS davon ist geaendert. Maschinell gesucht nach Beratung/beraten/vermitteln/Makler/anbieterunabhaengig/Angebot/sparen/guenstiger/empfehlen/begleiten (DE) und استشارة/مستشار/توفير/نوفر/وسيط (AR); enthaelt auch Fehltreffer (Kommentare, CSS, ausdrueckliche Tippgeber-Hinweise) - jede Zeile wird in PR 3 einzeln geprueft.

Besonders wichtig (nicht nur Website):
- KI-Verkaufsassistent und Website-Assistent (app/Services/Ai/Assistant/...): Prompts und Ablauf sind auf Angebote/Abschluss ausgelegt - fachlich mit der Tippgeber-Rolle zu klaeren, bevor der Assistent eingeschaltet wird.
- emails/contract_switch.blade.php: alter Erinnerungstext, seit PR 2 nicht mehr benutzt (Kfz -> neue Vorlage, uebrige Sparten pausiert).
- emails/schutzbrief_renewal.blade.php: 'wir beraten Sie gerne'; emails/customer_message.blade.php: 'Ihr Berater' / 'مستشارك'.
- database/seeders/ServicePageSeeder.php + Leistungsseiten in der DB; MessageTemplate-Standardvorlagen.

```
resources/views/emails/signatur_qualitaet.blade.php:28:<p style="font-size:15px;color:#333;">In der Beraterwelt unter <strong>Signaturen → Qualität</strong> lässt 
resources/views/emails/schutzbrief_renewal.blade.php:79:ntworten Sie auf diese E-Mail oder rufen Sie uns an – wir beraten Sie gerne.</p>
resources/views/emails/contract_switch.blade.php:21:n:0;font-size:22px;">{{ $lang === 'ar' ? '💡 فرصة للتوفير' : '💡 Ihre Sparchance' }}</h1>
resources/views/emails/contract_switch.blade.php:44: der ideale Zeitpunkt, Angebote zu vergleichen und in einen günstigeren Tarif zu wechseln.</p>
resources/views/emails/contract_switch.blade.php:46:Sie sich am besten heute bei uns, damit wir rechtzeitig das beste Angebot für Sie sichern.</p>
resources/views/emails/contract_switch.blade.php:62:�يميل أو اتصل فينا — المقارنة والاستشارة مجانية بالكامل.</p>
resources/views/emails/contract_switch.blade.php:65:ch auf diese E-Mail oder rufen Sie uns an – Vergleich und Beratung sind für Sie kostenlos.</p>
resources/views/emails/customer_message.blade.php:8:22px;">{{ $lang === 'ar' ? '💬 رسالة جديدة من مستشارك' : '💬 Neue Nachricht von Ihrem Berater' }}</h1>
resources/views/emails/customer_message.blade.php:14:<p style="font-size:15px;color:#333;">أرسل لك مستشارك في Dienstly24 الرسالة التالية:</p>
resources/views/emails/customer_message.blade.php:23:-size:15px;color:#333;">لديك رسالة جديدة من مستشارك في بوابة العملاء. يرجى تسجيل ال�
resources/views/emails/customer_message.blade.php:28:<p style="font-size:15px;color:#333;">Ihr Berater bei Dienstly24 hat Ihnen folgende Nachricht geschickt:</p>
resources/views/emails/customer_message.blade.php:37:e:15px;color:#333;">Sie haben eine neue Nachricht von Ihrem Berater im Kundenportal. Bitte melden Sie sich an, um sie zu lesen 
resources/views/emails/registration_verification.blade.php:46:    Dienstly24 &middot; Versicherungs- und Energiemakler<br>
resources/views/website/partials/google_trust.blade.php:98:-webkit-background-clip:text;background-clip:text;color:transparent;}
resources/views/website/home.blade.php:25:لتأمين وتسجيل السيارات والطاقة | استشارة بالألمانية والعربية'
resources/views/website/home.blade.php:26:  : 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie | Beratung DE & AR')
resources/views/website/home.blade.php:42:    ['هل الاستشارة مجانية فعلاً؟', 'نعم، الاستشارة 
resources/views/website/home.blade.php:43:ربية أيضاً؟', 'نعم، فريقنا يقدم الاستشارة بالألمانية والعربية – كما هو أ�
resources/views/website/home.blade.php:47:    ['Ist die Beratung wirklich kostenlos?', 'Ja. Die Kontaktaufnahme und die Bera
resources/views/website/home.blade.php:47:tung durch unseren Partnermakler sind für Sie kostenlos und unverbindlich.'],
resources/views/website/home.blade.php:62:          ? 'استشارة مستقلة عن شركات التأمين حول الت
resources/views/website/home.blade.php:63:          : 'Anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Energie – kostenlos,
resources/views/website/home.blade.php:120:.5.2 7 2.4 7 6.9"/></svg><div><b>100 %</b><span>{{ $isAr ? 'استشارة مستقلة' : 'Unabhängige Beratung' }}</span></div></
resources/views/website/home.blade.php:144:p>{{ $isAr ? 'قانوني أو خاص – شريكنا الوسيط المرخّص ينصحكم بالأنسب لوضعكم.' :
resources/views/website/home.blade.php:144: 'Gesetzlich oder privat – unser Partnermakler berät Sie zu den Optionen, die zu Ihrer Situation passen.'
resources/views/website/home.blade.php:168:      <p>{{ $isAr ? 'نفحص التعرفة ونوفر لكم عند تغيير المزوّد – ونتولى ع
resources/views/website/home.blade.php:168:�التسجيل.' : 'Tarif prüfen und beim Anbieterwechsel sparen – Kündigung und Anmeldung übernehmen wir.' }}</p>
resources/views/website/home.blade.php:178:طوات تفصلكم عن عرضكم' : 'In drei Schritten zu Ihrem Angebot' }}</h2>
resources/views/website/home.blade.php:182:�ة عن الشركات ونشرح لكم الفروق.' : 'Anbieterunabhängig prüfen wir passende Tarife und erklären die Untersc
resources/views/website/home.blade.php:183:lass="n">3</div><h3>{{ $isAr ? 'عرضكم الشخصي' : 'Persönliches Angebot' }}</h3><p>{{ $isAr ? 'تحصلون على عرضكم مش�
resources/views/website/home.blade.php:202:          ? 'نقدم استشارة واضحة وصادقة حول التأمين وتسجي�
resources/views/website/home.blade.php:203:          : 'Wir bieten verständliche, ehrliche Beratung rund um Versicherungen, Kfz-Zulassung und Energie – statt
resources/views/website/home.blade.php:205:التأمين – ولا نلزمكم بأي شركة.' : 'Anbieterunabhängig – wir binden Sie an keine Gesellschaft.' }}</p></di
resources/views/website/home.blade.php:206:$isAr ? 'وفروا الوقت والمال' : 'Zeit & Geld sparen' }}</b><p>{{ $isAr ? 'جهة اتصال واحدة لكل �
resources/views/website/home.blade.php:207:بٍ منكم' : 'Verständlich & nah' }}</b><p>{{ $isAr ? 'استشارة بالألمانية والعربية – ومتابعة 
resources/views/website/home.blade.php:207:شخصية من البداية إلى النهاية.' : 'Beratung auf Deutsch und Arabisch – persönlich betreut von Anfang
resources/views/website/home.blade.php:220:        : 'Über unsere Kooperationen mit Maklerpools und Plattformen vergleichen wir anbieterunabhängig di
resources/views/website/home.blade.php:224:    <span class="chip">Fonds Finanz<small>Maklerpool</small></span>
resources/views/website/home.blade.php:226:    <span class="chip">TeamGermany<small>Maklerpool</small></span>
resources/views/website/home.blade.php:248:   <div class="tcard reveal"><p class="q">{{ $isAr ? '«الاستشارة باللغة العربية ساعدتني كثيراً،
resources/views/website/home.blade.php:248:�هم بعد كل شيء بالألمانية.»' : '„Die Beratung auf Arabisch hat mir sehr geholfen, weil ich noch nicht all
resources/views/website/home.blade.php:261:    <details open><summary>{{ $isAr ? 'هل الاستشارة مجانية فعلاً؟' : 'Ist die Beratung wirklich k
resources/views/website/home.blade.php:261:?' }}</summary><p>{{ $isAr ? 'نعم، التواصل والاستشارة عند شريكنا الوسيط مجانية تماما�
resources/views/website/home.blade.php:261:� وغير ملزمة.' : 'Ja. Die Kontaktaufnahme und die Beratung durch unseren Partnermakler sind für Sie kostenlos und unv
resources/views/website/home.blade.php:262:</summary><p>{{ $isAr ? 'نعم، فريقنا يقدم الاستشارة بالألمانية والعربية – كما هو أ�
resources/views/website/hamburg.blade.php:11:        ? 'مرحباً Dienstly24، أريد استشارة. أنا في هامبورغ.'
resources/views/website/hamburg.blade.php:12:        : 'Hallo Dienstly24, ich haette gern eine Beratung. Ich bin aus Hamburg.';
resources/views/website/hamburg.blade.php:26:    ? 'وسيط تأمين في هامبورغ | كل شيء أونلاين
resources/views/website/hamburg.blade.php:27:    : 'Versicherungsmakler in Hamburg | komplett online | Dienstly24')
resources/views/website/hamburg.blade.php:30:    ? 'وسيط تأمين مقرّه هامبورغ – كل شيء أون
resources/views/website/hamburg.blade.php:31:    : 'Versicherungen aus Hamburg – Beratung durch unseren zugelassenen Partnermakler, komplett online, 
resources/views/website/hamburg.blade.php:33:@section('og-title', $isAr ? 'وسيط تأمين في هامبورغ – كل شيء أونلاي
resources/views/website/hamburg.blade.php:33:ن' : 'Versicherungsmakler in Hamburg – komplett online')
resources/views/website/hamburg.blade.php:52:    [$isAr ? 'وسيط تأمين في هامبورغ' : 'Versicherungsmakler in Hamburg', ($isAr ? '/ar/' : '/').$hSlug],
resources/views/website/hamburg.blade.php:68:        ? 'وسيط تأمين في هامبورغ – كل شيء أونلاي
resources/views/website/hamburg.blade.php:69:        : 'Versicherungsmakler in Hamburg – komplett online' }}</h1>
resources/views/website/hamburg.blade.php:76:       nationale Seite /leistungen/versicherungsmakler: Dienstly24 haelt
resources/views/website/hamburg.blade.php:81:      ? 'ملاحظة: الاستشارة ووساطة عقود التأمين يقدّمها شر�
resources/views/website/hamburg.blade.php:81:�كنا، وسيط تأمين مرخّص وفق المادة 34d الفقرة 
resources/views/website/hamburg.blade.php:82:      : 'Hinweis: Beratung und Vermittlung von Versicherungsverträgen übernimmt unser Kooperationspa
resources/views/website/hamburg.blade.php:82:rtner, ein zugelassener Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO, in eigener Verantwor
resources/views/website/hamburg.blade.php:82:tung. Dienstly24 stellt den Kontakt her und begleitet Sie online – alle Angaben dazu stehen in der' }}
resources/views/website/hamburg.blade.php:167:    <a href="{{ $lPrefix }}versicherungsmakler">
resources/views/website/hamburg.blade.php:168:rong>{{ $isAr ? 'كيف تعمل الوساطة' : 'Wie die Beratung funktioniert' }}</strong>
resources/views/website/hamburg.blade.php:170:          ? 'الفرق بين الوسيط والوكيل، ومعنى توكيل الوساطة، و�
resources/views/website/hamburg.blade.php:171:          : 'Makler, Vertreter, Maklervollmacht und was das kostet – ausführlich erklärt.' }}<
resources/views/website/hamburg.blade.php:195:      <summary>{{ $isAr ? 'هل الاستشارة بالعربية متاحة فعلاً؟' : 'Gibt es die 
resources/views/website/hamburg.blade.php:195:Beratung wirklich auch auf Arabisch?' }}</summary>
resources/views/website/hamburg.blade.php:197:          ? 'نعم. التواصل معنا والاستشارة عند شريكنا متاحان بالألمانية و�
resources/views/website/hamburg.blade.php:198:          : 'Ja. Der Kontakt mit uns und die Beratung durch unseren Partnermakler sind auf Deutsch und auf Arabis
resources/views/website/hamburg.blade.php:201:'هل تعملون خارج هامبورغ أيضاً؟' : 'Beraten Sie auch außerhalb von Hamburg?' }}</summary>
resources/views/website/hamburg.blade.php:204:a, deutschlandweit. Da ohnehin alles online läuft, ist die Beratung überall dieselbe – Hamburg ist unser Sitz, mehr nicht.' 
resources/views/website/hamburg.blade.php:207:      <summary>{{ $isAr ? 'قديش بتكلّف الاستشارة؟' : 'Was kostet die Beratung?' }}</summary>
resources/views/website/legal/widerruf.blade.php:10:d Finanzen (Nour Eddin Sheikh Alsouk), die die Versicherungsvermittlung in eigener Verantwortung durchführt. Ihren Widerruf richte
resources/views/website/legal/erstinformation.blade.php:7:     Vermittler/Untervermittler nach § 34d GewO. Beratung und Vermittlung erfolgen allein
resources/views/website/legal/erstinformation.blade.php:8:     durch NESA. Diese Erstinformation ist die des Vermittlers (NESA) - mit NESA abstimmen.
resources/views/website/legal/erstinformation.blade.php:9:     Kein Text auf der Website darf Dienstly24 als Vermittler, Makler oder Berater darstellen. -->
resources/views/website/legal/erstinformation.blade.php:13: Versicherungen. Damit Sie eine fundierte und rechtssichere Beratung erhalten, arbeiten wir mit einem zugelassenen Versicherungs
resources/views/website/legal/erstinformation.blade.php:13:makler zusammen: Ihre Anfrage leiten wir mit Ihrem Einverständnis
resources/views/website/legal/erstinformation.blade.php:14:<p><strong>Beratung, Vermittlung und Betreuung Ihrer Versicherungsverträge erfolgen ausschl
resources/views/website/legal/erstinformation.blade.php:16:<h2>1. Ihr Ansprechpartner für Beratung und Vermittlung</h2>
resources/views/website/legal/erstinformation.blade.php:18:<p><strong>Status:</strong> Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO.<br>
resources/views/website/legal/erstinformation.blade.php:19:rong> D-N5J2-9T4LJ-96, einsehbar unter <a href="https://www.vermittlerregister.info" target="_blank" rel="noopener">www.vermittlerregister
resources/views/website/legal/erstinformation.blade.php:26:<h2>3. Grundlage der Beratung</h2>
resources/views/website/legal/erstinformation.blade.php:27:gen und Versicherern, sodass nach fachlichen Kriterien eine Empfehlung erfolgen kann, welcher Versicherungsvertrag geeignet ist, I
resources/views/website/legal/erstinformation.blade.php:30:hen Kosten. NESA Versicherung und Finanzen erhält für die Vermittlung eine Provision vom jeweiligen Versicherungsunternehmen, die
resources/views/website/legal/agb.blade.php:13:A Versicherung und Finanzen (Nour Eddin Sheikh Alsouk) her, Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO. Beratung und Vermitt
resources/views/website/legal/agb.blade.php:14:<li>Beratung und Unterstützung bei der Kfz-Zulassung und dem Kennzeiche
resources/views/website/legal/agb.blade.php:15:<li>Beratung zu Strom- und Gasanbietern.</li>
resources/views/website/legal/agb.blade.php:17:nstly24 ist weder Partei des Versicherungsvertrags noch des Maklervertrags. Vertragspartner sind stets das jeweilige Versicher
resources/views/website/legal/agb.blade.php:19:<p>Die Kontaktaufnahme und die Beratung durch unseren Partner sind für Kunden kostenlos und unverb
resources/views/website/legal/agb.blade.php:19:indlich. Etwaige Vergütungen für die Versicherungsvermittlung erfolgen ausschließlich in Form von Provisionen der Versic
resources/views/website/legal/agb.blade.php:25:<p>Für Beratung und Vermittlung von Versicherungsverträgen haftet ausschließlich NESA Ver
resources/views/website/legal/agb.blade.php:25:en. Für die übrigen Leistungen (z.&nbsp;B. Kfz-Zulassung, Beratung zu Energieverträgen) haftet Dienstly24 nach den gesetzlich
resources/views/website/legal/impressum.blade.php:13:ations- und Kontaktplattform und ist nicht als Versicherungsvermittler tätig. Anfragen zu Versicherungen leiten wir mit Ihrem Ein
resources/views/website/legal/impressum.blade.php:13:verständnis an unseren Kooperationspartner weiter, der Beratung und Vermittlung in eigener Verantwortung übernimmt:</p>
resources/views/website/legal/impressum.blade.php:15:<p>Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO<br>Registrierungsnumm
resources/views/website/legal/impressum.blade.php:15:r: D-N5J2-9T4LJ-96 – einsehbar unter <a href="https://www.vermittlerregister.info" target="_blank" rel="noopener">www.vermittlerregister
resources/views/website/legal/impressum.blade.php:19:<p>Beschwerdestelle für Versicherungsvermittlung (für die Vermittlung durch unseren Partner): Versicherungsombudsmann e.V., Postf
resources/views/website/legal/datenschutz.blade.php:27:<p>Betrifft Ihre Anfrage die Vermittlung eines Versicherungsvertrags, geben wir die dafür erforderl
resources/views/website/legal/datenschutz.blade.php:27:, in deren Auftrag und unter deren Haftung die Versicherungsvermittlung erfolgt, sowie ggf. an das jeweilige Versicherungsunternehm
resources/views/website/layout.blade.php:27:e', 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie | Beratung DE & AR')</title>
resources/views/website/layout.blade.php:29:    ? 'Dienstly24 من هامبورغ: استشارة مستقلة عن شركات التأمين حول الت
resources/views/website/layout.blade.php:30:    : 'Dienstly24 aus Hamburg: anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Strom & Gas – kosten
resources/views/website/layout.blade.php:46:    ? 'استشارة مستقلة حول التأمين وتسجيل السي�
resources/views/website/layout.blade.php:47:    : 'Anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Energie – kostenlos,
resources/views/website/layout.blade.php:82:hidden="true"><path d="M20 6L9 17l-5-5"/></svg> {{ $isAr ? 'استشارة مجانية' : 'Kostenlose Beratung' }}</span><span clas
resources/views/website/layout.blade.php:136:      <li><a href="{{ ($isAr ? '/ar/' : '/') . 'versicherungsmakler-hamburg' }}">{{ $isAr ? 'هامبورغ' : 'Hamburg' }}</a>
resources/views/website/layout.blade.php:151:      ? 'تتم وساطة التأمين عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 
resources/views/website/layout.blade.php:151:حرف الألماني (GewO). تجدون بيانات الوسيط ورقم السجل وجهة الرقابة في صفحة �
resources/views/website/layout.blade.php:152:      : 'Die Versicherungsvermittlung erfolgt über einen zugelassenen Versicherungsmakler gem. �
resources/views/website/layout.blade.php:152:� 34d Abs. 1 GewO. Angaben zum Vermittler, Registernummer und Aufsichtsbehörde finden Sie im Impress
resources/views/website/legal-layout.blade.php:4:     Vermittlerregister). Deutschsprachig, dunkles Markendesign, lokale
resources/views/website/legal-layout.blade.php:18:round-image:radial-gradient(rgba(255,255,255,.035) 1px, transparent 1px); background-size:26px 26px; color:var(--text); font-f
resources/views/services/index.blade.php:13:�سجيل السيارات والكهرباء والغاز. استشارة مستقلة عن الشركات، مجاناً وبال�
resources/views/services/index.blade.php:14:�berblick: Versicherungen, Kfz-Zulassung sowie Strom & Gas. Anbieterunabhängige Beratung – kostenlos, deutschlandweit, auf Deutsch
resources/views/services/index.blade.php:49:kground-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
resources/views/services/show.blade.php:77:ground-image:radial-gradient(rgba(255,255,255,.045) 1px,transparent 1px);background-size:28px 28px;}
resources/views/services/show.blade.php:101:.kontaktwege .k-form{background:transparent;border:1px solid var(--glass-line);color:#c7cec9;}
resources/views/services/show.blade.php:145:ition:relative;-webkit-mask-image:linear-gradient(90deg,transparent,#000 7%,#000 93%,transparent);mask-image:linear-gradient(9
resources/views/services/show.blade.php:145:0deg,transparent,#000 7%,#000 93%,transparent);}
resources/views/services/show.blade.php:219:         acht Felder. Der Hinweis auf die deutschlandweite Beratung steht
resources/views/services/show.blade.php:226:            ? 'مرحباً Dienstly24، أريد استشارة بخصوص: ' . $page->t('title')
resources/views/services/show.blade.php:232:            ? 'استشارة مجانية وغير ملزمة – بالعربية و�
resources/views/services/show.blade.php:233:            : 'Kostenlose, unverbindliche Beratung – auf Deutsch und Arabisch, deutschlandweit telefonisch u
resources/views/services/show.blade.php:245:ta-cta="formular" data-cta-seite="{{ $page->slug }}">{{ __('Beratung anfragen') }}</a>
resources/views/services/show.blade.php:283:            <h2>💬 {{ __('Beratung anfragen') }}</h2>
resources/views/services/show.blade.php:372:-- Pflichtangaben vollstaendig (Abschnitt 12): Versicherungsvermittlung
resources/views/services/show.blade.php:393:    ? 'تتم وساطة التأمين عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 
resources/views/services/show.blade.php:393:حرف الألماني (GewO). تجدون بيانات الوسيط ورقم السجل وجهة الرقابة في صفحة �
resources/views/services/show.blade.php:394:    : 'Die Versicherungsvermittlung erfolgt über einen zugelassenen Versicherungsmakler gem. �
resources/views/services/show.blade.php:394:� 34d Abs. 1 GewO. Angaben zum Vermittler, Registernummer und Aufsichtsbehörde finden Sie im Impress
resources/views/services/show.blade.php:397:    ? 'مرحباً Dienstly24، أريد استشارة بخصوص: ' . $page->t('title')
resources/views/support/chat.blade.php:29:e.sie{background:color-mix(in srgb, var(--emerald) 55%, transparent);align-self:flex-end;border-end-end-radius:4px;}
resources/views/support/form.blade.php:14:kground-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
resources/views/legal/page.blade.php:62:hnen und uns vereinbarten Bedingungen aus Ihrem Betreuungs-/Maklervertrag.</p>
resources/views/legal/page.blade.php:87: <p>Die Verarbeitung erfolgt zur Erfüllung des Betreuungs-/Maklervertrags (Art. 6 Abs. 1 lit. b DSGVO) sowie zur Wahrung rech
resources/views/partials/chat_core.blade.php:3:     Kunden-Chat der Beraterwelt. Die Perspektive steckt im Payload
resources/views/partials/chat_core.blade.php:45:        // data-kind: die Kanal-Filter der Beraterwelt greifen sonst nicht auf
resources/views/partials/auth_glass_styles.blade.php:10:kground-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
resources/views/partials/doc_preview.blade.php:2:    Wiederverwendbare Dokument-Vorschau (Beraterwelt + Kundenportal).
resources/views/partials/doc_preview.blade.php:72:    // sonst -> Hinweis mit Download-Empfehlung).
resources/views/partials/chat_styles.blade.php:3:     + schwebendes Widget) und vom Kunden-Chat der Beraterwelt, damit der
resources/views/partials/chat_styles.blade.php:41:   interne Notizen gelb rechts, Kanal-Tag an Blasen (Beraterwelt) */
app/Models/MessageTemplate.php:30:        'berater' => 'Name des angemeldeten Mitarbeiters',
app/Models/MessageTemplate.php:52:            'berater' => $sender?->name ?? '',
app/Models/MessageTemplate.php:91:r melden uns dann umgehend.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
app/Models/MessageTemplate.php:94: diese Nachricht antworten.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
app/Models/MessageTemplate.php:97:ie uns bitte kurz Bescheid.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
app/Models/MessageTemplate.php:100:icht oder rufen Sie uns an.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
app/Models/MessageTemplate.php:103:sind wir gerne für Sie da.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
app/Models/MessageTemplate.php:106:nzen]\n\nVielen Dank vorab.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
app/Models/MessageTemplate.php:109:s Vertragsende schriftlich.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
app/Models/MessageTemplate.php:110:            ['category' => 'gesellschaft', 'name' => 'Maklervollmacht / Bestandsuebertragung', 'sort' => 30,
app/Models/MessageTemplate.php:112:> "Sehr geehrte Damen und Herren,\n\nanbei erhalten Sie die Maklervollmacht unseres gemeinsamen Kunden:\n\nName: {{name}}\nGeb
app/Models/MessageTemplate.php:112:um eine kurze Bestätigung.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
app/Services/Ai/Assistant/Sales/ConversationContext.php:13: * dieselbe Frage stellt) als auch die Beraterwelt (damit der Mitarbeiter
app/Services/Ai/Assistant/Sales/ConversationContext.php:64:    /** Fortschritt "3/5" fuer Beraterwelt und Uebergabe-Zusammenfassung. */
app/Services/Ai/Assistant/Sales/IntentClassifier.php:28:            'guenstiger', 'günstiger', 'change my contract', 'switch provider',
app/Services/Ai/Assistant/Sales/ConversationState.php:69:    /** Anzeige in der Beraterwelt - kurz und in der Sprache des Betriebs. */
app/Services/Ai/Assistant/Sales/ConversationState.php:134:     * Der naechste Schritt in Klartext - fuer die Beraterwelt und fuer den
app/Services/Ai/Assistant/Sales/InternalVerificationService.php:21: * der Beraterwelt, und auch dort ohne den Bestandswert.
app/Services/Ai/Assistant/Sales/Offers/ManualOfferSource.php:11: * Angebote der Phase 1: ein MENSCH hinterlegt sie in der Beraterwelt
app/Services/Ai/Assistant/AssistantPrompt.php:30:Versicherungs- und Energiemakler. Du arbeitest AUSSCHLIESSLICH im
app/Services/Ai/Assistant/AssistantPrompt.php:124:BERATUNG UND VERKAUF:
app/Services/Ai/Assistant/AssistantTexts.php:89:        'ar' => 'ملاحظة: لسنا وسيط تأمين ولا نقدّم توصيات بشأن التع
app/Services/Ai/Assistant/AssistantScopeGuard.php:52:        'dienstly', 'makler', 'berater',
app/Services/Ai/Assistant/AssistantScopeGuard.php:103:        'kein roboter', 'berater sprechen', 'jemand anrufen', 'rückruf', 'rueckruf',
app/Services/Ai/Assistant/Website/WebsitePrompt.php:28:Versicherungs- und Energiemakler. Du sprichst mit einem BESUCHER, der
app/Services/Ai/Assistant/AssistantSettings.php:10: * Liegen als SystemSetting, damit der Betreiber sie in der Beraterwelt
app/Services/Ai/Assistant/AssistantSettings.php:61:     * (das waere ein Sicherheitsverlust, kein Sparen).
database/seeders/ServicePageSeeder.php:50:             * Abschnitt 3): "Versicherungsmakler" ist das wichtigste
database/seeders/ServicePageSeeder.php:59:             * gebundener Vermittler unter der Haftung von NESA Versicherung
database/seeders/ServicePageSeeder.php:62:             * "Wir sind Ihr Versicherungsmakler mit Erlaubnis nach 34d"
database/seeders/ServicePageSeeder.php:68:                'slug' => 'versicherungsmakler',
database/seeders/ServicePageSeeder.php:71:                'title_de' => 'Versicherungsmakler',
database/seeders/ServicePageSeeder.php:72:                'title_ar' => 'وسيط تأمين',
database/seeders/ServicePageSeeder.php:73:                'subtitle_de' => 'Unabhängige Beratung statt Verkauf – so arbeiten wir',
database/seeders/ServicePageSeeder.php:74:                'subtitle_ar' => 'استشارة مستقلة بدل البيع – هيك منشتغل',
database/seeders/ServicePageSeeder.php:75:                'intro_de' => 'Ein Versicherungsmakler steht auf der Seite des Kunden, nicht auf der '
database/seeders/ServicePageSeeder.php:79:                'intro_ar' => 'وسيط التأمين (Versicherungsmakler) بيكون بصف الزبون، مو بصف شركة تأ
database/seeders/ServicePageSeeder.php:82:                'highlights_de' => "Anbieterunabhängiger Vergleich statt eines einzelnen Anbieters\n"
database/seeders/ServicePageSeeder.php:85:                    ."Beratung deutschlandweit – telefonisch, online und in Hamburg vor 
database/seeders/ServicePageSeeder.php:86:                    .'Beratung auf Deutsch und Arabisch',
database/seeders/ServicePageSeeder.php:90:                    ."استشارة بكل ألمانيا – هاتف وأونلاين، و�
database/seeders/ServicePageSeeder.php:91:                    .'استشارة بالعربي والألماني',
database/seeders/ServicePageSeeder.php:92:                'meta_description_de' => 'Versicherungsmakler statt Vertreter: Was der Unterschied ist, '
database/seeders/ServicePageSeeder.php:93:                    .'was eine Maklervollmacht bedeutet und wie die Beratung abläuft. Kostenlos,
database/seeders/ServicePageSeeder.php:95:                'meta_description_ar' => 'وسيط تأمين بدل وكيل شركة: شو الفرق، وش
database/seeders/ServicePageSeeder.php:96:                    .'وكيف بتمشي الاستشارة. مجاناً، بكل ألمانيا، بالعربي و
database/seeders/ServicePageSeeder.php:97:                'body_de' => "## Makler, Vertreter oder Vergleichsportal – der Unterschied\n"
database/seeders/ServicePageSeeder.php:98:Auftrag EINER Gesellschaft und vermittelt deren Tarife. Ein Versicherungsmakler wird dagegen vom Kunden beauftragt: Er ist rechtlich dessen
database/seeders/ServicePageSeeder.php:98:icht die Angebote mehrerer Gesellschaften und schuldet eine Empfehlung, die zum Bedarf passt (§ 60 VVG). Ein Vergleichsportal wie
database/seeders/ServicePageSeeder.php:100:                ."Damit hier nichts im Unklaren bleibt: Die Vermittlung von Versicherungsverträgen erfolgt über einen zugelassene
database/seeders/ServicePageSeeder.php:100:n Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO. Dienstly24 ist dabei
database/seeders/ServicePageSeeder.php:100: Ihr Ansprechpartner für den Kontakt: Beratung und Vermittlung übernimmt unser Kooperationspartner in eigener Verantwortu
database/seeders/ServicePageSeeder.php:100:ng. Name, Registernummer im Vermittlerregister und die zuständige Aufsichtsbehörde stehen vollständig i
database/seeders/ServicePageSeeder.php:101:                    ."## Was eine Maklervollmacht bedeutet\n"
database/seeders/ServicePageSeeder.php:102:                    ."Mit der Maklervollmacht erlauben Sie unserem Partnermakler, gegenüber den
database/seeders/ServicePageSeeder.php:107:                    ."Die Beratung ist für Sie kostenlos. Vergütet wird die Vermittlung übe
database/seeders/ServicePageSeeder.php:108:                    ."## So läuft die Beratung ab\n"
database/seeders/ServicePageSeeder.php:112:              ."- Entscheiden: Sie entscheiden in Ruhe. Wir begleiten den Abschluss und bleiben danach Ihr Ansprechpartner.\n\n"
database/seeders/ServicePageSeeder.php:116:                    .'Beraten wird telefonisch und online in ganz Deutschland; unser Bür
database/seeders/ServicePageSeeder.php:117:                'body_ar' => "## وسيط، وكيل، ولا موقع مقارنة – شو الفر
database/seeders/ServicePageSeeder.php:118:� شركة وحدة وبيسوّق تعرفاتها. أما وسيط التأمين (Makler) فالزبون هو يلي بيوك
database/seeders/ServicePageSeeder.php:120:�امض: وساطة عقود التأمين بتتم عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 
database/seeders/ServicePageSeeder.php:120:ly24 بتوصلك فيه وبتتابع معك، أما الاستشارة والوساطة فبيقدّمها شريكنا تحت �
database/seeders/ServicePageSeeder.php:121:              ."## شو معنى توكيل الوساطة (Maklervollmacht)\n"
database/seeders/ServicePageSeeder.php:122:                 ."بتوكيل الوساطة بتسمح لوسيطنا الشريك يمثّلك قدام الشركات: نط
database/seeders/ServicePageSeeder.php:127:                    ."الاستشارة مجانية إلك. أجر الوساطة (Courtage) ب�
database/seeders/ServicePageSeeder.php:128:                    ."## كيف بتمشي الاستشارة\n"
database/seeders/ServicePageSeeder.php:136:                    .'الاستشارة بتتم هاتفياً وأونلاين بكل ألما�
database/seeders/ServicePageSeeder.php:139:                        'q_de' => 'Was kostet mich ein Versicherungsmakler?',
database/seeders/ServicePageSeeder.php:140:                       'q_ar' => 'قديش بيكلّفني وسيط التأمين؟',
database/seeders/ServicePageSeeder.php:147:tet für eine Gesellschaft und vermittelt deren Tarife. Ein Makler wird vom Kunden beauftragt und vergleicht die Angebote mehr
database/seeders/ServicePageSeeder.php:148:ل لشركة وحدة وبيسوّق تعرفاتها. الوسيط بيوكّله الزبون وبيقارن عروض عدة 
database/seeders/ServicePageSeeder.php:151:                        'q_de' => 'Kündigt die Maklervollmacht meine bestehenden Verträge?',
database/seeders/ServicePageSeeder.php:153:                        'a_de' => 'Nein. Die Maklervollmacht kündigt nichts. Sie erlaubt uns nur, für Sie mit
database/seeders/ServicePageSeeder.php:157:                        'q_de' => 'Beraten Sie auch außerhalb von Hamburg?',
database/seeders/ServicePageSeeder.php:158:                        'q_ar' => 'بتقدّموا استشارة برّا هامبورغ كمان؟',
database/seeders/ServicePageSeeder.php:159:                        'a_de' => 'Ja. Die Beratung findet deutschlandweit telefonisch und online statt. Unser 
database/seeders/ServicePageSeeder.php:160:                        'a_ar' => 'أي. الاستشارة بتتم بكل ألمانيا هاتفياً وأونل�
database/seeders/ServicePageSeeder.php:163:                        'q_de' => 'Kann ich auf Arabisch beraten werden?',
database/seeders/ServicePageSeeder.php:164:                        'q_ar' => 'فيني آخد الاستشارة بالعربي؟',
database/seeders/ServicePageSeeder.php:165:                        'a_de' => 'Ja. Wir beraten auf Deutsch und auf Arabisch – Sie wählen, was Ihnen lei
database/seeders/ServicePageSeeder.php:166:                        'a_ar' => 'أي. منقدّم الاستشارة بالألماني وبالعربي – إنت بتختا
database/seeders/ServicePageSeeder.php:191:ch erklärt: Haftpflicht, Teil- und Vollkasko im Vergleich. Anbieterunabhängige Beratung auf Deutsch und Arabisch – kostenlos anfr
database/seeders/ServicePageSeeder.php:192:�سؤولية والتأمين الجزئي والشامل. استشارة مستقلة عن الشركات بالعربي والأ�
database/seeders/ServicePageSeeder.php:193:�ßig ändern, lohnt sich ein aktueller Vergleich.\n\n## So beraten wir Sie\nWir vergleichen anbieterunabhängig eine Vielzahl 
database/seeders/ServicePageSeeder.php:193: – auf Deutsch und Arabisch. Sie entscheiden in Ruhe; wir begleiten Sie bis zum Abschluss und darüber hinaus.\n\n## Tipps vor 
database/seeders/ServicePageSeeder.php:193:lten Sie vom Versicherer – wir kümmern uns im Rahmen der Vermittlung darum.\nWas macht eine gute Kfz-Versicherung aus? Ein passe
database/seeders/ServicePageSeeder.php:203:                        'q_de' => 'Was kostet mich die Beratung?',
database/seeders/ServicePageSeeder.php:204:                   'q_ar' => 'قديش بتكلّفني الاستشارة؟',
database/seeders/ServicePageSeeder.php:205:                        'a_de' => 'Die Beratung ist für Sie kostenlos und unverbindlich.',
database/seeders/ServicePageSeeder.php:206:                        'a_ar' => 'الاستشارة مجانية وبدون أي التزام.',
database/seeders/ServicePageSeeder.php:221:             'subtitle_de' => 'Gesetzlich oder privat - wir beraten zur passenden Lösung',
database/seeders/ServicePageSeeder.php:230:lights_de' => "Gesetzliche und private Krankenversicherung\nBeratung passend zu Beruf und Einkommen\nUnterstuetzung beim Wechsel
database/seeders/ServicePageSeeder.php:231:hlights_ar' => "التأمين الحكومي والخاص\nاستشارة حسب المهنة والدخل\nمساعدة بالتب
database/seeders/ServicePageSeeder.php:233:iption_de' => 'Krankenversicherung: gesetzlich oder privat? Anbieterunabhängige Beratung zu Optionen, Wechsel und Anträgen – auf 
database/seeders/ServicePageSeeder.php:234:ar' => 'التأمين الصحي: حكومي أو خاص؟ استشارة مستقلة حول الخيارات والتبديل و�
database/seeders/ServicePageSeeder.php:235: Zusatzbeiträge unterscheiden, kann ein Wechsel bares Geld sparen.\n\n## Grund- und Zusatzleistungen\nDie Grundleistungen sin
database/seeders/ServicePageSeeder.php:235:ein Hindernis – in der GKV gilt Aufnahmepflicht.\n\n## So beraten wir Sie\nWir erklären Ihnen die Unterschiede – gesetzlic
database/seeders/ServicePageSeeder.php:235:rif und Gesundheitszustand statt nach dem Einkommen – wir beraten Sie, was zu Ihrer Situation passt.",
database/seeders/ServicePageSeeder.php:261:e Erstattung bei Zahnersatz, Implantaten und Zahnreinigung. Anbieterunabhängige Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:262:�ركيبات والزرعات وتنظيف الأسنان. استشارة مستقلة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:263:axe: professionelle Zahnreinigung (je nach Tarif).\n\n## So beraten wir Sie\nWir zeigen Ihnen, welcher Tarif sich für Ihre Sit
database/seeders/ServicePageSeeder.php:276:Zulassungsstelle - Anmeldung, Ummeldung oder Abmeldung. Sie sparen sich den '
database/seeders/ServicePageSeeder.php:284: Zulassung Ihres Fahrzeugs bei der Zulassungsstelle – Sie sparen sich Behördengang und Wartezeit.\n\n## Das übernehmen wir
database/seeders/ServicePageSeeder.php:326:   'subtitle_de' => 'Tarif prüfen und beim Anbieterwechsel sparen',
database/seeders/ServicePageSeeder.php:327:                'subtitle_ar' => 'فحص التعرفة وتوفير عند تبديل المزوّد',
database/seeders/ServicePageSeeder.php:329: und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen '
database/seeders/ServicePageSeeder.php:338:' => 'Strom und Gas: Tarif prüfen und beim Anbieterwechsel sparen. Kündigung und Anmeldung übernehmen wir – Versorgung oh
database/seeders/ServicePageSeeder.php:340:                'body_de' => "## Sparen beim Anbieterwechsel\nDie Energiepreise ändern sich ständ
database/seeders/ServicePageSeeder.php:340: und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen können – für Strom, Gas oder beides.\n\n## Der Wechsel 
database/seeders/ServicePageSeeder.php:363:ützen zusätzlich Ihr eigenes Fahrzeug – wir vergleichen anbieterunabhängig für Sie.',
database/seeders/ServicePageSeeder.php:365:nTeilkasko z. B. bei Diebstahl\nSaisonkennzeichen möglich\nAnbieterunabhängiger Vergleich",
database/seeders/ServicePageSeeder.php:367: 'Motorradversicherung: Haftpflicht, Teil- und Vollkasko im anbieterunabhängigen Vergleich – Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:368:�ية وجزئي وشامل بمقارنة مستقلة – استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:369:nzeichen, mit denen Sie nur für die Fahrmonate zahlen. Wir beraten Sie zum passenden Umfang – auf Deutsch und Arabisch.",
database/seeders/ServicePageSeeder.php:386:cherung & Versicherungskennzeichen für Roller und Mofa – anbieterunabhängig, auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:388:fpreis ist eine Teilkasko möglich, etwa bei Diebstahl. Wir beraten Sie, was sich für Ihr Fahrzeug lohnt.",
database/seeders/ServicePageSeeder.php:416:nterwegs – mit Pannenhilfe, Abschleppdienst und mehr. Wir beraten zum passenden Umfang.',
database/seeders/ServicePageSeeder.php:418: "Pannen- und Abschlepphilfe\nWeltweit oder europaweit\nOft günstiger Zusatzbaustein\nAuch unabhängig vom Fahrzeug",
database/seeders/ServicePageSeeder.php:420:rief: Pannenhilfe, Abschleppdienst und Unterwegs-Schutz – anbieterunabhängig beraten, DE & AR.',
database/seeders/ServicePageSeeder.php:421: مساعدة وسحب وحماية على الطريق – استشارة مستقلة، بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:436:ke-Versicherung: Schutz bei Diebstahl und Beschädigung – anbieterunabhängig, DE & AR.',
database/seeders/ServicePageSeeder.php:457:i Feuer, Einbruch, Leitungswasser und Sturm zum Neuwert – anbieterunabhängig, DE & AR.',
database/seeders/ServicePageSeeder.php:476:�mer: Schutz vor Feuer, Leitungswasser, Sturm und Hagel – anbieterunabhängig, DE & AR.',
database/seeders/ServicePageSeeder.php:478:ch sind oft nur mit einem Elementarbaustein versichert. Wir beraten Sie dazu individuell.",
database/seeders/ServicePageSeeder.php:491:chern – gegen Schäden am Bau und Ansprüche Dritter. Wir beraten Sie rund um Ihr Bauprojekt.',
database/seeders/ServicePageSeeder.php:495:aftpflicht und Rohbau-Feuerschutz während der Bauphase – Beratung DE & AR.',
database/seeders/ServicePageSeeder.php:496:�لبناء وحماية الهيكل من الحريق – استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:507:zt Wohngebäude- und Hausratschutz um Naturgefahren – wir beraten Sie dazu.',
database/seeders/ServicePageSeeder.php:511: Überschwemmung, Starkregen und weiteren Naturgefahren – Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:512:� والأمطار الغزيرة ومخاطر أخرى – استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:527:tion ohne Bargeld – Bürgschaft gegenüber dem Vermieter. Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:528:�لة بدون نقد – ضمان تجاه المؤجّر. استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:529:e Bürgschaft nicht akzeptieren; klären Sie das vorab. Wir beraten Sie, ob sich das Modell für Sie lohnt.",
database/seeders/ServicePageSeeder.php:543:en- & Sachschäden\nIn mehreren Bundesländern Pflicht\nOft günstiger Jahresbeitrag\nAuch für mehrere Hunde",
database/seeders/ServicePageSeeder.php:545:�den durch Ihren Hund – in vielen Bundesländern Pflicht. Beratung DE & AR.',
database/seeders/ServicePageSeeder.php:546:ضرار كلبك – إلزامي بكثير ولايات. استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:547:tpflicht vorgeschrieben, teils abhängig von der Rasse. Wir beraten Sie zu den Regeln vor Ort.",
database/seeders/ServicePageSeeder.php:564:Tierarzt- und OP-Kosten absichern – Voll- oder OP-Schutz. Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:565:ب والعمليات – كاملة أو للعمليات. استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:583:zenversicherung: Kranken- und OP-Schutz für Ihre Katze – anbieterunabhängig, auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:601: bei Schäden durch Ihr Pferd, auch mit Reitbeteiligung – Beratung DE & AR.',
database/seeders/ServicePageSeeder.php:602:�ار حصانك، مع المشاركة بالركوب – استشارة بالعربي والألماني.',
database/seeders/ServicePageSeeder.php:603:ligungen, Fremdreiter oder Kutschfahrten einschließen. Wir beraten Sie individuell.",
database/seeders/ServicePageSeeder.php:617:tionskosten inklusive Vor- und Nachbehandlung absichern – Beratung auf Deutsch und Arabisch.',
database/seeders/ServicePageSeeder.php:618:يف العمليات مع العلاج قبل وبعد – استشارة بالعربي والألماني.',
lang/ar.json:83:    "Beratung anfragen": "اطلب استشارة",
lang/ar.json:162:    "Direkter Draht zu Ihrem Berater – Antworten Sie einfach hier im Portal.": "خط مباش�
lang/ar.json:162:� مع مستشارك – يمكنك الرد هنا في البوابة مبا
lang/ar.json:195:    "Empfehlung: Nur Post von Versicherungs- und Energieunternehmen weiterl
lang/ar.json:301:    "Ihr zuverlässiger Partner für professionelle Beratung, erstklassigen Service und persönliche Unterstützung in D
lang/ar.json:301:eutschland.": "شريككم الموثوق للاستشارات المهنية والخدمة الممتازة والد
lang/ar.json:324:    "In der Beraterwelt liegen Kundendaten, Gesundheitsangaben, Bankverbindunge
lang/ar.json:324:iner App auf Ihrem Telefon dazu.": "تحتوي منصة المستشار على بيانات العملاء ومعلومات صحي�
lang/ar.json:417:    "Nachricht an Ihren Berater": "رسالة إلى مستشارك",
lang/ar.json:448:    "Noch keine Nachrichten. Ihr Berater meldet sich hier bei Ihnen – Sie können ihm auch direkt 
lang/ar.json:448:.": "لا توجد رسائل بعد. سيتواصل معك مستشارك هنا – ويمكنك أيضاً مراسلته مبا�
lang/ar.json:561:    "Transparente Information darüber, welche vertragsbezogene Korresponde
lang/ar.json:565:    "Unser KI-Assistent antwortet sofort – Ihr Berater übernimmt jederzeit.": "يجيبك مساعدنا الذك
lang/ar.json:565:ي فوراً – ويمكن لمستشارك تولي المحادثة في أي وقت.",
lang/ar.json:611:    "Weiter zur Beraterwelt": "المتابعة إلى منصة المستشار",
lang/ar.json:758:�كم في أقرب وقت ممكن. ملاحظة: لسنا وسيط تأمين ولا نقدّم توصيات بشأن التع
config/website.php:137:     *                avif+webp+png sobald das Original Transparenz hat)
config/website.php:146:            'hint' => 'PNG mit transparentem Hintergrund, ca. 1024x1024. Fehlt das Bild, zeigt die Se
config/website.php:150:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund. Keine Marken-Logos (z. B. BMW) verwenden.',
config/website.php:154:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
config/website.php:158:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
config/website.php:162:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
config/website.php:166:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
config/website.php:170:            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
config/website.php:184:         * Kundenportal, Beraterwelt und Login. Bewusst nur PNG/WebP:
config/website.php:185:         * Wortmarken brauchen Transparenz und scharfe Kanten, JPG wuerde
config/website.php:190:            'hint' => 'PNG/WebP mit TRANSPARENTEM Hintergrund, mind. 320px hoch. Ersetzt logo-white.png in
config/website.php:197:            'hint' => 'PNG/WebP mit TRANSPARENTEM Hintergrund, mind. 320px hoch. Ersetzt logo-transparent.
config/website.php:197:png in der Beraterwelt-Kopfzeile.',
config/website.php:204:            'hint' => 'Quadratisches PNG/WebP mit transparentem Hintergrund. Ersetzt logo-icon-white.png in den dunklen 
config/website.php:211:           'hint' => 'QUADRATISCHES PNG, mind. 512x512, transparenter Hintergrund. Erzeugt automatisch 32px, 180px (Apple) und
```
