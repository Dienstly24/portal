<?php

namespace App\Models;

use App\Jobs\Messaging\SendOutboundMessageJob;
use App\Services\Ai\Assistant\AssistantSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $fillable = [
        'customer_id', 'sender_id', 'body', 'from_staff', 'ai_generated', 'read_at', 'email_mode',
        // Omnichannel (Phase B) - alle optional, damit jeder bestehende
        // Schreibweg unveraendert weiterlaeuft.
        'conversation_id', 'direction', 'sender_type', 'external_message_id',
        'message_type', 'status', 'metadata', 'sent_at', 'delivered_at',
        'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'from_staff' => 'boolean',
        'ai_generated' => 'boolean',
        'read_at' => 'datetime',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public const DIRECTION_INCOMING = 'incoming';
    public const DIRECTION_OUTGOING = 'outgoing';

    public const SENDER_EMPLOYEE = 'employee';
    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_SYSTEM = 'system';
    public const SENDER_BOT = 'bot';

    public const TYPE_TEXT = 'text';

    /**
     * Nachrichtenarten. Bewusst eine Liste und kein ENUM: eine neue Art
     * (Reaktion, Umfrage, Standort ...) darf keine Migration kosten.
     */
    public const MESSAGE_TYPES = [
        'text', 'image', 'video', 'audio', 'file', 'document',
        'sticker', 'location', 'contact', 'system', 'unsupported',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    /** Anzeigename des Assistenten - eine Quelle fuer Portal und Beraterwelt. */
    public const AI_SENDER_NAME = 'Dienstly24 Assistent';

    /**
     * Nachrichtentext OHNE sensible Angaben - nur fuer den Weg zum
     * KI-Modell (SlotExtractor ersetzt IBAN, Geburtsdatum & Co. durch
     * Platzhalter). Der gespeicherte `body` bleibt unveraendert, damit der
     * Kunde im Chat sieht, was er geschrieben hat.
     *
     * BEWUSST ALS ECHTE KLASSEN-EIGENSCHAFT deklariert: eine dynamisch
     * gesetzte Eigenschaft wuerde bei Eloquent als ATTRIBUT landen und
     * beim naechsten save() als Spalte geschrieben werden wollen.
     */
    public ?string $aiSafeBody = null;

    protected static function boot() {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            // `from_staff` (Altbestand) und `direction` (Omnichannel) sind
            // dieselbe Aussage in zwei Lesarten. Sie werden hier
            // aneinander gebunden, damit sie nie auseinanderlaufen -
            // egal, welchen der beiden Wege der Aufrufer benutzt.
            if ($m->direction === null) {
                $m->direction = $m->from_staff ? self::DIRECTION_OUTGOING : self::DIRECTION_INCOMING;
            } else {
                $m->from_staff = $m->direction === self::DIRECTION_OUTGOING;
            }
            $m->message_type = $m->message_type ?: self::TYPE_TEXT;
            $m->sender_type = $m->sender_type ?: ($m->from_staff
                ? ($m->ai_generated ? self::SENDER_BOT : self::SENDER_EMPLOYEE)
                : self::SENDER_CUSTOMER);
        });
        // AUSGEHENDER VERSAND (Auftrag Abschnitt 80).
        //
        // Hier im Modell und nicht im Controller - dieselbe Begruendung
        // wie bei der KI-Ruhefrist darunter: es gilt fuer JEDEN
        // Schreibweg (Kundenchat, Kundenakte, KI-Antwort,
        // Aufgaben-Automatik). Stuende der Anstoss in den Controllern,
        // muesste ihn jeder neue Weg wiederholen, und der eine
        // vergessene faellt als "die Nachricht ging nie raus" auf - beim
        // Kunden, nicht bei uns.
        //
        // Die Bedingung `external_user_id` ist der Filter: Portal und
        // interner Chat haben keine Gegenstelle ausserhalb und brauchen
        // keinen Versand. Nur ein Kanal mit echter Gegenstelle loest aus.
        static::created(function ($m) {
            if (! $m->from_staff || ! $m->conversation_id || $m->external_message_id) {
                return;
            }

            try {
                $unterhaltung = $m->conversation()->first();
                if ($unterhaltung?->external_user_id) {
                    SendOutboundMessageJob::dispatch($m->id);
                }
            } catch (\Throwable) {
                // Der Versand darf das Speichern der Nachricht nie
                // scheitern lassen - sie steht dann im Verlauf und kann
                // erneut angestossen werden.
            }
        });

        // Schreibt ein MENSCH an den Kunden, faengt die Ruhefrist der
        // Wiederaufnahme neu an (Betreiber-Vorgabe 20.08.2026): solange am
        // Fall gearbeitet wird, faellt die KI niemandem ins Wort. Hier im
        // Modell und nicht im Controller, damit es fuer JEDEN Schreibweg
        // gilt (Kundenchat, Kundenakte, Aufgaben-Automatik).
        static::created(function ($m) {
            if (! $m->from_staff || $m->ai_generated) {
                return;
            }
            $steuerstand = AiConversation::where('customer_id', $m->customer_id)->first();
            $steuerstand?->postponeResume(
                app(AssistantSettings::class)->resumeQuietHours()
            );
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class, 'conversation_id'); }
    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }

    /** @return HasMany<CustomerMessageAttachment, $this> */
    public function attachments(): HasMany { return $this->hasMany(CustomerMessageAttachment::class, 'message_id'); }

    public function scopeFromStaff($q) { return $q->where('from_staff', true); }
    public function scopeFromCustomer($q) { return $q->where('from_staff', false); }
    public function scopeUnread($q) { return $q->whereNull('read_at'); }
    public function scopeIncoming($q) { return $q->where('direction', self::DIRECTION_INCOMING); }
    public function scopeOutgoing($q) { return $q->where('direction', self::DIRECTION_OUTGOING); }

    /**
     * Zustellstand fortschreiben. Ein Status geht nur VORWAERTS
     * (pending -> sent -> delivered -> read): Statusmeldungen einer
     * Plattform treffen regelmaessig in falscher Reihenfolge ein, und
     * eine gelesene Nachricht darf nicht wieder auf "zugestellt"
     * zurueckfallen. `failed` ist davon ausgenommen - ein Fehlschlag ist
     * immer die juengere Wahrheit.
     */
    public function advanceStatus(string $status, ?string $reason = null): bool
    {
        $rang = [
            self::STATUS_PENDING => 1, self::STATUS_SENT => 2,
            self::STATUS_DELIVERED => 3, self::STATUS_READ => 4,
        ];

        if ($status !== self::STATUS_FAILED
            && ($rang[$status] ?? 0) <= ($rang[$this->status] ?? 0)) {
            return false;
        }

        $this->status = $status;
        match ($status) {
            self::STATUS_SENT => $this->sent_at = $this->sent_at ?: now(),
            self::STATUS_DELIVERED => $this->delivered_at = $this->delivered_at ?: now(),
            self::STATUS_READ => $this->read_at = $this->read_at ?: now(),
            self::STATUS_FAILED => [$this->failed_at = now(), $this->failure_reason = $reason],
            default => null,
        };
        $this->save();

        return true;
    }

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
            'own' => $staffView ? $this->from_staff : ! $this->from_staff,
            // Der Kunde MUSS erkennen, dass zunaechst ein Assistent antwortet
            // (Spezifikation Abschnitt 26); der Mitarbeiter sieht dieselbe
            // Kennzeichnung in der Beraterwelt (Abschnitt 27).
            'ai' => (bool) $this->ai_generated,
            'sender' => $this->from_staff
                ? ($this->ai_generated
                    ? __(self::AI_SENDER_NAME)
                    : ($this->sender?->name ?? 'Dienstly24 Team'))
                : ($this->customer?->user?->name ?? __('Kunde')),
            'show_sender' => $this->from_staff,
            'body' => $this->body,
            'day' => $this->created_at->isToday()
                ? __('Heute')
                : ($this->created_at->isYesterday() ? __('Gestern') : $this->created_at->lokal()->format('d.m.Y')),
            'time' => $this->created_at->lokal()->format('H:i'),
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
