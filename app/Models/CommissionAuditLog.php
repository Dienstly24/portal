<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Unveraenderliches Protokoll aller Provisions-Vorgaenge.
 *
 * WARUM EINE EIGENE TABELLE statt des allgemeinen ActivityLog: hier stehen
 * Betraege und Empfaenger - dieselbe enge Sichtbarkeit wie fuer die
 * Provisionen selbst. Es gibt aus der Oberflaeche KEINEN Loeschweg und
 * bewusst kein `update`: ein Protokoll, das man korrigieren kann, belegt
 * nichts mehr.
 */
class CommissionAuditLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_label', 'action', 'commission_id', 'contract_id',
        'internal_contract_number', 'field', 'old_value', 'new_value',
        'source_file', 'import_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    /** action => Klartext fuer die Anzeige. */
    public const ACTIONS = [
        'datei_hochgeladen' => 'Datei hochgeladen',
        'import_bestaetigt' => 'Import bestätigt',
        'import_verworfen' => 'Import verworfen',
        'provision_angelegt' => 'Provision angelegt',
        'provision_aktualisiert' => 'Provision aktualisiert',
        'provision_geaendert' => 'Provision bearbeitet',
        'status_geaendert' => 'Status geändert',
        'zahlung_erfasst' => 'Zahlung erfasst',
        'provision_storniert' => 'Provision storniert',
        'vertrag_zugeordnet' => 'Vertrag zugeordnet',
        'zuordnung_geloest' => 'Zuordnung gelöst',
        'rechnung_verknuepft' => 'Rechnung verknüpft',
        'rechnung_geloest' => 'Rechnungsverknüpfung gelöst',
        'vertragsnummer_geaendert' => 'Interne Vertragsnummer geändert',
        'export' => 'Daten exportiert',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            $m->created_at = $m->created_at ?: now();
        });
    }

    public function user() { return $this->belongsTo(User::class); }
    public function commission() { return $this->belongsTo(ContractCommission::class, 'commission_id'); }
    public function contract() { return $this->belongsTo(Contract::class); }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
