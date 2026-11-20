<?php

namespace App\Support\Tippgeber;

/**
 * Neutrale Texte der Leistungsseiten (Betreiber-Vorgabe 20.11.2026).
 *
 * Dienstly24 haelt KEINE Erlaubnis nach Paragraph 34d GewO. Kein Text
 * darf deshalb den Eindruck erwecken, Dienstly24 berate, vergleiche
 * Versicherungstarife, empfehle oder vermittle - sonst halten Kunden
 * Dienstly24 fuer einen Makler, und genau das ist das rechtliche Risiko.
 *
 * WARUM EINE ERSETZUNGSLISTE und nicht neue Seitentexte: die Inhalte der
 * Leistungsseiten liegen in der DATENBANK und sind im Admin pflegbar. Ein
 * Ueberschreiben der ganzen Felder wuerde jede Pflege des Betreibers
 * still vernichten. Ersetzt werden nur die Saetze, die Wort fuer Wort
 * dem Startinhalt entsprechen; was der Betreiber selbst geschrieben hat,
 * bleibt stehen und wird von `funde()` gemeldet. Dieselbe Liste laeuft
 * im Seeder (neue Installationen) und in der Migration (Bestand) - eine
 * Quelle, zwei Wege.
 *
 * Reihenfolge ist Programmlogik: laengere/spezifischere Saetze zuerst,
 * sonst zerlegt eine kurze Ersetzung den langen Satz, und er passt nicht
 * mehr.
 */
final class ServiceSeitenTexte
{
    /** Woerter, die einen Makler-/Beratungseindruck erzeugen (DE + AR). */
    public const VERBOTEN = '/\bBeratung|\bberaten\b|\bberät\b|\bBerater\b|anbieterunabh|wir vergleichen|vergleichen wir|bestes? Angebot'
        .'|استشارة|مستشار|منستشير|منقارن|مقارنة مستقلة|بشكل مستقل|ننصح|بينصح/iu';

