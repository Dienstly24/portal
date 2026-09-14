<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InternalConversationMessage extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['conversation_id', 'sender_id', 'body'];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<InternalConversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(InternalConversation::class, 'conversation_id'); }
    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
}
