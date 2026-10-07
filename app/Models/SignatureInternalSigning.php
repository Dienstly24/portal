<?php

namespace App\Models;

use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Protokoll EINER internen Unterschrift - append-only (kein updated_at,
 * kein Aenderungsweg), wie `signature_events`.
 *
 * Getrennt von der Unternehmenssignatur (Logo/Stempel): DAS hier ist die
 * Willenserklaerung eines Menschen, mit Name, Funktion, Zeit, IP, Geraet,
 * dem Nachweis der Zwei-Faktor-Pruefung, dem Hash des Bildes und dem Hash
 * des Dokuments davor und danach.
 *
 * @property string $id
 * @property string $signature_request_id
 * @property int|null $user_id
 * @property string $name
 * @property string $funktion
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $reauth_method
 * @property Carbon|null $reauth_at
 * @property string $image_method
 * @property string $image_hash
 * @property string|null $user_signature_id
 * @property string $document_hash_before
 * @property string $document_hash_after
 * @property string $bestaetigung
 * @property list<string> $felder
 * @property Carbon|null $created_at
 */
class SignatureInternalSigning extends Model
{
    public const UPDATED_AT = null;

    /** 2FA bestaetigt beim Anmelden (innerhalb des Zeitfensters). */
    public const REAUTH_LOGIN = '2fa_anmeldung';

    /** 2FA-Code unmittelbar vor dem Unterschreiben abgefragt. */
    public const REAUTH_ABGEFRAGT = '2fa_abgefragt';

    public const REAUTH_LABELS = [
        self::REAUTH_LOGIN => 'Zwei-Faktor-Bestätigung bei der Anmeldung',
        self::REAUTH_ABGEFRAGT => 'Zwei-Faktor-Code vor dem Unterschreiben abgefragt',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'signature_request_id', 'user_id', 'name', 'funktion', 'ip', 'user_agent',
        'reauth_method', 'reauth_at', 'image_method', 'image_hash', 'user_signature_id',
        'document_hash_before', 'document_hash_after', 'bestaetigung', 'felder',
    ];

    protected $casts = [
        'reauth_at' => 'datetime',
        'felder' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
        // Append-only: ein Protokolleintrag wird nie geaendert.
        static::updating(fn () => false);
    }

    /** @return BelongsTo<SignatureRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserSignature, $this> */
    public function userSignature(): BelongsTo
    {
        return $this->belongsTo(UserSignature::class);
    }

    public function reauthLabel(): string
    {
        return self::REAUTH_LABELS[$this->reauth_method] ?? $this->reauth_method;
    }

    /**
     * Wie das Bild entstand: "gezeichnet am Geraet X", "hochgeladen",
     * "per Handy-QR gezeichnet" - und ob es die HINTERLEGTE Unterschrift war
     * oder eine nur fuer dieses Dokument gezeichnete.
     */
    public function imageMethodLabel(): string
    {
        $label = UserSignature::METHODEN[$this->image_method] ?? $this->image_method;
        $hinterlegt = $this->user_signature_id !== null ? $this->userSignature : null;
        if ($hinterlegt !== null) {
            $label .= ($hinterlegt->device ? ' am Gerät '.$hinterlegt->device : '')
                .', hinterlegte Unterschrift vom '.(LocalTime::for($hinterlegt->created_at)?->format('d.m.Y') ?? '-');
        } else {
            $label .= ' (nur für dieses Dokument)';
        }

        return $label;
    }
}
