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
 */
class CustomerChannelIdentity extends Model
{
    protected $fillable = [
        'customer_id', 'channel_id', 'channel_account_id',
        'external_user_id', 'external_username', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
}
