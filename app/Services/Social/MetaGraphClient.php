<?php
namespace App\Services\Social;

use Illuminate\Support\Facades\Http;

/**
 * Duenner Client fuer die Meta Graph API: baut die URL aus der
 * konfigurierten Version, haengt das Token an und uebersetzt Fehler in
 * verstaendliche RuntimeExceptions ("Meta-API: ..."). Wird von
 * MetaPublisher (Posten), MetaInsightsService (Kennzahlen) und
 * MetaAdsService (Werbeanzeigen) gemeinsam genutzt.
 */
class MetaGraphClient
{
    public function get(string $path, array $params = []): array
    {
        return $this->request('get', $path, $params);
    }

    public function post(string $path, array $params = []): array
    {
        return $this->request('post', $path, $params);
    }

    public function delete(string $path, array $params = []): array
    {
        return $this->request('delete', $path, $params);
    }

    private function request(string $method, string $path, array $params): array
    {
        $url = 'https://graph.facebook.com/'
            . config('services.meta.graph_version', 'v23.0') . '/' . ltrim($path, '/');
        $params['access_token'] = (string) config('services.meta.token');

        try {
            $resp = match ($method) {
                'get' => Http::timeout(25)->get($url, $params),
                'delete' => Http::timeout(25)->delete($url, $params),
                default => Http::timeout(25)->asForm()->post($url, $params),
            };
        } catch (\Throwable $e) {
            throw new \RuntimeException('Meta-API nicht erreichbar: ' . $e->getMessage());
        }

        $json = $resp->json() ?? [];
        if ($resp->failed() || isset($json['error'])) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException('Meta-API: ' . $msg);
        }

        return $json;
    }
}
