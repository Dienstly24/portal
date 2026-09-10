<?php

namespace App\Models;

use App\Support\SignatureFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein Feld auf dem Dokument: Art, Seite, Platz - und wem es gehoert.
 *
 * Die Position ist ANTEILIG zur Seite (0..1). Ein Pixelwert waere an einen
 * Bildschirm gebunden; auf dem Telefon des Unterzeichners stuende das Feld
 * dann woanders als im Editor des Mitarbeiters.
 */
class SignatureField extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'signature_request_id', 'signature_signer_id', 'type', 'page',
        'pos_x', 'pos_y', 'width', 'height', 'required', 'label', 'value', 'image_path', 'filled_at', 'sort',
        'company_asset_id',
    ];

    protected $casts = [
        'page' => 'integer',
        'pos_x' => 'float',
        'pos_y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'required' => 'boolean',
        'sort' => 'integer',
        'filled_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<SignatureRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    /** @return BelongsTo<SignatureSigner, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }

    public function typeLabel(): string
    {
        return SignatureFieldType::label((string) $this->type);
    }

    public function isDrawn(): bool
    {
        return SignatureFieldType::isDrawn((string) $this->type);
    }

    /** Ein Firmenbild - gehoert keinem Unterzeichner. */
    public function isCompany(): bool
    {
        return $this->type === SignatureFieldType::COMPANY;
    }

    /** @return BelongsTo<CompanySignatureAsset, $this> */
    public function companyAsset(): BelongsTo
    {
        return $this->belongsTo(CompanySignatureAsset::class, 'company_asset_id');
    }

    public function isFilled(): bool
    {
        // Ein Firmenbild ist fertig, sobald es zugewiesen ist: es wartet auf
        // niemanden. Es blockiert deshalb auch keinen Versand und taucht in
        // keiner "noch offen"-Liste auf.
        if ($this->isCompany()) {
            return $this->company_asset_id !== null;
        }

        return $this->filled_at !== null
            && ($this->isDrawn() ? $this->image_path !== null : ($this->value ?? '') !== '');
    }
}
