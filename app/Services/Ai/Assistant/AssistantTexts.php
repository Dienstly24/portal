<?php

namespace App\Services\Ai\Assistant;

use App\Models\SystemSetting;

/**
 * Textbausteine des Assistenten - vom Betreiber aenderbar (Abschnitt 65).
 *
 * WARUM NICHT EINFACH DIE KONSTANTEN ERSETZEN: die Texte in
 * `AssistantReplies` sind dreisprachig und sorgfaeltig formuliert. Sie
 * bleiben die VORGABE; die Oberflaeche legt bei Bedarf einen eigenen Text
 * darueber. Wer nichts aendert, bekommt weiterhin den geprueften Text -
 * und ein leer gespeichertes Feld heisst "wieder die Vorgabe", nicht
 * "der Kunde bekommt eine leere Nachricht".
 *
 * DREISPRACHIG bleibt Pflicht: der Text folgt der ERKANNTEN Sprache der
 * Kundennachricht, nicht der Oberflaechensprache. Ein Betreiber, der nur
 * die deutsche Fassung pflegt, bekommt fuer Arabisch weiter die Vorgabe -
 * nie einen deutschen Text an einen arabisch schreibenden Kunden.
 */
class AssistantTexts
{
    public const GREETING = 'greeting';
    public const OUT_OF_OFFICE = 'out_of_office';
    public const WAITING = 'waiting';
    public const HANDOVER = 'handover';
    public const OUT_OF_SCOPE = 'out_of_scope';
    public const FALLBACK = 'fallback';
    public const LIMIT = 'limit';

    /** Sprachen, die gepflegt werden koennen. */
    public const LANGUAGES = ['de', 'en', 'ar'];

    public const LABELS = [
        self::GREETING => 'Begrüßung beim ersten Kontakt',
        self::OUT_OF_OFFICE => 'Außerhalb der Geschäftszeiten',
        self::WAITING => 'Wartehinweis',
        self::HANDOVER => 'Übergabe an das Team',
        self::OUT_OF_SCOPE => 'Anfrage außerhalb des Kundenservice',
        self::FALLBACK => 'KI-Dienst nicht verfügbar',
        self::LIMIT => 'Grenze automatischer Antworten erreicht',
    ];

    /**
     * Vorgaben. Die vier bestehenden kommen aus `AssistantReplies` -
     * EINE Quelle, damit sie nicht auseinanderlaufen.
     *
     * @return array<string,array<string,string>>
     */
    public static function defaults(): array
    {
        return [
            self::GREETING => [
                'de' => 'Guten Tag! Ich bin der digitale Assistent von Dienstly24. '
                    .'Wie kann ich Ihnen helfen?',
                'en' => 'Hello! I am the digital assistant of Dienstly24. How can I help you?',
                'ar' => 'مرحباً! أنا المساعد الرقمي لدى Dienstly24. كيف يمكنني مساعدتك؟',
            ],
            self::OUT_OF_OFFICE => [
                'de' => 'Vielen Dank für Ihre Nachricht. Wir haben sie erhalten – unser Team '
                    .'meldet sich zu den nächsten Geschäftszeiten bei Ihnen.',
                'en' => 'Thank you for your message. We have received it – our team will get '
                    .'back to you during our next business hours.',
                'ar' => 'شكراً لرسالتك. لقد استلمناها – وسيتواصل معك فريقنا خلال ساعات العمل التالية.',
            ],
            self::WAITING => [
                'de' => 'Einen Moment bitte – ich sehe das für Sie nach.',
                'en' => 'One moment please – I am looking into this for you.',
                'ar' => 'لحظة من فضلك – أتحقق من ذلك لك.',
            ],
            self::HANDOVER => AssistantReplies::HANDOVER,
            self::OUT_OF_SCOPE => AssistantReplies::OUT_OF_SCOPE,
            self::FALLBACK => AssistantReplies::FALLBACK,
            self::LIMIT => AssistantReplies::LIMIT,
        ];
    }

    /** Der geltende Text in der erkannten Sprache. */
    public function get(string $key, string $language = 'de'): string
    {
        $eigener = trim((string) SystemSetting::get($this->settingKey($key, $language), ''));
        if ($eigener !== '') {
            return $eigener;
        }

        $vorgaben = self::defaults()[$key] ?? [];

        // Deutsch ist der Rueckfall - Haussprache des Portals.
        return $vorgaben[$language] ?? $vorgaben['de'] ?? '';
    }

    /**
     * Alle Texte fuer die Pflegeseite: Vorgabe und etwaige eigene Fassung
     * getrennt, damit der Betreiber SIEHT, was er ueberschreibt.
     *
     * @return array<string,array<string,array{default:string,custom:string}>>
     */
    public function all(): array
    {
        $alle = [];
        foreach (self::defaults() as $key => $sprachen) {
            foreach (self::LANGUAGES as $sprache) {
                $alle[$key][$sprache] = [
                    'default' => $sprachen[$sprache] ?? ($sprachen['de'] ?? ''),
                    'custom' => (string) SystemSetting::get($this->settingKey($key, $sprache), ''),
                ];
            }
        }

        return $alle;
    }

    /**
     * Eigene Fassung speichern. Leer = zurueck auf die Vorgabe; deshalb
     * wird der leere Wert gespeichert und nicht etwa der Vorgabetext
     * hineinkopiert - sonst waere die Vorgabe ab dem ersten Speichern
     * eingefroren und spaetere Verbesserungen kaemen nie an.
     */
    public function put(string $key, string $language, string $text): void
    {
        if (! isset(self::LABELS[$key]) || ! in_array($language, self::LANGUAGES, true)) {
            return;
        }

        SystemSetting::set($this->settingKey($key, $language), trim($text));
    }

    private function settingKey(string $key, string $language): string
    {
        return 'ai_text_'.$key.'_'.$language;
    }
}
