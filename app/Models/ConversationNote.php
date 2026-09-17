<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INTERNE NOTIZ an einer Unterhaltung (Auftrag Abschnitt 13).
 *
 * "Warte auf die Bestaetigung der Werkstatt" ist keine Nachricht an den
 * Kunden und keine Eigenschaft des Kunden - es ist eine Randbemerkung
 * zu DIESEM Vorgang. Bisher gab es dafuer keinen Ort: entweder man
 * schrieb es als Kundennotiz (steht dort noch in zwei Jahren) oder gar
 * nicht.
 *
 * DIE TRENNUNG IST STRUKTURELL, nicht durch eine Bedingung: das
 * Kundenportal kann eine Notiz nicht laden, weil es diese Tabelle nicht
 * kennt. Ein Flag `ist_intern` an `customer_messages` waere nur so lange
 * sicher, wie JEDE Abfrage im Portal daran denkt - und die naechste neue
 * Abfrage denkt nicht daran. Eine vergessene Bedingung waere hier eine
 * interne Bemerkung im Chat des Kunden.
 *
 * UNVERAENDERLICH: es gibt kein `updated_at` und keinen Aenderungsweg.
 * Eine Notiz, die man nachtraeglich umschreiben kann, taugt nicht als
 * Gedaechtnis eines Vorgangs - dieselbe Regel wie bei
 * `signature_events`.
 */
class ConversationNote extends Model
{
    /** Kein updated_at - siehe Klassenkommentar. */
    public const UPDATED_AT = null;

    /**
     * Nur fuer eigene Leute.
     *
     * Die Voreinstellung ist bewusst die STRENGERE: eine Notiz, bei der
     * niemand ueber die Sichtbarkeit nachgedacht hat, bleibt drinnen.
     */
    public const VISIBILITY_INTERNAL = 'internal';

    /**
     * Auch fuer einen beauftragten externen Support sichtbar.
     *
     * Die Stufe existiert schon, bevor es die Rolle gibt (geplante
     * Phase 10). Sie spaeter einzufuehren hiesse, den gesamten
     * Altbestand an Notizen in einen Zustand zu versetzen, den niemand
     * geprueft hat.
     */
    public const VISIBILITY_SUPPORT = 'support';

    public const VISIBILITIES = [
        self::VISIBILITY_INTERNAL => 'Nur intern',
        self::VISIBILITY_SUPPORT => 'Auch für externen Support',
    ];

    protected $fillable = ['conversation_id', 'user_id', 'body', 'visibility'];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function visibilityLabel(): string
    {
        return self::VISIBILITIES[$this->visibility] ?? $this->visibility;
    }

    /** Unbekannte Stufen gelten als intern - nie optimistisch oeffnen. */
    public static function validVisibility(?string $wert): bool
    {
        return $wert !== null && array_key_exists($wert, self::VISIBILITIES);
    }
}
