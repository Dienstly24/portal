<?php

namespace App\Services\Haushalt;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerTimeline;
use App\Models\Haushalt;
use App\Models\HaushaltMitglied;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Die EINE Stelle, an der ein Haushalt entsteht und sich aendert (PR-5b).
 *
 * Regeln:
 *  - Ein Kunde gehoert hoechstens zu EINEM aktuellen Haushalt.
 *  - Nichts wird geloescht: Austragen setzt den Auszugstag, die Zeile bleibt.
 *  - Hauptansprechpartner gibt es hoechstens EINEN (aktuellen); verlaesst er
 *    den Haushalt, bleibt die Stelle LEER und die Karte sagt es - wer
 *    nachrueckt, entscheidet ein Mensch, nie das System.
 *  - Beitragszahler darf es mehrere geben (Paar mit gemeinsamem Konto).
 *  - Die Akten bleiben vollstaendig eigenstaendig; es wird kein Feld kopiert.
 */
class HaushaltService
{
    /** Neuen Haushalt mit dem Kunden als erstem Mitglied und Hauptansprechpartner gruenden. */
    public function gruenden(Customer $kunde, ?string $name = null, ?int $by = null, string $herkunft = Haushalt::HERKUNFT_MANUELL): Haushalt
    {
        $this->pruefeFrei($kunde);

        $haushalt = DB::transaction(function () use ($kunde, $name, $by, $herkunft) {
            $haushalt = Haushalt::create([
                'name' => $this->clean($name, 120),
                'herkunft' => $herkunft,
                'created_by' => $by,
            ]);
            HaushaltMitglied::create([
                'haushalt_id' => $haushalt->id,
                'customer_id' => $kunde->id,
                // Bei einer Uebernahme aus dem Bestand wird NIEMAND bestimmt.
                'hauptansprechpartner' => $herkunft === Haushalt::HERKUNFT_MANUELL,
                'valid_from' => $herkunft === Haushalt::HERKUNFT_MANUELL ? Carbon::today() : null,
                'created_by' => $by,
            ]);

            return $haushalt;
        });

        $this->vermerke($kunde, 'Haushalt gegründet', 'Neuer Haushalt angelegt.', $by);
        ActivityLog::record('haushalt_gegruendet', 'customer', (string) $kunde->id, ['haushalt_id' => $haushalt->id, 'herkunft' => $herkunft], $by);
        $this->forgetCount();

        return $haushalt;
    }

    /** Kunden in einen bestehenden Haushalt aufnehmen (Wiedereinzug oeffnet die alte Zeile). */
    public function aufnehmen(Haushalt $haushalt, Customer $kunde, ?int $by = null, bool $mitEinzugsdatum = true): HaushaltMitglied
    {
        $this->pruefeFrei($kunde);

        $mitglied = DB::transaction(function () use ($haushalt, $kunde, $by, $mitEinzugsdatum) {
            $mitglied = HaushaltMitglied::firstOrNew(['haushalt_id' => $haushalt->id, 'customer_id' => $kunde->id]);
            $mitglied->fill([
                'hauptansprechpartner' => false,
                'beitragszahler' => false,
                'valid_from' => $mitEinzugsdatum ? Carbon::today() : null,
                'valid_until' => null,
            ]);
            if (! $mitglied->exists) {
                $mitglied->created_by = $by;
            }
            $mitglied->save();

            return $mitglied;
        });

        $this->vermerke($kunde, 'Haushalt', 'In einen Haushalt aufgenommen.', $by);
        ActivityLog::record('haushalt_mitglied_aufgenommen', 'customer', (string) $kunde->id, ['haushalt_id' => $haushalt->id], $by);
        $this->forgetCount();

        return $mitglied;
    }

    /** Mitgliedschaft beenden (Auszug heute). Die Zeile bleibt als Historie. */
    public function austragen(HaushaltMitglied $mitglied, ?int $by = null): void
    {
        if (! $mitglied->istAktuell()) {
            return;
        }
        $mitglied->forceFill([
            'valid_until' => Carbon::today(),
            'hauptansprechpartner' => false,
        ])->save();

        if ($mitglied->customer) {
            $this->vermerke($mitglied->customer, 'Haushalt', 'Aus dem Haushalt ausgetragen. Die Kundenakte bleibt unverändert bestehen.', $by);
        }
        ActivityLog::record('haushalt_mitglied_ausgetragen', 'customer', (string) $mitglied->customer_id, ['haushalt_id' => $mitglied->haushalt_id], $by);
        $this->forgetCount();
    }

    /** Hauptansprechpartner festlegen - genau einer je Haushalt. */
    public function hauptansprechpartnerSetzen(HaushaltMitglied $mitglied, ?int $by = null): void
    {
        if (! $mitglied->istAktuell()) {
            throw new \InvalidArgumentException('Nur ein aktuelles Mitglied kann Hauptansprechpartner sein.');
        }
        DB::transaction(function () use ($mitglied) {
            HaushaltMitglied::where('haushalt_id', $mitglied->haushalt_id)
                ->whereKeyNot($mitglied->getKey())
                ->update(['hauptansprechpartner' => false]);
            $mitglied->forceFill(['hauptansprechpartner' => true])->save();
        });
        ActivityLog::record('haushalt_hauptansprechpartner', 'customer', (string) $mitglied->customer_id, ['haushalt_id' => $mitglied->haushalt_id], $by);
    }

