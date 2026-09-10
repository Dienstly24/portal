<?php

namespace App\Models;

use App\Support\SignatureStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Eine Signaturanfrage - das eigenstaendige Geschaeftsobjekt des Moduls.
 *
 * Sie kann zu einem Kunden und zu einem Vertrag gehoeren, MUSS es aber
 * nicht. Alles, was hier haengt (Dokument, Unterzeichner, Felder,
 * Protokoll), funktioniert ohne Kundenakte.
 *
 * @property-read Collection<int, SignatureSigner> $signers
 * @property-read Collection<int, SignatureField> $fields
 * @property-read Collection<int, SignatureEvent> $events
 * @property-read Customer|null $customer
 * @property-read Contract|null $contract
 * @property-read User|null $creator
 * @property-read Document|null $completedDocument
 */
class SignatureRequest extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'title', 'status', 'customer_id', 'contract_id', 'completed_document_id', 'created_by',
        'original_path', 'original_name', 'original_hash', 'original_size', 'page_count',
        'signed_path', 'signed_hash', 'signed_size',
        'signing_order', 'identity_check', 'consent_text',
        'document_type', 'reference', 'note',
        'sent_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'expires_at', 'last_activity_at',
    ];

    protected $casts = [
        'page_count' => 'integer',
        'original_size' => 'integer',
        'signed_size' => 'integer',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<SignatureSigner, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('signing_order')->orderBy('created_at');
    }

    /** @return HasMany<SignatureField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class)->orderBy('page')->orderBy('sort');
    }

    /** @return HasMany<SignatureEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class)->latest('created_at');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function completedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'completed_document_id');
    }

    /**
     * Der Zustimmungstext in der Sprache DIESES Unterzeichners.
     *
     * ZWEI FAELLE, und der Unterschied ist wichtig: steht hier noch die
     * Voreinstellung, ist der Satz von uns - dann gibt es ihn uebersetzt.
     * Hat ein Mitarbeiter etwas EIGENES geschrieben, wird es WOERTLICH
     * gezeigt, auch auf einer arabischen Seite. Einen selbst formulierten
     * rechtlichen Hinweis maschinell zu uebersetzen hiesse, dem
     * Unterzeichner eine Erklaerung vorzulegen, die so niemand geprueft
     * hat - und genau darauf beruft er sich spaeter.
     */
    /**
     * Die zusaetzliche Identitaetspruefung dieses Vorgangs.
     *
     * EINE Spalte, drei Werte - kein zweiter Schalter daneben. Ein SMS-Weg
     * fehlt bewusst (Betreiber-Vorgabe): das waere ein weiterer
     * Dienstleister, ein weiterer Vertrag und eine weitere Datenspur.
     */
    public const IDENTITY_NONE = 'keine';

    public const IDENTITY_DOB = 'geburtsdatum';

    public const IDENTITY_EMAIL = 'email';

    public const IDENTITY_CHECKS = [
        self::IDENTITY_NONE => 'Keine',
        self::IDENTITY_DOB => 'Geburtsdatum',
        self::IDENTITY_EMAIL => 'E-Mail-Bestätigung',
    ];

    public static function identityCheckKeys(): array
    {
        return array_keys(self::IDENTITY_CHECKS);
    }

    public function identityCheckLabel(): string
    {
        return self::IDENTITY_CHECKS[$this->identity_check] ?? (string) $this->identity_check;
    }

    public function requiresEmailVerification(): bool
    {
        return $this->identity_check === self::IDENTITY_EMAIL;
    }

    public function requiresDob(): bool
    {
        return $this->identity_check === self::IDENTITY_DOB;
    }

    /**
     * Der alte Name als abgeleiteter Wert - damit kein Aufrufer bricht,
     * ohne dass eine zweite Spalte dieselbe Frage ein zweites Mal
     * beantwortet.
     */
    public function getRequireEmailVerificationAttribute(): bool
    {
        return $this->requiresEmailVerification();
    }

    public function setRequireEmailVerificationAttribute($value): void
    {
        // Eine ausdrueckliche Geburtsdatums-Pruefung wird davon NIE
        // ueberschrieben: der alte Schalter kennt sie gar nicht.
        if ($this->identity_check === self::IDENTITY_DOB) {
            return;
        }
        $this->attributes['identity_check'] = filter_var($value, FILTER_VALIDATE_BOOLEAN)
            ? self::IDENTITY_EMAIL
            : self::IDENTITY_NONE;
    }

    public function consentTextFor(?SignatureSigner $signer = null): string
    {
        $text = (string) $this->consent_text;
        $vorgabe = (string) __('signing.consent_default', [], 'de');

        if ($signer === null || trim($text) !== trim($vorgabe)) {
            return $text;
        }

        return (string) __('signing.consent_default', [], $signer->localeCode());
    }

    public function statusLabel(): string
    {
        return SignatureStatus::label($this->status);
    }

    public function statusTone(): string
    {
        return SignatureStatus::tone((string) $this->status);
    }

    public function isDraft(): bool
    {
        return $this->status === SignatureStatus::DRAFT;
    }

    public function isOpen(): bool
    {
        return SignatureStatus::isOpen((string) $this->status);
    }

    public function isCompleted(): bool
    {
        return $this->status === SignatureStatus::COMPLETED;
    }

    /**
     * Abgelaufen ist eine Frage der UHR, nicht des gespeicherten Status: der
     * Nachtlauf zieht ihn nach, aber bis dahin darf niemand mehr
     * unterschreiben. Genau dieselbe Lehre wie beim Vertragsstatus - die
     * Anzeige darf nie auf einen Cron warten.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Kann jetzt noch unterschrieben werden? */
    public function acceptsSignatures(): bool
    {
        return $this->isOpen() && ! $this->hasExpired();
    }

    /** Der Unterzeichner, der laut Reihenfolge als naechster dran ist. */
    public function currentSigner(): ?SignatureSigner
    {
        return $this->signers->firstWhere(fn (SignatureSigner $s) => $s->isPending());
    }

    public function isSequential(): bool
    {
        return $this->signing_order !== 'parallel';
    }

    /** Fortschritt "2 von 3". */
    public function signedCount(): int
    {
        return $this->signers->filter(fn (SignatureSigner $s) => $s->hasSigned())->count();
    }
}
