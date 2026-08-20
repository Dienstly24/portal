<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein zusammengefasster Fehler. Eine Zeile je Fingerabdruck, nicht je
 * Auftreten - siehe Migration.
 */
class ErrorEvent extends Model
{
    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'file', 'line',
        'route', 'method', 'status_code', 'occurrences',
        'first_seen_at', 'last_seen_at', 'last_user_id',
        'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'line' => 'integer',
        'status_code' => 'integer',
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** Offene (nicht als erledigt markierte) Fehler. */
    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    /** Seit einem Zeitpunkt zuletzt aufgetreten. */
    public function scopeSeenSince($query, \DateTimeInterface $since)
    {
        return $query->where('last_seen_at', '>=', $since);
    }

    public function lastUser()
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Kurzer Klassenname ohne Namensraum - fuer die Anzeige. */
    public function shortClass(): string
    {
        $parts = explode('\\', $this->exception_class);

        return end($parts) ?: $this->exception_class;
    }

    /** Datei relativ zum Projekt - der absolute Pfad hilft niemandem. */
    public function shortFile(): string
    {
        $file = (string) $this->file;
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
