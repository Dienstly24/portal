<?php

namespace App\Http\Controllers;

use App\Services\Signature\SignatureHandoffService;
use Illuminate\Http\Request;

/**
 * Die Handy-Seite von "Auf dem Handy unterschreiben" (Teil B). Ohne
 * Anmeldung erreichbar - der Zugang IST der kurzlebige, einmal nutzbare
 * Link aus dem QR-Code. Wer den Link hat, kann zeichnen; das Ergebnis
 * bekommt aber nur die Sitzung am Rechner, die den Link erzeugt hat (siehe
 * SignatureHandoffService).
 */
class SignatureHandoffController extends Controller
{
    public function __construct(private readonly SignatureHandoffService $handoffs)
    {
    }

    public function show(string $token)
    {
        $handoff = $this->handoffs->finde($token);
        if ($handoff === null || ! $handoff->istOffen()) {
            return response()->view('signature.handoff', ['zustand' => 'tot', 'token' => null], 410);
        }

        return view('signature.handoff', ['zustand' => 'offen', 'token' => $token, 'bis' => $handoff->expires_at]);
    }

    public function store(Request $request, string $token)
    {
        $handoff = $this->handoffs->finde($token);
        if ($handoff === null || ! $handoff->istOffen()) {
            return response()->json(['ok' => false, 'message' => 'Dieser Link ist abgelaufen oder wurde schon benutzt.'], 410);
        }
        $data = $request->validate(['zeichnung' => ['required', 'string', 'max:3000000']]);
        try {
            $this->handoffs->zeichnungAnnehmen($handoff, $data['zeichnung'], mb_substr((string) $request->userAgent(), 0, 160) ?: null);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Übertragen. Sie können das Handy weglegen - die Unterschrift erscheint jetzt am Rechner.']);
    }
}
