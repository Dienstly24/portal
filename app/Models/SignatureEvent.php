<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein Eintrag im Signatur-Protokoll.
 *
 * NUR ANLEGEN. Es gibt bewusst kein updated_at, keinen Bearbeiten-Weg und
 * keinen Loeschen-Knopf in der Oberflaeche: ein Protokoll, das der
 * Protokollierte aendern kann, belegt nichts. Dieselbe Regel wie beim
 * Provisions-Protokoll (`commission_audit_logs`).
 */
class SignatureEvent extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'signature_request_id', 'signature_signer_id', 'user_id', 'event',
        'actor', 'description', 'ip', 'user_agent', 'meta', 'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Die Ereignisse, die das Modul kennt. Die Liste ist zugleich die
     * Uebersetzung fuer die Anzeige - ein Ereignis ohne Klartext waere im
     * Protokoll wertlos.
     */
    public const LABELS = [
        'created' => 'Signaturanfrage erstellt',
        'document_uploaded' => 'PDF hochgeladen',
        'signer_added' => 'Unterzeichner hinzugefügt',
        'signer_removed' => 'Unterzeichner entfernt',
        'fields_saved' => 'Felder gespeichert',
        'sent' => 'Einladung versendet',
        'reminder_sent' => 'Erinnerung versendet',
        'verification_requested' => 'Bestätigungscode angefordert',
        'verification_failed' => 'Bestätigungscode falsch',
        'verified' => 'E-Mail-Adresse bestätigt',
        'opened' => 'Dokument geöffnet',
        'document_viewed' => 'Dokument angesehen',
        'signing_started' => 'Unterschrift begonnen',
        'field_filled' => 'Feld ausgefüllt',
        'signed' => 'Unterschrift abgeschlossen',
        // Der GEGENPOL zu 'signing_started': ohne ihn endete das Protokoll
        // bei einer Stoerung stumm mit "Unterschrift begonnen" - genau das
        // Bild, mit dem der gemeldete HTTP 500 aufgefallen ist. Ein
        // Fehlschlag, den nur die Logdatei kennt, sieht im Protokoll wie ein
        // abgebrochener Kunde aus.
        'signing_failed' => 'Unterschrift fehlgeschlagen',
        'declined' => 'Unterschrift abgelehnt',
        'completed' => 'Signaturvorgang abgeschlossen',
        'pdf_generated' => 'Unterschriebenes PDF erzeugt',
        'downloaded' => 'Dokument heruntergeladen',
        'customer_linked' => 'Kunde zugeordnet',
        'contract_linked' => 'Vertrag zugeordnet',
        'customer_created' => 'Kunde angelegt',
        'cancelled' => 'Signaturanfrage abgebrochen',
        'expired' => 'Signaturanfrage abgelaufen',
        'token_revoked' => 'Zugang widerrufen',
        'access_denied' => 'Zugriff abgelehnt',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
            $model->created_at ??= now();
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? $this->event;
    }
}
