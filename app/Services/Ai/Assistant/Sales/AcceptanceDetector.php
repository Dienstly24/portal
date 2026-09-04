<?php

namespace App\Services\Ai\Assistant\Sales;

/**
 * Zustimmung des Kunden zu einem Angebot (Spezifikation Abschnitt 4).
 *
 * WICHTIG - die Rollenverteilung: die Entscheidung trifft das MODELL,
 * weil "das passt so" nur im Gespraechszusammenhang Zustimmung bedeutet.
 * Diese Klasse ist das Sicherheitsnetz fuer die eindeutigen Faelle, damit
 * ein klares "ich nehme Angebot B" nicht daran scheitert, dass das Modell
 * das Werkzeug vergisst.
 *
 * Zwei Regeln machen sie ungefaehrlich:
 *  - Sie greift NUR, wenn ein Angebot vorliegt und auf die Entscheidung
 *    gewartet wird.
 *  - Eine VERNEINUNG schlaegt jede Zustimmung ("nein, doch nicht" wird
 *    nie als Zusage gelesen).
 */
class AcceptanceDetector
{
    private const ACCEPT = [
        'ja', 'jawohl', 'passt', 'passt so', 'einverstanden', 'ok', 'okay', 'in ordnung',
        'gerne', 'sehr gerne', 'perfekt', 'super', 'das nehme ich', 'ich nehme',
        'nehme ich', 'gefaellt mir', 'gefällt mir', 'gut so', 'machen wir',
        'weiter machen', 'weitermachen', 'lass uns weitermachen', 'ich moechte das',
        'ich möchte das', 'ich stimme zu', 'akzeptiere', 'abschliessen', 'abschließen',
        'bestellen', 'beauftragen', 'vertrag machen', 'ich will das',
        'yes', 'agreed', 'sounds good', 'i accept', 'i want this', 'lets do it',
        'موافق', 'أوافق', 'تمام', 'مناسب', 'ماشي', 'اقبل', 'أقبل', 'نكمل', 'اريد هذا',
        'أريد هذا', 'العرض عجبني', 'خلاص',
    ];

    private const REJECT = [
        'nein', 'kein interesse', 'doch nicht', 'lieber nicht', 'zu teuer', 'ueberlegen',
        'überlegen', 'spaeter', 'später', 'noch nicht', 'nicht jetzt', 'abbrechen',
        'no', 'not interested', 'too expensive', 'later', 'cancel',
        'لا', 'لست مهتم', 'غالي', 'ليس الآن', 'لاحقا', 'لاحقاً',
    ];

    /**
     * @return array{accepted: bool, rejected: bool, label: ?string}
     *         label = ausdruecklich genanntes Angebot ("A"/"B"), falls
     *                 der Kunde eines benannt hat
     */
    public function check(string $message, array $offerLabels = []): array
    {
        $text = $this->normalize($message);

        if ($this->hits($text, self::REJECT)) {
            return ['accepted' => false, 'rejected' => true, 'label' => null];
        }

        $label = null;
        foreach ($offerLabels as $kandidat) {
            // "Angebot B" / "Option B" / "B nehme ich" - aber nie ein
            // zufaelliges Wort, das den Buchstaben enthaelt.
            if (preg_match('/\b(?:angebot|option|offer|variante|عرض)\s*'.preg_quote(mb_strtolower($kandidat), '/').'\b/u', $text)
                || preg_match('/\b'.preg_quote(mb_strtolower($kandidat), '/').'\b/u', $text)) {
                $label = $kandidat;
                break;
            }
        }

        $zustimmung = $this->hits($text, self::ACCEPT);

        // Ein blosser Angebotsname ohne Zustimmungswort ist eine
        // Rueckfrage ("was kostet B?"), keine Zusage.
        return [
            'accepted' => $zustimmung,
            'rejected' => false,
            'label' => $zustimmung ? $label : null,
        ];
    }

    private function hits(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/(?:^|[^\p{L}])'.preg_quote($needle, '/').'(?:[^\p{L}]|$)/u', $text)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));

        return $text.' '.str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
    }
}
