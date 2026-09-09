<?php

namespace App\Services\Signature;

use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use Illuminate\Support\Facades\Log;

/**
 * Das Signatur-Protokoll. Es ist der eigentliche Wert des Moduls: eine
 * Unterschrift ohne nachvollziehbaren Hergang ist ein Bild auf einem Blatt.
 *
 * ZWEI REGELN, beide bewusst:
 *  1. NUR ANLEGEN. Es gibt keinen Bearbeiten- und keinen Loeschen-Weg -
 *     weder in der Oberflaeche noch hier im Dienst.
 *  2. Das Protokollieren darf den Vorgang NIE scheitern lassen. Faellt das
 *     Schreiben aus, wird es geloggt und der Unterzeichner unterschreibt
 *     trotzdem - dieselbe Regel wie beim ErrorRecorder und beim
 *     Provisions-Protokoll. Ein Vorgang, den ein voller Datentraeger
 *     abbricht, waere der schlechtere Tausch.
 */
class SignatureAuditService
{
    public function record(
        ?SignatureRequest $request,
        string $event,
        ?SignatureSigner $signer = null,
        ?string $description = null,
        array $meta = [],
    ): ?SignatureEvent {
        try {
            $http = request();
            $user = auth()->user();

            return SignatureEvent::create([
                'signature_request_id' => $request?->id,
                'signature_signer_id' => $signer?->id,
                'user_id' => $user?->id,
                'event' => $event,
                // ?-> ist auf der linken Seite von ?? ueberfluessig: der
                // Null-Zusammenfuehrungsoperator faengt den Fall bereits ab.
                'actor' => $signer->name ?? $user->name ?? null,
                'description' => $description === null ? null : mb_substr($description, 0, 500),
                'ip' => $http->ip(),
                // Der Browser-Kennstring gehoert zum Nachweis: er zeigt, mit
                // welchem Geraet unterschrieben wurde.
                'user_agent' => mb_substr((string) $http->userAgent(), 0, 500) ?: null,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Signatur-Protokoll konnte nicht geschrieben werden: '.$e->getMessage(), [
                'event' => $event,
                'signature_request_id' => $request?->id,
            ]);

            return null;
        }
    }
}
