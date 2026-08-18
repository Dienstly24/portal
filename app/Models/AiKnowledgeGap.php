<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eine Wissensluecke: der Assistent hat gesucht und nichts gefunden
 * (Betreiber-Auftrag 18.08.2026).
 *
 * Das ist die Rueckmeldung, die bisher fehlte. Der Assistent lernt NICHT
 * von selbst - er darf nichts behaupten, was niemand freigegeben hat.
 * Aber er kann sagen, WONACH gefragt wurde, ohne dass eine Antwort
 * hinterlegt ist. Was daraus wird, entscheidet ein Mensch.
 *
 * Bewusst OHNE Kundenbezug: gespeichert wird der Suchbegriff (Stichworte
 * des Modells), nicht die Nachricht und nicht, wer gefragt hat.
 */
class AiKnowledgeGap extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const SCOPE_CUSTOMER = 'kunde';
    public const SCOPE_WEBSITE = 'website';

    public const STATUS_OPEN = 'offen';
    public const STATUS_DONE = 'erledigt';
    public const STATUS_IGNORED = 'ignoriert';

    public const SCOPE_LABELS = [
        self::SCOPE_CUSTOMER => 'Kundenportal',
        self::SCOPE_WEBSITE => 'Website (Besucher)',
    ];

    protected $fillable = [
        'topic_key', 'topic', 'scope', 'language', 'hits',
        'first_seen_at', 'last_seen_at', 'status',
        'resolved_entry_id', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }

    public function scopeOpen($q) { return $q->where('status', self::STATUS_OPEN); }

    public function scopeLabel(): string
    {
        return self::SCOPE_LABELS[$this->scope] ?? $this->scope;
    }

    /**
     * Eine erfolglose Suche festhalten. Idempotent je Thema und Bereich:
     * dieselbe Frage zum zehnten Mal erhoeht den Zaehler, statt eine
     * zehnte Zeile anzulegen - die Haeufigkeit ist genau die Information,
     * nach der der Betreiber seine Arbeit sortiert.
     *
     * Eine bereits ERLEDIGTE Luecke wird wieder geoeffnet: taucht das
     * Thema erneut auf, obwohl ein Eintrag existiert, findet die Suche ihn
     * nicht - dann stimmen Titel oder Stichwoerter nicht.
     */
    public static function record(string $term, string $scope = self::SCOPE_CUSTOMER, ?string $language = null): ?self
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
        // Zu kurz oder zu lang: kein brauchbares Thema fuer eine Arbeitsliste.
        if (mb_strlen($term) < 3) {
            return null;
        }
        $term = mb_substr($term, 0, 190);
        $key = mb_substr(static::normalize($term), 0, 190);
        if ($key === '') {
            return null;
        }

        $gap = static::firstOrNew(['topic_key' => $key, 'scope' => $scope]);
        $gap->topic = $term;
        $gap->language = $language ?: $gap->language;
        $gap->hits = ($gap->exists ? $gap->hits : 0) + 1;
        $gap->first_seen_at = $gap->first_seen_at ?: now();
        $gap->last_seen_at = now();
        if ($gap->status === self::STATUS_DONE) {
            $gap->status = self::STATUS_OPEN;
            $gap->resolved_at = null;
        }
        $gap->save();

        return $gap;
    }

    /**
     * Vergleichsfassung: klein, ohne Umlaut-Schreibweise, Woerter
     * alphabetisch - "Angebote Strom" und "strom angebote" sind dieselbe
     * Luecke, sonst stuende dasselbe Thema mehrfach in der Liste.
     */
    public static function normalize(string $wert): string
    {
        $wert = mb_strtolower($wert);
        $wert = strtr($wert, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $woerter = preg_split('/[^\p{L}\p{N}]+/u', $wert, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($woerter);

        return implode(' ', $woerter);
    }
}
