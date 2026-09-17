<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EIN Kunde, VIELE Kanal-Identitaeten.
 *
 * Ohne diese Tabelle bekaeme die Kundenakte mit jeder Anbindung eine
 * weitere Spalte (whatsapp_id, instagram_id, telegram_id ...) und wuerde
 * nie wieder schrumpfen. Hier ist die Zuordnung DATEN - eine neue
 * Anbindung schreibt eine Zeile, keine Migration.
 *
 * SEIT 17.09.2026 traegt jede Zeile ausserdem, WIE sie entstanden ist
 * (Auftrag Abschnitt 8). Vorher sah man einer Zuordnung nicht an, ob ein
 * Mensch die Akte ausgewaehlt hat oder ob eine Telefonnummer zufaellig
 * zu genau einem Kunden passte. Beides stand als derselbe Datensatz da -
 * obwohl das eine ein BELEG und das andere ein INDIZ ist.
 */
class CustomerChannelIdentity extends Model
{
    /**
     * Ein Mensch hat diese Akte ausgewaehlt. Der staerkste Beleg, den es
     * gibt - jemand haftet dafuer.
     */
    public const METHOD_MANUAL = 'manual';

    /** Die Kennung der Plattform war bereits zugeordnet. */
    public const METHOD_IDENTITY = 'identity';

    /** Telefonnummer traf GENAU EINEN Kunden. Indiz, kein Beleg. */
    public const METHOD_PHONE = 'phone_exact';

    /** E-Mail traf GENAU EINEN Kunden. Indiz, kein Beleg. */
    public const METHOD_EMAIL = 'email_exact';

    public const METHODS = [
        self::METHOD_MANUAL => 'Von einem Mitarbeiter zugeordnet',
        self::METHOD_IDENTITY => 'Bereits bekannte Kennung',
        self::METHOD_PHONE => 'Über die Telefonnummer erkannt',
        self::METHOD_EMAIL => 'Über die E-Mail-Adresse erkannt',
    ];

    /**
     * Die Verfahren, die nur ein INDIZ liefern.
     *
     * Eine Rufnummer kann weitergegeben, uebernommen oder von einem
     * Familienmitglied benutzt werden; eine Adresse kann ein
     * Firmen- oder Familienpostfach sein. Es ist trotzdem richtig, sie
     * zu benutzen - die meisten Treffer stimmen. Falsch waere nur, so zu
     * tun, als haette ein Mensch hingeschaut.
     */
    public const UNCERTAIN_METHODS = [self::METHOD_PHONE, self::METHOD_EMAIL];

    protected $fillable = [
        'customer_id', 'channel_id', 'channel_account_id',
        'external_user_id', 'external_username', 'metadata',
        'match_method', 'verified_by', 'verified_at',
    ];

    protected $casts = ['metadata' => 'array', 'verified_at' => 'datetime'];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    /**
     * Wartet diese Zuordnung noch auf eine menschliche Bestaetigung?
     *
     * NUR die unsicheren Verfahren, und nur solange niemand bestaetigt
     * hat. Ein fehlendes `match_method` (Altbestand vor dem 17.09.2026)
     * gilt AUSDRUECKLICH NICHT als ungeprueft: die Herkunft wurde damals
     * nicht vermerkt, mehr sagt das Feld nicht. Den gesamten Bestand
     * rueckwirkend mit einer Warnung zu versehen, die niemand veranlasst
     * hat, haette die echten Faelle darin untergehen lassen - dieselbe
     * Ueberlegung wie bei den Fehler-Eintraegen: nur echte Defekte, sonst
     * ist die Anzeige wertlos.
     */
    public function needsVerification(): bool
    {
        return in_array($this->match_method, self::UNCERTAIN_METHODS, true)
            && $this->verified_at === null;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->match_method] ?? 'Herkunft nicht vermerkt';
    }

    /** Ein Mensch bestaetigt die Zuordnung. Ab dann ist sie ein Beleg. */
    public function confirm(User $benutzer): void
    {
        $this->forceFill([
            'verified_by' => $benutzer->id,
            'verified_at' => now(),
        ])->save();
    }
}
