<?php

namespace App\Services\Ai\Assistant;

use App\Models\AiProviderAccount;

/**
 * WOHER kommen Anbieter, Schluessel und Modell? (Auftrag 81/95/101)
 *
 * Ab jetzt aus ZWEI Quellen - der Oberflaeche und der Server-`.env`. Zwei
 * Quellen ohne erklaerte Rangfolge sind eine Zufallsentscheidung, die
 * niemand nachvollziehen kann, wenn es einmal klemmt. Deshalb steht die
 * Regel hier, an einer Stelle, und `explain()` gibt sie jederzeit aus:
 *
 *  1. `AI_ASSISTANT_PROVIDER=none` ist die NOTBREMSE und schlaegt alles.
 *     Ein Notaus, den eine Datenbankzeile aushebeln kann, ist keiner.
 *  2. Ein AKTIVER Zugang MIT Schluessel aus der Oberflaeche gewinnt.
 *     Das ist der Sinn der Umstellung: der Betreiber soll wechseln
 *     koennen, ohne die `.env` anzufassen.
 *  3. Sonst gilt unveraendert die `.env` (Bestand). Ein Portal, in dem
 *     noch kein Zugang gepflegt ist, laeuft damit exakt wie bisher -
 *     diese Aenderung schaltet von sich aus gar nichts um.
 *
 * Der Schluessel wird hier NUR gelesen und weitergegeben; er wird nie
 * protokolliert, nie zurueckgegeben an die Oberflaeche und nie in eine
 * Ausnahme geschrieben.
 */
class AiProviderSettings
{
    /** Zwischenspeicher je Anfrage - der Zugang aendert sich nicht mitten im Request. */
    private ?AiProviderAccount $cached = null;
    private bool $loaded = false;

    /** Der aktive Zugang aus der Oberflaeche, falls einer einsatzbereit ist. */
    public function account(): ?AiProviderAccount
    {
        if (! $this->loaded) {
            $this->loaded = true;
            try {
                $this->cached = AiProviderAccount::active()->latest('updated_at')->first();
            } catch (\Throwable) {
                // Vor der Migration (oder bei einer Stoerung) faellt alles
                // auf die .env zurueck - der Assistent darf an dieser
                // Stelle nie ausfallen.
                $this->cached = null;
            }
        }

        return $this->cached?->usable() ? $this->cached : null;
    }

    /** Welcher Anbieter gilt: 'claude', 'openai', 'none' ... */
    public function provider(): string
    {
        $konfiguriert = strtolower(trim((string) config('services.ai_assistant_provider', 'claude')));

        // Regel 1: die Notbremse steht ueber der Oberflaeche.
        if ($konfiguriert === 'none') {
            return 'none';
        }

        // Regel 2: gepflegter Zugang gewinnt.
        if ($account = $this->account()) {
            return strtolower($account->provider);
        }

        // Regel 3: Bestand.
        return $konfiguriert !== '' ? $konfiguriert : 'claude';
    }

    /**
     * Schluessel fuer einen Anbieter. `$fallback` ist der bisherige Wert
     * aus der Konfiguration - der Aufrufer reicht ihn herein, damit diese
     * Klasse nichts ueber die Konfigurationsschluessel der einzelnen
     * Anbieter wissen muss.
     */
    public function apiKey(string $provider, ?string $fallback = null): ?string
    {
        $account = $this->account();
        if ($account && strtolower($account->provider) === strtolower($provider)) {
            return $account->apiKey();
        }

        $fallback = trim((string) $fallback);

        return $fallback !== '' ? $fallback : null;
    }

    /** Modell fuer einen Anbieter - leer gepflegt heisst "wie bisher". */
    public function model(string $provider, string $fallback): string
    {
        $account = $this->account();
        if ($account && strtolower($account->provider) === strtolower($provider)) {
            $model = trim((string) $account->model);
            if ($model !== '') {
                return $model;
            }
        }

        return $fallback;
    }

    /**
     * Herkunft im Klartext - fuer die Oberflaeche und `ki:pruefen`.
     * NIE der Wert selbst, nur die QUELLE.
     *
     * @return array{provider:string,source:string,model:?string}
     */
    public function explain(): array
    {
        $konfiguriert = strtolower(trim((string) config('services.ai_assistant_provider', 'claude')));

        if ($konfiguriert === 'none') {
            return ['provider' => 'none', 'source' => 'Notbremse in der .env (AI_ASSISTANT_PROVIDER=none)', 'model' => null];
        }

        if ($account = $this->account()) {
            return [
                'provider' => strtolower($account->provider),
                'source' => 'Zugang "'.$account->name.'" aus der Oberflaeche',
                'model' => $account->model ?: null,
            ];
        }

        return [
            'provider' => $konfiguriert !== '' ? $konfiguriert : 'claude',
            'source' => 'Server-.env (kein Zugang in der Oberflaeche gepflegt)',
            'model' => null,
        ];
    }
}
