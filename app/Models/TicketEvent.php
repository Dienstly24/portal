<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Verlaufseintrag eines Tickets (Statuswechsel, Zuweisungen, Antworten, ...). */
class TicketEvent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    public const LABELS = [
        'created' => ['🆕', 'Ticket erstellt'],
        'status_changed' => ['🔁', 'Status geändert'],
        'reopened' => ['↩️', 'Wieder geöffnet'],
        'assigned' => ['👤', 'Zugewiesen'],
        'unassigned' => ['👤', 'Zuweisung entfernt'],
        'priority_changed' => ['⚑', 'Priorität geändert'],
        'type_changed' => ['🏷️', 'Typ geändert'],
        'staff_reply' => ['📨', 'Antwort an Kunde gesendet'],
        'customer_reply' => ['💬', 'Antwort vom Kunden erhalten'],
        'note_added' => ['🔒', 'Interne Notiz hinzugefügt'],
        'closed_by_customer' => ['✅', 'Vom Kunden geschlossen'],
        'auto_closed' => ['🕓', 'Automatisch geschlossen'],
        'rated' => ['⭐', 'Vom Kunden bewertet'],
        'deleted' => ['🗑️', 'In den Papierkorb verschoben'],
        'restored' => ['♻️', 'Aus dem Papierkorb wiederhergestellt'],
    ];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: Str::uuid());
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function icon(): string { return self::LABELS[$this->event][0] ?? 'ℹ️'; }
    public function label(): string { return self::LABELS[$this->event][1] ?? $this->event; }
}
