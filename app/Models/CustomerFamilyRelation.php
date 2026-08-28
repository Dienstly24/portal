<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eine gerichtete Familienbeziehung zwischen ZWEI bestehenden Kundenakten.
 *
 * Lesart: "`related_customer_id` ist `relationship_type` von `customer_id`".
 * Jede Beziehung existiert immer als PAAR (Hin- und Rueckrichtung), damit man
 * vom Elternteil zum Kind und vom Kind zurueck zu den Eltern navigieren kann.
 * Angelegt und gepflegt wird das Paar ausschliesslich ueber den
 * FamilyRelationService - er ist die EINE Stelle, an der die Gegenrichtung
 * entsteht.
 */
class CustomerFamilyRelation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'related_customer_id', 'relationship_type', 'is_dependent',
        'valid_from', 'valid_until', 'independent_since', 'transition_prepared_at',
        'note', 'created_by',
    ];

    protected $casts = [
        'is_dependent' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'independent_since' => 'datetime',
        'transition_prepared_at' => 'datetime',
    ];

    /**
     * Familienrollen. Bewusst getrennt vom KUNDENSTATUS (siehe
     * Customer::familyStatus): eine 16-jaehrige Tochter ist eigenstaendige
     * Kundin UND bleibt Tochter.
     */
    public const ROLES = [
        'ehepartner'  => 'Ehepartner/in',
        'vater'       => 'Vater',
        'mutter'      => 'Mutter',
        'elternteil'  => 'Elternteil',
        'sohn'        => 'Sohn',
        'tochter'     => 'Tochter',
        'kind'        => 'Kind',
        'geschwister' => 'Geschwister',
        'sonstiges'   => 'Sonstiges Familienmitglied',
    ];

    /** Rollen, die im Formular direkt waehlbar sind (Betreiber-Vorgabe). */
    public const SELECTABLE_ROLES = ['ehepartner', 'vater', 'mutter', 'sohn', 'tochter', 'kind', 'geschwister', 'sonstiges'];

    /** Rollen, die ein KIND der Bezugsperson beschreiben (Abhaengigkeit moeglich). */
    public const CHILD_ROLES = ['sohn', 'tochter', 'kind'];

    /** Rollen, die einen ELTERNTEIL der Bezugsperson beschreiben. */
    public const PARENT_ROLES = ['vater', 'mutter', 'elternteil'];

    public static function roleLabel(?string $role): string
    {
        return self::ROLES[$role] ?? 'Familienmitglied';
    }

    public static function roleEmoji(?string $role): string
    {
        return match ($role) {
            'ehepartner' => '💍',
            'vater', 'mutter', 'elternteil' => '🧑‍🦱',
            'sohn' => '👦',
            'tochter' => '👧',
            'kind' => '🧒',
            'geschwister' => '🧑‍🤝‍🧑',
            default => '👤',
        };
    }

    /**
     * Gegenrolle fuer die Rueckrichtung.
     *
     * Zeile (A, B, R) heisst "B ist R von A". Die Gegenzeile (B, A, R') heisst
     * "A ist R' von B" - R' beschreibt also A und richtet sich nach dem
     * GESCHLECHT VON A. Ist es unbekannt, wird die neutrale Form genommen
     * (nie geraten).
     */
    public static function inverseRole(string $role, ?string $genderOfSubject): string
    {
        $gender = strtolower(trim((string) $genderOfSubject));

        if (in_array($role, self::CHILD_ROLES, true)) {
            // B ist Kind von A -> A ist Elternteil von B.
            return match ($gender) {
                'male' => 'vater',
                'female' => 'mutter',
                default => 'elternteil',
            };
        }

        if (in_array($role, self::PARENT_ROLES, true)) {
            // B ist Elternteil von A -> A ist Kind von B.
            return match ($gender) {
                'male' => 'sohn',
                'female' => 'tochter',
                default => 'kind',
            };
        }

        // Symmetrische Rollen.
        return match ($role) {
            'ehepartner' => 'ehepartner',
            'geschwister' => 'geschwister',
            default => 'sonstiges',
        };
    }

    /** Passende Beziehungsart fuer die Dubletten-Ausnahme (customer_relationships). */
    public static function duplicateExemptionType(string $role): string
    {
        return $role === 'ehepartner' ? 'spouse' : 'family';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function relatedCustomer()
    {
        return $this->belongsTo(Customer::class, 'related_customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Beziehung ist heute gueltig (kein Ende gesetzt oder Ende in der Zukunft). */
    public function isCurrent(): bool
    {
        return $this->valid_until === null || $this->valid_until->gte(Carbon::today());
    }

    /**
     * Ist das verknuepfte Familienmitglied HEUTE ein abhaengiges Kind?
     *
     * Bewusst nicht nur das gespeicherte Flag: der Tageslauf
     * `familie:uebergaenge-anwenden` zieht es nach, aber die ANZEIGE darf nicht
     * davon abhaengen, dass der Cron gelaufen ist. Ohne Geburtsdatum gilt
     * niemand als abhaengig - ein Alter wird nie geraten.
     */
    public function dependentNow(): bool
    {
        if (!$this->is_dependent || !$this->isCurrent()) {
            return false;
        }
        $age = $this->relatedCustomer?->age();

        return $age !== null && $age < Customer::DEPENDENT_AGE;
    }

    /** Tag des 15. Geburtstags des verknuepften Kindes (oder null). */
    public function independenceDate(): ?Carbon
    {
        $birth = $this->relatedCustomer?->birth_date;
        if (empty($birth)) {
            return null;
        }

        return Carbon::parse($birth)->addYears(Customer::DEPENDENT_AGE)->startOfDay();
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
