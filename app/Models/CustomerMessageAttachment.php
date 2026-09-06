<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerMessageAttachment extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'message_id', 'uploaded_by', 'file_name', 'file_path', 'disk',
        // Omnichannel: was der Kanal ueber die Datei MELDET. Bisher wurde
        // der MIME-Typ aus der Dateiendung geraten - bei einer ueber eine
        // Plattform-Kennung geholten Datei gibt es oft gar keine Endung.
        'type', 'mime_type', 'file_size', 'external_media_id', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function message() { return $this->belongsTo(CustomerMessage::class, 'message_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    /** Bild-Anhaenge koennen im Chat als Vorschau gerendert werden. */
    public function isImage(): bool {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    public function isPdf(): bool {
        return $this->extension() === 'pdf';
    }

    /** Bilder und PDFs kann der Browser direkt anzeigen (Content-Disposition: inline). */
    public function isViewable(): bool {
        return $this->isImage() || $this->isPdf();
    }

    /** Gemeldeter MIME-Typ, ersatzweise aus der Dateiendung abgeleitet. */
    public function mimeType(): string {
        // Der gemeldete Typ schlaegt die Endung: was der Kanal sagt,
        // WISSEN wir - die Endung ist nur ein Indiz und fehlt bei einer
        // ueber eine Plattform-Kennung geholten Datei ganz.
        if ($this->mime_type) {
            return $this->mime_type;
        }

        return match ($this->extension()) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function extension(): string {
        return strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION));
    }
}
