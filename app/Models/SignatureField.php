<?php

namespace App\Models;

use App\Support\SignatureFieldType;
use Illuminate\Database\Eloquent\Model;
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

    public function request()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function signer()
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

    public function isFilled(): bool
    {
        return $this->filled_at !== null
            && ($this->isDrawn() ? $this->image_path !== null : ($this->value ?? '') !== '');
    }
}
