<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein hochgeladener Gespraechsverlauf - als ENTWURF (Phase 3).
 *
 * Zweistufig wie der Provisions-Import: erst sieht der Betreiber, was
 * erkannt wurde, dann entscheidet er. Ein Import, der sein Ergebnis
 * erst nach dem Schreiben zeigt, laesst keine Wahl mehr.
 */
class TrainingImport extends Model
{
    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_IMPORTIERT = 'importiert';
    public const STATUS_VERWORFEN = 'verworfen';

    public const STATUSES = [
        self::STATUS_ENTWURF => 'Entwurf - noch nicht übernommen',
        self::STATUS_IMPORTIERT => 'Übernommen',
        self::STATUS_VERWORFEN => 'Verworfen',
    ];

    public const SOURCE_WHATSAPP = 'whatsapp_export';

    public const SOURCES = [
        self::SOURCE_WHATSAPP => 'WhatsApp-Export (Chat exportieren)',
    ];

    protected $fillable = [
        'user_id', 'customer_id', 'file_name', 'source_type',
        'business_sender', 'status', 'stats', 'confirmed_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'confirmed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return HasMany<TrainingImportMessage, $this> */
    public function messages(): HasMany { return $this->hasMany(TrainingImportMessage::class); }
    /** @return HasMany<AiTrainingExample, $this> */
    public function examples(): HasMany { return $this->hasMany(AiTrainingExample::class); }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_ENTWURF;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Die Absender des Exports mit ihrer Anzahl.
     *
     * @return array<string,int>
     */
    public function senders(): array
    {
        return $this->stats['senders'] ?? [];
    }

    /**
     * Kann dieser Entwurf uebernommen werden?
     *
     * ZWEI Bedingungen, und beide sind fachlich: ohne Kundenakte gibt es
     * keinen Verlauf, an den die Nachrichten gehoeren; ohne die Angabe,
     * welcher Absender WIR sind, ist nicht bestimmbar, was Frage und was
     * Antwort ist. Beides zu erraten waere moeglich und beides waere
     * falsch.
     *
     * @return array<int,string> leere Liste = uebernehmbar
     */
    public function blockers(): array
    {
        $gruende = [];

        if (! $this->isDraft()) {
            $gruende[] = 'Dieser Verlauf ist bereits '.mb_strtolower($this->statusLabel()).'.';
        }
        if (! $this->customer_id) {
            $gruende[] = 'Es ist keine Kundenakte zugeordnet.';
        }
        if (! $this->business_sender) {
            $gruende[] = 'Es ist nicht festgelegt, welcher Absender der Betrieb ist.';
        }
        if ($this->messages()->count() === 0) {
            $gruende[] = 'In der Datei wurde keine Nachricht erkannt.';
        }

        return $gruende;
    }
}
