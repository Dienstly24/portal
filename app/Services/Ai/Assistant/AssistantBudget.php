<?php

namespace App\Services\Ai\Assistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * EINE Kostenbremse fuer alle KI-Assistenten (Audit 15.09.2026).
 *
 * WARUM (gemessener Befund): der Portal-Assistent hatte Grenzen
 * (`rate_per_hour`, `daily_reply_limit`) - der WEBSITE-Assistent hatte
 * KEINE. Ausgerechnet der oeffentliche, unangemeldete Weg war damit
 * ungedeckelt: 20 Anfragen je Minute und IP sind 28.800 Modellaufrufe
 * am Tag aus EINER Quelle, und mit wechselnden Adressen beliebig viele.
 * Der Bot-Schutz half nicht: eine sachlich formulierte Versicherungs-
 * frage passiert den kostenlosen Vorfilter jedes Mal.
 *
 * Statt eines zweiten Systems steht die Bremse jetzt an EINER Stelle.
 * Drei Ebenen, von eng nach weit - jede kann den Modellaufruf allein
 * verhindern:
 *
 *   1. SITZUNG je Stunde   - bremst den einzelnen Besucher
 *   2. SITZUNG je Tag      - deckelt ihn hart, auch ueber Stunden
 *   3. TAGESBUDGET global  - deckelt die Summe ALLER Besucher
 *
 * Ebene 3 ist die eigentliche Zusicherung: sie ist von der Anzahl der
 * Adressen unabhaengig, ein Wechsel der IP umgeht sie also nicht.
 *
 * Alles laeuft ueber Cache/RateLimiter - dieselbe Mechanik, die der
 * Portal-Assistent schon benutzt. Es gibt bewusst KEINE neue Tabelle:
 * ein Zaehler, der taeglich verfaellt, gehoert nicht in die Datenbank.
 *
 * WAS NIE PROTOKOLLIERT WIRD: der Nachrichtentext, die Kennung des
 * Besuchers im Klartext und die IP. Ein Limit-Ereignis ist eine Aussage
 * ueber UNSERE Auslastung, nicht ueber einen Menschen.
 */
class AssistantBudget
{
    /** Bereich des Portal-Assistenten (angemeldete Kunden). */
    public const BEREICH_PORTAL = 'portal';

    /** Bereich des Website-Assistenten (oeffentlich, ohne Anmeldung). */
    public const BEREICH_WEBSITE = 'website';

    /**
     * Darf dieser Aufruf ans Modell?
     *
     * @param  string  $bereich  self::BEREICH_*
     * @param  string  $kennung  Sitzungs-/Kundenkennung (wird gehasht)
     * @return string|null  Grund im Klartext, wenn NICHT - sonst null
     */
    public function ueberschritten(string $bereich, string $kennung): ?string
    {
        $grenzen = $this->grenzen($bereich);
        $schluessel = $this->kennungsSchluessel($bereich, $kennung);

        // 1) Je Sitzung und Stunde.
        if ($grenzen['pro_stunde'] > 0) {
            $stundenKey = 'ai-budget:h:'.$schluessel;
            if (RateLimiter::tooManyAttempts($stundenKey, $grenzen['pro_stunde'])) {
                return $this->melden($bereich, 'sitzung_stunde',
                    'Zu viele Anfragen in kurzer Zeit ('.$grenzen['pro_stunde'].'/Stunde).');
            }
        }

        // 2) Je Sitzung und Tag.
        if ($grenzen['pro_tag'] > 0) {
            $tagKey = 'ai-budget:d:'.$schluessel.':'.now()->format('Y-m-d');
            if ((int) Cache::get($tagKey, 0) >= $grenzen['pro_tag']) {
                return $this->melden($bereich, 'sitzung_tag',
                    'Tagesgrenze dieser Unterhaltung erreicht ('.$grenzen['pro_tag'].').');
            }
        }

        // 3) Tagesbudget ueber ALLE Besucher dieses Bereichs. Diese Grenze
        //    ist unabhaengig von IP und Sitzung - sie ist der eigentliche
        //    Schutz vor einer unbegrenzten Rechnung.
        if ($grenzen['bereich_pro_tag'] > 0) {
            $bereichKey = $this->bereichsSchluessel($bereich);
            if ((int) Cache::get($bereichKey, 0) >= $grenzen['bereich_pro_tag']) {
                return $this->melden($bereich, 'bereich_tag',
                    'Tagesbudget des Assistenten erreicht ('.$grenzen['bereich_pro_tag'].').');
            }
        }

        return null;
    }

