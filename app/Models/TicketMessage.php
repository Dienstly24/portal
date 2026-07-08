<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TicketMessage extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['ticket_id','sender_id','body','attachment_path','is_internal'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
