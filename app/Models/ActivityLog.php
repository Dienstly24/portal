<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model {
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
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function user() { return $this->belongsTo(User::class); }
    public function workSession() { return $this->belongsTo(WorkSession::class); }

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
