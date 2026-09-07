<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zugang zu einem KI-Anbieter (Auftrag Abschnitte 81/95/101).
 *
 * SICHERHEIT wie beim Kanalkonto: `credentials` ist verschluesselt
 * gecastet UND in `$hidden` - ein versehentliches `return $account;` in
 * einem Controller kann den Schluessel damit nicht ausliefern.
 */
class AiProviderAccount extends Model
{
    protected $fillable = [
        'provider', 'name', 'credentials', 'model', 'settings',
        'is_active', 'last_tested_at', 'last_test_status',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function apiKey(): ?string
    {
        $key = trim((string) (($this->credentials ?? [])['api_key'] ?? ''));

        return $key !== '' ? $key : null;
    }

    /** Ist dieser Zugang einsatzbereit - also mit Schluessel? */
    public function usable(): bool
    {
        return $this->is_active && $this->apiKey() !== null;
    }
}