    /**
     * Einen tatsaechlich erfolgten Modellaufruf zaehlen.
     *
     * BEWUSST NACH der Pruefung und nur bei einem ECHTEN Aufruf: eine
     * Frage, die der kostenlose Vorfilter abfaengt, kostet nichts und
     * darf das Budget nicht verbrauchen.
     */
    public function verbrauchen(string $bereich, string $kennung): void
    {
        $grenzen = $this->grenzen($bereich);
        $schluessel = $this->kennungsSchluessel($bereich, $kennung);

        if ($grenzen['pro_stunde'] > 0) {
            RateLimiter::hit('ai-budget:h:'.$schluessel, 3600);
        }

        if ($grenzen['pro_tag'] > 0) {
            $this->hochzaehlen('ai-budget:d:'.$schluessel.':'.now()->format('Y-m-d'));
        }

        if ($grenzen['bereich_pro_tag'] > 0) {
            $this->hochzaehlen($this->bereichsSchluessel($bereich));
        }
    }

    /** Aktueller Stand des Tagesbudgets - fuer die Systemzustand-Seite. */
    public function bereichsVerbrauch(string $bereich): int
    {
        return (int) Cache::get($this->bereichsSchluessel($bereich), 0);
    }

    /** @return array{pro_stunde:int, pro_tag:int, bereich_pro_tag:int} */
    public function grenzen(string $bereich): array
    {
        if ($bereich === self::BEREICH_WEBSITE) {
            return [
                'pro_stunde' => (int) config('services.ai_assistant.website_rate_per_hour', 12),
                'pro_tag' => (int) config('services.ai_assistant.website_session_daily_limit', 40),
                'bereich_pro_tag' => (int) config('services.ai_assistant.website_daily_limit', 300),
            ];
        }

        return [
            'pro_stunde' => (int) config('services.ai_assistant.rate_per_hour', 20),
            'pro_tag' => 0, // Im Portal begrenzt bereits maxRepliesPerCase.
            'bereich_pro_tag' => (int) config('services.ai_assistant.daily_reply_limit', 500),
        ];
    }

    private function bereichsSchluessel(string $bereich): string
    {
        return 'ai-budget:bereich:'.$bereich.':'.now()->format('Y-m-d');
    }

    /**
     * Die Kennung wird GEHASHT abgelegt. Sie ist zwar schon eine
     * Zufalls-UUID, aber ein Zaehlerschluessel ist kein Ort fuer eine
     * Kennung, mit der sich ein Besucher wiederfinden laesst.
     */
    private function kennungsSchluessel(string $bereich, string $kennung): string
    {
        return $bereich.':'.substr(hash('sha256', $kennung), 0, 32);
    }

    private function hochzaehlen(string $key): void
    {
        // Erst anlegen (mit Ablauf), dann erhoehen - increment() legt in
        // manchen Cache-Treibern keinen fehlenden Schluessel an.
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);
    }

    /**
     * Limit-Ereignis protokollieren - OHNE Nachrichtentext, ohne IP,
     * ohne Klartext-Kennung. Nur Bereich und Art der Grenze.
     */
    private function melden(string $bereich, string $art, string $grund): string
    {
        Log::info('KI-Budget: Grenze erreicht', [
            'bereich' => $bereich,
            'art' => $art,
        ]);

        return $grund;
    }
}
