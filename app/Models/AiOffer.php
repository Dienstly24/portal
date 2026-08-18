<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Angebot in einem KI-Gespraech (Spezifikation Abschnitte 5 und 7).
 *
 * PHASE 1: der Mitarbeiter hinterlegt die Angebote (origin = 'employee').
 * Die KI sucht NICHTS und erfindet NICHTS - sie stellt nur vor, was hier
 * steht, erklaert den Unterschied und haelt die Entscheidung des Kunden
 * fest.
 *
 * PHASE 2 aendert daran nur das Feld origin ('api'): Zustandsmaschine,
 * Werkzeuge und Oberflaeche bleiben, wie sie sind.
 */
class AiOffer extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const ORIGIN_EMPLOYEE = 'employee';
    public const ORIGIN_API = 'api';

    protected $fillable = [
        'conversation_id', 'lead_id', 'label', 'provider', 'product', 'speed',
        'price', 'price_period', 'duration_months', 'terms', 'origin',
        'created_by', 'presented_at', 'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'presented_at' => 'datetime',
            'selected_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function conversation() { return $this->belongsTo(AiConversation::class, 'conversation_id'); }
    public function lead() { return $this->belongsTo(AiLead::class, 'lead_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function isSelected(): bool
    {
        return $this->selected_at !== null;
    }

    /**
     * Zeile fuer die KI und fuer die Beraterwelt. Bewusst dieselbe
     * Formulierung an beiden Stellen: was der Mitarbeiter liest, liest
     * auch der Kunde - so entstehen keine zwei Wahrheiten.
     */
    public function summary(): string
    {
        $teile = array_filter([
            $this->provider,
            $this->product,
            $this->speed,
            $this->price !== null
                ? number_format((float) $this->price, 2, ',', '.') . ' EUR/' . ($this->price_period ?: 'Monat')
                : null,
            $this->duration_months ? $this->duration_months . ' Monate Laufzeit' : null,
        ]);

        return 'Angebot ' . $this->label . ': ' . implode(', ', $teile)
            . ($this->terms ? ' - ' . $this->terms : '');
    }
}