    /** @var array<string, string> alt => neu */
    public const ERSETZUNGEN = [
        // ---- Kfz-Versicherung ----
        "## So beraten wir Sie\nWir vergleichen anbieterunabhängig eine Vielzahl von Kfz-Tarifen und erklären Ihnen die Unterschiede verständlich – auf Deutsch und Arabisch. Sie entscheiden in Ruhe; wir begleiten Sie bis zum Abschluss und darüber hinaus." => "## So helfen wir Ihnen\nDienstly24 ist kein Versicherungsvermittler und gibt keine Tarifempfehlungen. Auf Wunsch stellen wir den Kontakt zu unserem zugelassenen Partner (Versicherungsmakler mit Erlaubnis nach § 34d GewO) her – auf Deutsch und Arabisch. Sie entscheiden in Ruhe.",
        "## كيف منستشيرك\nمنقارن بشكل مستقل عن الشركات عدد كبير من تعرفات السيارة، ومنشرحلك الفروقات بوضوح – بالعربي والألماني. إنت بتقرّر على راحتك، ومنرافقك لحد إتمام العقد وبعده." => "## كيف منساعدك\nDienstly24 مو وسيط تأمين وما بتعطي توصيات بالتعرفات. عند الطلب منوصلك بشريكنا المرخّص (وسيط تأمين حسب المادة 34d من قانون GewO) – بالعربي والألماني. وإنت بتقرّر على راحتك.",
        'Weil sich Tarife und Preise regelmäßig ändern, lohnt sich ein aktueller Vergleich.' => 'Tarife und Preise ändern sich regelmäßig.',
        'وبما إنه التعرفات بتتغيّر باستمرار، المقارنة المحدّثة بتوفّرلك.' => 'والتعرفات بتتغيّر باستمرار.',
        '## Tipps vor dem Vergleich' => '## Gut vorbereitet',
        '## نصائح قبل المقارنة' => '## نصائح مفيدة',
        'Die elektronische Versicherungsbestätigung erhalten Sie vom Versicherer – wir kümmern uns im Rahmen der Vermittlung darum.' => 'Die elektronische Versicherungsbestätigung erhalten Sie vom Versicherer.',
        'تأكيد التأمين الإلكتروني (eVB) بيجي من شركة التأمين، ومننتكفّل فيه ضمن الوساطة.' => 'تأكيد التأمين الإلكتروني (eVB) بيجي من شركة التأمين.',
        'Wir zeigen Wege, den Beitrag zu senken – etwa als Zweitwagen oder über Telematik-Tarife.' => 'Möglichkeiten wie eine Zweitwagenregelung oder Telematik-Tarife erklärt Ihnen unser zugelassener Partner.',
        'منوريك طرق لتخفيف القسط، متل تسجيلها كسيارة ثانية أو عبر تعرفات telematik.' => 'وخيارات متل تسجيلها كسيارة ثانية أو تعرفات telematik بيشرحها شريكنا المرخّص.',
        'Kfz-Versicherung verständlich erklärt: Haftpflicht, Teil- und Vollkasko im Vergleich. Anbieterunabhängige Beratung auf Deutsch und Arabisch – kostenlos anfragen.' => 'Kfz-Versicherung verständlich erklärt: Haftpflicht, Teil- und Vollkasko im Überblick. Service auf Deutsch und Arabisch – kostenlos anfragen.',
        'استشارة مستقلة عن الشركات بالعربي والألماني – اطلب مجاناً.' => 'خدمة بالعربي والألماني – اطلب مجاناً.',
        'Wir vergleichen die Tarife und erklären die Unterschiede' => 'Kontakt zu unserem zugelassenen Partner',
        'منقارنلك التعرفات ومنشرحلك الفروقات' => 'منوصلك بشريكنا المرخّص',
        'Was kostet mich die Beratung?' => 'Was kostet mich der Service?',
        'قديش بتكلّفني الاستشارة؟' => 'قديش بتكلّفني الخدمة؟',
        'Die Beratung ist für Sie kostenlos und unverbindlich.' => 'Die Kontaktaufnahme ist für Sie kostenlos und unverbindlich.',
        'الاستشارة مجانية وبدون أي التزام.' => 'التواصل معنا مجاني وبدون أي التزام.',

        // ---- Krankenversicherung ----
        "## So beraten wir Sie\nWir erklären Ihnen die Unterschiede – gesetzlich oder privat, Beitrag und Zusatzleistungen – und unterstützen bei Wechsel und Anträgen, verständlich auf Deutsch und Arabisch. Die aktuell gültigen Beitragssätze nennen wir Ihnen im persönlichen Gespräch." => "## So helfen wir Ihnen\nDienstly24 ist kein Versicherungsvermittler und gibt keine Empfehlungen. Für Fragen zu Ihrer Krankenversicherung stellen wir auf Wunsch den Kontakt zu unserem zugelassenen Partner her – auf Deutsch und Arabisch.",
        "## كيف منستشيرك\nمنشرحلك الفروقات – حكومي أو خاص، القسط والتغطيات – ومنساعدك بالتبديل والطلبات، بوضوح بالعربي والألماني. الأقساط الحالية منخبّرك ياها بالجلسة الشخصية." => "## كيف منساعدك\nDienstly24 مو وسيط تأمين وما بتعطي توصيات. لأسئلتك عن التأمين الصحي منوصلك عند الطلب بشريكنا المرخّص – بالعربي والألماني.",
        'Gesetzlich oder privat - wir beraten zur passenden Lösung' => 'Gesetzlich oder privat – die Grundlagen verständlich erklärt',
        'Beratung passend zu Beruf und Einkommen' => 'Kontakt zu unserem zugelassenen Partner',
        'استشارة حسب المهنة والدخل' => 'تواصل مع شريكنا المرخّص',
        'Anbieterunabhängige Beratung zu Optionen, Wechsel und Anträgen – auf Deutsch und Arabisch, kostenlos.' => 'Die Grundlagen verständlich erklärt – Service auf Deutsch und Arabisch, kostenlos.',
        'استشارة مستقلة حول الخيارات والتبديل والطلبات – بالعربي والألماني، مجاناً.' => 'الأساسيات بشرح واضح – خدمة بالعربي والألماني، مجاناً.',
        'Weil sich die Zusatzbeiträge unterscheiden, kann ein Wechsel bares Geld sparen.' => 'Die Zusatzbeiträge unterscheiden sich von Kasse zu Kasse.',
        'Die PKV richtet sich nach Tarif und Gesundheitszustand statt nach dem Einkommen – wir beraten Sie, was zu Ihrer Situation passt.' => 'Die PKV richtet sich nach Tarif und Gesundheitszustand statt nach dem Einkommen. Was zu Ihrer Situation passt, klärt unser zugelassener Partner mit Ihnen.',
        'الخاص بيعتمد على التعرفة والحالة الصحية بدل الدخل – ومنستشيرك شو بيناسب وضعك.' => 'الخاص بيعتمد على التعرفة والحالة الصحية بدل الدخل. وشو بيناسب وضعك بيوضّحه معك شريكنا المرخّص.',

        'وبما إنه الأقساط الإضافية بتختلف، التبديل ممكن يوفّرلك.' => 'والأقساط الإضافية بتختلف من صندوق لصندوق.',
        'E-Scooter-Versicherung mit Versicherungsplakette – Haftpflicht schnell und günstig, auf Deutsch und Arabisch.' => 'E-Scooter-Versicherung mit Versicherungsplakette – Service auf Deutsch und Arabisch.',

        // ---- Zahnzusatzversicherung ----
        "## So beraten wir Sie\nWir zeigen Ihnen, welcher Tarif sich für Ihre Situation lohnt, und achten auf Wartezeiten und Leistungsbegrenzungen – verständlich, auf Deutsch und Arabisch." => "## So helfen wir Ihnen\nAchten Sie bei jedem Tarif auf Wartezeiten und Leistungsbegrenzungen. Dienstly24 gibt keine Tarifempfehlung; auf Wunsch stellen wir den Kontakt zu unserem zugelassenen Partner her – auf Deutsch und Arabisch.",
        "## كيف منستشيرك\nمنوريك أي تعرفة بتستاهل لوضعك، ومننتبه لفترات الانتظار وحدود التغطية – بشرح واضح بالعربي والألماني." => "## كيف منساعدك\nانتبه بأي تعرفة لفترات الانتظار وحدود التغطية. Dienstly24 ما بتعطي توصيات بالتعرفات؛ وعند الطلب منوصلك بشريكنا المرخّص – بالعربي والألماني.",
        'Wir zeigen, welcher Tarif sich lohnt' => 'Kontakt zu unserem zugelassenen Partner',
        'منوريك أي تعرفة بتستاهل' => 'منوصلك بشريكنا المرخّص',
        'Höhere Erstattung bei Zahnersatz' => 'Erstattung bei Zahnersatz',

        // ---- Kfz-Zulassung / Kennzeichen (kein Versicherungsthema, nur "sparen" entschaerft) ----
        'Sie sparen sich den Behördengang und die Wartezeit.' => 'Ohne Behördengang und ohne Wartezeit.',
        'Sie sparen sich Behördengang und Wartezeit.' => 'ohne Behördengang und ohne Wartezeit.',

        // ---- Strom & Gas (Energie, keine Versicherung - Ersparnis wird nicht versprochen) ----
        '## Sparen beim Anbieterwechsel' => '## Anbieterwechsel',
        '## وفّر عند تبديل المزوّد' => '## تبديل المزوّد',
        'Wir prüfen Ihren aktuellen Tarif und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen können – für Strom, Gas oder beides.' => 'Wir sehen uns Ihren aktuellen Tarif an und kümmern uns auf Wunsch um den Anbieterwechsel – für Strom, Gas oder beides.',
        'Wir prüfen Ihren aktuellen Tarif und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen können.' => 'Wir sehen uns Ihren aktuellen Tarif an und kümmern uns auf Wunsch um den Anbieterwechsel.',
        'منراجع تعرفتك الحالية ومنوريك قديش فيك توفّر إذا بدّلت المزوّد – كهرباء أو غاز أو الاثنين.' => 'منطّلع على تعرفتك الحالية ومنتكفّل عند الطلب بتبديل المزوّد – كهرباء أو غاز أو الاثنين.',
        'منراجع تعرفتك الحالية ومنوريك قديش فيك توفّر إذا بدّلت المزوّد.' => 'منطّلع على تعرفتك الحالية ومنتكفّل عند الطلب بتبديل المزوّد.',
        'Tarif prüfen und beim Anbieterwechsel sparen' => 'Anbieterwechsel bei Strom und Gas',
        'فحص التعرفة وتوفير عند تبديل المزوّد' => 'تبديل مزوّد الكهرباء والغاز',
        'الكهرباء والغاز: افحص تعرفتك ووفّر عند تبديل المزوّد.' => 'الكهرباء والغاز: تبديل المزوّد بسهولة.',

        // ---- Motorrad / Moped / E-Scooter / Schutzbrief / Fahrrad ----
        'Teil- und Vollkasko schützen zusätzlich Ihr eigenes Fahrzeug – wir vergleichen anbieterunabhängig für Sie.' => 'Teil- und Vollkasko schützen zusätzlich Ihr eigenes Fahrzeug.',
        'والجزئي والشامل بيحميان دراجتك – ومنقارنلك بشكل مستقل.' => 'والجزئي والشامل بيحميان دراجتك.',
        'Anbieterunabhängiger Vergleich' => 'Kontakt zu unserem zugelassenen Partner',
        'مقارنة مستقلة عن الشركات' => 'تواصل مع شريكنا المرخّص',
        'Motorradversicherung: Haftpflicht, Teil- und Vollkasko im anbieterunabhängigen Vergleich – Beratung auf Deutsch und Arabisch.' => 'Motorradversicherung: Haftpflicht, Teil- und Vollkasko im Überblick – Service auf Deutsch und Arabisch.',
        'تأمين الدراجة النارية: مسؤولية وجزئي وشامل بمقارنة مستقلة – استشارة بالعربي والألماني.' => 'تأمين الدراجة النارية: مسؤولية وجزئي وشامل بلمحة – خدمة بالعربي والألماني.',
        'Wir beraten Sie zum passenden Umfang – auf Deutsch und Arabisch.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her – auf Deutsch und Arabisch.',
        'ومنستشيرك بالتغطية المناسبة.' => 'ومنوصلك بشريكنا المرخّص.',
        'Wir beraten Sie, was sich für Ihr Fahrzeug lohnt.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'ومنستشيرك شو بيستاهل لمركبتك.' => 'ومنوصلك بشريكنا المرخّص.',
        '– anbieterunabhängig, auf Deutsch und Arabisch.' => '– Service auf Deutsch und Arabisch.',
        '– مستقل، بالعربي والألماني.' => '– خدمة بالعربي والألماني.',
        'Wir vergleichen die Angebote und erklären Ihnen die Bedingungen – verständlich, auf Deutsch und Arabisch.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her – auf Deutsch und Arabisch.',
        'منقارن العروض ومنشرحلك الشروط بوضوح – بالعربي والألماني.' => 'منوصلك بشريكنا المرخّص – بالعربي والألماني.',
        'ومنلاقيلك التعرفة المناسبة بسرعة.' => 'ومنوصلك بشريكنا المرخّص.',
        'ومنلاقيلك الحماية المناسبة.' => 'ومنوصلك بشريكنا المرخّص.',
        'Wir beraten zum passenden Umfang.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        '– anbieterunabhängig beraten, DE & AR.' => '– Service DE & AR.',
        '– استشارة مستقلة، بالعربي والألماني.' => '– خدمة بالعربي والألماني.',
        'Eine Fahrradversicherung schützt bei Diebstahl und häufig auch bei Beschädigung oder Reparatur – wir vergleichen für Sie.' => 'Eine Fahrradversicherung schützt bei Diebstahl und häufig auch bei Beschädigung oder Reparatur.',
        'تأمين الدراجة بيحميك من السرقة وغالباً من الأضرار والتصليح – ومنقارنلك.' => 'تأمين الدراجة بيحميك من السرقة وغالباً من الأضرار والتصليح.',
        'Wir vergleichen die Bedingungen – etwa Nachtzeitklausel oder Selbstbeteiligung – und finden den passenden Tarif.' => 'Achten Sie auf die Bedingungen – etwa Nachtzeitklausel oder Selbstbeteiligung. Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'منقارن الشروط – متل شرط الليل أو التحمّل الذاتي – ومنلاقي التعرفة المناسبة.' => 'انتبه للشروط – متل شرط الليل أو التحمّل الذاتي. ومنوصلك بشريكنا المرخّص.',
        '– anbieterunabhängig, DE & AR.' => '– Service DE & AR.',

        // ---- Wohnen / Bauen ----
        'Wir beraten Sie dazu individuell.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'ومنستشيرك فيها بشكل فردي.' => 'ومنوصلك بشريكنا المرخّص.',
        'Wir beraten Sie rund um Ihr Bauprojekt.' => 'Für Ihr Bauprojekt stellen wir den Kontakt zu unserem zugelassenen Partner her.',
        'ومنستشيرك حول مشروعك.' => 'ومنوصلك بشريكنا المرخّص بخصوص مشروعك.',
        '– wir beraten Sie dazu.' => '.',
        '– ومنستشيرك فيه.' => '.',
        'منقيّم مستوى الخطر بمنطقتك ومنلاقيلك حماية مناسبة.' => 'لأسئلتك منوصلك بشريكنا المرخّص.',
        'Wir beraten Sie, ob sich das Modell für Sie lohnt.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'ومنستشيرك إذا النموذج بيستاهل إلك.' => 'ومنوصلك بشريكنا المرخّص.',

        // ---- Tiere ----
        'Wir beraten Sie zu den Regeln vor Ort.' => 'Die Regeln Ihres Bundeslandes gelten.',
        'ومنستشيرك بالقوانين المحلية.' => 'والقوانين المحلية هي المرجع.',
        'Wir vergleichen Leistungen und Wartezeiten und finden den passenden Schutz für Ihren Hund.' => 'Achten Sie auf Leistungen und Wartezeiten. Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'منقارن التغطيات وفترات الانتظار ومنلاقيلك الأنسب لكلبك.' => 'انتبه للتغطيات وفترات الانتظار. ومنوصلك بشريكنا المرخّص.',
        'Wir vergleichen die Tarife für Sie.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'ومنقارنلك التعرفات.' => 'ومنوصلك بشريكنا المرخّص.',
        'Wir beraten Sie individuell.' => 'Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'ومنستشيرك بشكل فردي.' => 'ومنوصلك بشريكنا المرخّص.',
        'Wir vergleichen Erstattungshöhen, Wartezeiten und Altersgrenzen und finden den passenden Tarif.' => 'Achten Sie auf Erstattungshöhen, Wartezeiten und Altersgrenzen. Den Kontakt zu unserem zugelassenen Partner stellen wir her.',
        'منقارن قيم التعويض وفترات الانتظار وحدود العمر ومنلاقي التعرفة المناسبة.' => 'انتبه لقيم التعويض وفترات الانتظار وحدود العمر. ومنوصلك بشريكنا المرخّص.',

        // ---- allgemeine Endungen der Meta-Beschreibungen (zuletzt: kurz) ----
        'Anbieterunabhängige Beratung auf Deutsch und Arabisch.' => 'Service auf Deutsch und Arabisch.',
        'استشارة مستقلة بالعربي والألماني.' => 'خدمة بالعربي والألماني.',
        'Beratung auf Deutsch und Arabisch.' => 'Service auf Deutsch und Arabisch.',
        'Beratung DE & AR.' => 'Service DE & AR.',
        'استشارة بالعربي والألماني.' => 'خدمة بالعربي والألماني.',
    ];

    /** Ersetzt alle bekannten Saetze im Text. */
    public static function anwenden(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return strtr($text, self::ERSETZUNGEN);
    }

    /**
     * Rest-Treffer, die keine Ersetzung abgedeckt hat (z.B. vom Betreiber
     * selbst geschriebener Text). Liefert die gefundenen Woerter.
     *
     * @return list<string>
     */
    public static function funde(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        preg_match_all(self::VERBOTEN, $text, $m);

        return array_values(array_unique($m[0]));
    }
}
