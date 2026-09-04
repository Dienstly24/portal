<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Duenner Client fuer die Meta Graph API: baut die URL aus der
 * konfigurierten Version und uebersetzt Fehler in verstaendliche
 * RuntimeExceptions ("Meta-API: ..."). Wird von MetaPublisher (Posten),
 * MetaInsightsService (Kennzahlen) und MetaAdsService (Werbeanzeigen)
 * gemeinsam genutzt.
 *
 * Token-Regeln (Pre-Merge-Review):
 * - Das Token wird IMMER als Authorization-Bearer-Header gesendet - nie
 *   als Query-/Body-Parameter. So landet es nicht in Fehlermeldungen/
 *   Logs (Connection-Exceptions enthalten die volle URL) und DELETE
 *   funktioniert (JSON-Bodies parst die Graph API nicht).
 * - Seiten-Endpunkte (/{page-id}/photos, /{page-id}/feed, Seiten- und
 *   Beitrags-Insights) verlangen ein PAGE Access Token - das
 *   System-User-Token reicht dort NICHT. pageToken() liefert es aus der
 *   .env (META_PAGE_ACCESS_TOKEN, schreibt der Assistent) oder holt es
 *   einmalig ueber GET /{page-id}?fields=access_token (Cache 12 h).
 * - IG- und act_...-Endpunkte laufen mit dem System-User-Token.
 */
class MetaGraphClient
{
    public function get(string $path, array $params = [], ?string $token = null): array
    {
        return $this->request('get', $path, $params, $token);
    }

    public function post(string $path, array $params = [], ?string $token = null): array
    {
        return $this->request('post', $path, $params, $token);
    }

    public function delete(string $path, array $params = [], ?string $token = null): array
    {
        return $this->request('delete', $path, $params, $token);
    }

    /**
     * Page Access Token fuer Seiten-Endpunkte: aus der Konfiguration
     * (META_PAGE_ACCESS_TOKEN) oder zur Laufzeit vom System-User-Token
     * abgeleitet (GET /{page-id}?fields=access_token, 12 h gecacht).
     */
    public function pageToken(): string
    {
        $configured = (string) config('services.meta.page_token');
        if ($configured !== '') {
            return $configured;
        }

        $pageId = (string) config('services.meta.page_id');

        return (string) Cache::remember('meta_page_token_'.$pageId, now()->addHours(12), function () use ($pageId) {
            $resp = $this->get($pageId, ['fields' => 'access_token']);
            $token = (string) ($resp['access_token'] ?? '');
            if ($token === '') {
                throw new \RuntimeException('Meta-API: Seiten-Token konnte nicht ermittelt werden (Seite dem Systembenutzer zugewiesen?).');
            }

            return $token;
        });
    }

    private function request(string $method, string $path, array $params, ?string $token): array
    {
        $url = 'https://graph.facebook.com/'
            .config('services.meta.graph_version', 'v23.0').'/'.ltrim($path, '/');
        $client = Http::timeout(25)->withToken($token ?? (string) config('services.meta.token'));

        try {
            $resp = match ($method) {
                'get' => $client->get($url, $params),
                'delete' => $client->delete($url),
                default => $client->asForm()->post($url, $params),
            };
        } catch (\Throwable $e) {
            throw new \RuntimeException('Meta-API nicht erreichbar: '.$e->getMessage());
        }

        $json = $resp->json() ?? [];
        if ($resp->failed() || isset($json['error'])) {
            $msg = $json['error']['message'] ?? ('HTTP '.$resp->status());
            throw new \RuntimeException('Meta-API: '.$msg);
        }

        return $json;
    }
}
