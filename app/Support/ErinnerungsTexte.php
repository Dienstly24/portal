<?php

namespace App\Support;

/**
 * Freigegebene Texte der Vertrags-Erinnerungen (Betreiber-Vorgabe
 * 20.11.2026). HTML- und Textfassung der Mail lesen BEIDE von hier - sie
 * koennen nicht auseinanderlaufen.
 *
 * TIPPGEBER-REGEL: erinnern, auf die Wechselmoeglichkeit hinweisen, Kontakt
 * zu einem zugelassenen Partner anbieten. NIE beraten, empfehlen,
 * vermitteln oder Ersparnis versprechen. Ein Test prueft verbotene Woerter.
 *
 * Platzhalter: {ihre} (Sparte mit Possessiv), {bei_partner} (" bei X" oder
 * leer), {verlaengerung}, {frist}. Vorlage 'a' = Frist noch offen, 'b' =
 * Frist abgelaufen, Verlaengerung noch nicht erreicht.
 */
final class ErinnerungsTexte
{
    public const TEXTE = [
        'kfz' => [
            'de' => [
                'betreff' => '{Ihre} verlängert sich am {verlaengerung} automatisch',
                'a' => [
                    '{Ihre}{bei_partner} endet nicht einfach: Sie verlängert sich am {verlaengerung} automatisch um ein weiteres Jahr.',
                    'Wenn Sie zu diesem Termin wechseln möchten, muss Ihre Kündigung in der Regel spätestens am {frist} beim Versicherer eingegangen sein. Maßgeblich sind die Bedingungen Ihres Vertrags.',
                    'Ein Wechsel kann sich lohnen – Versicherer bieten für vergleichbaren Schutz oft unterschiedliche Beiträge. Gerne stellen wir für Sie den Kontakt zu einem zugelassenen Partner her, der Sie unverbindlich informiert.',
                ],
                'b' => [
                    '{Ihre}{bei_partner} verlängert sich am {verlaengerung} automatisch um ein weiteres Jahr. Die reguläre Kündigungsfrist zu diesem Termin ist bereits abgelaufen.',
                    'Gut zu wissen: Erhöht Ihr Versicherer den Beitrag, haben Sie in vielen Fällen ein Sonderkündigungsrecht – in der Regel innerhalb eines Monats nach Erhalt der Mitteilung. Maßgeblich sind die Bedingungen Ihres Vertrags.',
                    'Wenn Sie möchten, stellen wir für Sie den Kontakt zu einem zugelassenen Partner her, der Sie unverbindlich informiert.',
                ],
            ],
            'ar' => [
                'betreff' => 'يتجدّد {ihre} تلقائياً في {verlaengerung}',
                'a' => [
                    'لا ينتهي {ihre}{bei_partner} من تلقاء نفسه، بل يتجدّد تلقائياً في {verlaengerung} لمدة سنة إضافية.',
                    'إذا رغبت في التغيير اعتباراً من هذا الموعد، فيجب عادةً أن يصل إشعار الإلغاء إلى شركة التأمين في موعد أقصاه {frist}. وتبقى شروط عقدك هي المرجع.',
                    'قد يكون التغيير مجدياً، إذ كثيراً ما تقدّم شركات التأمين أقساطاً مختلفة لتغطية مماثلة. ويسعدنا أن نضعك على تواصل مع شريك مرخّص يقدّم لك المعلومات دون أي التزام.',
                ],
                'b' => [
                    'يتجدّد {ihre}{bei_partner} تلقائياً في {verlaengerung} لمدة سنة إضافية. وقد انتهت مهلة الإلغاء العادية لهذا الموعد.',
                    'معلومة مفيدة: إذا رفعت شركة التأمين القسط، يحق لك في كثير من الحالات الإلغاء الاستثنائي، عادةً خلال شهر من استلام الإشعار. وتبقى شروط عقدك هي المرجع.',
                    'إذا رغبت، نضعك على تواصل مع شريك مرخّص يقدّم لك المعلومات دون أي التزام.',
                ],
            ],
        ],
    ];

    /** Feste Bausteine, die nicht von der Sparte abhaengen. */
    public const RAHMEN = [
        'de' => [
            'gruss' => 'Guten Tag',
            'chat' => 'Im Chat schreiben',
            'whatsapp' => 'Per WhatsApp schreiben',
            'antworten' => 'Oder antworten Sie einfach auf diese E-Mail.',
            'telefon' => 'Telefon',
            'daten' => 'Ihre Vertragsdaten',
            'sparte' => 'Sparte',
            'vertragsnummer' => 'Vertragsnummer',
            'verlaengerung' => 'Automatische Verlängerung am',
            'frist' => 'Kündigung muss eingehen bis',
            'schluss' => 'Mit freundlichen Grüßen',
            'team' => 'Ihr Dienstly24-Team',
            'impressum' => 'Impressum',
            'datenschutz' => 'Datenschutz',
            'abbestellen_frage' => 'Keine Erinnerungen mehr erhalten?',
            'abbestellen' => 'Hier abbestellen',
            'wa_text' => 'Hallo Dienstly24, ich habe eine Erinnerung zu {meine} erhalten. Referenz: {ref}',
        ],
        'ar' => [
            'gruss' => 'مرحباً',
            'chat' => 'اكتب لنا في المحادثة',
            'whatsapp' => 'راسلنا عبر واتساب',
            'antworten' => 'أو ردّ ببساطة على هذا البريد الإلكتروني.',
            'telefon' => 'الهاتف',
            'daten' => 'بيانات عقدك',
            'sparte' => 'النوع',
            'vertragsnummer' => 'رقم العقد',
            'verlaengerung' => 'التجديد التلقائي في',
            'frist' => 'يجب أن يصل الإلغاء بحلول',
            'schluss' => 'مع أطيب التحيات،',
            'team' => 'فريق Dienstly24',
            'impressum' => 'بيانات الناشر',
            'datenschutz' => 'سياسة الخصوصية',
            'abbestellen_frage' => 'لا ترغب في تلقي التذكيرات؟',
            'abbestellen' => 'إلغاء الاشتراك من هنا',
            'wa_text' => 'مرحباً Dienstly24، وصلني تذكير بخصوص {meine}. المرجع: {ref}',
        ],
    ];

    /** @return array{betreff: string, absaetze: list<string>}|null */
    public static function fuer(string $vorlage, string $variante, string $sprache, array $werte): ?array
    {
        $sprache = $sprache === 'ar' ? 'ar' : 'de';
        $satz = self::TEXTE[$vorlage][$sprache] ?? null;
        if ($satz === null || ! isset($satz[$variante])) {
            return null;
        }
        $ersetzen = static fn (string $text): string => strtr($text, $werte);

        return [
            'betreff' => $ersetzen($satz['betreff']),
            'absaetze' => array_map($ersetzen, $satz[$variante]),
        ];
    }

    public static function rahmen(string $sprache): array
    {
        return self::RAHMEN[$sprache === 'ar' ? 'ar' : 'de'];
    }
}
