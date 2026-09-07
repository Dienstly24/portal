<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiProviderAccount;
use App\Services\Ai\Assistant\AiProviderSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * KI-Anbieter (/admin/ki-anbieter) - Auftrag Abschnitte 81/95/97/101.
 *
 * Anbieter, Modell und Zugangsschluessel aus der Oberflaeche, damit ein
 * Wechsel nicht mehr bedeutet, die Server-`.env` anzufassen.
 *
 * NUR ADMIN - hier liegt der teuerste Schluessel des Systems.
 *
 * Die Geheimnis-Regeln sind dieselben wie bei den Kanaelen (Abschnitt
 * 96) und aus demselben Grund: ein Wert verlaesst den Server nie wieder,
 * ein leeres Feld loescht nichts, und protokolliert wird die Handlung,
 * nie der Wert.
 */
class AiProviderController extends Controller
{
    /** Anbieter, fuer die ein Adapter existiert (Abschnitt 101). */
    public const PROVIDERS = [
        'claude' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
    ];

    public function index(AiProviderSettings $settings)
    {
        return view('admin.ai_providers.index', [
            'accounts' => AiProviderAccount::orderByDesc('is_active')->orderBy('name')->get(),
            'providers' => self::PROVIDERS,
            'geltung' => $settings->explain(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $account = new AiProviderAccount([
            'provider' => $data['provider'],
            'name' => $data['name'],
            'model' => ($data['model'] ?? '') ?: null,
        ]);
        $account->credentials = $this->mergeKey([], $data['api_key'] ?? null);
        $account->is_active = false;
        $account->save();

        // Erst anlegen, dann bewusst aktivieren - ein neuer Zugang
        // schaltet den laufenden Betrieb nie im selben Schritt um.
        if (! empty($data['is_active'])) {
            $this->activateOnly($account);
        }

        ActivityLog::record('ai_provider_created', 'ai_provider_account', $account->id, [
            'provider' => $account->provider,
            'name' => $account->name,
            'has_key' => $account->apiKey() !== null,
        ]);

        return redirect()->route('admin.ai_providers.index')
            ->with('success', 'Zugang "'.$account->name.'" angelegt.');
    }

    public function update(Request $request, int $id)
    {
        $account = AiProviderAccount::findOrFail($id);
        $data = $this->validated($request);

        $account->fill([
            'provider' => $data['provider'],
            'name' => $data['name'],
            'model' => ($data['model'] ?? '') ?: null,
        ]);
        // Leeres Feld = unveraendert. Sonst raeumt jedes Speichern des
        // Modellnamens den Schluessel ab.
        $account->credentials = $this->mergeKey($account->credentials ?? [], $data['api_key'] ?? null);
        $account->save();

        if (! empty($data['is_active'])) {
            $this->activateOnly($account);
        } else {
            $account->forceFill(['is_active' => false])->save();
        }

        ActivityLog::record('ai_provider_updated', 'ai_provider_account', $account->id, [
            'provider' => $account->provider,
            'name' => $account->name,
            'is_active' => $account->fresh()->is_active,
            'has_key' => $account->apiKey() !== null,
        ]);

        return back()->with('success', 'Zugang "'.$account->name.'" gespeichert.');
    }

    public function destroy(int $id)
    {
        $account = AiProviderAccount::findOrFail($id);
        $name = $account->name;
        $account->delete();

        ActivityLog::record('ai_provider_deleted', 'ai_provider_account', $id, ['name' => $name]);

        return back()->with('success',
            'Zugang "'.$name.'" entfernt. Ohne gepflegten Zugang gilt wieder die Server-.env.');
    }

    /**
     * Verbindungstest (Abschnitt 97).
     *
     * Der kleinstmoegliche echte Aufruf - er ist die EINZIGE Pruefung,
     * die Schluessel, Endpunkt UND Modellfreigabe zugleich beweist
     * (dieselbe Lehre wie bei `ki:pruefen --live`). Die Fremdmeldung wird
     * NICHT durchgereicht: sie kann den Schluessel enthalten.
     */
    public function test(int $id)
    {
        $account = AiProviderAccount::findOrFail($id);
        $key = $account->apiKey();

        if (! $key) {
            return back()->with('warning', 'Test "'.$account->name.'": kein Schluessel hinterlegt.');
        }

        [$status, $meldung] = $this->probe($account->provider, $key, $account->model);

        $account->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ])->save();

        ActivityLog::record('ai_provider_tested', 'ai_provider_account', $account->id, [
            'provider' => $account->provider,
            'status' => $status,
        ]);

        return back()->with($status === 'ok' ? 'success' : 'warning',
            'Test "'.$account->name.'": '.$meldung);
    }

    /** @return array{0:string,1:string} */
    private function probe(string $provider, string $key, ?string $model): array
    {
        try {
            if ($provider === 'openai') {
                $antwort = Http::withToken($key)->timeout(15)
                    ->get(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/models');
            } else {
                $antwort = Http::withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model ?: 'claude-opus-5',
                    'max_tokens' => 16,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);
            }
        } catch (\Throwable $e) {
            report($e);

            return ['unavailable', 'Dienst nicht erreichbar (Netz oder Firewall).'];
        }

        return match (true) {
            $antwort->successful() => ['ok', 'Verbunden.'],
            $antwort->status() === 401 || $antwort->status() === 403 => ['invalid_credentials', 'Schluessel abgelehnt.'],
            $antwort->status() === 404 => ['model_not_found', 'Modell oder Endpunkt nicht gefunden.'],
            $antwort->status() === 429 => ['rate_limited', 'Zu viele Anfragen - spaeter erneut.'],
            default => ['unavailable', 'Dienst antwortet mit Fehler '.$antwort->status().'.'],
        };
    }

    /**
     * Genau EIN Zugang ist aktiv. Als Transaktion, damit es nie einen
     * Moment ohne oder mit zwei aktiven Zugaengen gibt.
     */
    private function activateOnly(AiProviderAccount $account): void
    {
        DB::transaction(function () use ($account) {
            AiProviderAccount::where('id', '!=', $account->id)->update(['is_active' => false]);
            $account->forceFill(['is_active' => true])->save();
        });
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', array_keys(self::PROVIDERS))],
            'name' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:400'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $vorhanden
     * @return array<string,string>|null
     */
    private function mergeKey(array $vorhanden, ?string $neu): ?array
    {
        $neu = trim((string) $neu);
        if ($neu !== '') {
            $vorhanden['api_key'] = $neu;
        }

        return $vorhanden ?: null;
    }
}
