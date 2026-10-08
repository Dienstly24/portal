<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein als "gemeinsam genutzt" markierter Kontaktwert (Familien-E-Mail,
 * Festnetz, Konto der Eltern, Mehrfamilienhaus). Er bildet in der
 * Dubletten-Pruefung kein Verdachtspaar mehr (PR-4, M2 des Berichts).
 *
 * Gespeichert ist NUR ein HMAC des normalisierten Werts - nie der Wert
 * selbst. Der Schluessel ist der APP_KEY: wird er gewechselt, greifen die
 * Markierungen nicht mehr (dann erscheinen die Paare wieder, verloren
 * geht nichts).
 */
class GeteilterKontaktwert extends Model
{
    protected $table = 'geteilte_kontaktdaten';

    protected $fillable = ['art', 'wert_hash', 'anzeige', 'notiz', 'created_by'];

    public const ARTEN = [
        'email' => 'E-Mail-Adresse',
        'telefon' => 'Telefonnummer',
        'iban' => 'Bankverbindung (IBAN)',
        'anschrift' => 'Anschrift',
    ];

    public static function hashFuer(string $art, string $schluessel): string
    {
        return hash_hmac('sha256', $art.'|'.$schluessel, (string) config('app.key'));
    }

    /**
     * Alle Markierungen als Nachschlage-Menge [art => [hash => true]] -
     * eine Abfrage je Dubletten-Lauf.
     *
     * @return array<string, array<string, bool>>
     */
    public static function hashMenge(): array
    {
        $menge = [];
        foreach (static::query()->get(['art', 'wert_hash']) as $z) {
            $menge[$z->art][$z->wert_hash] = true;
        }

        return $menge;
    }

    public function ersteller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
