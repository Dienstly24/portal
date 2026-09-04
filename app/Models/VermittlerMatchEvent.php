<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Historie der Zuordnung: wann und wie ein Vertrag mit der Abrechnung des
 * Vermittlers verknuepft wurde. Referenz-Nr. und Vermittler-ID stehen
 * ZUSAETZLICH im Klartext, damit die Historie das Loeschen des Vertrags
 * ueberlebt (contract_id wird dann null, der Eintrag bleibt lesbar).
 */
class VermittlerMatchEvent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public const UPDATED_AT = null;

    protected $fillable = [
        'contract_id', 'reference_number', 'vermittler_id', 'action',
        'detail', 'import_id', 'user_id',
    ];

    public const ACTIONS = [
        'reference_stored' => 'Referenz-Nr. hinterlegt',
        'reference_changed' => 'Referenz-Nr. geändert',
        'id_linked' => 'Vermittler-ID zugeordnet',
        'id_changed' => 'Vermittler-ID geändert',
        'id_unlinked' => 'Vermittler-ID entfernt',
        'imported' => 'In Abrechnung gefunden',
        'status_changed' => 'Abrechnungsstatus geändert',
        'conflict' => 'Prüfung erforderlich',
        'not_found' => 'Nicht in Abrechnung gefunden',
        'manual_link' => 'Manuell zugeordnet',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            $m->created_at = $m->created_at ?: now();
        });
    }

    public function contract() { return $this->belongsTo(Contract::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function import() { return $this->belongsTo(VermittlerImport::class, 'import_id'); }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** Einen Historien-Eintrag schreiben (eine Stelle fuer alle Schreibwege). */
    public static function record(string $action, array $data = []): self
    {
        return static::create(array_merge(['action' => $action], $data));
    }
}
