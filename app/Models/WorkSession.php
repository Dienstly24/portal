<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Arbeitssitzung eines Mitarbeiters: Login bis Logout/Timeout.
 * Aktive Arbeitszeit wird serverseitig vom ActivityTracker
 * gutgeschrieben; Leerlauf = Sitzungsdauer - aktive Zeit.
 */
class WorkSession extends Model {
    protected $fillable = [
        'user_id', 'login_at', 'last_seen_at', 'last_productive_at',
        'logout_at', 'active_seconds', 'ended_by', 'ip', 'user_agent',
    ];
    protected $casts = [
        'login_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_productive_at' => 'datetime',
        'logout_at' => 'datetime',
        'active_seconds' => 'integer',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function activityLogs() { return $this->hasMany(ActivityLog::class); }

    public function scopeOpen($query) { return $query->whereNull('logout_at'); }

    /** Sitzungsende fuer Berechnungen: Logout, sonst letzter Request. */
    public function effectiveEnd(): \Carbon\Carbon {
        return $this->logout_at ?? $this->last_seen_at ?? $this->login_at;
    }

    /** Gesamtdauer der Sitzung in Sekunden (Anmeldezeit). */
    public function durationSeconds(): int {
        return max(0, (int) $this->login_at->diffInSeconds($this->effectiveEnd()));
    }

    /** Leerlauf in Sekunden (nie negativ). */
    public function idleSeconds(): int {
        return max(0, $this->durationSeconds() - (int) $this->active_seconds);
    }
}
