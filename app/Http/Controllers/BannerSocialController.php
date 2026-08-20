<?php
namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\BannerSocialChannel;
use App\Models\BannerSocialPost;
use App\Jobs\PublishSocialChannelJob;
use App\Services\Social\MetaPublisher;
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
            'metaConfigured' => [
                'facebook' => MetaPublisher::configuredFor('facebook'),
                'instagram' => MetaPublisher::configuredFor('instagram'),
            ],
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
            'auto_publish' => 'nullable|boolean',
            'scheduled_for' => 'nullable|date|required_if:auto_publish,1',
        ], [
            'caption_de.max' => 'Der deutsche Beitragstext ist zu lang (max. 3000 Zeichen).',
            'caption_ar.max' => 'Der arabische Beitragstext ist zu lang (max. 3000 Zeichen).',
            'target_url.url' => 'Das Klick-Ziel muss eine öffentliche https://-Adresse sein (z. B. eine Seite der Website).',
            'scheduled_for.required_if' => 'Bitte einen Zeitpunkt für die automatische Veröffentlichung angeben.',
        ]);

        $post = BannerSocialPost::firstOrNew(['banner_id' => $banner->id]);
        if (!$post->exists) {
            $post->created_by = auth()->id();
        }
        // Der Betreiber gibt DEUTSCHE Uhrzeit ein; app.timezone ist UTC.
        // Deshalb als Europe/Berlin interpretieren und in UTC speichern -
        // sonst postet der Planer 1-2 Stunden spaeter als gedacht.
        $scheduledFor = $request->boolean('auto_publish') && !empty($data['scheduled_for'])
            ? \Illuminate\Support\Carbon::parse($data['scheduled_for'], BannerSocialPost::OPERATOR_TZ)->utc()
            : null;
        if ($scheduledFor && $scheduledFor->lte(now())) {
            // Nach der Zeitzonen-Umrechnung pruefen (after:now auf dem
            // rohen String liesse Berlin-Zeiten im Offset-Fenster durch).
            return back()->withInput()->withErrors([
                'scheduled_for' => 'Der geplante Zeitpunkt liegt in der Vergangenheit - bitte eine künftige Uhrzeit wählen (für „sofort" den Button „Jetzt per API posten" nutzen).',
            ]);
        }
        $scheduleChanged = ($post->scheduled_for?->toDateTimeString()) !== ($scheduledFor?->toDateTimeString());
        $post->fill([
            'caption_de' => $data['caption_de'] ?? null,
            'caption_ar' => $data['caption_ar'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'scheduled_for' => $scheduledFor,
            'updated_by' => auth()->id(),
        ])->save();

        // Kanaele mit der Auswahl synchronisieren: neue Plattformen bekommen
        // einen eindeutigen Kurzlink, abgewaehlte werden entfernt; bestehende
        // behalten Code und Klickzahlen. VEROEFFENTLICHTE Kanaele werden NIE
        // geloescht: der Kurzlink steht bereits im Live-Beitrag (wuerde tot)
        // und ohne published_at koennte der Planer erneut posten.
        $selected = $data['platforms'] ?? [];
        $geschuetzt = $post->channels()
            ->whereNotIn('platform', $selected)
            ->where(fn ($q) => $q->whereNotNull('published_at')->orWhereNotNull('external_post_id'))
            ->get();
        $post->channels()->whereNotIn('platform', array_merge($selected, $geschuetzt->pluck('platform')->all()))->delete();
        foreach ($selected as $platform) {
            $post->channels()->firstOrCreate(
                ['platform' => $platform],
                ['short_code' => BannerSocialChannel::generateCode($platform)]
            );
        }

        // Neuer/verschobener Auto-Zeitpunkt -> fruehere (fehlgeschlagene)
        // Auto-Versuche zuruecksetzen, damit der Planer erneut ansetzt.
        // Bereits erstellte Beitraege (external_post_id) bleiben unberuehrt.
        if ($scheduleChanged) {
            $post->channels()->whereNull('external_post_id')
                ->update(['auto_attempted_at' => null, 'publish_error' => null]);
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
                'title' => 'Social-Media-Post veröffentlichen: ' . $banner->title,
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
                : ' Bildformate konnten nicht erzeugt werden (Medium prüfen).';
        }
        if ($geschuetzt->isNotEmpty()) {
            $labels = $geschuetzt->map(fn ($ch) => $ch->platformInfo()['label'])->implode(', ');
            $msg .= ' Hinweis: ' . $labels . ' wurde bereits veröffentlicht und bleibt samt Tracking-Link erhalten.';
        }

        // Bewusst explizit statt back(): der Referer kann (z. B. nach einem
        // Kurzlink-Aufruf in derselben Session) woanders hinzeigen.
        return redirect()->route('admin.banners.social', $banner->id)->with('success', $msg);
    }

    /**
     * Sofort ueber die Meta Graph API veroeffentlichen (Facebook/Instagram).
     * Fehler werden verstaendlich am Kanal gespeichert und angezeigt -
     * ein erneuter Klick ist der bewusste zweite Versuch.
     */
    public function publishNow(Banner $banner, string $platform)
    {
        $post = $banner->socialPost()->firstOrFail();
        $channel = $post->channels()->where('platform', $platform)->firstOrFail();

        // Bereits ueber die API veroeffentlicht (external_post_id gesetzt) ->
        // NICHT erneut posten, sonst laege der Beitrag doppelt auf der Plattform.
        // Deckt den Doppelklick UND den Fall ab, dass der geplante Lauf den
        // Beitrag zwischenzeitlich schon abgesetzt hat (Audit CONC-3; der
        // geplante Lauf beansprucht seinerseits atomar per auto_attempted_at).
        if ($channel->external_post_id) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => 'Dieser Beitrag wurde bereits über die Meta-API veröffentlicht.']);
        }

        // Bereits (manuell) als veroeffentlicht markiert -> kein API-Post,
        // sonst laege der Beitrag doppelt auf der Plattform.
        if ($channel->published_at && !$channel->external_post_id) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => 'Bereits als veröffentlicht markiert - erst zurücksetzen, dann per API posten.']);
        }

        // Versuch ATOMAR beanspruchen - erst danach darf gepostet werden.
        // Die Pruefungen oben lesen nur; zwischen Lesen und Posten passt ein
        // zweiter Klick oder der geplante Lauf. Ein UPDATE mit Bedingung
        // laesst genau einen Beanspruchenden durch (Audit CONC-3).
        $frei = now()->subMinutes(\App\Models\BannerSocialChannel::PUBLISH_STALE_MINUTES);
        $beansprucht = \App\Models\BannerSocialChannel::whereKey($channel->id)
            ->whereNull('external_post_id')
            ->whereNull('published_at')
            ->where(fn ($q) => $q->whereNull('publish_started_at')->orWhere('publish_started_at', '<', $frei))
            ->update(['publish_started_at' => now(), 'publish_error' => null]);

        if ($beansprucht === 0) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => 'Für diesen Kanal läuft bereits eine Veröffentlichung. Bitte kurz warten und die Seite neu laden.']);
        }

        // Der Versand laeuft im HINTERGRUND. Der Instagram-Weg (Container,
        // Verarbeitung abwarten, veroeffentlichen, Permalink) dauert im
        // schlechtesten Fall rund drei Minuten - laenger als jede uebliche
        // PHP-Laufzeitgrenze. Riss der Request dabei ab, stand der Beitrag
        // womoeglich schon auf Instagram, ohne dass die App es wusste.
        PublishSocialChannelJob::dispatch($channel->id, auth()->id());

        return redirect()->route('admin.banners.social', $banner->id)
            ->with('success', $channel->platformInfo()['label']
                . ': Veröffentlichung gestartet. Sie bekommen eine Benachrichtigung, sobald der Beitrag online ist.');
    }

    /** Kennzahlen (Likes/Kommentare/Reichweite) sofort von Meta holen. */
    public function refreshInsights(Banner $banner)
    {
        $post = $banner->socialPost()->with('channels')->firstOrFail();
        $service = app(\App\Services\Social\MetaInsightsService::class);

        $ok = 0;
        $fehler = null;
        foreach ($post->channels->whereNotNull('external_post_id') as $channel) {
            try {
                $service->refreshChannel($channel);
                $ok++;
            } catch (\Throwable $e) {
                $fehler = $e->getMessage();
            }
        }
        // Seiten-Ueberblick (Follower/Aufrufe) gleich mit auffrischen -
        // die Anleitung verspricht es, und der Betreiber erwartet es.
        try {
            $service->refreshPageOverview();
        } catch (\Throwable $e) {
        }

        if ($ok === 0) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => $fehler ?: 'Keine per API veröffentlichten Beiträge vorhanden.']);
        }

        return redirect()->route('admin.banners.social', $banner->id)
            ->with('success', 'Kennzahlen von Meta aktualisiert (' . $ok . ' ' . ($ok === 1 ? 'Beitrag' : 'Beiträge') . ').');
    }

    /** Veroeffentlichung je Plattform protokollieren bzw. zuruecknehmen. */
    public function markPublished(Banner $banner, string $platform)
    {
        $post = $banner->socialPost()->firstOrFail();
        $channel = $post->channels()->where('platform', $platform)->firstOrFail();

        // Per API erstellte Beitraege sind Fakten - das Protokoll dazu
        // laesst sich nicht "zuruecksetzen" (der Beitrag ist ja live).
        if ($channel->external_post_id) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => 'Dieser Beitrag wurde über die Meta-API veröffentlicht - das Protokoll kann nicht zurückgesetzt werden.']);
        }

        if ($channel->published_at) {
            $channel->update(['published_at' => null, 'published_by' => null]);

            return redirect()->route('admin.banners.social', $banner->id)
                ->with('success', 'Veröffentlichung zurückgesetzt.');
        }
        $channel->update(['published_at' => now(), 'published_by' => auth()->id()]);

        return redirect()->route('admin.banners.social', $banner->id)
            ->with('success', $channel->platformInfo()['label'] . ' als veröffentlicht markiert.');
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
