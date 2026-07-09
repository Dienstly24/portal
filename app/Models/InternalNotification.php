<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalNotification extends Model
{
    protected $fillable = ['user_id', 'message_id', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function message() { return $this->belongsTo(InternalMessage::class, 'message_id')->withTrashed(); }

    public function scopeUnread($q) { return $q->whereNull('read_at'); }
}
