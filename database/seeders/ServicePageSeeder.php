<?php

namespace Database\Seeders;

use App\Models\ServicePage;
use Illuminate\Database\Seeder;

/**
 * Legt die sechs Hauptleistungen als Startinhalt an. Idempotent und
 * NICHT-destruktiv: vorhandene Seiten werden nicht ueberschrieben (nur
 * fehlende Beispiel-Formularfelder werden einmalig nachgetragen), damit im
 * Admin gepflegte Inhalte bei jedem Deploy erhalten bleiben.
 */
class ServicePageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $i => $page) {
            $page['sort_order'] = $i * 10;
            $existing = ServicePage::where('slug', $page['slug'])->first();

            if (!$existing) {
                // Neue Standardseite anlegen.
                ServicePage::create($page);
                continue;
            }

            // Bestehende Seite: nur LEERE Spalten einmalig nachtragen, damit im
            // Admin gepflegte Inhalte bei jedem Deploy erhalten bleiben.
            $updates = [];
            foreach (['body_de', 'body_ar', 'meta_description_de', 'meta_description_ar', 'providers'] as $col) {
                if (blank($existing->{$col}) && !empty($page[$col] ?? null)) {
                    $updates[$col] = $page[$col];
                }
            }
            if (empty($existing->fields) && !empty($page['fields'])) {
                $updates['fields'] = $page['fields'];
            }
            if ($updates) {
                $existing->update($updates);
            }
        }
    }

    private function pages(): array
    {
        return [
            [
                'slug' => 'kfz-versicherung',
                'category' => 'versicherung',
                'icon' => '🚗',
                'title_de' => 'Kfz-Versicherung',
                'title_ar' => 'تأمين السيارة',
                'subtitle_de' => 'Haftpflicht, Teilkasko und Vollkasko verstaendlich erklaert',
                'subtitle_ar' => 'شرح واضح للمسؤولية والتأمين الجزئي والشامل',
                'intro_de' => 'Die Kfz-Versicherung schuetzt Sie vor den finanziellen Folgen eines '
                    . 'Verkehrsunfalls. Die Kfz-Haftpflicht ist in Deutschland gesetzlich '
                    . 'vorgeschrieben und deckt Schaeden, die Sie anderen zufuegen. Teil- und '
                    . 'Vollkasko sind freiwillig und schuetzen zusaetzlich Ihr eigenes Fahrzeug.',
                'intro_ar' => 'تأمين السيارة بيحميك من التبعات المالية لحادث سير. تأمين المسؤولية '
                    . '(Haftpflicht) إلزامي قانونياً بألمانيا وبيغطي الأضرار يلي بتسببها للغير. '
                    . 'أما التأمين الجزئي (Teilkasko) والشامل (Vollkasko) فاختياريان وبيحميان سيارتك.',
                'highlights_de' => "Gesetzlich vorgeschriebene Haftpflicht\nTeilkasko z. B. bei Diebstahl, Glasbruch, Wildunfall\nVollkasko auch bei selbst verschuldeten Schaeden\nWir vergleichen die Tarife und erklaeren die Unterschiede",
                'highlights_ar' => "تأمين المسؤولية الإلزامي\nالتأمين الجزئي مثلاً للسرقة وكسر الزجاج وحوادث الحيوانات\nالتأمين الشامل حتى للأضرار بخطئك\nمنقارنلك التعرفات ومنشرحلك الفروقات",
                'meta_description_de' => 'Kfz-Versicherung verständlich erklärt: Haftpflicht, Teil- und Vollkasko im Vergleich. Anbieterunabhängige Beratung auf Deutsch und Arabisch – kostenlos anfragen.',
                'meta_description_ar' => 'تأمين السيارة بوضوح: المسؤولية والتأمين الجزئي والشامل. استشارة مستقلة عن الشركات بالعربي والألماني – اطلب مجاناً.',
                'body_de' => "## Darauf kommt es an\nDie Kfz-Haftpflicht ist für jedes in Deutschland zugelassene Fahrzeug gesetzlich vorgeschrieben. Sie übernimmt Schäden, die Sie mit Ihrem Auto anderen zufügen. Ohne gültige Haftpflicht ist keine Zulassung möglich.\n\nIhren Schutz können Sie freiwillig erweitern:\n- Teilkasko: schützt bei äußeren Einflüssen wie Diebstahl, Glasbruch, Wildunfall, Sturm oder Hagel.\n- Vollkasko: umfasst die Teilkasko-Leistungen und zahlt zusätzlich bei selbst verursachten Schäden und bei Vandalismus. Besonders sinnvoll für Neuwagen, hochwertige Fahrzeuge und Leasingfahrzeuge.\n\n## Was den Beitrag beeinflusst\nDie Höhe des Beitrags hängt von mehreren Faktoren ab – unter anderem Fahrzeugtyp, jährliche Fahrleistung, Wohnort, Schadenfreiheitsklasse und gewünschtem Leistungsumfang. Weil sich Tarife und Preise regelmäßig ändern, lohnt sich ein aktueller Vergleich.\n\n## So beraten wir Sie\nWir vergleichen anbieterunabhängig eine Vielzahl von Kfz-Tarifen und erklären Ihnen die Unterschiede verständlich – auf Deutsch und Arabisch. Sie entscheiden in Ruhe; wir begleiten Sie bis zum Abschluss und darüber hinaus.\n\n## Tipps vor dem Vergleich\n- Halten Sie die Fahrzeugdaten und Angaben zu Ihrer bisherigen Versicherung bereit.\n- Überlegen Sie, welcher Umfang zu Ihnen passt: reicht Teilkasko oder ist Vollkasko sinnvoll?\n- Achten Sie auf Zusatzleistungen wie Werkstattbindung oder Rabattschutz.\n- Beachten Sie die Kündigungsfrist: in der Regel ein Monat zum Ablauf des Vertrags.\n\n## Gut zu wissen\nWoher bekomme ich die eVB-Nummer? Die elektronische Versicherungsbestätigung erhalten Sie vom Versicherer – wir kümmern uns im Rahmen der Vermittlung darum.\nWas macht eine gute Kfz-Versicherung aus? Ein passender Leistungsumfang zu einem fairen Preis, sinnvolle Zusatzbausteine und ein verlässlicher Service im Schadenfall.\nWas ist für Fahranfänger wichtig? Fahranfänger starten meist in einer ungünstigen Schadenfreiheitsklasse. Wir zeigen Wege, den Beitrag zu senken – etwa als Zweitwagen oder über Telematik-Tarife.",
                'body_ar' => "## أهم النقاط\nتأمين المسؤولية (Haftpflicht) إلزامي قانونياً لأي سيارة مسجّلة بألمانيا، وبيغطي الأضرار يلي بتسببها لغيرك بسيارتك. بدون تأمين مسؤولية ساري ما بينفع تسجّل السيارة.\n\nوفيك توسّع الحماية اختيارياً:\n- التأمين الجزئي (Teilkasko): بيحميك من مؤثرات خارجية متل السرقة وكسر الزجاج وحوادث الحيوانات والعواصف والبَرَد.\n- التأمين الشامل (Vollkasko): بيشمل تغطية الجزئي وبيدفع كمان للأضرار يلي بتصير بخطئك وللتخريب. مناسب خصوصاً للسيارات الجديدة والغالية وسيارات الليزينغ.\n\n## شو بيأثّر على القسط\nقيمة القسط بتعتمد على عوامل كتير، منها نوع السيارة، والمسافة السنوية، ومكان سكنك، ودرجة الخلو من الحوادث (SF-Klasse)، ومستوى التغطية المطلوب. وبما إنه التعرفات بتتغيّر باستمرار، المقارنة المحدّثة بتوفّرلك.\n\n## كيف منستشيرك\nمنقارن بشكل مستقل عن الشركات عدد كبير من تعرفات السيارة، ومنشرحلك الفروقات بوضوح – بالعربي والألماني. إنت بتقرّر على راحتك، ومنرافقك لحد إتمام العقد وبعده.\n\n## نصائح قبل المقارنة\n- جهّز بيانات السيارة ومعلومات تأمينك الحالي.\n- فكّر أي تغطية بتناسبك: بيكفي الجزئي ولا الشامل أفضل؟\n- انتبه للخدمات الإضافية متل ربط الورشة أو حماية درجة الخصم.\n- انتبه لمهلة الإلغاء: عادةً شهر واحد قبل انتهاء العقد.\n\n## معلومات مفيدة\nمن وين بجيب رقم eVB؟ تأكيد التأمين الإلكتروني (eVB) بيجي من شركة التأمين، ومننتكفّل فيه ضمن الوساطة.\nشو بيميّز تأمين سيارة منيح؟ تغطية مناسبة بسعر عادل، وإضافات مفيدة، وخدمة موثوقة وقت الحادث.\nشو المهم للسائق المبتدئ؟ عادةً بيبدأ بدرجة خلو غير مناسبة. منوريك طرق لتخفيف القسط، متل تسجيلها كسيارة ثانية أو عبر تعرفات telematik.",
                'faq' => [
                    [
                        'q_de' => 'Welche Kfz-Versicherung ist Pflicht?',
                        'q_ar' => 'أي تأمين سيارة إلزامي؟',
                        'a_de' => 'Die Kfz-Haftpflichtversicherung ist gesetzlich vorgeschrieben. Ohne sie darf ein Fahrzeug nicht zugelassen werden.',
                        'a_ar' => 'تأمين المسؤولية (Haftpflicht) إلزامي قانونياً؛ بدونه ما بينفع تسجّل السيارة.',
                    ],
                    [
                        'q_de' => 'Was kostet mich die Beratung?',
                        'q_ar' => 'قديش بتكلّفني الاستشارة؟',
                        'a_de' => 'Die Beratung ist fuer Sie kostenlos und unverbindlich.',
                        'a_ar' => 'الاستشارة مجانية وبدون أي التزام.',
                    ],
                ],
                'fields' => [
                    ['label_de' => 'Fahrzeug (Marke / Modell)', 'label_ar' => 'السيارة (الماركة / الموديل)', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                    ['label_de' => 'Gewuenschte Deckung', 'label_ar' => 'التغطية المطلوبة', 'type' => 'select', 'options_de' => 'Haftpflicht, Teilkasko, Vollkasko', 'options_ar' => 'مسؤولية, تأمين جزئي, تأمين شامل', 'required' => true],
                    ['label_de' => 'Erstzulassung (Jahr)', 'label_ar' => 'سنة أول ترخيص', 'type' => 'number', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'krankenversicherung',
                'category' => 'versicherung',
                'icon' => '🩺',
                'title_de' => 'Krankenversicherung',
                'title_ar' => 'التأمين الصحي',
                'subtitle_de' => 'Gesetzlich oder privat - wir beraten zur passenden Loesung',
                'subtitle_ar' => 'حكومي أو خاص - منساعدك تختار الأنسب',
                'intro_de' => 'In Deutschland besteht eine Krankenversicherungspflicht. Sie koennen '
                    . 'sich gesetzlich (GKV) oder unter bestimmten Voraussetzungen privat (PKV) '
                    . 'versichern. Welche Variante zu Ihnen passt, haengt von Beruf, Einkommen und '
                    . 'persoenlicher Situation ab - wir erklaeren Ihnen die Optionen.',
                'intro_ar' => 'بألمانيا التأمين الصحي إلزامي. فيك تتأمّن حكومي (GKV) أو - ضمن شروط - '
                    . 'خاص (PKV). أي خيار بيناسبك بيعتمد على المهنة والدخل ووضعك الشخصي - ومنشرحلك '
                    . 'الخيارات بوضوح.',
                'highlights_de' => "Gesetzliche und private Krankenversicherung\nBeratung passend zu Beruf und Einkommen\nUnterstuetzung beim Wechsel und bei Antraegen\nVerstaendlich auf Deutsch und Arabisch",
                'highlights_ar' => "التأمين الحكومي والخاص\nاستشارة حسب المهنة والدخل\nمساعدة بالتبديل وتقديم الطلبات\nشرح واضح بالعربي والألماني",
                'providers' => "Techniker Krankenkasse\nBARMER\nDAK-Gesundheit\nAOK\nKKH\nhkk\nHEK\nIKK classic\nKNAPPSCHAFT\nBIG direkt gesund\nSBK\npronova BKK",
                'meta_description_de' => 'Krankenversicherung: gesetzlich oder privat? Anbieterunabhängige Beratung zu Optionen, Wechsel und Anträgen – auf Deutsch und Arabisch, kostenlos.',
                'meta_description_ar' => 'التأمين الصحي: حكومي أو خاص؟ استشارة مستقلة حول الخيارات والتبديل والطلبات – بالعربي والألماني، مجاناً.',
                'body_de' => "## Gesetzlich oder privat?\nIn Deutschland besteht Krankenversicherungspflicht. Rund 90 Prozent der Menschen sind gesetzlich versichert (GKV); unter bestimmten Voraussetzungen – etwa als Angestellter über der Versicherungspflichtgrenze, als Selbstständiger oder Beamter – ist die private Krankenversicherung (PKV) möglich.\n\n## So funktioniert die GKV\nDie gesetzliche Krankenversicherung beruht auf dem Solidaritätsprinzip: Die Beiträge richten sich nach dem Einkommen, der Anspruch auf medizinische Versorgung ist für alle gleich. Verwaltet wird sie von den Krankenkassen – staatlich beaufsichtigt, aber eigenständig organisiert.\n\n## Die Kassenarten\n- Allgemeine Ortskrankenkassen (AOK): regional organisiert.\n- Ersatzkassen: ursprünglich für Angestellte, heute für alle offen.\n- Betriebskrankenkassen (BKK) und Innungskrankenkassen (IKK): meist für alle geöffnet.\n- Landwirtschaftliche Krankenversicherung: für den landwirtschaftlichen Bereich.\n\n## Beitrag und Zusatzbeitrag\nDer allgemeine Beitragssatz ist gesetzlich einheitlich. Hinzu kommt ein kassenindividueller Zusatzbeitrag, dessen Höhe von der wirtschaftlichen Lage der Kasse abhängt und regelmäßig angepasst wird. Bei Angestellten trägt der Arbeitgeber die Hälfte. Weil sich die Zusatzbeiträge unterscheiden, kann ein Wechsel bares Geld sparen.\n\n## Grund- und Zusatzleistungen\nDie Grundleistungen sind weitgehend gesetzlich festgelegt und bei allen Kassen gleich – von der ärztlichen und zahnärztlichen Versorgung über Medikamente bis zu Krankenhaus und Reha. Bei einem Wechsel verzichten Sie auf keine gesetzliche Grundleistung. Unterschiede gibt es bei den freiwilligen Zusatzleistungen, zum Beispiel:\n- Zuschuss zur professionellen Zahnreinigung\n- Leistungen der Alternativmedizin (z. B. Homöopathie, Osteopathie)\n- zusätzliche Vorsorgeuntersuchungen\n- Bonusprogramme\n\n## Kasse wechseln\nEin Wechsel ist in der Regel nach zwölf Monaten Mitgliedschaft möglich; bei einer Erhöhung des Zusatzbeitrags besteht ein Sonderkündigungsrecht. Vorerkrankungen sind kein Hindernis – in der GKV gilt Aufnahmepflicht.\n\n## So beraten wir Sie\nWir erklären Ihnen die Unterschiede – gesetzlich oder privat, Beitrag und Zusatzleistungen – und unterstützen bei Wechsel und Anträgen, verständlich auf Deutsch und Arabisch. Die aktuell gültigen Beitragssätze nennen wir Ihnen im persönlichen Gespräch.\n\n## Gut zu wissen\nWie funktioniert die Familienversicherung? Ehe- oder eingetragene Partner sowie Kinder ohne eigenes bzw. mit geringem Einkommen können beitragsfrei mitversichert sein.\nWorin unterscheidet sich die PKV von der GKV? Die PKV richtet sich nach Tarif und Gesundheitszustand statt nach dem Einkommen – wir beraten Sie, was zu Ihrer Situation passt.",
                'body_ar' => "## حكومي أو خاص؟\nبألمانيا التأمين الصحي إلزامي. حوالي 90% من الناس بالتأمين الحكومي (GKV)، وضمن شروط معيّنة – متل الموظف يلي دخله فوق حدّ الإلزام، أو صاحب العمل الحر، أو الموظف الرسمي – بيصير ممكن التأمين الخاص (PKV).\n\n## كيف بيشتغل الحكومي (GKV)\nالتأمين الحكومي قائم على مبدأ التضامن: القسط حسب الدخل، وحق العلاج واحد للجميع. بتديره صناديق التأمين (Krankenkassen) – تحت إشراف الدولة بس بإدارة مستقلة.\n\n## أنواع الصناديق\n- AOK: منظّمة إقليمياً.\n- Ersatzkassen: كانت للموظفين، هلّق مفتوحة للجميع.\n- BKK و IKK: غالباً مفتوحة للجميع.\n- صندوق القطاع الزراعي.\n\n## القسط والقسط الإضافي\nالقسط العام موحّد قانونياً، وبينضاف عليه قسط إضافي خاص بكل صندوق، بيختلف حسب وضع الصندوق وبيتعدّل دورياً. للموظفين، صاحب العمل بيتحمّل نصّه. وبما إنه الأقساط الإضافية بتختلف، التبديل ممكن يوفّرلك.\n\n## التغطيات الأساسية والإضافية\nالتغطيات الأساسية محدّدة قانونياً وواحدة عند كل الصناديق – من العلاج الطبي والأسنان للأدوية والمشفى والتأهيل. عند التبديل ما بتخسر أي تغطية أساسية. الفرق بالتغطيات الاختيارية، متل:\n- دعم تنظيف الأسنان الاحترافي\n- علاجات الطب البديل (متل الهوميوباتي والأوستيوباتي)\n- فحوصات وقائية إضافية\n- برامج مكافآت\n\n## تبديل الصندوق\nالتبديل عادةً ممكن بعد 12 شهر عضوية؛ وعند رفع القسط الإضافي في حق إلغاء استثنائي. الأمراض السابقة مو عائق – بالحكومي في إلزام قبول.\n\n## كيف منستشيرك\nمنشرحلك الفروقات – حكومي أو خاص، القسط والتغطيات – ومنساعدك بالتبديل والطلبات، بوضوح بالعربي والألماني. الأقساط الحالية منخبّرك ياها بالجلسة الشخصية.\n\n## معلومات مفيدة\nكيف بيشتغل التأمين العائلي؟ الزوج/الشريك المسجّل والأولاد بدون دخل أو بدخل قليل ممكن يتأمّنوا مجاناً ضمن العائلة.\nشو الفرق بين الخاص والحكومي؟ الخاص بيعتمد على التعرفة والحالة الصحية بدل الدخل – ومنستشيرك شو بيناسب وضعك.",
                'faq' => [
                    [
                        'q_de' => 'Kann ich von gesetzlich zu privat wechseln?',
                        'q_ar' => 'فيني بدّل من الحكومي للخاص؟',
                        'a_de' => 'Ein Wechsel ist unter bestimmten Voraussetzungen moeglich (z. B. Einkommen, Beruf). Wir pruefen Ihre Situation individuell.',
                        'a_ar' => 'التبديل ممكن ضمن شروط (الدخل، المهنة). منراجع وضعك بشكل فردي.',
                    ],
                ],
            ],
            [
                'slug' => 'zahnzusatzversicherung',
                'category' => 'versicherung',
                'icon' => '🦷',
                'title_de' => 'Zahnzusatzversicherung',
                'title_ar' => 'تأمين الأسنان الإضافي',
                'subtitle_de' => 'Hoehere Erstattung bei Zahnersatz und Behandlungen',
                'subtitle_ar' => 'تغطية أعلى لتركيبات وعلاجات الأسنان',
                'intro_de' => 'Die gesetzliche Krankenversicherung uebernimmt bei Zahnersatz oft nur '
                    . 'einen Teil der Kosten. Eine Zahnzusatzversicherung erhoeht die Erstattung '
                    . 'z. B. bei Kronen, Implantaten oder professioneller Zahnreinigung.',
                'intro_ar' => 'التأمين الحكومي غالباً بيغطي جزء بس من تكاليف تركيبات الأسنان. تأمين '
                    . 'الأسنان الإضافي بيرفع نسبة التغطية مثلاً للتيجان والزرعات وتنظيف الأسنان.',
                'highlights_de' => "Hoehere Erstattung bei Zahnersatz\nLeistungen fuer Kronen, Implantate, Inlays\nOft auch professionelle Zahnreinigung\nWir zeigen, welcher Tarif sich lohnt",
                'highlights_ar' => "تغطية أعلى للتركيبات\nتغطية للتيجان والزرعات والحشوات\nغالباً تنظيف احترافي للأسنان\nمنوريك أي تعرفة بتستاهل",
                'meta_description_de' => 'Zahnzusatzversicherung: höhere Erstattung bei Zahnersatz, Implantaten und Zahnreinigung. Anbieterunabhängige Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين الأسنان الإضافي: تغطية أعلى للتركيبات والزرعات وتنظيف الأسنان. استشارة مستقلة بالعربي والألماني.',
                'body_de' => "## Warum eine Zahnzusatzversicherung?\nDie gesetzliche Krankenversicherung übernimmt bei Zahnersatz meist nur einen Festzuschuss. Den Rest zahlen Sie selbst – bei Kronen, Brücken oder Implantaten kann das schnell teuer werden. Eine Zahnzusatzversicherung erhöht die Erstattung deutlich.\n\n## Typische Leistungen\n- Zahnersatz: Kronen, Brücken, Implantate.\n- Zahnbehandlung: z. B. hochwertige Füllungen oder Wurzelbehandlung.\n- Prophylaxe: professionelle Zahnreinigung (je nach Tarif).\n\n## So beraten wir Sie\nWir zeigen Ihnen, welcher Tarif sich für Ihre Situation lohnt, und achten auf Wartezeiten und Leistungsbegrenzungen – verständlich, auf Deutsch und Arabisch.",
                'body_ar' => "## ليش تأمين أسنان إضافي؟\nالتأمين الحكومي عادةً بيغطي بس جزء ثابت من تركيبات الأسنان، والباقي بتدفعه إنت – ومع التيجان والجسور والزرعات بيصير مكلف بسرعة. تأمين الأسنان الإضافي بيرفع نسبة التغطية بشكل ملحوظ.\n\n## تغطيات شائعة\n- تركيبات: تيجان، جسور، زرعات.\n- علاج الأسنان: متل الحشوات عالية الجودة أو علاج العصب.\n- الوقاية: تنظيف احترافي للأسنان (حسب التعرفة).\n\n## كيف منستشيرك\nمنوريك أي تعرفة بتستاهل لوضعك، ومننتبه لفترات الانتظار وحدود التغطية – بشرح واضح بالعربي والألماني.",
                'faq' => [],
            ],
            [
                'slug' => 'kfz-zulassung',
                'category' => 'kfz',
                'icon' => '📋',
                'title_de' => 'Kfz-Zulassungsservice',
                'title_ar' => 'خدمة تسجيل السيارات',
                'subtitle_de' => 'An-, Um- und Abmeldung ohne Warteschlange',
                'subtitle_ar' => 'تسجيل وتحويل وإلغاء بدون طوابير',
                'intro_de' => 'Wir uebernehmen die komplette Zulassung Ihres Fahrzeugs bei der '
                    . 'Zulassungsstelle - Anmeldung, Ummeldung oder Abmeldung. Sie sparen sich den '
                    . 'Behoerdengang und die Wartezeit.',
                'intro_ar' => 'مننجزلك تسجيل سيارتك كامل بدائرة المرور - تسجيل جديد أو تحويل أو إلغاء. '
                    . 'بتوفّر عليك زيارة الدائرة ووقت الانتظار.',
                'highlights_de' => "Anmeldung, Ummeldung, Abmeldung\nKein Behoerdengang, keine Warteschlange\nAuch mit Wunschkennzeichen moeglich\nSchnelle und sichere Abwicklung",
                'highlights_ar' => "تسجيل، تحويل، إلغاء\nبدون زيارة الدائرة وبدون طوابير\nممكن كمان لوحة برقم خاص\nإنجاز سريع وآمن",
                'meta_description_de' => 'Kfz-Zulassungsservice: An-, Um- und Abmeldung ohne Behördengang und Warteschlange – schnell, sicher und auf Wunsch mit Wunschkennzeichen.',
                'meta_description_ar' => 'خدمة تسجيل السيارات: تسجيل وتحويل وإلغاء بدون زيارة الدائرة وبدون طوابير – سريع وآمن ومع لوحة برقم خاص عند الطلب.',
                'body_de' => "## Zulassung ohne Aufwand\nWir übernehmen die komplette Zulassung Ihres Fahrzeugs bei der Zulassungsstelle – Sie sparen sich Behördengang und Wartezeit.\n\n## Das übernehmen wir\n- Anmeldung eines neuen oder gebrauchten Fahrzeugs.\n- Ummeldung, z. B. bei Halter- oder Wohnortwechsel.\n- Abmeldung (Stilllegung) Ihres Fahrzeugs.\n- Auf Wunsch mit Wunschkennzeichen.\n\n## So läuft es ab\nSie stellen Ihre Anfrage, wir nennen Ihnen die genau benötigten Unterlagen und erledigen den Rest. Schnell, sicher und verständlich – auf Deutsch und Arabisch.",
                'body_ar' => "## تسجيل بدون عناء\nمننجزلك تسجيل سيارتك كامل بدائرة المرور – بتوفّر عليك زيارة الدائرة ووقت الانتظار.\n\n## شو مننجزه\n- تسجيل سيارة جديدة أو مستعملة.\n- تحويل، متل تغيير المالك أو مكان السكن.\n- إلغاء (إيقاف) السيارة.\n- ومع لوحة برقم خاص عند الطلب.\n\n## كيف بتمشي\nبتبعت طلبك، منخبّرك بالأوراق المطلوبة بالضبط، ومننجز الباقي. سريع وآمن وبشرح واضح – بالعربي والألماني.",
                'faq' => [
                    [
                        'q_de' => 'Welche Unterlagen brauche ich?',
                        'q_ar' => 'شو الأوراق يلي بحتاجها؟',
                        'a_de' => 'Das haengt von der Art der Zulassung ab. Nach Ihrer Anfrage nennen wir Ihnen die genau benoetigten Unterlagen.',
                        'a_ar' => 'بيعتمد على نوع المعاملة. بعد طلبك منخبّرك بالأوراق المطلوبة بالضبط.',
                    ],
                ],
                'fields' => [
                    ['label_de' => 'Art der Zulassung', 'label_ar' => 'نوع المعاملة', 'type' => 'select', 'options_de' => 'Anmeldung, Ummeldung, Abmeldung', 'options_ar' => 'تسجيل جديد, تحويل, إلغاء', 'required' => true],
                    ['label_de' => 'Wunschkennzeichen (optional)', 'label_ar' => 'رقم لوحة مرغوب (اختياري)', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'kennzeichen-per-post',
                'category' => 'kfz',
                'icon' => '🔖',
                'title_de' => 'Kennzeichen per Post',
                'title_ar' => 'لوحات السيارة بالبريد',
                'subtitle_de' => 'Neue Kennzeichen versiegelt nach Hause geliefert',
                'subtitle_ar' => 'لوحات جديدة مختومة بتوصل لبيتك',
                'intro_de' => 'Sie bestellen Ihre neuen Kennzeichen bequem online, wir liefern sie '
                    . 'versiegelt direkt zu Ihnen nach Hause - schnell, sicher und guenstig. Auf '
                    . 'Wunsch auch mit Wunschkennzeichen.',
                'intro_ar' => 'بتطلب لوحاتك الجديدة أونلاين، ومنوصّلها مختومة لعندك عالبيت - بسرعة '
                    . 'وأمان وسعر مناسب. وإذا بدك برقم خاص كمان.',
                'highlights_de' => "Bestellung bequem von zu Hause\nVersiegelte Lieferung nach Hause\nAuch Wunschkennzeichen moeglich\nSchnell, sicher und guenstig",
                'highlights_ar' => "طلب مريح من البيت\nتوصيل مختوم لعنوانك\nممكن رقم خاص\nسريع وآمن وبسعر منافس",
                'meta_description_de' => 'Kfz-Kennzeichen per Post: neue Nummernschilder versiegelt nach Hause geliefert – bequem, schnell und günstig, auch mit Wunschkennzeichen.',
                'meta_description_ar' => 'لوحات السيارة بالبريد: لوحات جديدة مختومة بتوصل لبيتك – مريح وسريع وبسعر مناسب، ومع رقم خاص عند الطلب.',
                'body_de' => "## Kennzeichen bequem bestellen\nSie bestellen Ihre neuen Kfz-Kennzeichen ganz einfach online – wir liefern sie versiegelt direkt zu Ihnen nach Hause. Kein Weg zum Prägeschild-Anbieter nötig.\n\n## Gut zu wissen\n- Versiegelte, geprägte Kennzeichen nach gültigem Standard.\n- Lieferung nach Hause, schnell und sicher.\n- Auf Wunsch mit Wunschkennzeichen.\n\n## In Kombination mit der Zulassung\nGern übernehmen wir zusätzlich die Zulassung Ihres Fahrzeugs, sodass alles aus einer Hand kommt. Fragen Sie einfach an – auf Deutsch oder Arabisch.",
                'body_ar' => "## اطلب لوحاتك بسهولة\nبتطلب لوحات سيارتك الجديدة أونلاين بكل بساطة – ومنوصّلها مختومة لعندك عالبيت. ما في داعي تروح لمحل صناعة اللوحات.\n\n## معلومات مفيدة\n- لوحات مختومة ومصنّعة حسب المعيار الساري.\n- توصيل للبيت، بسرعة وأمان.\n- ومع رقم خاص عند الطلب.\n\n## مع خدمة التسجيل\nفينا كمان نتكفّل بتسجيل سيارتك، فيصير كل شي من مكان واحد. بس اسأل – بالعربي أو الألماني.",
                'faq' => [],
            ],
            [
                'slug' => 'strom-gas',
                'category' => 'energie',
                'icon' => '⚡',
                'title_de' => 'Strom & Gas',
                'title_ar' => 'الكهرباء والغاز',
                'subtitle_de' => 'Tarif pruefen und beim Anbieterwechsel sparen',
                'subtitle_ar' => 'فحص التعرفة وتوفير عند تبديل المزوّد',
                'intro_de' => 'Die Energiepreise aendern sich staendig. Wir pruefen Ihren aktuellen '
                    . 'Tarif und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen '
                    . 'koennen. Die Kuendigung und Anmeldung uebernehmen wir - Ihre Versorgung '
                    . 'laeuft ohne Unterbrechung weiter.',
                'intro_ar' => 'أسعار الطاقة عم تتغير باستمرار. منراجع تعرفتك الحالية ومنوريك قديش '
                    . 'فيك توفّر إذا بدّلت المزوّد. مننتكفّل بإلغاء العقد القديم وتسجيل الجديد، '
                    . 'والتزويد ما بينقطع.',
                'highlights_de' => "Kostenloser Tarif-Check\nWechselservice komplett aus einer Hand\nVersorgung ohne Unterbrechung\nZugang zu Tarifen vieler Anbieter",
                'highlights_ar' => "فحص مجاني للتعرفة\nخدمة تبديل كاملة من عنا\nتزويد بدون انقطاع\nوصول لعروض مزوّدين كتار",
                'providers' => "E.ON\nEnBW\nVattenfall\nLichtBlick\nYello\nNaturstrom\ngrüner strom\nEWE\nStadtwerke",
                'meta_description_de' => 'Strom und Gas: Tarif prüfen und beim Anbieterwechsel sparen. Kündigung und Anmeldung übernehmen wir – Versorgung ohne Unterbrechung.',
                'meta_description_ar' => 'الكهرباء والغاز: افحص تعرفتك ووفّر عند تبديل المزوّد. مننتكفّل بالإلغاء والتسجيل – وتزويد بدون انقطاع.',
                'body_de' => "## Sparen beim Anbieterwechsel\nDie Energiepreise ändern sich ständig. Wir prüfen Ihren aktuellen Tarif und zeigen Ihnen, wie viel Sie durch einen Anbieterwechsel sparen können – für Strom, Gas oder beides.\n\n## Der Wechsel ist einfach\n- Wir übernehmen Kündigung beim alten und Anmeldung beim neuen Anbieter.\n- Ihre Versorgung läuft ohne Unterbrechung weiter.\n- Sie behalten den Überblick – wir erklären jeden Schritt.\n\n## Was wir brauchen\nFür den Vergleich genügen Ihre letzte Jahresabrechnung oder Ihr ungefährer Jahresverbrauch in kWh sowie Ihre Postleitzahl. Fragen Sie an – auf Deutsch oder Arabisch.",
                'body_ar' => "## وفّر عند تبديل المزوّد\nأسعار الطاقة عم تتغيّر باستمرار. منراجع تعرفتك الحالية ومنوريك قديش فيك توفّر إذا بدّلت المزوّد – كهرباء أو غاز أو الاثنين.\n\n## التبديل سهل\n- مننتكفّل بإلغاء العقد القديم وتسجيل الجديد.\n- التزويد بيضل شغّال بدون انقطاع.\n- بتضل عارف كل شي – منشرحلك كل خطوة.\n\n## شو منحتاج\nللمقارنة بتكفي فاتورتك السنوية الأخيرة أو استهلاكك التقريبي بالكيلوواط ساعة مع الرمز البريدي. بس اسأل – بالعربي أو الألماني.",
                'faq' => [
                    [
                        'q_de' => 'Was brauche ich fuer den Vergleich?',
                        'q_ar' => 'شو بتحتاجوا مني للمقارنة؟',
                        'a_de' => 'Nur Ihre letzte Jahresabrechnung oder Ihren ungefaehren Jahresverbrauch in kWh.',
                        'a_ar' => 'بس فاتورتك السنوية الأخيرة أو استهلاكك التقريبي بالكيلوواط ساعة.',
                    ],
                ],
                'fields' => [
                    ['label_de' => 'Sparte', 'label_ar' => 'النوع', 'type' => 'select', 'options_de' => 'Strom, Gas, Strom und Gas', 'options_ar' => 'كهرباء, غاز, كهرباء وغاز', 'required' => true],
                    ['label_de' => 'Jahresverbrauch (kWh)', 'label_ar' => 'الاستهلاك السنوي (كيلوواط ساعة)', 'type' => 'number', 'options_de' => '', 'options_ar' => '', 'required' => false],
                    ['label_de' => 'PLZ', 'label_ar' => 'الرمز البريدي', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
        ];
    }
}
