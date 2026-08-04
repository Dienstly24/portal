<?php
namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\BannerSocialChannel;
use App\Models\BannerSocialPost;
use App\Services\Social\SocialFormatGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Social-Publishing je Banner (Phase 1, Betreiber-Auftrag 04.08.2026):
 * - Beitragstext DE/AR + oeffentliches Klick-Ziel pflegen
 * - automatisch erzeugte Bildformate (Feed/Story/Link-Vorschau) laden
 * - je Plattform (Facebook/Instagram/TikTok) ein Tracking-Kurzlink
 * - Veroeffentlichung protokollieren (wer/wann) + optionale Wiedervorlage
 * - Download-Paket (ZIP) mit allen Formaten, Texten und Links
 * Nur Admin/Manager (Route-Middleware). Phase 2 (direktes Posten ueber die
 * Meta-API) setzt spaeter auf denselben Daten auf.
 */
class BannerSocialController extends Controller
{
    public function show(Banner $banner)
    {
        $post = $banner->socialPost()->with('channels.publisher')->first();

        return view('admin.banner_social', [
            'banner' => $banner,
            'post' => $post,
            'formats' => SocialFormatGenerator::existing($banner),
            'canZip' => class_exists(\ZipArchive::class),
        ]);
    }

    public function save(Request $request, Banner $banner)
    {
        $data = $request->validate([
            'caption_de' => 'nullable|string|max:3000',
            'caption_ar' => 'nullable|string|max:3000',
            // Nur oeffentliche https-Ziele: Portal-interne Pfade waeren fuer
            // Social-Besucher ohne Login wertlos (Login-Wand).
            'target_url' => 'nullable|url:https|max:500',
            'platforms' => 'nullable|array',
            'platforms.*' => 'in:' . implode(',', array_keys(BannerSocialPost::PLATFORMS)),
            'create_task' => 'nullable|boolean',
        ], [
            'target_url.url' => 'Das Klick-Ziel muss eine oeffentliche https://-Adresse sein (z. B. eine Seite der Website).',
        ]);

        $post = BannerSocialPost::firstOrNew(['banner_id' => $banner->id]);
        if (!$post->exists) {
            $post->created_by = auth()->id();
        }
        $post->fill([
            'caption_de' => $data['caption_de'] ?? null,
            'caption_ar' => $data['caption_ar'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'updated_by' => auth()->id(),
        ])->save();

        // Kanaele mit der Auswahl synchronisieren: neue Plattformen bekommen
        // einen eindeutigen Kurzlink, abgewaehlte werden entfernt; bestehende
        // behalten Code und Klickzahlen.
        $selected = $data['platforms'] ?? [];
        $post->channels()->whereNotIn('platform', $selected)->delete();
        foreach ($selected as $platform) {
            $post->channels()->firstOrCreate(
                ['platform' => $platform],
                ['short_code' => BannerSocialChannel::generateCode($platform)]
            );
        }

        $generated = app(SocialFormatGenerator::class)->generate($banner);

        // Optionale Wiedervorlage: erinnert an die eigentliche
        // Veroeffentlichung (faellig am Startdatum, sonst heute).
        if ($request->boolean('create_task')) {
            $due = $banner->start_date && $banner->start_date->isFuture()
                ? $banner->start_date
                : now();
            \App\Models\Task::create([
                'assigned_to' => auth()->id(),
                'created_by' => auth()->id(),
                'title' => 'Social-Media-Post veroeffentlichen: ' . $banner->title,
                'description' => 'Bildformate, Texte und Tracking-Links: ' . route('admin.banners.social', $banner->id),
                'type' => 'follow_up',
                'status' => 'open',
                'priority' => 'medium',
                'due_date' => $due->toDateString(),
            ]);
        }

        $msg = 'Social-Media-Post gespeichert.';
        if ($banner->media_type === 'image') {
            $msg .= $generated
                ? ' Bildformate wurden neu erzeugt.'
                : ' Bildformate konnten nicht erzeugt werden (Medium pruefen).';
        }

        // Bewusst explizit statt back(): der Referer kann (z. B. nach einem
        // Kurzlink-Aufruf in derselben Session) woanders hinzeigen.
        return redirect()->route('admin.banners.social', $banner->id)->with('success', $msg);
    }

    /** Veroeffentlichung je Plattform protokollieren bzw. zuruecknehmen. */
    public function markPublished(Banner $banner, string $platform)
    {
        $post = $banner->socialPost()->firstOrFail();
        $channel = $post->channels()->where('platform', $platform)->firstOrFail();

        if ($channel->published_at) {
            $channel->update(['published_at' => null, 'published_by' => null]);

            return redirect()->route('admin.banners.social', $banner->id)
                ->with('success', 'Veroeffentlichung zurueckgesetzt.');
        }
        $channel->update(['published_at' => now(), 'published_by' => auth()->id()]);

        return redirect()->route('admin.banners.social', $banner->id)
            ->with('success', $channel->platformInfo()['label'] . ' als veroeffentlicht markiert.');
    }

    /** Download-Paket: alle Bildformate + Beitragstexte + Tracking-Links. */
    public function downloadZip(Banner $banner)
    {
        abort_unless(class_exists(\ZipArchive::class), 404);

        $post = $banner->socialPost()->with('channels')->first();
        $formats = SocialFormatGenerator::existing($banner);
        $disk = Storage::disk('public');

        $tmp = tempnam(sys_get_temp_dir(), 'social');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($formats as $key => $path) {
            $zip->addFromString('bild-' . $key . '.jpg', $disk->get($path));
        }
        // Video/GIF: Original beilegen (Plattformen schneiden selbst zu;
        // beim GIF bleibt so die Animation erhalten).
        $ext = strtolower(pathinfo($banner->media_path, PATHINFO_EXTENSION));
        if (($banner->media_type === 'video' || $ext === 'gif') && $disk->exists($banner->media_path)) {
            $zip->addFromString('original.' . $ext, $disk->get($banner->media_path));
        }
        if ($post?->caption_de) {
            $zip->addFromString('text-deutsch.txt', $post->caption_de);
        }
        if ($post?->caption_ar) {
            $zip->addFromString('text-arabisch.txt', $post->caption_ar);
        }
        $lines = [];
        foreach (($post?->channels ?? collect()) as $ch) {
            $lines[] = $ch->platformInfo()['label'] . ': ' . $ch->shortUrl();
        }
        if ($lines) {
            $zip->addFromString('tracking-links.txt', implode(PHP_EOL, $lines) . PHP_EOL);
        }
        $zip->close();

        return response()
            ->download($tmp, 'social-banner-' . $banner->id . '.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
