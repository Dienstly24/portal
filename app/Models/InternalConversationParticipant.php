<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalConversationParticipant extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'last_read_at'];
    protected $casts = ['last_read_at' => 'datetime'];

    public function conversation() { return $this->belongsTo(InternalConversation::class, 'conversation_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
