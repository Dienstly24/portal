<?php
namespace App\Models;

use App\Services\Ai\Assistant\Sales\ConversationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Steuerstand des KI-Kundenassistenten je Kunde (Spezifikation
 * Abschnitte 15/16). Die Unterhaltung selbst ist der bestehende
 * Portal-Chat (customer_messages) - diese Zeile sagt nur, WER gerade
 * antwortet.
 *
 * Zwei Zustaende bestimmen alles:
 *  ai_active = false        -> ein Mitarbeiter fuehrt das Gespraech
 *  handover_required = true -> Uebergabe offen, KI schweigt
 * Nur wenn beides passt (aktiv, keine offene Uebergabe), darf eine
 * automatische Antwort entstehen -> canAutoReply().
 */
class AiConversation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    /** Uebergabegruende (Abschnitt 12) - Anzeige ueber REASON_LABELS. */
    public const REASON_OUT_OF_SCOPE = 'out_of_scope';
    public const REASON_UNCERTAIN = 'uncertain';
    public const REASON_CUSTOMER_REQUEST = 'customer_request';
    public const REASON_SENSITIVE = 'sensitive';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_INJECTION = 'prompt_injection';
    public const REASON_SERVICE_DOWN = 'service_unavailable';
    public const REASON_LIMIT = 'limit_reached';
    public const REASON_STAFF = 'staff_takeover';

    public const REASON_LABELS = [
        self::REASON_OUT_OF_SCOPE => 'Anfrage außerhalb des Kundenservice-Bereichs',
        self::REASON_UNCERTAIN => 'KI war sich nicht sicher – manuelle Prüfung',
        self::REASON_CUSTOMER_REQUEST => 'Kunde hat ausdrücklich einen Mitarbeiter verlangt',
        self::REASON_SENSITIVE => 'Rechtlich/vertraglich sensibel – Mitarbeiter erforderlich',
        self::REASON_COMPLAINT => 'Beschwerde',
        self::REASON_INJECTION => 'Unzulässiger Versuch, die KI-Regeln zu umgehen',
        self::REASON_SERVICE_DOWN => 'KI-Dienst nicht verfügbar',
        self::REASON_LIMIT => 'Grenze automatischer Antworten erreicht',
        self::REASON_STAFF => 'Mitarbeiter hat übernommen',
    ];

    /** Betriebszustand der KI (Abschnitt 13) - getrennt vom Gespraechszustand. */
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'customer_id', 'ai_active', 'handover_required', 'handover_reason', 'handover_at',
        'assigned_employee_id', 'last_ai_action', 'last_ai_response', 'summary',
        // Wiederaufnahme nach einer Uebernahme (Betreiber-Vorgabe 20.08.2026)
        'auto_resume', 'resume_not_before', 'resume_ticket_id', 'resumed_at',
        'last_ai_at', 'auto_reply_count',
        // Verkaufsassistent (Abschnitte 12/13/14)
        'state', 'intent', 'category', 'channel', 'lead_id', 'collected',
        'verification_status', 'selected_offer_id', 'status', 'paused_reason',
        'last_successful_step', 'current_step', 'next_action', 'last_error_at',
    ];

    /**
     * Startwerte AUCH im Speicher (nicht nur als Spalten-Default): ein per
     * firstOrCreate() angelegter Steuerstand traegt die DB-Vorgaben sonst
     * erst nach einem refresh() - canAutoReply() haette dann auf null
     * geprueft und die KI waere fuer jeden neuen Kunden stumm geblieben.
     */
    protected $attributes = [
        'ai_active' => true,
        'handover_required' => false,
        'auto_resume' => true,
        'auto_reply_count' => 0,
        'state' => ConversationState::NEW,
        'channel' => 'portal',
        'status' => self::STATUS_RUNNING,
    ];

    protected function casts(): array
    {
        return [
            'ai_active' => 'boolean',
            'handover_required' => 'boolean',
            'handover_at' => 'datetime',
            'last_ai_at' => 'datetime',
            'auto_resume' => 'boolean',
            'resume_not_before' => 'datetime',
            'resumed_at' => 'datetime',
            'auto_reply_count' => 'integer',
            // Enthalten Kundendaten (letzte Antwort, Zusammenfassung fuer
            // den Mitarbeiter) -> verschluesselt at rest, wie AiDecision.
            'last_ai_response' => 'encrypted',
            'summary' => 'encrypted',
            // Gesammelte Kundenangaben (Abschnitt 14) - koennen sensible
            // Werte enthalten, deshalb verschluesselt at rest.
            'collected' => 'encrypted:array',
            'last_error_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function employee() { return $this->belongsTo(User::class, 'assigned_employee_id'); }
    public function logs() { return $this->hasMany(AiAssistantLog::class, 'conversation_id')->latest(); }

    /** Steuerstand des Kunden holen/anlegen (Standard: KI aktiv). */
    public static function forCustomer(string $customerId): self
    {
        return static::firstOrCreate(['customer_id' => $customerId]);
    }

    /**
     * Darf die KI jetzt automatisch antworten? Beide Sperren zaehlen -
     * ein Mitarbeiter, der uebernommen hat, wird nie von der KI
     * ueberstimmt (Abschnitt 15).
     */
    public function canAutoReply(): bool
    {
        return $this->ai_active && !$this->handover_required;
    }

    public function reasonLabel(): ?string
    {
        return $this->handover_reason
            ? (self::REASON_LABELS[$this->handover_reason] ?? $this->handover_reason)
            : null;
    }

    /**
     * Uebergabe an das Team vermerken. Die KI schweigt danach, bis ein
     * Mitarbeiter sie bewusst wieder aktiviert.
     */
    public function markHandover(string $reason, ?string $summary = null): self
    {
        $this->forceFill([
            'handover_required' => true,
            'handover_reason' => $reason,
            'handover_at' => now(),
            'summary' => $summary ?: $this->summary,
        ])->save();

        return $this;
    }

    /**
     * Gruende, nach denen die KI NIE von selbst zurueckkommt
     * (Betreiber-Entscheidung 20.08.2026). Eine Beschwerde bleibt
     * Chefsache: eine automatische Antwort danach liest sich wie
     * Gleichgueltigkeit, egal wie gut sie formuliert ist.
     */
    public const NO_AUTO_RESUME_REASONS = [
        self::REASON_COMPLAINT,
    ];

    /**
     * Mitarbeiter uebernimmt: KI aus, Uebergabe erledigt, Zaehler zurueck
     * (das Gespraech beginnt fuer die KI danach neu).
     *
     * Die Uebernahme gilt dem VORGANG, nicht dem Kunden: `resume_ticket_id`
     * und `resume_not_before` halten fest, wann die KI wieder einfache
     * Anfragen beantworten darf (siehe ConversationResumeService). Wer sie
     * dauerhaft aus haben will, nutzt deactivate() - das ist der Knopf
     * "KI deaktivieren" und setzt auto_resume auf false.
     *
     * @param string|null $ticketId Vorgang, dessen Abschluss die KI freigibt
     * @param int $quietHours Ruhefrist ohne Mitarbeiter-Nachricht
     */
    public function takeOver(int $employeeId, ?string $ticketId = null, int $quietHours = 24): self
    {
        $this->forceFill([
            'ai_active' => false,
            'handover_required' => false,
            'handover_reason' => self::REASON_STAFF,
            'assigned_employee_id' => $employeeId,
            'auto_reply_count' => 0,
            'auto_resume' => true,
            'resume_ticket_id' => $ticketId,
            'resume_not_before' => now()->addHours(max(1, $quietHours)),
            'resumed_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Ruhefrist neu setzen - aufgerufen, sobald ein Mitarbeiter dem Kunden
     * schreibt. Solange am Fall gearbeitet wird, faengt die Frist wieder
     * von vorne an; die KI faellt niemandem ins Wort.
     */
    public function postponeResume(int $quietHours = 24): self
    {
        if (!$this->auto_resume) {
            return $this;
        }

        $this->forceFill([
            'resume_not_before' => now()->addHours(max(1, $quietHours)),
        ])->save();

        return $this;
    }

    /**
     * Darf die KI diesen Fall ueberhaupt von selbst wieder uebernehmen?
     * Beschwerden und ein bewusstes "dauerhaft aus" schliessen das aus.
     */
    public function mayAutoResume(): bool
    {
        return $this->auto_resume
            && !in_array((string) $this->handover_reason, self::NO_AUTO_RESUME_REASONS, true);
    }

    /**
     * KI wieder freigeben. Standard ist die bewusste Mitarbeiter-Aktion
     * (Abschnitt 15); $automatisch = true kommt aus der Wiederaufnahme
     * nach abgeschlossenem Vorgang bzw. abgelaufener Ruhefrist und wird
     * am Gespraech vermerkt (resumed_at), damit im Panel steht, dass hier
     * niemand geklickt hat.
     */
    public function reactivate(bool $automatisch = false): self
    {
        $this->forceFill([
            'ai_active' => true,
            'handover_required' => false,
            'handover_reason' => null,
            'handover_at' => null,
            'auto_reply_count' => 0,
            'auto_resume' => true,
            'resume_not_before' => null,
            'resume_ticket_id' => null,
            'resumed_at' => $automatisch ? now() : null,
        ])->save();

        return $this;
    }

    /**
     * KI stumm schalten, ohne eine Uebergabe zu behaupten - und zwar
     * DAUERHAFT: das ist die bewusste Entscheidung "dieser Kunde wird von
     * Menschen betreut". Keine Ruhefrist holt sie zurueck, nur der Knopf
     * "KI wieder aktivieren".
     */
    public function deactivate(): self
    {
        $this->forceFill([
            'ai_active' => false,
            'auto_resume' => false,
            'resume_not_before' => null,
            'resume_ticket_id' => null,
        ])->save();

        return $this;
    }

    // ---------------------------------------------------------------
    // Verkaufsassistent: Zustand, Kontext, Stoerung (Abschnitte 12-14)
    // ---------------------------------------------------------------

    public function offers() { return $this->hasMany(AiOffer::class, 'conversation_id')->orderBy('label'); }
    public function events() { return $this->hasMany(AiConversationEvent::class, 'conversation_id')->latest(); }
    public function selectedOffer() { return $this->belongsTo(AiOffer::class, 'selected_offer_id'); }
    public function lead() { return $this->belongsTo(AiLead::class, 'lead_id'); }

    public function stateLabel(): string
    {
        return ConversationState::label($this->state);
    }

    /**
     * Zustand wechseln - NUR ueber erlaubte Uebergaenge.
     *
     * Ein abgelehnter Uebergang ist kein Fehler, sondern der Normalfall
     * eines Modells, das zu weit springen will: der Zustand bleibt dann
     * einfach stehen. Rueckgabe sagt, ob gewechselt wurde.
     */
    public function moveTo(string $state, ?string $step = null): bool
    {
        if (!ConversationState::allows($this->state, $state)) {
            return false;
        }

        $vorher = $this->state;
        $this->forceFill([
            'state' => $state,
            'next_action' => ConversationState::nextAction($state),
            'last_successful_step' => $step ?: $this->last_successful_step,
        ])->save();

        return $vorher !== $state;
    }

    /** Gesammelte Angaben (immer ein Array, auch wenn nie etwas erfasst wurde). */
    public function collectedData(): array
    {
        $daten = $this->collected;

        return is_array($daten) ? $daten : [];
    }

    /**
     * Angaben ergaenzen. Ein LEERER neuer Wert loescht nie einen
     * vorhandenen - dieselbe Regel wie im Dokumenten-Eingang, damit eine
     * unklare Kundennachricht keinen bereits erfassten Wert vernichtet.
     */
    public function remember(array $werte): self
    {
        $daten = $this->collectedData();
        foreach ($werte as $key => $wert) {
            if ($wert === null || trim((string) $wert) === '') {
                continue;
            }
            $daten[$key] = (string) $wert;
        }

        $this->forceFill(['collected' => $daten])->save();

        return $this;
    }

    /** Ist die KI wegen einer Stoerung angehalten (Abschnitt 13)? */
    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Stoerung festhalten. Ohne diesen Vermerk sieht der Mitarbeiter nur,
     * dass nichts passiert - genau das soll nie wieder vorkommen.
     */
    public function pause(string $reason, ?string $currentStep = null): self
    {
        $this->forceFill([
            'status' => self::STATUS_PAUSED,
            'paused_reason' => Str::limit($reason, 190),
            'current_step' => $currentStep ?: $this->current_step,
            'next_action' => 'Erneut versuchen oder Unterhaltung übernehmen',
            'last_error_at' => now(),
        ])->save();

        return $this;
    }

    /** Stoerung beendet - der Betrieb laeuft weiter. */
    public function resume(): self
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'paused_reason' => null,
            'next_action' => ConversationState::nextAction($this->state),
        ])->save();

        return $this;
    }
}
