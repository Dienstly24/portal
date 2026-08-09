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
    /** Klick im Mail-Body (GET) - zeigt die Bestaetigungsseite, aendert NICHTS. */
    public function handle(string $token)
    {
        // KEINE Statusaenderung im GET (Audit MAIL-2): Mail-Sicherheits-Scanner
        // und Link-Prefetcher (Outlook/AV-Gateways) rufen Links in E-Mails
        // automatisch ab und wuerden Kunden sonst ungewollt abmelden. Erst der
        // ausdrueckliche POST (Button bzw. RFC-8058-Ein-Klick) meldet ab.
        $customer = Customer::where('unsubscribe_token', $token)->firstOrFail();
        return view('unsubscribe_confirm', [
            'lang' => $customer->preferred_lang ?? 'de',
            'token' => $token,
        ]);
    }

    /**
     * Abmeldung ausfuehren (POST) - zwei Aufrufer:
     * - Mensch: Klick auf "Abmelden" auf der Bestaetigungsseite (Formular-POST)
     *   -> HTML-Erfolgsseite.
     * - RFC 8058 Ein-Klick (Gmail/Yahoo/Apple): Server-zu-Server-POST mit Body
     *   "List-Unsubscribe=One-Click" an die List-Unsubscribe-URL, erwartet eine
     *   2xx-Antwort ohne Interaktion (Audit MAIL-1). Kein View - nur 200.
     */
    public function oneClick(string $token, \Illuminate\Http\Request $request)
    {
        $customer = $this->unsubscribe($token);

        // Maschinen-Client (One-Click-Body bzw. keine HTML-Erwartung): nur 200.
        if ($request->input('List-Unsubscribe') === 'One-Click' || ! $request->acceptsHtml()) {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        return view('unsubscribe', ['lang' => $customer->preferred_lang ?? 'de']);
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
