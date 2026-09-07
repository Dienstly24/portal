<?php

namespace App\Support;

use App\Models\SystemSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Geschaeftszeiten (Auftrag Abschnitt 64).
 *
 * DIE ZEITZONEN-FALLE, an der so etwas fast immer scheitert: gespeicherte
 * Zeitstempel sind UTC (`app.timezone`), der Betrieb sitzt in Deutschland.
 * "09:00 bis 18:00" meint ORTSZEIT. Wer `now()` roh gegen diese Zeiten
 * haelt, sperrt im Sommer zwei Stunden zu frueh auf und zu - und zwei
 * Stunden Abweichung sehen plausibel aus, deshalb faellt es niemandem
 * auf. Verglichen wird deshalb IMMER in `app.display_timezone`
 * (dieselbe Regel wie bei jeder Anzeige, 21.08.2026).
 *
 * BEWUSST NICHT GEBAUT: Feiertage und Urlaubszeiten. Eine halbe
 * Feiertagsliste waere schlechter als keine - sie wuerde an Ostern
 * "geoeffnet" behaupten. Bis dahin bleibt der Hauptschalter der
 * ehrliche Weg.
 */
class BusinessHours
{
    public const SETTING = 'ai_business_hours';
    public const ENABLED = 'ai_business_hours_enabled';

    /** Reihenfolge der Anzeige - Montag zuerst, wie im deutschen Kalender. */
    public const DAYS = [
        'mon' => 'Montag',
        'tue' => 'Dienstag',
        'wed' => 'Mittwoch',
        'thu' => 'Donnerstag',
        'fri' => 'Freitag',
        'sat' => 'Samstag',
        'sun' => 'Sonntag',
    ];

    /**
     * Werden die Geschaeftszeiten ueberhaupt beachtet?
     *
     * Voreinstellung AUS. Eine neue Regel, die sich selbst einschaltet,
     * wuerde das Verhalten des laufenden Betriebs still veraendern - und
     * zwar in Richtung "die KI antwortet nachts nicht mehr", also genau
     * die Art Aenderung, die als Stoerung gemeldet wird.
     */
    public function enforced(): bool
    {
        return (string) SystemSetting::get(self::ENABLED, '0') === '1';
    }

    /**
     * Gepflegte Zeiten je Wochentag.
     *
     * @return array<string,array{open:bool,from:string,to:string}>
     */
    public function schedule(): array
    {
        $roh = SystemSetting::get(self::SETTING, null);
        $roh = is_string($roh) ? json_decode($roh, true) : $roh;
        $roh = is_array($roh) ? $roh : [];

        $plan = [];
        foreach (array_keys(self::DAYS) as $tag) {
            $eintrag = is_array($roh[$tag] ?? null) ? $roh[$tag] : [];
            $plan[$tag] = [
                // Ohne Pflege gilt Mo-Fr 09:00-18:00 als plausible Vorgabe.
                // Sie wirkt nur, wenn der Hauptschalter an ist.
                'open' => (bool) ($eintrag['open'] ?? ! in_array($tag, ['sat', 'sun'], true)),
                'from' => $this->time($eintrag['from'] ?? '09:00'),
                'to' => $this->time($eintrag['to'] ?? '18:00'),
            ];
        }

        return $plan;
    }

    /** @param array<string,mixed> $eingabe */
    public function save(array $eingabe): void
    {
        $plan = [];
        foreach (array_keys(self::DAYS) as $tag) {
            $eintrag = is_array($eingabe[$tag] ?? null) ? $eingabe[$tag] : [];
            $plan[$tag] = [
                'open' => (bool) ($eintrag['open'] ?? false),
                'from' => $this->time($eintrag['from'] ?? '09:00'),
                'to' => $this->time($eintrag['to'] ?? '18:00'),
            ];
        }

        SystemSetting::set(self::SETTING, json_encode($plan));
    }

    /**
     * Ist gerade geoeffnet?
     *
     * Ohne Hauptschalter gilt IMMER "geoeffnet" - dann wirkt die Regel
     * nirgends, statt still zu sperren.
     */
    public function open(?CarbonInterface $at = null): bool
    {
        if (! $this->enforced()) {
            return true;
        }

        // HIER liegt der Kern: Ortszeit, nicht UTC.
        $zeitpunkt = Carbon::instance($at ? $at->toDateTime() : now()->toDateTime())
            ->setTimezone(config('app.display_timezone', 'Europe/Berlin'));

        $tag = strtolower($zeitpunkt->format('D'));
        $tag = substr($tag, 0, 3);
        $plan = $this->schedule()[$tag] ?? null;

        if (! $plan || ! $plan['open']) {
            return false;
        }

        $jetzt = $zeitpunkt->format('H:i');

        // Ein Zeitraum, dessen Ende VOR dem Anfang liegt (z.B. 20:00-02:00),
        // laeuft ueber Mitternacht. Ohne diesen Fall waere so ein Tag
        // dauerhaft "geschlossen" - und niemand saehe warum.
        if ($plan['to'] <= $plan['from']) {
            return $jetzt >= $plan['from'] || $jetzt < $plan['to'];
        }

        return $jetzt >= $plan['from'] && $jetzt < $plan['to'];
    }

    public function closed(?CarbonInterface $at = null): bool
    {
        return ! $this->open($at);
    }

    /** "HH:MM" erzwingen - kaputte Eingaben werden nie gespeichert. */
    private function time(mixed $wert): string
    {
        $wert = trim((string) $wert);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $wert) ? $wert : '09:00';
    }
}
