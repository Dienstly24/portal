<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Die HINTERLEGTE Unterschrift (oder Paraphe) eines Mitarbeiters.
 *
 * Es gibt je Person und Art genau EINE aktive Zeile. Ersetzen ARCHIVIERT
 * die alte (active = false, archived_at) - sie wird NIE geloescht: ein
 * frueheres Protokoll nennt ihren Hash, und die Datei belegt, was damals
 * gesetzt wurde.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $kind
 * @property string $path
 * @property string $hash
 * @property string $method
 * @property string|null $device
 * @property bool $active
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 */
class UserSignature extends Model
{
    public const UNTERSCHRIFT = 'unterschrift';

    public const PARAPHE = 'paraphe';

    public const KINDS = [self::UNTERSCHRIFT => 'Unterschrift', self::PARAPHE => 'Paraphe'];

    public const GEZEICHNET = 'gezeichnet';

    public const HOCHGELADEN = 'hochgeladen';

    public const HANDY_QR = 'handy_qr';

    public const METHODEN = [
        self::GEZEICHNET => 'gezeichnet',
        self::HOCHGELADEN => 'hochgeladen',
        self::HANDY_QR => 'per Handy-QR gezeichnet',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['user_id', 'kind', 'path', 'hash', 'method', 'device', 'active', 'archived_at'];

    protected $casts = [
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function methodLabel(): string
    {
        return self::METHODEN[$this->method] ?? $this->method;
    }
}
