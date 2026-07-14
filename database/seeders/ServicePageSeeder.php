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
            } elseif (empty($existing->fields) && !empty($page['fields'])) {
                // Nur die Beispiel-Formularfelder einmalig nachtragen, wenn noch
                // keine gesetzt sind - vorhandene Admin-Aenderungen (Texte, FAQ,
                // eigene Felder) werden dabei NICHT ueberschrieben.
                $existing->update(['fields' => $page['fields']]);
            }
            // Bereits vorhandene Seiten werden ansonsten bewusst nicht angefasst,
            // damit im Admin gepflegte Inhalte bei jedem Deploy erhalten bleiben.
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
