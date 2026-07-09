<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Interne Mitarbeiter-Nachricht (Chat oder Notiz) zu einem Kunden.
 * NIEMALS im Kundenportal ausgeben - es existieren bewusst keine
 * Portal-Routen/-Views, die dieses Model berühren.
 */
class InternalMessage extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_id', 'sender_id', 'message', 'type', 'mentioned_users'];

    protected $casts = ['mentioned_users' => 'array'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function deletedBy() { return $this->belongsTo(User::class, 'deleted_by'); }
    public function notifications() { return $this->hasMany(InternalNotification::class, 'message_id'); }

    public function scopeChat($q) { return $q->where('type', 'chat'); }
    public function scopeNote($q) { return $q->where('type', 'note'); }

    /**
     * Nachricht sicher als HTML rendern: erst vollständig escapen,
     * danach @Mentions farblich hervorheben (kein XSS möglich, da die
     * Hervorhebung nur bereits escapten Text umschließt).
     */
    public function renderedMessage(): string
    {
        $escaped = e($this->message);
        return preg_replace(
            '/@([\p{L}\p{N}._-]+)/u',
            '<span style="color:#185FA5;font-weight:600;">@$1</span>',
            $escaped
        );
    }
}
