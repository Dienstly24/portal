<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kanal-DEFINITION (whatsapp, instagram, portal, internal ...).
 *
 * Die Faehigkeiten stehen als DATEN in `capabilities`, nicht als
 * Bedingungen im Conversation Engine. Der Engine fragt "kann dieser
 * Kanal Medien?" - nie "ist dieser Kanal WhatsApp?". Genau dadurch
 * kostet ein neuer Kanal spaeter keinen Eingriff im Kern.
 */
class Channel extends Model
{
    public const INTERNAL = 'internal';
    public const PORTAL = 'portal';

    protected $fillable = ['key', 'name', 'driver', 'is_active', 'capabilities', 'sort'];
    protected $casts = ['is_active' => 'boolean', 'capabilities' => 'array'];

    public function accounts() { return $this->hasMany(ChannelAccount::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** Unbekannte Faehigkeit gilt als NICHT vorhanden - nie optimistisch raten. */
    public function supports(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    /**
     * Kanal ueber seinen Schluessel. Gecacht, weil praktisch jede
     * Nachricht ihn braucht und die Tabelle sich fast nie aendert.
     * Bewusst nur die ID im Cache - ein Eloquent-Objekt kaeme aus
     * database/file/redis als __PHP_Incomplete_Class zurueck.
     */
    public static function idFor(string $key): ?int
    {
        $id = cache()->remember(
            'channel_id:'.$key,
            now()->addHour(),
            fn () => static::where('key', $key)->value('id') ?? 0
        );

        return $id ?: null;
    }
}
