<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ticket extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['ticket_number','customer_id','assigned_to','type','status','subject','description',
        'priority','source','guest_name','guest_email','guest_phone',
        'first_response_at','resolved_at','closed_at','closed_by','due_at','reopened_count','rating','rating_comment'];
    protected $casts = [
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    /** Alle Workflow-Stati mit deutschen Labels (Beraterwelt). */
    public const STATUSES = [
        'open' => 'Offen',
        'in_progress' => 'In Bearbeitung',
        'waiting' => 'Wartet auf Kunde',
        'resolved' => 'Gelöst',
        'closed' => 'Geschlossen',
    ];

    /** Prioritaeten inkl. SLA-Reaktionszeit (Faelligkeit ab Erstellung). */
    public const PRIORITIES = [
        'dringend' => ['label' => 'Dringend', 'icon' => '🚨', 'sla_hours' => 4],
        'hoch' => ['label' => 'Hoch', 'icon' => '🔴', 'sla_hours' => 24],
        'mittel' => ['label' => 'Mittel', 'icon' => '🟡', 'sla_hours' => 72],
        'niedrig' => ['label' => 'Niedrig', 'icon' => '🟢', 'sla_hours' => 120],
    ];

    public const TYPES = [
        'damage' => 'Schadenmeldung',
        'change' => 'Vertragsänderung',
        'offer' => 'Angebot',
        'data_update' => 'Datenaktualisierung',
        'cancellation' => 'Kündigung',
        'complaint' => 'Beschwerde',
        'other' => 'Sonstiges',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: Str::uuid();
            $m->ticket_number = $m->ticket_number ?: static::nextTicketNumber();
            // SLA-Faelligkeit ab Erstellung, abhaengig von der Prioritaet
            $m->due_at = $m->due_at ?: now()->addHours(static::slaHours($m->priority ?? 'mittel'));
        });
        static::created(function ($m) {
            $m->logEvent('created', 'Quelle: ' . ($m->source ?? 'portal'));
        });
    }

    /** Fortlaufende Ticketnummer: T-JJ + 5-stellig (analog Kundennummern). */
    public static function nextTicketNumber(): string {
        $prefix = 'T-' . now()->format('y');
        $max = static::where('ticket_number', 'like', $prefix . '%')
            ->pluck('ticket_number')
            ->filter(fn ($n) => preg_match('/^' . preg_quote($prefix, '/') . '\d{5}$/', (string) $n))
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;
        do {
            $max++;
            $number = $prefix . str_pad((string) $max, 5, '0', STR_PAD_LEFT);
        } while (static::where('ticket_number', $number)->exists());
        return $number;
    }

    public static function slaHours(?string $priority): int {
        return self::PRIORITIES[$priority]['sla_hours'] ?? 72;
    }

    /** Nur Tickets mit Kundenakte (ohne Gast-Anfragen/Leads). */
    public function scopeCustomerOnly($query) { return $query->whereNotNull('customer_id'); }

    /** Noch nicht erledigt (weder geloest noch geschlossen). */
    public function scopeActive($query) { return $query->whereNotIn('status', ['resolved', 'closed']); }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }
    public function messages() { return $this->hasMany(TicketMessage::class); }
    public function events() { return $this->hasMany(TicketEvent::class); }
    public function attachments() { return $this->hasMany(TicketAttachment::class); }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }
    public function typeLabel(): string { return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type)); }
    public function priorityLabel(): string {
        $p = self::PRIORITIES[$this->priority] ?? self::PRIORITIES['mittel'];
        return $p['icon'] . ' ' . $p['label'];
    }

    /**
     * Kundenfreundliches Status-Label fuers Portal ("waiting" heisst aus
     * Kundensicht: Ihre Rueckmeldung ist gefragt). In Views mit __() nutzen.
     */
    public function portalStatusLabel(): string {
        return [
            'open' => 'Offen',
            'in_progress' => 'In Bearbeitung',
            'waiting' => 'Wartet auf Ihre Antwort',
            'resolved' => 'Gelöst',
            'closed' => 'Geschlossen',
        ][$this->status] ?? $this->status;
    }

    /** CSS-Suffix fuer .badge-* in Admin- und Portal-Layout. */
    public function statusBadge(): string {
        return ['open' => 'open', 'in_progress' => 'pending', 'waiting' => 'waiting', 'resolved' => 'approved', 'closed' => 'closed'][$this->status] ?? 'open';
    }

    public function isFinished(): bool { return in_array($this->status, ['resolved', 'closed'], true); }

    /** Ueberfaellig = SLA gerissen, solange noch keine erste Antwort erfolgt ist. */
    public function isOverdue(): bool {
        return $this->due_at
            && $this->first_response_at === null
            && !$this->isFinished()
            && $this->due_at->isPast();
    }

    /** Verlaufseintrag schreiben (Wer/Was/Wann - Grundlage des Ticket-Verlaufs). */
    public function logEvent(string $event, ?string $details = null, ?int $userId = null): void {
        TicketEvent::create([
            'ticket_id' => $this->id,
            'user_id' => $userId ?? auth()->id(),
            'event' => $event,
            'details' => $details,
        ]);
    }

    /**
     * Zentraler Statuswechsel: pflegt resolved_at/closed_at/closed_by und
     * den Wiedereroeffnungs-Zaehler und schreibt den Verlauf. EIN Codepfad
     * fuer Beraterwelt, Kundenportal und Auto-Close.
     *
     * @return bool true nur bei einem echten Wechsel (fuer Benachrichtigungen:
     *              Doppel-Submits duerfen keine zweite Kunden-Glocke erzeugen).
     */
    public function transitionTo(string $status, ?int $userId = null, ?string $eventOverride = null): bool {
        $old = $this->status;
        if ($old === $status || !isset(self::STATUSES[$status])) return false;

        $reopening = $this->isFinished() && !in_array($status, ['resolved', 'closed'], true);
        $data = ['status' => $status];
        if ($status === 'resolved') { $data['resolved_at'] = now(); }
        if ($status === 'closed') { $data['closed_at'] = now(); $data['closed_by'] = $userId ?? auth()->id(); }
        // Beim Verlassen von "geschlossen" (auch closed -> resolved) duerfen
        // keine Abschlussdaten stehen bleiben - sonst zeigt die Akte
        // "Geloest" UND "Geschlossen am ..." gleichzeitig.
        if ($old === 'closed' && $status !== 'closed') {
            $data['closed_at'] = null;
            $data['closed_by'] = null;
        }
        if ($reopening) {
            $data['reopened_count'] = $this->reopened_count + 1;
            $data['resolved_at'] = null;
            $data['closed_at'] = null;
            $data['closed_by'] = null;
        }
        $this->update($data);
        $this->logEvent(
            $eventOverride ?? ($reopening ? 'reopened' : 'status_changed'),
            (self::STATUSES[$old] ?? $old) . ' → ' . self::STATUSES[$status],
            $userId
        );
        return true;
    }

    /**
     * Faengt die seltene Kollision zweier zeitgleich vergebener
     * Ticketnummern ab (Unique-Index): einmal neu ziehen statt 500er.
     */
    public function save(array $options = []) {
        try {
            return parent::save($options);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (!$this->exists && $this->ticket_number) {
                $this->ticket_number = static::nextTicketNumber();
                return parent::save($options);
            }
            throw $e;
        }
    }
}
