<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalConversationParticipant extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'last_read_at'];
    protected $casts = ['last_read_at' => 'datetime'];

    /** @return BelongsTo<InternalConversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(InternalConversation::class, 'conversation_id'); }
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
