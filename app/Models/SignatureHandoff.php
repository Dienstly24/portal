<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Auf dem Handy unterschreiben": ein QR-Code am Rechner, gezeichnet wird
 * auf dem Telefon.
 *
 * Der Zugang ist KURZLEBIG (10 Minuten), EINMAL nutzbar und an Konto UND
 * Sitzung des Rechners gebunden - nur die Sitzung, die ihn erzeugt hat,
 * bekommt das Ergebnis. Gespeichert wird nur der SHA-256 des Tokens.
 *
 * @property string $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $session_hash
 * @property string $kind
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $image_path
 * @property string|null $device
 */
class SignatureHandoff extends Model
{
    public const GUELTIG_MINUTEN = 10;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['user_id', 'token_hash', 'session_hash', 'kind', 'expires_at', 'used_at', 'image_path', 'device'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function istOffen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
