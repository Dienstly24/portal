<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generische externe Kennung (Architekturplan Abschnitt 7), polymorph
 * an Customer/Contract (später Partner). Ersetzt KEINE bestehenden
 * Felder - contracts.lexoffice_id und contracts.contract_number bleiben,
 * hier landen nur zusätzliche externe Kennungen.
 */
class ExternalReference extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['referenceable_type', 'referenceable_id', 'type', 'value', 'source'];

    public const TYPE_FONDS_FINANZ_NUMBER = 'fonds_finanz_number';
    public const TYPE_FONDS_FINANZ_DOCUMENT = 'fonds_finanz_document';
    public const TYPE_EXTERNAL_CONTRACT_NUMBER = 'contract_number_external';
    public const TYPE_PARTNER_ID = 'partner_id';

    public const TYPE_LABELS = [
        self::TYPE_FONDS_FINANZ_NUMBER => 'Fonds-Finanz-Nummer',
        self::TYPE_FONDS_FINANZ_DOCUMENT => 'Fonds-Finanz-Dokumentnummer',
        self::TYPE_EXTERNAL_CONTRACT_NUMBER => 'Externe Vertragsnummer',
        self::TYPE_PARTNER_ID => 'Partner-ID',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function referenceable()
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
