<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'user_id', 'work_session_id', 'action', 'entity_type', 'entity_id', 'meta',
        'route', 'url_path', 'method', 'ip', 'user_agent',
        'is_productive', 'points', 'active_seconds',
    ];
    protected $casts = [
        'meta' => 'array',
        'is_productive' => 'boolean',
        'points' => 'integer',
        'active_seconds' => 'integer',
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = Str::uuid());
    }
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** @return BelongsTo<WorkSession, $this> */
    public function workSession(): BelongsTo { return $this->belongsTo(WorkSession::class); }

    /**
     * Einheitliches Schreiben eines Audit-Eintrags (Audit ARCH-9). Ersetzt das
     * ueberall duplizierte create([... json_encode(meta) ...]); meta ist als
     * Array-Cast gespeichert.
     */
    public static function record(string $action, ?string $entityType = null, $entityId = null, array $meta = [], ?int $userId = null): self {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => $meta,
        ]);
    }

    /**
     * Doppelte Kodierung an der WURZEL abfangen (Audit 28.09.2026, KI-036).
     *
     * Rund 85 Schreibstellen uebergeben `json_encode([...])` an eine Spalte
     * mit Array-Cast - gespeichert wurde dann ein JSON-STRING im JSON
     * (doppelt kodiert). Lesend faengt `metaArray()` das ab, aber jede
     * Abfrage auf den Inhalt von `meta` waere falsch gewesen. Statt 85
     * Stellen einzeln umzuschreiben, nimmt das Modell beide Formen an und
     * speichert immer dieselbe: ein JSON-Objekt. Ein String, der KEIN
     * JSON-Objekt ist, bleibt unveraendert (nichts wird geraten).
     */
    public function setMetaAttribute($value): void
    {
        if (is_string($value)) {
            $entschluesselt = json_decode($value, true);
            if (is_array($entschluesselt)) {
                $value = $entschluesselt;
            }
        }

        $this->attributes['meta'] = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Meta robust als Array liefern: Alt-Eintraege wurden teils als
     * vor-serialisierter JSON-String gespeichert (doppelt kodiert),
     * neue Eintraege als echtes Array ueber den Cast.
     */
    public function metaArray(): array {
        $meta = $this->meta;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        return is_array($meta) ? $meta : [];
    }
}
