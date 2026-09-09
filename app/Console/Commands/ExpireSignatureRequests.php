<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\SignatureRequest;
use App\Services\Signature\SignatureRequestService;
use App\Support\SignatureStatus;
use Illuminate\Console\Command;

/**
 * Laesst abgelaufene Signaturanfragen ablaufen.
 *
 * Die Anzeige wartet NICHT auf diesen Lauf - `hasExpired()` rechnet die
 * Frist bei jedem Aufruf aus, und der Unterzeichner kommt nach Fristablauf
 * auch ohne Cron nicht mehr durch (dieselbe Lehre wie beim Vertragsstatus,
 * 17.08.2026: die Anzeige darf nie von einem Hintergrundlauf abhaengen).
 * Der Befehl zieht den GESPEICHERTEN Zustand nach, widerruft die Zugaenge
 * und meldet dem Ersteller, dass sein Dokument nicht unterschrieben wurde.
 *
 * Je Anfrage abgesichert: eine kaputte laesst die uebrigen nicht liegen.
 */
class ExpireSignatureRequests extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'signaturen:ablaufen {--dry-run : Nur anzeigen, nichts aendern}';

    protected $description = 'Abgelaufene Signaturanfragen schliessen und Zugaenge widerrufen';

    public function handle(SignatureRequestService $service): int
    {
        $offen = SignatureRequest::query()
            ->whereIn('status', SignatureStatus::OPEN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('signers')
            ->get();

        if ($offen->isEmpty()) {
            $this->info('Keine abgelaufenen Signaturanfragen.');

            return self::SUCCESS;
        }

        $verarbeitet = $this->verarbeiteEinzeln($offen, function (SignatureRequest $request) use ($service) {
            if ($this->option('dry-run')) {
                $this->line('Wuerde ablaufen lassen: '.$request->title.' ('.$request->id.')');

                return;
            }
            $service->expire($request);
        }, 'Signaturanfrage');

        $this->info($verarbeitet.' Signaturanfrage(n) abgelaufen.');

        return $this->ergebnisMitUebersprungenen();
    }
}
