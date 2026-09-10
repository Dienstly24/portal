<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ein hinterlegtes Firmenbild: Unterschrift des Betriebs, Stempel oder Logo.
 *
 * ES IST KEIN UNTERZEICHNER. Es hat keine E-Mail, kein Token, keine
 * Zustimmung, keinen Zeitpunkt und keine IP - es ist eine Grafik, die ein
 * BERECHTIGTER MITARBEITER auf ein Dokument setzt. Das Protokoll haelt
 * genau das fest: wer sie eingesetzt hat, nicht "wer unterschrieben hat".
 *
 * @property string $id
 * @property string $name
 * @property string $type
 * @property string $path
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $hash
 * @property bool $is_default
 * @property bool $active
 * @property int|null $created_by
 */
class CompanySignatureAsset extends Model
{
    public const UNTERSCHRIFT = 'unterschrift';

    public const STEMPEL = 'stempel';

    public const LOGO = 'logo';

    public const TYPES = [
        self::UNTERSCHRIFT => 'Unternehmenssignatur',
        self::STEMPEL => 'Firmenstempel',
        self::LOGO => 'Firmenlogo',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name', 'type', 'path', 'width', 'height', 'bytes', 'hash',
        'is_default', 'active', 'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SignatureField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class, 'company_asset_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public static function typeKeys(): array
    {
        return array_keys(self::TYPES);
    }
}
