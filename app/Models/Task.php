<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Aufgabe / Wiedervorlage der Beraterwelt.
 *
 * Neben Titel/Typ/Prioritaet/Faelligkeit kann je Aufgabe eine AUTOMATISCHE
 * Kunden-E-Mail geplant werden (auto_email_*): Betreff/Text mit
 * {{platzhaltern}} + Stichtag. Der Versand laeuft ueber den Scheduler
 * (tasks:send-auto-emails); wird die Aufgabe vorher erledigt, wird der
 * Versand automatisch uebersprungen (Model-Hook -> Status 'skipped').
 */
class Task extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    /** Gueltige Aufgabentypen inkl. Anzeige-Label und Icon (eine Quelle fuer Formulare/Listen). */
    public const TYPES = [
        'call' => ['label' => 'Anruf',         'icon' => '📞'],
        'email' => ['label' => 'E-Mail',        'icon' => '✉️'],
        'meeting' => ['label' => 'Termin',        'icon' => '📅'],
        'document' => ['label' => 'Dokument',      'icon' => '📄'],
        'follow_up' => ['label' => 'Wiedervorlage', 'icon' => '🔄'],
        'reminder' => ['label' => 'Erinnerung',    'icon' => '⏰'],
        'other' => ['label' => 'Sonstige',      'icon' => '📌'],
    ];

    public const PRIORITIES = ['high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Niedrig'];

    public const STATUSES = ['open' => 'Offen', 'in_progress' => 'In Bearbeitung', 'done' => 'Erledigt'];

    protected $fillable = [
        'assigned_to', 'created_by', 'customer_id', 'email_message_id', 'contract_id',
        'title', 'description', 'type', 'status', 'priority', 'due_date', 'completed_at',
        'auto_email_status', 'auto_email_subject', 'auto_email_body',
        'auto_email_send_on', 'auto_email_sent_at', 'auto_email_error',
    ];

    protected $casts = [
        'due_date' => 'date',
        'auto_email_send_on' => 'date',
        'auto_email_sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
        // Erledigt-Zeitpunkt automatisch fuehren + geplanten E-Mail-Versand
        // einer erledigten Aufgabe stoppen (der Kunde hat reagiert - die
        // "Nachfassen"-Mail waere jetzt falsch). Gilt fuer ALLE Wege
        // (Formular, Schnell-Dropdown, Imports), weil zentral im Modell.
        static::saving(function (Task $m) {
            if ($m->status === 'done') {
                $m->completed_at = $m->completed_at ?: now();
                if ($m->auto_email_status === 'pending') {
                    $m->auto_email_status = 'skipped';
                    $m->auto_email_error = 'Aufgabe erledigt - geplanter Versand uebersprungen.';
                }
            } else {
                $m->completed_at = null;
            }
        });
    }

    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function emailMessage() { return $this->belongsTo(EmailMessage::class); }
    public function contract() { return $this->belongsTo(Contract::class); }

    /** Offene Aufgaben (alles ausser erledigt). */
    public function scopeOpen($query) { return $query->where('status', '!=', 'done'); }

    /** Faelligkeit ueberschritten (heute zaehlt NICHT als ueberfaellig). */
    public function isOverdue(): bool {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && $this->status !== 'done';
    }
}
