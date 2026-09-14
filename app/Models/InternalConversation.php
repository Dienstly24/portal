<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Eigenständige interne Unterhaltung zwischen Mitarbeitern.
 * Keine Verbindung zu Kunden/Tickets - strukturell nicht ans
 * Kundenportal auslieferbar. (Spec Teil 8)
 */
class InternalConversation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['subject', 'created_by', 'last_message_at'];
    protected $casts = ['last_message_at' => 'datetime'];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function participants(): HasMany { return $this->hasMany(InternalConversationParticipant::class, 'conversation_id'); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class, 'internal_conversation_participants', 'conversation_id', 'user_id')->withPivot('last_read_at'); }
    public function messages(): HasMany { return $this->hasMany(InternalConversationMessage::class, 'conversation_id'); }
    /**
     * Nur die juengste Nachricht - fuer das Notification Center, damit dort
     * nicht der komplette Nachrichtenverlauf jeder Unterhaltung geladen
     * werden muss (Behebung eines N+1-/Speicher-Problems).
     */
    public function latestMessage(): HasOne { return $this->hasOne(InternalConversationMessage::class, 'conversation_id')->latestOfMany('created_at'); }

    public function hasParticipant(int $userId): bool {
        return $this->participants()->where('user_id', $userId)->exists();
    }
}
