<?php
namespace App\Models;

use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Interessent aus dem Website-Assistenten (Spezifikation Abschnitt 20).
 *
 * Ein Lead ist bewusst KEIN Kunde: der Besucher hat nichts unterschrieben
 * und oft noch keine Akte. Erst wenn ein Mitarbeiter daraus einen Kunden
 * macht, zeigt customer_id darauf - der Lead bleibt als Herkunft stehen
 * (Quelle, Zeitpunkt, was die KI gesammelt hat).
 *
 * Warum eine eigene Tabelle und nicht `tickets`: ein Ticket ist ein
 * Vorgang mit Bearbeitungsstand, ein Lead ist ein Verkaufsdatensatz mit
 * Gespraechszustand und Angebotsauswahl. Ein zusaetzliches Ticket kann
 * jederzeit daraus entstehen, umgekehrt waere es eine Zweckentfremdung.
 */
class AiLead extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const SOURCE_WEBSITE = 'website';
    public const SOURCE_PORTAL = 'portal';

    public const STATUS_NEW_CUSTOMER = 'neukunde';
    public const STATUS_EXISTING_CUSTOMER = 'bestandskunde';

    protected $fillable = [
        'customer_id', 'source', 'intent', 'state', 'service', 'contact',
        'address', 'collected', 'transcript', 'customer_status', 'verification_status',
        'next_action', 'selected_offer_id', 'assigned_employee_id', 'ticket_id',
    ];

    protected $attributes = [
        'source' => self::SOURCE_WEBSITE,
        'state' => ConversationState::NEW,
    ];

    protected function casts(): array
    {
        return [
            // Kontaktangaben eines Interessenten sind Personendaten.
            'contact' => 'encrypted:array',
            'address' => 'encrypted',
            'collected' => 'encrypted:array',
            'transcript' => 'encrypted:array',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function employee() { return $this->belongsTo(User::class, 'assigned_employee_id'); }
    public function offers() { return $this->hasMany(AiOffer::class, 'lead_id')->orderBy('label'); }

    public function collectedData(): array
    {
        return is_array($this->collected) ? $this->collected : [];
    }

    public function contactData(): array
    {
        return is_array($this->contact) ? $this->contact : [];
    }

    /** Angaben ergaenzen; ein leerer Wert loescht nie einen vorhandenen. */
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

    /**
     * Gespraechsverlauf (aeltester zuerst). Begrenzt, damit ein sehr
     * langes Gespraech die Zeile nicht sprengt.
     */
    public function transcriptData(): array
    {
        return is_array($this->transcript) ? $this->transcript : [];
    }

    public function appendTranscript(string $role, string $text): self
    {
        $verlauf = $this->transcriptData();
        $verlauf[] = ['rolle' => $role, 'text' => mb_substr(trim($text), 0, 1000)];

        $this->forceFill(['transcript' => array_slice($verlauf, -20)])->save();

        return $this;
    }

    public function stateLabel(): string
    {
        return ConversationState::label($this->state);
    }

    public function intentLabel(): string
    {
        return RequirementProfile::intentLabel($this->intent);
    }

    /** Anzeigename: was der Besucher genannt hat, sonst die Herkunft. */
    public function displayName(): string
    {
        $kontakt = $this->contactData();

        return trim((string) ($kontakt['name'] ?? ''))
            ?: trim((string) ($kontakt['email'] ?? ''))
            ?: 'Interessent (' . $this->source . ')';
    }
}
