<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Messaging\Channels\Onboarding\EmbeddedSignupService;
use App\Services\Messaging\Channels\Onboarding\OnboardingFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Der Rueckweg aus dem Meta-Fenster (Auftrag 20).
 *
 * Der Browser liefert NUR einen kurzlebigen Code und zwei Kennungen.
 * Alles Weitere - Tausch gegen ein Token, Pruefung, Webhook-Abonnement -
 * passiert hier auf dem Server. Ein Token erreicht den Browser zu
 * keinem Zeitpunkt, und das App-Secret verlaesst den Server nie.
 */
class WhatsAppOnboardingController extends Controller
{
    public function __construct(private readonly EmbeddedSignupService $signup) {}

    public function complete(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'waba_id' => ['required', 'string', 'max:100'],
            'phone_number_id' => ['required', 'string', 'max:100'],
            // Ob im Fenster der Business-App-Weg gewaehlt wurde. Kommt aus
            // dem Browser und wird deshalb als BEHAUPTUNG behandelt, nicht
            // als Beweis - siehe Hinweis unten.
            'coexistence' => ['nullable', 'boolean'],
        ]);

        try {
            $konto = $this->signup->complete(
                code: $data['code'],
                wabaId: $data['waba_id'],
                phoneNumberId: $data['phone_number_id'],
                coexistence: (bool) ($data['coexistence'] ?? false),
                // Das Bestaetigungs-Token des Webhooks erzeugen WIR - es
                // muss niemand abtippen, und niemand waehlt versehentlich
                // ein kurzes.
                verifyToken: Str::random(40),
            );
        } catch (OnboardingFailed $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::record('whatsapp_onboarded', 'channel_account', $konto->id, [
            'name' => $konto->name,
            'connection_type' => $konto->connection_type,
            'connection_status' => $konto->connection_status,
        ]);

        $hinweis = $konto->isCoexistence()
            ? 'Die Nummer ist als Coexistence angebunden (Business App + Cloud API). '
                .'Bitte prüfen Sie in der WhatsApp Business App, dass die Verknüpfung dort bestätigt ist.'
            : 'Die Nummer ist über die Cloud API angebunden. '
                .'Das ist NICHT dasselbe wie Coexistence - dafür ist der Weg "WhatsApp Business App verbinden" nötig.';

        return back()->with('success', 'WhatsApp-Nummer "'.$konto->name.'" verbunden. '.$hinweis);
    }
}
