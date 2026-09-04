<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Unveraenderliches Audit-Log einer Provision (Provisions-Management):
 * jede Anlage, Betrags-Anpassung, Freigabe, Auszahlung und Storno-
 * Gegenbuchung mit Nutzer, Zeitpunkt, altem/neuem Wert und Grund.
 * user_id null = System (automatische Buchung).
 */
class ProvisionAuditLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'provision_id', 'user_id', 'action', 'field',
        'old_value', 'new_value', 'reason', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACTIONS = [
        'created' => 'Angelegt',
        'amount_changed' => 'Betrag geaendert',
        'status_changed' => 'Status geaendert',
        'storno_created' => 'Gegenbuchung erzeugt',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            $m->created_at = $m->created_at ?: now();
        });
    }

    public function provision() { return $this->belongsTo(Provision::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /**
     * Zentrale Schreibstelle: haelt das Log konsistent (eine Signatur fuer
     * alle Aufrufer). $userId null = angemeldeter Nutzer, sonst System.
     */
    public static function write(
        Provision $provision,
        string $action,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $reason = null,
        ?int $userId = null,
    ): self {
        return static::create([
            'provision_id' => $provision->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue !== null ? mb_substr($oldValue, 0, 500) : null,
            'new_value' => $newValue !== null ? mb_substr($newValue, 0, 500) : null,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ]);
    }
}
