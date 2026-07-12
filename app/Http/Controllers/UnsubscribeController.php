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
    public function handle(string $token)
    {
        $customer = Customer::where('unsubscribe_token', $token)->firstOrFail();
        if ($customer->unsubscribed_at === null) {
            $customer->forceFill([
                'marketing_consent' => false,
                'unsubscribed_at' => now(),
            ])->save();
        }
        return view('unsubscribe', ['lang' => $customer->preferred_lang ?? 'de']);
    }
}
