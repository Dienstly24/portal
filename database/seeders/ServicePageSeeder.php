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

            // ===== Auto & Fahrzeuge =====
            [
                'slug' => 'motorradversicherung',
                'category' => 'kfz', 'icon' => '🏍️',
                'title_de' => 'Motorradversicherung', 'title_ar' => 'تأمين الدراجة النارية',
                'subtitle_de' => 'Haftpflicht, Teil- und Vollkasko fürs Motorrad', 'subtitle_ar' => 'مسؤولية وجزئي وشامل للدراجة النارية',
                'intro_de' => 'Wie beim Auto ist die Haftpflicht fürs Motorrad Pflicht. Teil- und Vollkasko schützen zusätzlich Ihr eigenes Fahrzeug – wir vergleichen anbieterunabhängig für Sie.',
                'intro_ar' => 'متل السيارة، تأمين المسؤولية للدراجة النارية إلزامي. والجزئي والشامل بيحميان دراجتك – ومنقارنلك بشكل مستقل.',
                'highlights_de' => "Pflicht-Haftpflicht fürs Motorrad\nTeilkasko z. B. bei Diebstahl\nSaisonkennzeichen möglich\nAnbieterunabhängiger Vergleich",
                'highlights_ar' => "تأمين مسؤولية إلزامي\nتأمين جزئي مثلاً للسرقة\nإمكانية لوحة موسمية\nمقارنة مستقلة عن الشركات",
                'meta_description_de' => 'Motorradversicherung: Haftpflicht, Teil- und Vollkasko im anbieterunabhängigen Vergleich – Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين الدراجة النارية: مسؤولية وجزئي وشامل بمقارنة مستقلة – استشارة بالعربي والألماني.',
                'body_de' => "## Passender Schutz fürs Motorrad\nDie Haftpflicht ist auch fürs Motorrad gesetzlich vorgeschrieben. Mit Teil- oder Vollkasko sichern Sie zusätzlich Ihr eigenes Fahrzeug ab – etwa bei Diebstahl, Sturm oder selbst verursachten Schäden.\n\n## Gut zu wissen\nFür Saisonfahrzeuge gibt es Saisonkennzeichen, mit denen Sie nur für die Fahrmonate zahlen. Wir beraten Sie zum passenden Umfang – auf Deutsch und Arabisch.",
                'body_ar' => "## حماية مناسبة للدراجة\nتأمين المسؤولية إلزامي للدراجة النارية كمان. ومع الجزئي أو الشامل بتأمّن دراجتك – متل السرقة والعواصف والأضرار بخطئك.\n\n## معلومات مفيدة\nللدراجات الموسمية في لوحة موسمية بتدفع فيها بس لأشهر الاستخدام. ومنستشيرك بالتغطية المناسبة.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Motorrad (Marke / Modell)', 'label_ar' => 'الدراجة (ماركة/موديل)', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                    ['label_de' => 'Gewuenschte Deckung', 'label_ar' => 'التغطية المطلوبة', 'type' => 'select', 'options_de' => 'Haftpflicht, Teilkasko, Vollkasko', 'options_ar' => 'مسؤولية, جزئي, شامل', 'required' => false],
                ],
            ],
            [
                'slug' => 'mopedversicherung',
                'category' => 'kfz', 'icon' => '🛵',
                'title_de' => 'Mopedversicherung', 'title_ar' => 'تأمين الموبيد/السكوتر',
                'subtitle_de' => 'Versicherungskennzeichen für Roller, Mofa & Co.', 'subtitle_ar' => 'لوحة تأمين للسكوتر والموبيد',
                'intro_de' => 'Roller, Mofas und Mopeds brauchen ein Versicherungskennzeichen. Es gilt jeweils bis Ende Februar des Folgejahres – wir finden den passenden Schutz.',
                'intro_ar' => 'السكوتر والموبيد بيحتاجوا لوحة تأمين، بتضل سارية لآخر شباط من السنة التالية – ومنلاقيلك الحماية المناسبة.',
                'highlights_de' => "Versicherungskennzeichen (Pflicht)\nGültig bis Ende Februar\nTeilkasko optional\nSchnell und unkompliziert",
                'highlights_ar' => "لوحة تأمين (إلزامية)\nسارية لآخر شباط\nتأمين جزئي اختياري\nسريع وبسيط",
                'meta_description_de' => 'Mopedversicherung & Versicherungskennzeichen für Roller und Mofa – anbieterunabhängig, auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين الموبيد ولوحة التأمين للسكوتر والموبيد – مستقل، بالعربي والألماني.',
                'body_de' => "## Versicherungskennzeichen\nFür Kleinkrafträder wie Roller und Mofa ist statt eines amtlichen Kennzeichens ein Versicherungskennzeichen erforderlich. Es bestätigt den Haftpflichtschutz und wird jährlich getauscht.\n\n## Optionaler Schutz\nGegen Aufpreis ist eine Teilkasko möglich, etwa bei Diebstahl. Wir beraten Sie, was sich für Ihr Fahrzeug lohnt.",
                'body_ar' => "## لوحة التأمين\nللمركبات الصغيرة متل السكوتر والموبيد، بدل اللوحة الرسمية في لوحة تأمين بتأكّد تغطية المسؤولية وبتتبدّل سنوياً.\n\n## حماية اختيارية\nبفرق سعر بسيط في تأمين جزئي، مثلاً للسرقة. ومنستشيرك شو بيستاهل لمركبتك.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Fahrzeugart', 'label_ar' => 'نوع المركبة', 'type' => 'select', 'options_de' => 'Roller, Mofa, Moped, E-Roller', 'options_ar' => 'سكوتر, موفا, موبيد, سكوتر كهربائي', 'required' => false],
                ],
            ],
            [
                'slug' => 'e-scooter-versicherung',
                'category' => 'kfz', 'icon' => '🛴',
                'title_de' => 'E-Scooter-Versicherung', 'title_ar' => 'تأمين السكوتر الكهربائي',
                'subtitle_de' => 'Pflichtversicherung für Ihren E-Scooter', 'subtitle_ar' => 'تأمين إلزامي للسكوتر الكهربائي',
                'intro_de' => 'E-Scooter mit Straßenzulassung brauchen eine Haftpflichtversicherung und eine Versicherungsplakette. Wir finden schnell den passenden Tarif.',
                'intro_ar' => 'السكوتر الكهربائي المرخّص للطرق بيحتاج تأمين مسؤولية ولصاقة تأمين. ومنلاقيلك التعرفة المناسبة بسرعة.',
                'highlights_de' => "Haftpflicht ist Pflicht\nVersicherungsplakette pro Jahr\nGünstige Jahresbeiträge\nSchnell abgeschlossen",
                'highlights_ar' => "تأمين المسؤولية إلزامي\nلصاقة تأمين سنوية\nأقساط سنوية مناسبة\nإنجاز سريع",
                'meta_description_de' => 'E-Scooter-Versicherung mit Versicherungsplakette – Haftpflicht schnell und günstig, auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين السكوتر الكهربائي مع لصاقة التأمين – مسؤولية بسرعة وسعر مناسب، بالعربي والألماني.',
                'body_de' => "## Plakette statt Kennzeichen\nElektrokleinstfahrzeuge wie E-Scooter benötigen eine Versicherungsplakette, die den Haftpflichtschutz nachweist. Ohne diese ist das Fahren im Straßenverkehr nicht erlaubt.\n\n## So helfen wir\nWir vergleichen die Angebote und erklären Ihnen die Bedingungen – verständlich, auf Deutsch und Arabisch.",
                'body_ar' => "## لصاقة بدل لوحة\nالمركبات الكهربائية الصغيرة متل السكوتر بتحتاج لصاقة تأمين بتثبت تغطية المسؤولية. بدونها ما بينفع تستخدمه بالطريق.\n\n## كيف منساعد\nمنقارن العروض ومنشرحلك الشروط بوضوح – بالعربي والألماني.",
                'faq' => [],
                'fields' => [],
            ],
            [
                'slug' => 'schutzbrief',
                'category' => 'kfz', 'icon' => '🛟',
                'title_de' => 'Kfz-Schutzbrief', 'title_ar' => 'خطاب الحماية للسيارة',
                'subtitle_de' => 'Pannen- und Unfallhilfe unterwegs', 'subtitle_ar' => 'مساعدة على الطريق عند العطل والحوادث',
                'intro_de' => 'Ein Schutzbrief hilft bei Panne, Unfall oder Diebstahl unterwegs – mit Pannenhilfe, Abschleppdienst und mehr. Wir beraten zum passenden Umfang.',
                'intro_ar' => 'خطاب الحماية بيساعدك عند العطل أو الحادث أو السرقة على الطريق – مساعدة، سحب، وغيرها. ومنستشيرك بالتغطية المناسبة.',
                'highlights_de' => "Pannen- und Abschlepphilfe\nWeltweit oder europaweit\nOft günstiger Zusatzbaustein\nAuch unabhängig vom Fahrzeug",
                'highlights_ar' => "مساعدة عند العطل والسحب\nتغطية أوروبية أو عالمية\nغالباً إضافة رخيصة\nممكن مستقلة عن السيارة",
                'meta_description_de' => 'Kfz-Schutzbrief: Pannenhilfe, Abschleppdienst und Unterwegs-Schutz – anbieterunabhängig beraten, DE & AR.',
                'meta_description_ar' => 'خطاب الحماية للسيارة: مساعدة وسحب وحماية على الطريق – استشارة مستقلة، بالعربي والألماني.',
                'body_de' => "## Hilfe, wenn es drauf ankommt\nEin Schutzbrief leistet bei Panne, Unfall oder Diebstahl – etwa Pannenhilfe vor Ort, Abschleppen zur Werkstatt, Ersatzfahrzeug oder Übernachtung. Der Umfang hängt vom Tarif ab.\n\n## So helfen wir\nWir zeigen Ihnen, ob ein Schutzbrief über die Kfz-Versicherung oder separat sinnvoller ist.",
                'body_ar' => "## مساعدة وقت الحاجة\nخطاب الحماية بيقدّم عند العطل أو الحادث أو السرقة – متل المساعدة بالموقع، السحب للورشة، سيارة بديلة أو مبيت. التغطية حسب التعرفة.\n\n## كيف منساعد\nمنوريك إذا الأفضل يكون ضمن تأمين السيارة ولا منفصل.",
                'faq' => [],
                'fields' => [],
            ],
            [
                'slug' => 'fahrradversicherung',
                'category' => 'kfz', 'icon' => '🚲',
                'title_de' => 'Fahrrad- & E-Bike-Versicherung', 'title_ar' => 'تأمين الدراجة والـE-Bike',
                'subtitle_de' => 'Schutz bei Diebstahl und Beschädigung', 'subtitle_ar' => 'حماية من السرقة والأضرار',
                'intro_de' => 'Fahrräder und E-Bikes sind wertvoll. Eine Fahrradversicherung schützt bei Diebstahl und häufig auch bei Beschädigung oder Reparatur – wir vergleichen für Sie.',
                'intro_ar' => 'الدراجات والـE-Bike غالية. تأمين الدراجة بيحميك من السرقة وغالباً من الأضرار والتصليح – ومنقارنلك.',
                'highlights_de' => "Schutz bei Diebstahl\nOft auch Beschädigung/Reparatur\nAuch für E-Bikes und Pedelecs\nWeltweiter Schutz möglich",
                'highlights_ar' => "حماية من السرقة\nغالباً أضرار وتصليح كمان\nللـE-Bike والـPedelec كمان\nإمكانية تغطية عالمية",
                'meta_description_de' => 'Fahrrad- und E-Bike-Versicherung: Schutz bei Diebstahl und Beschädigung – anbieterunabhängig, DE & AR.',
                'meta_description_ar' => 'تأمين الدراجة والـE-Bike: حماية من السرقة والأضرار – مستقل، بالعربي والألماني.',
                'body_de' => "## Warum sich der Schutz lohnt\nGerade hochwertige Räder und E-Bikes sind ein beliebtes Diebstahlziel. Eine Fahrradversicherung ersetzt den Zeitwert oder Neuwert und leistet je nach Tarif auch bei Beschädigung, Verschleiß oder Akkuschäden.\n\n## So helfen wir\nWir vergleichen die Bedingungen – etwa Nachtzeitklausel oder Selbstbeteiligung – und finden den passenden Tarif.",
                'body_ar' => "## ليش الحماية بتستاهل\nالدراجات والـE-Bike الغالية هدف شائع للسرقة. تأمين الدراجة بيعوّض القيمة الحالية أو قيمة الجديد، وحسب التعرفة بيغطي الأضرار والاستهلاك وأعطال البطارية.\n\n## كيف منساعد\nمنقارن الشروط – متل شرط الليل أو التحمّل الذاتي – ومنلاقي التعرفة المناسبة.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Radtyp', 'label_ar' => 'نوع الدراجة', 'type' => 'select', 'options_de' => 'Fahrrad, E-Bike/Pedelec', 'options_ar' => 'دراجة, E-Bike', 'required' => false],
                    ['label_de' => 'Kaufpreis (ca.)', 'label_ar' => 'سعر الشراء (تقريبي)', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],

            // ===== Haus & Wohnung =====
            [
                'slug' => 'hausratversicherung',
                'category' => 'wohnen', 'icon' => '🛋️',
                'title_de' => 'Hausratversicherung', 'title_ar' => 'تأمين محتويات المنزل',
                'subtitle_de' => 'Schutz für Ihr Hab und Gut zu Hause', 'subtitle_ar' => 'حماية لممتلكاتك داخل البيت',
                'intro_de' => 'Die Hausratversicherung ersetzt Ihren Besitz – Möbel, Elektronik, Kleidung – bei Feuer, Einbruch, Leitungswasser oder Sturm. Wir finden den passenden Schutz.',
                'intro_ar' => 'تأمين المحتويات بيعوّض ممتلكاتك – أثاث، إلكترونيات، ملابس – عند الحريق أو السرقة أو تسرّب الماء أو العواصف. ومنلاقيلك الحماية المناسبة.',
                'highlights_de' => "Schutz bei Feuer, Einbruch, Wasser, Sturm\nErstattung zum Neuwert\nFahrraddiebstahl oft einschließbar\nWichtig für jede Wohnung",
                'highlights_ar' => "حماية من الحريق والسرقة والماء والعواصف\nتعويض بقيمة الجديد\nغالباً ممكن تضمين سرقة الدراجة\nمهم لأي منزل",
                'meta_description_de' => 'Hausratversicherung: Schutz bei Feuer, Einbruch, Leitungswasser und Sturm zum Neuwert – anbieterunabhängig, DE & AR.',
                'meta_description_ar' => 'تأمين محتويات المنزل: حماية من الحريق والسرقة والماء والعواصف بقيمة الجديد – مستقل، بالعربي والألماني.',
                'body_de' => "## Was versichert ist\nVersichert ist Ihr gesamter Hausrat gegen die Gefahren Feuer, Einbruchdiebstahl, Leitungswasser sowie Sturm und Hagel. Ersetzt wird in der Regel der Neuwert, damit Sie Beschädigtes gleichwertig ersetzen können.\n\n## Auf die Versicherungssumme achten\nWichtig ist eine ausreichende Versicherungssumme (meist nach Wohnfläche). Wir helfen Ihnen, Unterversicherung zu vermeiden und sinnvolle Zusatzbausteine zu wählen.",
                'body_ar' => "## شو المؤمَّن\nمحتويات بيتك كلها مؤمَّنة ضد الحريق والسرقة وتسرّب الماء والعواصف والبَرَد. والتعويض عادةً بقيمة الجديد حتى تعوّض المتضرّر بشكل مكافئ.\n\n## انتبه لمبلغ التأمين\nالمهم مبلغ تأمين كافٍ (عادةً حسب المساحة). ومنساعدك تتجنّب نقص التأمين وتختار الإضافات المفيدة.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Wohnfläche (m²)', 'label_ar' => 'المساحة (م²)', 'type' => 'number', 'options_de' => '', 'options_ar' => '', 'required' => false],
                    ['label_de' => 'PLZ', 'label_ar' => 'الرمز البريدي', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'wohngebaeudeversicherung',
                'category' => 'wohnen', 'icon' => '🏡',
                'title_de' => 'Wohngebäudeversicherung', 'title_ar' => 'تأمين المبنى السكني',
                'subtitle_de' => 'Schutz für Ihr Haus als Eigentümer', 'subtitle_ar' => 'حماية لبيتك كمالك',
                'intro_de' => 'Die Wohngebäudeversicherung schützt das Gebäude selbst vor Schäden durch Feuer, Leitungswasser, Sturm und Hagel – unverzichtbar für Eigentümer.',
                'intro_ar' => 'تأمين المبنى بيحمي البناء نفسه من أضرار الحريق وتسرّب الماء والعواصف والبَرَد – أساسي للمالك.',
                'highlights_de' => "Schutz des Gebäudes\nFeuer, Leitungswasser, Sturm/Hagel\nElementarschäden einschließbar\nWichtig für Eigentümer",
                'highlights_ar' => "حماية البناء\nحريق، تسرّب ماء، عواصف/بَرَد\nممكن تضمين الكوارث الطبيعية\nمهم للمالك",
                'meta_description_de' => 'Wohngebäudeversicherung für Eigentümer: Schutz vor Feuer, Leitungswasser, Sturm und Hagel – anbieterunabhängig, DE & AR.',
                'meta_description_ar' => 'تأمين المبنى للمالك: حماية من الحريق والماء والعواصف والبَرَد – مستقل، بالعربي والألماني.',
                'body_de' => "## Das Gebäude absichern\nAnders als die Hausratversicherung schützt die Wohngebäudeversicherung die Bausubstanz – also Mauern, Dach, fest verbaute Teile. Typisch abgedeckt sind Feuer, Leitungswasser sowie Sturm und Hagel.\n\n## Elementarschäden nicht vergessen\nSchäden durch Überschwemmung, Starkregen oder Erdrutsch sind oft nur mit einem Elementarbaustein versichert. Wir beraten Sie dazu individuell.",
                'body_ar' => "## تأمين البناء\nعكس تأمين المحتويات، تأمين المبنى بيحمي هيكل البناء – الجدران والسقف والأجزاء الثابتة. وعادةً بيغطي الحريق وتسرّب الماء والعواصف والبَرَد.\n\n## لا تنسى الكوارث الطبيعية\nأضرار الفيضان والأمطار الغزيرة والانزلاقات غالباً بتنغطّى بس بإضافة الكوارث. ومنستشيرك فيها بشكل فردي.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Objektart', 'label_ar' => 'نوع العقار', 'type' => 'select', 'options_de' => 'Einfamilienhaus, Mehrfamilienhaus, Eigentumswohnung', 'options_ar' => 'بيت لعائلة, بناء عدة عائلات, شقة تمليك', 'required' => false],
                    ['label_de' => 'Baujahr', 'label_ar' => 'سنة البناء', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'neubauversicherung',
                'category' => 'wohnen', 'icon' => '🏗️',
                'title_de' => 'Neubauversicherung', 'title_ar' => 'تأمين البناء الجديد',
                'subtitle_de' => 'Absicherung während der Bauphase', 'subtitle_ar' => 'حماية خلال فترة البناء',
                'intro_de' => 'Wer neu baut, sollte Bauleistung und Bauherrenhaftpflicht absichern – gegen Schäden am Bau und Ansprüche Dritter. Wir beraten Sie rund um Ihr Bauprojekt.',
                'intro_ar' => 'يلي عم يبني جديد لازم يأمّن أعمال البناء ومسؤولية صاحب البناء – ضد أضرار البناء ومطالبات الغير. ومنستشيرك حول مشروعك.',
                'highlights_de' => "Bauleistungsversicherung\nBauherrenhaftpflicht\nFeuer-Rohbauversicherung\nSchutz in der Bauphase",
                'highlights_ar' => "تأمين أعمال البناء\nمسؤولية صاحب البناء\nتأمين حريق الهيكل\nحماية خلال البناء",
                'meta_description_de' => 'Neubauversicherung: Bauleistung, Bauherrenhaftpflicht und Rohbau-Feuerschutz während der Bauphase – Beratung DE & AR.',
                'meta_description_ar' => 'تأمين البناء الجديد: أعمال البناء ومسؤولية صاحب البناء وحماية الهيكل من الحريق – استشارة بالعربي والألماني.',
                'body_de' => "## Sicher durch die Bauzeit\nWährend der Bauphase drohen besondere Risiken. Die Bauleistungsversicherung deckt unvorhergesehene Schäden am Bauwerk, die Bauherrenhaftpflicht schützt vor Ansprüchen Dritter, etwa bei Unfällen auf der Baustelle.\n\n## So helfen wir\nWir stellen die passenden Bausteine für Ihr Projekt zusammen – verständlich, auf Deutsch und Arabisch.",
                'body_ar' => "## أمان خلال فترة البناء\nخلال البناء في مخاطر خاصة. تأمين أعمال البناء بيغطي الأضرار غير المتوقّعة بالبناء، ومسؤولية صاحب البناء بتحميك من مطالبات الغير، متل الحوادث بالورشة.\n\n## كيف منساعد\nمنجمّعلك الإضافات المناسبة لمشروعك بوضوح – بالعربي والألماني.",
                'faq' => [],
                'fields' => [],
            ],
            [
                'slug' => 'elementarversicherung',
                'category' => 'wohnen', 'icon' => '🌊',
                'title_de' => 'Elementarversicherung', 'title_ar' => 'تأمين الكوارث الطبيعية',
                'subtitle_de' => 'Schutz vor Naturgefahren wie Überschwemmung', 'subtitle_ar' => 'حماية من مخاطر الطبيعة متل الفيضان',
                'intro_de' => 'Starkregen und Überschwemmungen nehmen zu. Die Elementarversicherung ergänzt Wohngebäude- und Hausratschutz um Naturgefahren – wir beraten Sie dazu.',
                'intro_ar' => 'الأمطار الغزيرة والفيضانات عم تزيد. تأمين الكوارث بيكمّل تأمين المبنى والمحتويات بمخاطر الطبيعة – ومنستشيرك فيه.',
                'highlights_de' => "Überschwemmung & Starkregen\nErdrutsch, Schneedruck, Erdbeben\nErgänzt Gebäude & Hausrat\nImmer wichtiger",
                'highlights_ar' => "فيضان وأمطار غزيرة\nانزلاقات، ثقل الثلج، زلازل\nبيكمّل المبنى والمحتويات\nصار أهم من قبل",
                'meta_description_de' => 'Elementarversicherung: Schutz vor Überschwemmung, Starkregen und weiteren Naturgefahren – Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين الكوارث الطبيعية: حماية من الفيضان والأمطار الغزيرة ومخاطر أخرى – استشارة بالعربي والألماني.',
                'body_de' => "## Warum Elementarschutz wichtig ist\nSchäden durch Überschwemmung, Starkregen, Rückstau, Erdrutsch oder Schneedruck sind in der normalen Gebäude- oder Hausratversicherung meist nicht enthalten. Der Elementarbaustein schließt diese Lücke.\n\n## So helfen wir\nWir prüfen die Gefährdungslage Ihrer Region und finden einen passenden Schutz.",
                'body_ar' => "## ليش مهم\nأضرار الفيضان والأمطار الغزيرة وارتداد المجاري والانزلاقات وثقل الثلج عادةً مو مشمولة بتأمين المبنى أو المحتويات العادي. إضافة الكوارث بتسدّ هالثغرة.\n\n## كيف منساعد\nمنقيّم مستوى الخطر بمنطقتك ومنلاقيلك حماية مناسبة.",
                'faq' => [],
                'fields' => [],
            ],
            [
                'slug' => 'mietkautionsversicherung',
                'category' => 'wohnen', 'icon' => '🔑',
                'title_de' => 'Mietkautionsversicherung', 'title_ar' => 'تأمين كفالة الإيجار',
                'subtitle_de' => 'Kaution ohne Bargeld hinterlegen', 'subtitle_ar' => 'كفالة بدون دفع نقدي',
                'intro_de' => 'Statt die Mietkaution in bar zu hinterlegen, bürgt die Versicherung gegenüber dem Vermieter. Ihr Geld bleibt verfügbar – gegen einen jährlichen Beitrag.',
                'intro_ar' => 'بدل ما تدفع كفالة الإيجار نقداً، شركة التأمين بتكفلك عند المؤجّر. مصاريك بيضل متاح – مقابل قسط سنوي.',
                'highlights_de' => "Keine hohe Barkaution nötig\nBürgschaft gegenüber Vermieter\nGeld bleibt verfügbar\nJährlicher Beitrag",
                'highlights_ar' => "بدون كفالة نقدية كبيرة\nكفالة تجاه المؤجّر\nمصاريك بيضل متاح\nقسط سنوي",
                'meta_description_de' => 'Mietkautionsversicherung: Kaution ohne Bargeld – Bürgschaft gegenüber dem Vermieter. Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين كفالة الإيجار: كفالة بدون نقد – ضمان تجاه المؤجّر. استشارة بالعربي والألماني.',
                'body_de' => "## Liquidität behalten\nDie Mietkaution beträgt oft mehrere Monatsmieten. Mit einer Mietkautionsbürgschaft hinterlegen Sie kein Bargeld – die Versicherung bürgt gegenüber dem Vermieter. Dafür zahlen Sie einen jährlichen Beitrag.\n\n## Gut zu wissen\nDer Vermieter muss die Bürgschaft nicht akzeptieren; klären Sie das vorab. Wir beraten Sie, ob sich das Modell für Sie lohnt.",
                'body_ar' => "## خلّي سيولتك\nكفالة الإيجار غالباً عدة أشهر إيجار. مع كفالة التأمين ما بتدفع نقد – الشركة بتكفلك عند المؤجّر، مقابل قسط سنوي.\n\n## معلومة مهمة\nالمؤجّر مو مجبر يقبل الكفالة؛ وضّح هالنقطة قبل. ومنستشيرك إذا النموذج بيستاهل إلك.",
                'faq' => [],
                'fields' => [],
            ],

            // ===== Tierversicherungen =====
            [
                'slug' => 'hundehaftpflicht',
                'category' => 'tiere', 'icon' => '🐕',
                'title_de' => 'Hundehaftpflichtversicherung', 'title_ar' => 'تأمين مسؤولية الكلب',
                'subtitle_de' => 'Pflicht in vielen Bundesländern', 'subtitle_ar' => 'إلزامي في كثير من الولايات',
                'intro_de' => 'Für Schäden, die Ihr Hund verursacht, haften Sie – oft unbegrenzt. Die Hundehaftpflicht übernimmt diese Kosten und ist in einigen Bundesländern Pflicht.',
                'intro_ar' => 'عن الأضرار يلي بيسببها كلبك إنت المسؤول – غالباً بلا حدود. تأمين مسؤولية الكلب بيتحمّل هالتكاليف، وبكثير ولايات إلزامي.',
                'highlights_de' => "Schutz bei Personen- & Sachschäden\nIn mehreren Bundesländern Pflicht\nOft günstiger Jahresbeitrag\nAuch für mehrere Hunde",
                'highlights_ar' => "حماية لأضرار الأشخاص والممتلكات\nإلزامي في عدة ولايات\nغالباً قسط سنوي رخيص\nلعدة كلاب كمان",
                'meta_description_de' => 'Hundehaftpflichtversicherung: Schutz bei Schäden durch Ihren Hund – in vielen Bundesländern Pflicht. Beratung DE & AR.',
                'meta_description_ar' => 'تأمين مسؤولية الكلب: حماية من أضرار كلبك – إلزامي بكثير ولايات. استشارة بالعربي والألماني.',
                'body_de' => "## Warum sie wichtig ist\nAls Halter haften Sie für Schäden Ihres Hundes – ob zerbissene Kleidung oder ein verursachter Verkehrsunfall. Die Kosten können schnell hoch sein. Die Hundehaftpflicht übernimmt sie im Rahmen der Versicherungssumme.\n\n## Pflicht je nach Bundesland\nIn mehreren Bundesländern ist die Hundehaftpflicht vorgeschrieben, teils abhängig von der Rasse. Wir beraten Sie zu den Regeln vor Ort.",
                'body_ar' => "## ليش مهم\nكمالك إنت مسؤول عن أضرار كلبك – من ملابس ممزّقة لحادث سير. التكاليف ممكن تكبر بسرعة. وتأمين المسؤولية بيتحمّلها ضمن مبلغ التأمين.\n\n## إلزامي حسب الولاية\nبعدة ولايات التأمين إلزامي، أحياناً حسب السلالة. ومنستشيرك بالقوانين المحلية.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Anzahl Hunde', 'label_ar' => 'عدد الكلاب', 'type' => 'number', 'options_de' => '', 'options_ar' => '', 'required' => false],
                    ['label_de' => 'Rasse', 'label_ar' => 'السلالة', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'hundekrankenversicherung',
                'category' => 'tiere', 'icon' => '🐶',
                'title_de' => 'Hundekrankenversicherung', 'title_ar' => 'تأمين صحة الكلب',
                'subtitle_de' => 'Tierarzt- und OP-Kosten absichern', 'subtitle_ar' => 'تغطية تكاليف الطبيب والعمليات',
                'intro_de' => 'Tierarztkosten können hoch ausfallen – besonders bei Operationen. Die Hundekrankenversicherung übernimmt Behandlungen und OPs je nach Tarif ganz oder teilweise.',
                'intro_ar' => 'تكاليف الطبيب البيطري ممكن تكون عالية – خصوصاً العمليات. تأمين صحة الكلب بيغطي العلاجات والعمليات كلياً أو جزئياً حسب التعرفة.',
                'highlights_de' => "Behandlungen & Operationen\nVoll- oder OP-Schutz wählbar\nfreie Tierarztwahl\nSchutz vor hohen Kosten",
                'highlights_ar' => "علاجات وعمليات\nتغطية كاملة أو للعمليات فقط\nحرية اختيار الطبيب\nحماية من التكاليف العالية",
                'meta_description_de' => 'Hundekrankenversicherung: Tierarzt- und OP-Kosten absichern – Voll- oder OP-Schutz. Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين صحة الكلب: تغطية تكاليف الطبيب والعمليات – كاملة أو للعمليات. استشارة بالعربي والألماني.',
                'body_de' => "## Voll- oder OP-Schutz\nSie können zwischen einer OP-Versicherung (nur Operationen) und einer Vollversicherung (auch ambulante Behandlungen, Medikamente, Vorsorge) wählen. Je nach Tarif gibt es Erstattungsgrenzen und Selbstbeteiligungen.\n\n## So helfen wir\nWir vergleichen Leistungen und Wartezeiten und finden den passenden Schutz für Ihren Hund.",
                'body_ar' => "## كاملة أو للعمليات\nفيك تختار بين تأمين للعمليات فقط، أو تأمين كامل (علاجات ودواء ووقاية كمان). وحسب التعرفة في حدود تعويض وتحمّل ذاتي.\n\n## كيف منساعد\nمنقارن التغطيات وفترات الانتظار ومنلاقيلك الأنسب لكلبك.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Schutzumfang', 'label_ar' => 'نطاق التغطية', 'type' => 'select', 'options_de' => 'OP-Schutz, Vollschutz', 'options_ar' => 'عمليات فقط, تغطية كاملة', 'required' => false],
                    ['label_de' => 'Alter des Hundes', 'label_ar' => 'عمر الكلب', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
                ],
            ],
            [
                'slug' => 'katzenversicherung',
                'category' => 'tiere', 'icon' => '🐈',
                'title_de' => 'Katzenversicherung', 'title_ar' => 'تأمين القطط',
                'subtitle_de' => 'Kranken- und OP-Schutz für Ihre Katze', 'subtitle_ar' => 'تغطية صحية وعمليات لقطتك',
                'intro_de' => 'Auch für Katzen können Tierarzt- und OP-Kosten teuer werden. Eine Katzenversicherung übernimmt je nach Tarif Behandlungen und Operationen.',
                'intro_ar' => 'كمان للقطط، تكاليف الطبيب والعمليات ممكن تكون غالية. تأمين القطط بيغطي العلاجات والعمليات حسب التعرفة.',
                'highlights_de' => "Behandlungen & Operationen\nOP- oder Vollschutz\nfreie Tierarztwahl\nSchutz vor hohen Kosten",
                'highlights_ar' => "علاجات وعمليات\nعمليات فقط أو تغطية كاملة\nحرية اختيار الطبيب\nحماية من التكاليف العالية",
                'meta_description_de' => 'Katzenversicherung: Kranken- und OP-Schutz für Ihre Katze – anbieterunabhängig, auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين القطط: تغطية صحية وعمليات لقطتك – مستقل، بالعربي والألماني.',
                'body_de' => "## Schutz nach Ihren Wünschen\nWie beim Hund gibt es OP- und Vollversicherungen. Gerade Freigänger tragen ein höheres Verletzungsrisiko. Wir zeigen Ihnen, welcher Umfang zu Ihrer Katze passt.\n\n## Auf Bedingungen achten\nWichtig sind Wartezeiten, Erstattungsgrenzen und Altersgrenzen. Wir vergleichen die Tarife für Sie.",
                'body_ar' => "## حماية حسب رغبتك\nمتل الكلب، في تأمين للعمليات وتأمين كامل. خصوصاً القطط يلي بتطلع برّا عندها خطر إصابة أعلى. ومنوريك أي تغطية بتناسب قطتك.\n\n## انتبه للشروط\nالمهم فترات الانتظار وحدود التعويض وحدود العمر. ومنقارنلك التعرفات.",
                'faq' => [],
                'fields' => [
                    ['label_de' => 'Schutzumfang', 'label_ar' => 'نطاق التغطية', 'type' => 'select', 'options_de' => 'OP-Schutz, Vollschutz', 'options_ar' => 'عمليات فقط, تغطية كاملة', 'required' => false],
                ],
            ],
            [
                'slug' => 'pferdehaftpflicht',
                'category' => 'tiere', 'icon' => '🐴',
                'title_de' => 'Pferdehaftpflichtversicherung', 'title_ar' => 'تأمين مسؤولية الحصان',
                'subtitle_de' => 'Absicherung für Schäden durch Ihr Pferd', 'subtitle_ar' => 'حماية من أضرار الحصان',
                'intro_de' => 'Pferde können erhebliche Schäden verursachen – und dafür haften Sie als Halter. Die Pferdehaftpflicht schützt vor diesen oft hohen Kosten.',
                'intro_ar' => 'الخيل ممكن تسبّب أضرار كبيرة – وإنت كمالك المسؤول. تأمين مسؤولية الحصان بيحميك من هالتكاليف العالية.',
                'highlights_de' => "Schutz bei Personen- & Sachschäden\nauch Reitbeteiligung absicherbar\nhohe Deckungssummen\nWichtig für jeden Pferdehalter",
                'highlights_ar' => "حماية لأضرار الأشخاص والممتلكات\nممكن تأمين المشاركة بالركوب\nمبالغ تغطية عالية\nمهم لأي صاحب حصان",
                'meta_description_de' => 'Pferdehaftpflichtversicherung: Schutz bei Schäden durch Ihr Pferd, auch mit Reitbeteiligung – Beratung DE & AR.',
                'meta_description_ar' => 'تأمين مسؤولية الحصان: حماية من أضرار حصانك، مع المشاركة بالركوب – استشارة بالعربي والألماني.',
                'body_de' => "## Warum sie unverzichtbar ist\nOb ausgebrochenes Pferd oder ein Unfall beim Ausritt – als Halter haften Sie für die Folgen, oft in erheblicher Höhe. Die Pferdehaftpflicht übernimmt berechtigte Ansprüche und wehrt unberechtigte ab.\n\n## Zusatzoptionen\nHäufig lassen sich Reitbeteiligungen, Fremdreiter oder Kutschfahrten einschließen. Wir beraten Sie individuell.",
                'body_ar' => "## ليش أساسي\nسواء حصان هرب أو حادث أثناء الركوب – كمالك إنت مسؤول عن النتائج، وغالباً بمبالغ كبيرة. والتأمين بيتحمّل المطالبات المحقّة وبيردّ غير المحقّة.\n\n## خيارات إضافية\nغالباً ممكن تضمين المشاركة بالركوب أو رُكّاب آخرين أو عربات الخيل. ومنستشيرك بشكل فردي.",
                'faq' => [],
                'fields' => [],
            ],
            [
                'slug' => 'pferde-op-versicherung',
                'category' => 'tiere', 'icon' => '🐎',
                'title_de' => 'Pferde-OP-Versicherung', 'title_ar' => 'تأمين عمليات الحصان',
                'subtitle_de' => 'Operationskosten für Ihr Pferd absichern', 'subtitle_ar' => 'تغطية تكاليف عمليات حصانك',
                'intro_de' => 'Operationen beim Pferd sind teuer. Die Pferde-OP-Versicherung übernimmt die Kosten für Operationen und die damit verbundene Behandlung.',
                'intro_ar' => 'عمليات الخيل مكلفة. تأمين عمليات الحصان بيغطي تكاليف العمليات والعلاج المرتبط فيها.',
                'highlights_de' => "Kostenübernahme bei Operationen\ninkl. Vor- und Nachbehandlung\nfreie Tierklinikwahl\nSchutz vor hohen Rechnungen",
                'highlights_ar' => "تغطية تكاليف العمليات\nمع علاج ما قبل وبعد\nحرية اختيار العيادة\nحماية من الفواتير العالية",
                'meta_description_de' => 'Pferde-OP-Versicherung: Operationskosten inklusive Vor- und Nachbehandlung absichern – Beratung auf Deutsch und Arabisch.',
                'meta_description_ar' => 'تأمين عمليات الحصان: تغطية تكاليف العمليات مع العلاج قبل وبعد – استشارة بالعربي والألماني.',
                'body_de' => "## Hohe OP-Kosten absichern\nSchon eine einzelne Operation, etwa bei einer Kolik, kann mehrere Tausend Euro kosten. Die Pferde-OP-Versicherung übernimmt diese Kosten inklusive Narkose sowie Vor- und Nachsorge.\n\n## So helfen wir\nWir vergleichen Erstattungshöhen, Wartezeiten und Altersgrenzen und finden den passenden Tarif.",
                'body_ar' => "## غطّي تكاليف العمليات العالية\nعملية وحدة، متل حالة مغص، ممكن تكلّف عدة آلاف يورو. والتأمين بيغطي هالتكاليف مع التخدير والعلاج قبل وبعد.\n\n## كيف منساعد\nمنقارن قيم التعويض وفترات الانتظار وحدود العمر ومنلاقي التعرفة المناسبة.",
                'faq' => [],
                'fields' => [],
            ],
        ];
    }
}
