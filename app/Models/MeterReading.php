<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eine Zaehlerstands-Ablesung eines Energievertrags (Betreiber-Vorgabe
 * 29.07.2026). Jede Meldung - Mitarbeiter, Kunde im Portal oder automatisch
 * aus einem hochgeladenen Zaehlerfoto - wird als eigene Zeile gespeichert;
 * der Verlauf bleibt vollstaendig erhalten und ergibt die Verbrauchshistorie.
 *
 * Analog zu VehicleMileageReading (Kilometerstand), hier aber mit Register
 * (OBIS-Kennzahl), weil ein Zweirichtungszaehler Bezug UND Einspeisung zaehlt.
 */
class MeterReading extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_energy_detail_id', 'meter_number', 'register', 'reading', 'unit',
        'reading_date', 'captured_at', 'source', 'document_id', 'created_by', 'note',
    ];
    protected $casts = [
        'reading_date' => 'date',
        'captured_at' => 'datetime',
        'reading' => 'decimal:3',
    ];

    public const SOURCES = [
        'staff' => 'Beraterwelt',
        'customer' => 'Kundenportal',
        'document' => 'Zaehlerfoto',
    ];

    /**
     * Zaehlwerke (OBIS). Bezug ist der Normalfall; die Einspeisung faellt nur
     * bei Zweirichtungszaehlern (PV-Anlage) an und wird getrennt gefuehrt.
     */
    public const REGISTERS = [
        '1.8.0' => 'Bezug (gesamt)',
        '1.8.1' => 'Bezug HT (Hochtarif)',
        '1.8.2' => 'Bezug NT (Niedertarif)',
        '2.8.0' => 'Einspeisung',
    ];

    public const REGISTER_DEFAULT = '1.8.0';

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function energyDetail()
    {
        return $this->belongsTo(ContractEnergyDetail::class, 'contract_energy_detail_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ($this->source ?: '—');
    }

    public function registerLabel(): string
    {
        return self::REGISTERS[$this->register] ?? ($this->register ?: '—');
    }

    /** Einspeisung (PV) statt Bezug - wird nie mit dem Verbrauch vermischt. */
    public function isFeedIn(): bool
    {
        return $this->register === '2.8.0';
    }

    /** Zaehlerstand fuer die Anzeige, z.B. "4.680,5 kWh". */
    public function formatted(): string
    {
        return self::formatValue((float) $this->reading, $this->unit ?: 'kWh');
    }

    /** Einheitliche Zahlendarstellung fuer Staende und Verbraeuche. */
    public static function formatValue(float $value, string $unit = 'kWh'): string
    {
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 1;
        return number_format($value, $decimals, ',', '.').' '.$unit;
    }
}
