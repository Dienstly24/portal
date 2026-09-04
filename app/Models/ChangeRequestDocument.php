<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Nachweis-Dokument zu einer Kundenaenderung (Ausweis, Meldebescheinigung,
 * Kontonachweis ...). Liegt IMMER auf der privaten Disk unter
 * customers/{id}/nachweise - damit die Datei bei einer Kundenloeschung
 * (DSGVO) mit dem Kundenverzeichnis verschwindet und nie per URL
 * erreichbar ist.
 *
 * check_status/check_result halten das Ergebnis der automatischen Pruefung
 * (ChangeProofVerifier) fest: NICHT den Rohtext des Dokuments
 * (Datenminimierung), sondern nur, welche Angabe im Dokument gefunden
 * wurde und welche nicht.
 */
class ChangeRequestDocument extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'change_request_id', 'kind', 'file_name', 'file_path', 'disk',
        'mime', 'size', 'check_status', 'check_result',
    ];

    protected $casts = [
        'check_result' => 'encrypted:array',
        'size' => 'integer',
    ];

    public const KINDS = [
        'id_front' => 'Ausweis (Vorderseite)',
        'id_back' => 'Ausweis (Rückseite)',
        'meldebescheinigung' => 'Meldebescheinigung',
        'bank_proof' => 'Kontonachweis (Bankkarte / Kontoauszug)',
        'other' => 'Weiterer Nachweis',
    ];

    public const CHECK_LABELS = [
        'pending' => 'Prüfung läuft',
        'match' => 'Angaben im Dokument gefunden',
        'partial' => 'Teilweise gefunden',
        'no_match' => 'Angaben NICHT gefunden',
        'unreadable' => 'Nicht maschinell lesbar',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
        });
    }

    public function changeRequest()
    {
        return $this->belongsTo(CustomerChangeRequest::class, 'change_request_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS['other'];
    }

    public function checkLabel(): string
    {
        return self::CHECK_LABELS[$this->check_status] ?? $this->check_status;
    }

    /** Bilder und PDFs koennen direkt im Browser angezeigt werden. */
    public function isViewable(): bool
    {
        return in_array($this->mimeType(), [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
        ], true);
    }

    public function mimeType(): string
    {
        if ($this->mime) {
            return $this->mime;
        }
        return match (strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