    /** Beitragszahler an/aus (mehrere erlaubt). */
    public function beitragszahlerUmschalten(HaushaltMitglied $mitglied, ?int $by = null): void
    {
        if (! $mitglied->istAktuell()) {
            throw new \InvalidArgumentException('Nur ein aktuelles Mitglied kann Beitragszahler sein.');
        }
        $mitglied->forceFill(['beitragszahler' => ! $mitglied->beitragszahler])->save();
        ActivityLog::record('haushalt_beitragszahler', 'customer', (string) $mitglied->customer_id, [
            'haushalt_id' => $mitglied->haushalt_id, 'beitragszahler' => $mitglied->beitragszahler,
        ], $by);
    }

    /** Aktuelle Mitgliedschaften eines Kunden (normal 0 oder 1; mehr nur nach einer Zusammenfuehrung). */
    public function aktuelleMitgliedschaften(Customer $kunde): Collection
    {
        return HaushaltMitglied::aktuell()->where('customer_id', $kunde->id)->with('haushalt')->get();
    }

    /**
     * Daten fuer die Karte "Haushalt" in der Kundenakte.
     *
     * Portfolio-Scope: Mitglieder, die der Bearbeiter nicht sehen darf,
     * erscheinen nur als ANZAHL - nie mit Namen, nie mit Vertraegen.
     *
     * @param  array<int, string>|null  $sichtbar  null = alle sichtbar
     * @return array<string, mixed>|null
     */
    public function uebersicht(Customer $kunde, ?array $sichtbar = null): ?array
    {
        $mitgliedschaften = $this->aktuelleMitgliedschaften($kunde);
        if ($mitgliedschaften->isEmpty()) {
            return null;
        }
        $haushalt = $mitgliedschaften->first()->haushalt;
        $alle = $haushalt->aktuelleMitglieder()->with('customer.user')->get()
            ->filter(fn (HaushaltMitglied $m) => $m->customer !== null)
            ->sortBy(fn (HaushaltMitglied $m) => [! $m->hauptansprechpartner, (string) ($m->customer->birth_date ?? '9999-12-31')])
            ->values();
        $mitglieder = $sichtbar === null
            ? $alle
            : $alle->filter(fn (HaushaltMitglied $m) => in_array((string) $m->customer_id, $sichtbar, true))->values();
        $ids = $mitglieder->pluck('customer_id')->map(fn ($id) => (string) $id)->all();

        $vertraege = Contract::query()->with('customer.user')
            ->whereIn('customer_id', $ids)->currentlyActive()->ownPortfolio()
            ->orderBy('type')->get();
        $fremd = Contract::query()->whereIn('customer_id', $ids)->currentlyActive()
            ->whereNotIn('id', $vertraege->pluck('id'))->count();

        $warnungen = [];
        if (! $alle->contains(fn (HaushaltMitglied $m) => $m->hauptansprechpartner)) {
            $warnungen[] = 'Kein Hauptansprechpartner festgelegt.';
        }
        $anschriften = $mitglieder->map(fn (HaushaltMitglied $m) => $m->customer->householdKey())
            ->filter()->unique();
        if ($anschriften->count() > 1) {
            $warnungen[] = 'Die Mitglieder haben unterschiedliche Anschriften - bitte prüfen, ob alle noch zusammen wohnen.';
        }
        if ($mitgliedschaften->count() > 1) {
            $warnungen[] = 'Diese Akte gehört zu '.$mitgliedschaften->count().' Haushalten (Folge einer Zusammenführung) - bitte einen davon austragen.';
        }

        return [
            'haushalt' => $haushalt,
            'mitglieder' => $mitglieder,
            'verborgen' => $alle->count() - $mitglieder->count(),
            'vertraege' => $vertraege,
            'monatsbeitrag' => round($vertraege->sum(fn (Contract $c) => $c->monthlyPremium()), 2),
            'fremdvertraege' => $fremd,
            'warnungen' => $warnungen,
        ];
    }

    /**
     * Paare, die HEUTE im selben Haushalt leben, als "a|b"-Schluessel (a<b).
     * Ein Haushalt ist keine Dublette - die Dubletten-Pruefung blendet sie aus.
     *
     * @return array<string, bool>
     */
    public static function paarSchluessel(): array
    {
        $set = [];
        $gruppen = HaushaltMitglied::aktuell()->get(['haushalt_id', 'customer_id'])->groupBy('haushalt_id');
        foreach ($gruppen as $mitglieder) {
            $ids = $mitglieder->pluck('customer_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $set[$ids[$i].'|'.$ids[$j]] = true;
                }
            }
        }

        return $set;
    }

    private function pruefeFrei(Customer $kunde): void
    {
        if (HaushaltMitglied::aktuell()->where('customer_id', $kunde->id)->exists()) {
            throw new \InvalidArgumentException('Dieser Kunde gehört bereits zu einem Haushalt. Bitte dort zuerst austragen.');
        }
    }

    private function clean(?string $text, int $max): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** Timeline-Eintrag; darf den Vorgang nie scheitern lassen. */
    private function vermerke(Customer $kunde, string $titel, string $text, ?int $by): void
    {
        try {
            CustomerTimeline::create([
                'customer_id' => $kunde->id,
                'user_id' => $by,
                'type' => 'family',
                'title' => $titel,
                'description' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Haushalt-Timeline-Eintrag fehlgeschlagen: '.$e->getMessage());
        }
    }

    private function forgetCount(): void
    {
        app(DuplicateDetectionService::class)->forgetCount();
    }
}
