<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Beziehung zwischen zwei Kunden ("Verwandte Kunden"), die bewusst NICHT als
 * Dublette gelten. Jede Zeile - egal welcher Art - schliesst das Paar aus der
 * Dubletten-Pruefung aus.
 *
 * Das Paar wird immer in fester Reihenfolge gespeichert (a < b); eine
 * symmetrische Art existiert dadurch genau einmal. Die einzige GERICHTETE Art
 * ist `elternteil_kind`: die Richtung steht ausdruecklich in
 * `parent_customer_id` (gleich a ODER b). Je Paar und Art hoechstens eine
 * Zeile (UNIQUE a, b, type) - ein Paar kann z. B. Geschwister UND gleicher
 * Haushalt sein.
 *
 * Geschrieben wird ausschliesslich ueber den CustomerRelationshipService -
 * dort entsteht auch der Gleichlauf mit den Familienrollen
 * (customer_family_relations). Der saving-Hook hier ist die letzte Sperre
 * fuer die Richtungsregel; ein CHECK-Constraint ist auf MySQL nicht moeglich
 * (Spalten mit Fremdschluessel-Aktion).
 */
class CustomerRelationship extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_a_id', 'customer_b_id', 'type', 'parent_customer_id', 'note', 'created_by'];

    /**
     * Anzeige-Werte fuer die Listen, KEINE Spalten (echte Eigenschaften,
     * damit Eloquent sie nie als Attribut zu speichern versucht): Familienart
     * ohne passende Familienrolle bzw. Elternteil-Vorschlag aus den
     * Geburtsdaten.
     */
    public bool $unbestaetigt = false;

    public ?string $vorschlagElternteil = null;

    /** Klartext-Labels aller Arten (Reihenfolge = Reihenfolge in der Auswahl). */
    public const LABELS = [
        'ehepartner' => 'Ehepaar',
        'elternteil_kind' => 'Elternteil – Kind',
        'geschwister' => 'Geschwister',
        'sonstige_verwandte' => 'Sonstige Verwandte',
        'gleicher_haushalt' => 'Gleicher Haushalt',
        'nachbar' => 'Nachbarn',
        'sonstiges' => 'Sonstiges',
        'not_duplicate' => 'Kein Duplikat',
    ];

    /** Erlaubte Arten (Validierung). */
    public const TYPES = [
        'not_duplicate', 'ehepartner', 'elternteil_kind', 'geschwister',
        'sonstige_verwandte', 'gleicher_haushalt', 'nachbar', 'sonstiges',
    ];

    /** Arten, die im Dialog "Beziehung festlegen" waehlbar sind. */
    public const RELATION_TYPES = [
        'ehepartner', 'elternteil_kind', 'geschwister', 'sonstige_verwandte',
        'gleicher_haushalt', 'nachbar', 'sonstiges',
    ];

    /**
     * Arten mit einer Familienrolle (customer_family_relations). Je Paar gibt
     * es hoechstens EINE davon - die Familientabelle fuehrt eine Rolle je Paar.
     */
    public const FAMILY_TYPES = ['ehepartner', 'elternteil_kind', 'geschwister', 'sonstige_verwandte'];

    /** Sammel-Aktion: ohne Elternteil-Kind (die Richtung ist je Paar zu waehlen). */
    public const BULK_TYPES = [
        'not_duplicate', 'ehepartner', 'geschwister', 'sonstige_verwandte',
        'gleicher_haushalt', 'nachbar', 'sonstiges',
    ];

    /** Mindest-Altersabstand fuer den Elternteil-Vorschlag (Jahre). */
    public const PARENT_MIN_AGE_GAP = 16;

    public static function typeLabel(?string $type): string
    {
        return self::LABELS[$type] ?? 'Verwandt';
    }

    public static function typeEmoji(?string $type): string
    {
        return match ($type) {
            'ehepartner' => '💍',
            'elternteil_kind' => '👨‍👧',
            'geschwister' => '🧑‍🤝‍🧑',
            'sonstige_verwandte' => '👪',
            'gleicher_haushalt' => '🏠',
            'nachbar' => '🏘',
            'sonstiges' => '📝',
            default => '🔗',
        };
    }

    public static function isFamilyType(?string $type): bool
    {
        return in_array($type, self::FAMILY_TYPES, true);
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
        static::saving(function (self $m) {
            $a = (string) $m->customer_a_id;
            $b = (string) $m->customer_b_id;
            if ($a === '' || $a === $b) {
                throw new \InvalidArgumentException('Eine Beziehung braucht zwei verschiedene Kunden.');
            }
            if ($a > $b) {
                [$m->customer_a_id, $m->customer_b_id] = [$b, $a];
            }
            if (! in_array($m->type, self::TYPES, true)) {
                throw new \InvalidArgumentException('Unbekannte Beziehungsart: '.$m->type);
            }
            $parent = $m->parent_customer_id === null ? null : (string) $m->parent_customer_id;
            if ($m->type === 'elternteil_kind') {
                if ($parent !== $a && $parent !== $b) {
                    throw new \InvalidArgumentException('Elternteil – Kind: der Elternteil muss einer der beiden Kunden sein.');
                }
            } elseif ($parent !== null) {
                throw new \InvalidArgumentException('Ein Elternteil ist nur bei „Elternteil – Kind" erlaubt.');
            }
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customerA(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_a_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customerB(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_b_id');
    }

    /** Die jeweils andere Seite des Paares. */
    public function otherCustomerId(string $customerId): string
    {
        return (string) $this->customer_a_id === $customerId
            ? (string) $this->customer_b_id
            : (string) $this->customer_a_id;
    }

    /**
     * Was die ANDERE Seite aus Sicht von $customerId ist - bei Elternteil-Kind
     * "Elternteil" bzw. "Kind", sonst das Label der Art.
     */
    public function labelFor(string $customerId): string
    {
        if ($this->type !== 'elternteil_kind') {
            return self::typeLabel($this->type);
        }

        return (string) $this->parent_customer_id === $customerId ? 'Kind' : 'Elternteil';
    }

    /** Text der Richtung fuer Listen mit beiden Seiten ("X ist Elternteil von Y"). */
    public function directionText(): ?string
    {
        if ($this->type !== 'elternteil_kind') {
            return null;
        }
        $parent = (string) $this->parent_customer_id === (string) $this->customer_a_id ? $this->customerA : $this->customerB;
        $child = (string) $this->parent_customer_id === (string) $this->customer_a_id ? $this->customerB : $this->customerA;

        return ($parent?->user?->name ?: 'Kunde').' ist Elternteil von '.($child?->user?->name ?: 'Kunde');
    }

    /**
     * Vorschlag fuer den Elternteil: der AELTERE, wenn beide Geburtsdaten
     * bekannt sind und mindestens PARENT_MIN_AGE_GAP Jahre dazwischen liegen.
     * Sonst null - ein Alter wird nie geraten.
     */
    public static function suggestParent(Customer $a, Customer $b): ?string
    {
        if (empty($a->birth_date) || empty($b->birth_date)) {
            return null;
        }
        $da = Carbon::parse($a->birth_date);
        $db = Carbon::parse($b->birth_date);
        [$older, $younger, $id] = $da->lessThan($db) ? [$da, $db, (string) $a->id] : [$db, $da, (string) $b->id];

        return $older->diffInYears($younger) >= self::PARENT_MIN_AGE_GAP ? $id : null;
    }

    /** Reihenfolge-unabhaengiger Schluessel eines Paares (a < b). */
    public static function pairKey(string $x, string $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** Set aller markierten Paare als "a|b"-Schluessel (fuer schnellen Ausschluss). */
    public static function dismissedKeySet(): array
    {
        return static::query()
            ->get(['customer_a_id', 'customer_b_id'])
            ->mapWithKeys(fn ($r) => [$r->customer_a_id.'|'.$r->customer_b_id => true])
            ->all();
    }
}
