<?php

namespace App\Services\Ai\Assistant\Sales;

/**
 * Erste, KOSTENLOSE Einschaetzung des Anliegens (Abschnitte 16 und 21).
 *
 * Das ist bewusst nur ein VORSCHLAG: die verlaessliche Festlegung trifft
 * das Modell ueber das Werkzeug setConversationIntent, weil es den
 * Gespraechsverlauf kennt. Diese Klasse sorgt dafuer, dass schon die
 * allererste Nachricht eine brauchbare Kategorie hat - auch dann, wenn
 * die KI ausfaellt und ein Mitarbeiter uebernehmen muss.
 *
 * KONSERVATIV: was nicht klar erkennbar ist, bleibt eine allgemeine
 * Frage. Ein falsch geratener Verkaufsvorgang waere schlimmer als keiner.
 */
class IntentClassifier
{
    private const PATTERNS = [
        RequirementProfile::INTENT_UPGRADE => [
            'schneller', 'mehr geschwindigkeit', 'hoehere geschwindigkeit', 'höhere geschwindigkeit',
            'upgrade', 'aufstocken', 'aufwerten', 'groesseren tarif', 'größeren tarif',
            'faster', 'upgrade my', 'أسرع', 'ترقية',
        ],
        RequirementProfile::INTENT_CONTRACT_CHANGE => [
            'vertrag wechseln', 'anbieter wechseln', 'wechseln', 'kuendigen und', 'kündigen und',
            'neuen vertrag', 'vertrag aendern', 'vertrag ändern', 'anderes angebot',
            'guenstiger', 'günstiger', 'change my contract', 'switch provider',
            'تغيير العقد', 'تغيير المزود',
        ],
        RequirementProfile::INTENT_NEW_INTERNET => [
            'neuen anschluss', 'internet anschliessen', 'internet anschließen',
            'internetanschluss', 'dsl anschluss', 'glasfaser', 'neues internet',
            'internet beantragen', 'internet bestellen', 'internet angebot', 'internetangebot',
            'umgezogen', 'umzug', 'new internet', 'internet connection', 'fiber',
            'إنترنت جديد', 'تركيب إنترنت', 'عرض إنترنت',
        ],
        RequirementProfile::INTENT_TECHNICAL_SUPPORT => [
            'stoerung', 'störung', 'kein internet', 'geht nicht', 'funktioniert nicht',
            'langsam', 'ausfall', 'verbindung bricht', 'not working', 'outage', 'slow',
            'عطل', 'لا يعمل', 'انقطاع',
        ],
    ];

    /**
     * Reihenfolge zaehlt: eine Aufwertung ist auch ein Wechsel, aber der
     * spezifischere Fall gewinnt. Deshalb wird von speziell nach
     * allgemein geprueft (Reihenfolge der Liste oben).
     */
    public function classify(string $message): string
    {
        $text = $this->normalize($message);

        foreach (self::PATTERNS as $intent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $intent;
                }
            }
        }

        return RequirementProfile::INTENT_GENERAL_QUESTION;
    }

    /** Kategorie fuer die Auswertung (Abschnitt 21). */
    public function category(string $intent, bool $isCustomer): string
    {
        return match ($intent) {
            RequirementProfile::INTENT_NEW_INTERNET => $isCustomer ? 'INTERNET_OFFER' : 'NEW_CUSTOMER',
            RequirementProfile::INTENT_CONTRACT_CHANGE => 'CONTRACT_CHANGE',
            RequirementProfile::INTENT_UPGRADE => 'INTERNET_OFFER',
            RequirementProfile::INTENT_TECHNICAL_SUPPORT => 'TECHNICAL_SUPPORT',
            RequirementProfile::INTENT_HUMAN_REQUIRED => 'HUMAN_ESCALATION',
            default => $isCustomer ? 'EXISTING_CUSTOMER' : 'GENERAL_INFORMATION',
        };
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));

        return $text.' '.str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
    }
}
