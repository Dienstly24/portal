<?php
namespace App\Http\Controllers;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bannerverwaltung (Vollausbau):
 * - erstellen/bearbeiten (inkl. Medientausch), Entwurf, aktiv/inaktiv
 * - Bild (beliebige Maße, automatische WebP-Optimierung), MP4/WEBM/GIF
 * - Klick-Ziel (intern/extern, gleiches/neues Fenster)
 * - Statistiken (Impressions/Klicks/CTR, heute/7/30 Tage, eindeutige
 *   Betrachter, letzter Ausspielzeitpunkt) + Zurücksetzen
 * - Sortierung per Pfeil-Buttons, Audit (erstellt/geändert von)
 * Nur Admin/Manager (Route-Middleware).
 */
class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::orderBy('sort_order')->orderBy('id')->get();
        $creators = \App\Models\User::whereIn('id', $banners->pluck('created_by')->merge($banners->pluck('updated_by'))->filter()->unique())
            ->pluck('name', 'id');

        return view('admin.banners', compact('banners', 'creators'));
    }

    /**
     * Statistik-Dashboard: Gesamtwerte, 30-Tage-Verlauf (Impressions und
     * Klicks als getrennte Diagramme – bewusst KEINE Doppelachse) und
     * Banner-Vergleich mit CTR. Tage ohne Daten werden mit 0 aufgefüllt.
     */
    public function stats()
    {
        $banners = Banner::orderByDesc('total_impressions')->get();

        $from = now()->subDays(29)->toDateString();
        $daily = \App\Models\BannerDailyStat::where('date', '>=', $from)
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('date')->orderBy('date')->get()->keyBy('date');

        $labels = [];
        $impressions = [];
        $clicks = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('d.m.');
            $impressions[] = (int) ($daily[$day]->impressions ?? 0);
            $clicks[] = (int) ($daily[$day]->clicks ?? 0);
        }

        $totalImpressions = (int) $banners->sum('total_impressions');
        $totalClicks = (int) $banners->sum('total_clicks');

        return view('admin.banner_stats', [
            'banners' => $banners,
            'labels' => $labels,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'totalImpressions' => $totalImpressions,
            'totalClicks' => $totalClicks,
            'avgCtr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 1) : 0.0,
            'best' => $banners->filter(fn ($b) => $b->total_impressions > 0)->sortByDesc(fn ($b) => $b->ctr())->first(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $data['media_type'] = $this->storeMedia($request, $data);
        $data['created_by'] = auth()->id();
        $data['is_draft'] = $request->boolean('is_draft');
        Banner::create($data);

        return back()->with('success', $data['is_draft'] ? 'Banner als Entwurf gespeichert.' : 'Banner erstellt.');
    }

    public function update(Request $request, Banner $banner)
    {
        $data = $this->validated($request, false);
        if ($request->hasFile('media')) {
            $old = $banner->media_path;
            $data['media_type'] = $this->storeMedia($request, $data);
            try { Storage::disk('public')->delete($old); } catch (\Throwable $e) {}
        }
        $data['updated_by'] = auth()->id();
        $data['is_draft'] = $request->boolean('is_draft');
        $banner->update($data);

        return back()->with('success', 'Banner aktualisiert – Änderungen sind sofort wirksam.');
    }

    public function toggle(Banner $banner)
    {
        $banner->update(['is_active' => !$banner->is_active, 'is_draft' => false, 'updated_by' => auth()->id()]);
        return back()->with('success', $banner->is_active ? 'Banner aktiviert.' : 'Banner deaktiviert.');
    }

    /** Sortierung: tauscht die Position mit dem Nachbarn (↑/↓). */
    public function move(Request $request, Banner $banner)
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];

        $ordered = Banner::orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $ordered->search(fn ($b) => $b->id === $banner->id);
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith >= 0 && $swapWith < $ordered->count()) {
            // Eindeutige, lückenlose sort_order-Werte herstellen und tauschen.
            foreach ($ordered as $i => $b) {
                $b->sort_order = $i;
            }
            [$ordered[$index]->sort_order, $ordered[$swapWith]->sort_order] =
                [$ordered[$swapWith]->sort_order, $ordered[$index]->sort_order];
            foreach ($ordered as $b) {
                $b->saveQuietly();
            }
        }

        return back();
    }

    public function resetStats(Banner $banner)
    {
        $banner->resetStats();
        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'banner_stats_reset',
            'entity_type' => 'banner',
            'entity_id' => (string) $banner->id,
            'meta' => json_encode(['title' => $banner->title], JSON_UNESCAPED_UNICODE),
        ]);
        return back()->with('success', 'Statistiken zurückgesetzt.');
    }

    public function destroy(Banner $banner)
    {
        try { Storage::disk('public')->delete($banner->media_path); } catch (\Throwable $e) {}
        $banner->delete();
        return back()->with('success', 'Banner gelöscht.');
    }

    private function validated(Request $request, bool $isCreate): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            // Beliebige Bildmaße; GIF und Videos zusätzlich erlaubt.
            'media' => ($isCreate ? 'required' : 'nullable') . '|file|mimes:jpg,jpeg,png,webp,gif,mp4,webm|max:20480',
            // Nur http(s)-URLs oder interne Pfade (fuehrendes /) zulassen -
            // blockt javascript:/data:-Schemata und haertet den Open-Redirect
            // beim Banner-Klick (PortalController). (Audit SEC-7)
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#i'],
            'link_target' => 'nullable|in:self,blank',
            'dismiss_days' => 'nullable|integer|min:1|max:365',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ]);
        unset($data['media']);
        $data['link_target'] = $data['link_target'] ?? 'self';
        return $data;
    }

    /**
     * Medium speichern. JPG/PNG werden automatisch optimiert: auf max.
     * 2400px Breite verkleinert und als WebP (Qualität 82) gespeichert –
     * kleinere Dateien, schnellere Portal-Ladezeit, Qualität bleibt hoch.
     * GIF (Animation), WebP und Videos bleiben unverändert.
     */
    private function storeMedia(Request $request, array &$data): string
    {
        $file = $request->file('media');
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['mp4', 'webm'])) {
            $data['media_path'] = $file->store('banners', 'public');
            return 'video';
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png']) && function_exists('imagewebp')) {
            $optimized = $this->toWebp($file->getPathname(), $ext);
            if ($optimized !== null) {
                $name = 'banners/' . uniqid('banner_') . '.webp';
                Storage::disk('public')->put($name, $optimized);
                $data['media_path'] = $name;
                return 'image';
            }
        }

        $data['media_path'] = $file->store('banners', 'public');
        return 'image';
    }

    /** JPG/PNG -> WebP (max. 2400px Breite). null bei Fehlern -> Original behalten. */
    private function toWebp(string $path, string $ext): ?string
    {
        try {
            $img = $ext === 'png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
            if (!$img) {
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > 2400) {
                $nh = (int) round($h * 2400 / $w);
                $resized = imagecreatetruecolor(2400, $nh);
                // Transparenz (PNG) erhalten
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, 2400, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            } else {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            ob_start();
            imagewebp($img, null, 82);
            $out = ob_get_clean();
            imagedestroy($img);

            return $out !== false && $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
