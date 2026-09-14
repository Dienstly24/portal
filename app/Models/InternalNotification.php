<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNotification extends Model
{
    protected $fillable = ['user_id', 'type', 'message_id', 'change_request_id', 'title', 'body', 'link', 'dedup_key', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function message(): BelongsTo { return $this->belongsTo(InternalMessage::class, 'message_id')->withTrashed(); }
    public function changeRequest(): BelongsTo { return $this->belongsTo(CustomerChangeRequest::class, 'change_request_id'); }

    public function scopeUnread($q) { return $q->whereNull('read_at'); }
}
