<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\ImageVariantGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Erzeugt die Web-Varianten eines hochgeladenen Bildes (P1-1c).
 * Wird beim Upload synchron ausgefuehrt (dispatchSync), damit das Bild
 * sofort auf der Website erscheint und kein Queue-Worker Voraussetzung
 * ist; auf einem Server mit laufendem Worker kann auf dispatch()
 * umgestellt werden.
 */
class ProcessMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    /**
     * Genau EIN Versuch (Audit 15.09.2026 - vorher gar keine Angabe).
     *
     * Ohne eigene Angabe entscheidet der Worker-Aufruf auf dem Server
     * (`queue:work --tries=3`), also eine Einstellung, die in diesem
     * Repository nicht steht. Hier ist ein Wiederholen aber sinnlos:
     * handle() faengt JEDE Stoerung selbst ab, vermerkt sie am Asset
     * und kehrt normal zurueck - der Job schlaegt also nie so fehl,
     * dass ein zweiter Versuch etwas aendern koennte. Und der uebliche
     * Aufruf ist ohnehin dispatchSync().
     */
    public int $tries = 1;

    public function __construct(
        public MediaAsset $asset,
        /** Slot, dem das Bild gleich zugewiesen wird - bestimmt Groessen/Formate. */
        public ?string $intendedSlot = null,
    ) {
    }

    public function handle(ImageVariantGenerator $generator): void
    {
        try {
            $generator->generate($this->asset, $this->intendedSlot);
        } catch (\Throwable $e) {
            $this->asset->forceFill([
                'processing_status' => 'failed',
                'processing_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();
            \Log::warning('Medien-Verarbeitung fehlgeschlagen (Asset '.$this->asset->id.'): '.$e->getMessage());
        }
    }
}
