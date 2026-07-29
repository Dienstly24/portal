<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractEnergyDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_id','tariff','consumption_kwh','meter_number','malo_id',
        'meter_reading','grid_operator','metering_operator','payment_amount','payment_interval',
        // Kundennummer beim Energieanbieter (separat von der Vertragsnummer,
        // die am Vertrag selbst haengt) - Betreiber-Vorgabe fuer Energievertraege.
        'customer_number',
        // Vorversorger (bisheriger Lieferant beim Wechsel) + dessen Kundennummer.
        'previous_provider','previous_customer_number',
        // Tarifpreise: Arbeitspreis (ct/kWh) und Grundpreis (EUR/Monat).
        'working_price','base_price',
    ];
    protected $casts = [
        'working_price' => 'decimal:3',
        'base_price'    => 'decimal:2',
        'payment_amount'=> 'decimal:2',
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
        // Die normalisierte Zaehlernummer ist die Grundlage der Zuordnung
        // "Zaehlerfoto -> Vertrag -> Kunde" und wird immer mitgefuehrt, egal
        // ueber welchen Weg (Formular, Import, Dokumenteneingang) die Nummer
        // gesetzt wurde.
        static::saving(function ($m) {
            if ($m->isDirty('meter_number')) {
                $m->meter_number_normalized = self::normalizeMeterNumber($m->meter_number);
            }
        });
    }
    public function contract() { return $this->belongsTo(Contract::class); }

    /** Zaehlerstands-Historie, juengste Ablesung zuerst. */
    public function meterReadings() {
        return $this->hasMany(MeterReading::class)->orderByDesc('reading_date')->orderByDesc('created_at');
    }

    /**
     * Zaehlernummer fuer den Vergleich normalisieren: nur A-Z und Ziffern,
     * gross. So trifft "1 LOG00 9228 3078" dieselbe Nummer wie
     * "1LOG0092283078" - Trennzeichen auf dem Zaehler und in der
     * Vertragsbestaetigung unterscheiden sich regelmaessig.
     */
    public static function normalizeMeterNumber(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }
        $n = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($number)));
        return $n !== '' ? $n : null;
    }

    /** Juengste Ablesung des Zaehlwerks (Standard: Bezug). */
    public function latestMeterReading(string $register = MeterReading::REGISTER_DEFAULT): ?MeterReading
    {
        return $this->meterReadings->firstWhere('register', $register);
    }

    /**
     * Verbrauchshistorie eines Zaehlwerks: je Ablesung der Verbrauch SEIT der
     * vorherigen Ablesung, der Zeitraum in Tagen und der Tagesschnitt.
     * Juengste zuerst (Anzeige-Reihenfolge).
     *
     * Bewusst nichts geschaetzt: die erste Ablesung hat keinen Vorgaenger und
     * liefert daher keinen Verbrauch (consumption = null). Ein niedrigerer
     * Stand als zuvor (Zaehlerwechsel oder Tippfehler) wird als solcher
     * markiert statt in einen negativen Verbrauch gerechnet.
     *
     * @return list<array{reading: MeterReading, consumption: ?float, days: ?int, per_day: ?float, implausible: bool}>
     */
    public function consumptionHistory(string $register = MeterReading::REGISTER_DEFAULT): array
    {
        $chronological = $this->meterReadings
            ->where('register', $register)
            ->sortBy([['reading_date', 'asc'], ['created_at', 'asc']])
            ->values();

        $rows = [];
        $previous = null;
        foreach ($chronological as $reading) {
            $consumption = null;
            $days = null;
            $perDay = null;
            $implausible = false;

            if ($previous) {
                $delta = (float) $reading->reading - (float) $previous->reading;
                $days = (int) Carbon::parse($previous->reading_date)->diffInDays(Carbon::parse($reading->reading_date));
                if ($delta < 0) {
                    $implausible = true; // Zaehlerwechsel oder Zahlendreher
                } else {
                    $consumption = round($delta, 3);
                    $perDay = $days > 0 ? round($delta / $days, 2) : null;
                }
            }

            $rows[] = [
                'reading' => $reading,
                'consumption' => $consumption,
                'days' => $days,
                'per_day' => $perDay,
                'implausible' => $implausible,
            ];
            $previous = $reading;
        }

        return array_reverse($rows);
    }

    /**
     * Verbrauchs-Ueberblick fuer Kunde und Berater: letzter Zeitraum,
     * Tagesschnitt und - sofern der Zeitraum lang genug ist - die
     * Hochrechnung aufs Jahr im Vergleich zum vereinbarten Jahresverbrauch.
     *
     * Liefert null, solange es weniger als zwei Ablesungen gibt; die
     * Hochrechnung bleibt null, wenn der Zeitraum unter 14 Tagen liegt (zu
     * kurz fuer eine belastbare Aussage - lieber nichts sagen als raten).
     *
     * @return array{latest: MeterReading, previous: MeterReading, consumption: float, days: int, per_day: ?float, projected: ?int, expected: ?int, deviation_percent: ?int, exceeded: ?bool}|null
     */
    public function consumptionStatus(string $register = MeterReading::REGISTER_DEFAULT): ?array
    {
        $history = $this->consumptionHistory($register);
        $current = $history[0] ?? null;
        if (!$current || $current['consumption'] === null || $current['days'] === null) {
            return null;
        }

        $chronological = $this->meterReadings->where('register', $register)
            ->sortBy([['reading_date', 'asc'], ['created_at', 'asc']])->values();
        $latest = $current['reading'];
        $previous = $chronological[$chronological->count() - 2] ?? null;
        if (!$previous) {
            return null;
        }

        $days = $current['days'];
        $consumption = $current['consumption'];
        $projected = $days >= 14 ? (int) round($consumption / $days * 365) : null;
        $expected = $this->consumption_kwh ? (int) $this->consumption_kwh : null;

        $deviation = null;
        $exceeded = null;
        if ($projected !== null && $expected) {
            $deviation = (int) round(($projected - $expected) / $expected * 100);
            $exceeded = $projected > $expected;
        }

        return [
            'latest' => $latest,
            'previous' => $previous,
            'consumption' => $consumption,
            'days' => $days,
            'per_day' => $current['per_day'],
            'projected' => $projected,
            'expected' => $expected,
            'deviation_percent' => $deviation,
            'exceeded' => $exceeded,
        ];
    }

    /**
     * Geschaetzte Kosten eines Verbrauchs in Euro - nur wenn der Arbeitspreis
     * (ct/kWh) im Vertrag gepflegt ist. Der Grundpreis bleibt bewusst aussen
     * vor: er faellt zeitabhaengig an, nicht je kWh.
     */
    public function estimatedCost(?float $kwh): ?float
    {
        if ($kwh === null || $this->working_price === null) {
            return null;
        }
        return round($kwh * (float) $this->working_price / 100, 2);
    }

    /**
     * Einheit der Ablesungen: was zuletzt erfasst wurde, sonst die uebliche
     * Einheit der Sparte (Gaszaehler zaehlen Kubikmeter, Stromzaehler kWh).
     */
    public function readingUnit(string $register = MeterReading::REGISTER_DEFAULT): string
    {
        $latest = $this->latestMeterReading($register);
        if ($latest && $latest->unit) {
            return $latest->unit;
        }
        return $this->contract?->type === 'gas' ? 'm³' : 'kWh';
    }

    /** Zaehlwerke, zu denen es tatsaechlich Ablesungen gibt (Anzeige-Tabs). */
    public function registersWithReadings(): array
    {
        $registers = $this->meterReadings->pluck('register')->unique()->values()->all();
        return $registers !== [] ? $registers : [MeterReading::REGISTER_DEFAULT];
    }
}
