<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Direktnachricht zwischen Beratung und Kunde (Portal-Chat).
 * from_staff=true: vom Team an den Kunden, sonst Kundenantwort.
 * read_at = Lesezeitpunkt der jeweiligen Gegenseite.
 */
class CustomerMessage extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const EMAIL_MODES = ['none', 'hint', 'full'];

    protected $fillable = ['customer_id', 'sender_id', 'body', 'from_staff', 'read_at', 'email_mode'];
    protected $casts = ['from_staff' => 'boolean', 'read_at' => 'datetime'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function attachments() { return $this->hasMany(CustomerMessageAttachment::class, 'message_id'); }

    public function scopeFromStaff($q) { return $q->where('from_staff', true); }
    public function scopeFromCustomer($q) { return $q->where('from_staff', false); }
    public function scopeUnread($q) { return $q->whereNull('read_at'); }
}
