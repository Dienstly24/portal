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

    /**
     * Einheitliche Chat-Struktur fuer Portal-Seite, Portal-Widget und
     * Kunden-Chat der Beraterwelt. $staffView spiegelt die Perspektive:
     * im Portal sind Kundennachrichten "eigene", in der Beraterwelt die
     * Staff-Nachrichten (dort mit Absender-Name, weil mehrere Kollegen
     * schreiben koennen).
     */
    public function toChatPayload(bool $staffView = false): array
    {
        $attachmentRoute = $staffView ? 'admin.messages.attachment' : 'portal.messages.attachment';
        $viewRoute = $staffView ? 'admin.messages.attachment.view' : 'portal.messages.attachment.view';

        return [
            'id' => $this->id,
            'from_staff' => $this->from_staff,
            'own' => $staffView ? $this->from_staff : !$this->from_staff,
            'sender' => $this->from_staff
                ? ($this->sender?->name ?? 'Dienstly24 Team')
                : ($this->customer?->user?->name ?? __('Kunde')),
            'show_sender' => $this->from_staff,
            'body' => $this->body,
            'day' => $this->created_at->isToday()
                ? __('Heute')
                : ($this->created_at->isYesterday() ? __('Gestern') : $this->created_at->format('d.m.Y')),
            'time' => $this->created_at->format('H:i'),
            'read' => $this->read_at !== null,
            'attachments' => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->file_name,
                'kind' => $a->isImage() ? 'image' : ($a->isPdf() ? 'pdf' : 'file'),
                'view_url' => $a->isViewable() ? route($viewRoute, $a->id) : null,
                'download_url' => route($attachmentRoute, $a->id),
            ])->values()->all(),
        ];
    }
}
