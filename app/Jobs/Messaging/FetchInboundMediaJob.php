<?php

namespace App\Jobs\Messaging;

use App\Models\CustomerMessageAttachment;
use App\Services\Messaging\Channels\ChannelManager;
use App\Support\UploadRules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mediendatei einer eingegangenen Nachricht holen (Auftrag Abschnitt 39).
 *
 * WARUM ALS EIGENER JOB: die Kennung im Webhook ist nur ein Verweis -
 * dahinter stehen ZWEI authentifizierte Abrufe. Das im Webhook zu
 * erledigen, wuerde die Quittung an Meta verzoegern, und Meta schaltet
 * einen Webhook ab, der zu lange braucht. Der Anhang existiert deshalb
 * sofort als DATENSATZ, die Datei kommt gleich danach.
 *
 * `tries = 3`: die Medien-URLs der Plattformen sind kurzlebig, ein
 * zweiter Anlauf bei einem Netzfehler ist sinnvoll. Doppelt laden kann
 * nichts kaputt machen - es entsteht dieselbe Datei.
 */
class FetchInboundMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $attachmentId) {}

    public function handle(ChannelManager $manager): void
    {
        $anhang = CustomerMessageAttachment::with('message.conversation.channel', 'message.conversation.channelAccount')
            ->find($this->attachmentId);

        // Schon geholt? Dann nichts tun - der Job ist wiederholbar.
        if (! $anhang || $anhang->file_path !== '' || ! $anhang->external_media_id) {
            return;
        }

        $unterhaltung = $anhang->message?->conversation;
        $kanal = $unterhaltung?->channel;

        if (! $kanal || ! $manager->has($kanal->key)) {
            return;
        }

        $datei = $manager->driver($kanal->key)
            ->fetchMedia($anhang->external_media_id, $unterhaltung->channelAccount);

        if (! $datei || $datei['contents'] === '') {
            return;
        }

        // GROESSENGRENZE wie bei jedem anderen Upload: eine fremde Datei
        // darf den Speicher nicht unbegrenzt fuellen. `UploadRules` ist
        // die eine Quelle dafuer (ARCH-6).
        $maxBytes = UploadRules::MAX_KB * 1024;
        if (strlen($datei['contents']) > $maxBytes) {
            $anhang->forceFill([
                'metadata' => ['fehler' => 'Datei zu gross - nicht gespeichert.'],
            ])->save();

            return;
        }

        // Auf der PRIVATEN Disk und unter dem Kunden - dasselbe
        // Verzeichnis wie bei den uebrigen Chat-Anhaengen, damit die
        // Kundenloeschung sie mit entfernt (DSGVO).
        $ordner = 'customers/'.($anhang->message->customer_id ?: 'ohne-kunde').'/messages';
        $name = $this->fileName($anhang, $datei);
        $pfad = $ordner.'/'.Str::uuid().'-'.$name;

        Storage::disk('local')->put($pfad, $datei['contents']);

        $anhang->forceFill([
            'file_path' => $pfad,
            'disk' => 'local',
            'file_name' => $name,
            'mime_type' => $anhang->mime_type ?: ($datei['mime_type'] ?? null),
            'file_size' => strlen($datei['contents']),
        ])->save();
    }

    /**
     * Einen brauchbaren Dateinamen finden. Viele Plattformen liefern
     * KEINEN - dann wird einer aus Typ und MIME gebildet, statt eine
     * Datei ohne Endung abzulegen, die niemand oeffnen kann.
     *
     * @param  array<string,mixed>  $datei
     */
    private function fileName(CustomerMessageAttachment $anhang, array $datei): string
    {
        $name = trim((string) ($anhang->file_name ?: ($datei['file_name'] ?? '')));
        if ($name !== '' && $name !== 'anhang' && str_contains($name, '.')) {
            // Fremder Dateiname: nur den reinen Namen uebernehmen, nie
            // einen Pfad - sonst schriebe ein Absender in fremde Ordner.
            return basename($name);
        }

        $mime = (string) ($anhang->mime_type ?: ($datei['mime_type'] ?? ''));
        $endung = match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            default => 'bin',
        };

        return ($anhang->type ?: 'anhang').'.'.$endung;
    }
}
