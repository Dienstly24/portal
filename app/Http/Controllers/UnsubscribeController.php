<?php
namespace App\Http\Controllers;

use App\Models\Customer;

/**
 * Öffentlicher Abmelde-Link aus Marketing-Mails (UWG §7 / DSGVO,
 * Paket A1). Ohne Login erreichbar; das Token ist pro Kunde eindeutig
 * und wird beim ersten Versand erzeugt. Idempotent.
 */
class UnsubscribeController extends Controller
{
    /** Klick im Mail-Body (GET) - zeigt die Bestaetigungsseite. */
    public function handle(string $token)
    {
        $customer = $this->unsubscribe($token);
        return view('unsubscribe', ['lang' => $customer->preferred_lang ?? 'de']);
    }

    /**
     * Ein-Klick-Abmeldung (RFC 8058): Gmail/Yahoo/Apple senden bei Klick auf
     * ihren nativen "Abmelden"-Button einen Server-zu-Server-POST an die
     * List-Unsubscribe-URL und erwarten eine 2xx-Antwort ohne Interaktion.
     * Fehlte diese POST-Route, quittierte der Server mit 405/419 und der Kunde
     * wurde NICHT abgemeldet - obwohl der Header genau das versprach und
     * grosse Anbieter das inzwischen von Massenversendern verlangen (Audit
     * MAIL-1). Kein View, keine Weiterleitung - nur 200.
     */
    public function oneClick(string $token)
    {
        $this->unsubscribe($token);
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /** Idempotente Abmeldung; wirft 404 bei unbekanntem Token. */
    private function unsubscribe(string $token): Customer
    {
        $customer = Customer::where('unsubscribe_token', $token)->firstOrFail();
        if ($customer->unsubscribed_at === null) {
            $customer->forceFill([
                'marketing_consent' => false,
                'unsubscribed_at' => now(),
            ])->save();
        }
        return $customer;
    }
}
