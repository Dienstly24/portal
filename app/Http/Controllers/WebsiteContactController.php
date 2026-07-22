<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Kontaktformular der oeffentlichen Website (dienstly24.de).
 *
 * Ersetzt den alten Weg "Formular -> lose Mail an info@" (zog Bot-Spam wie
 * Casino-Werbung an) durch einen mehrstufig geschuetzten Portal-Endpunkt,
 * der aus jeder echten Anfrage ein Ticket macht:
 *
 * 1. Formular-Token: Das Website-JS holt beim Laden ein verschluesseltes
 *    Token (GET /api/website-contact/token). Bots, die kein JavaScript
 *    ausfuehren, koennen gar nicht erst gueltig absenden.
 * 2. Mindest-Ausfuellzeit: Absenden frueher als MIN_AGE_SECONDS nach der
 *    Token-Ausgabe gilt als Bot -> Token-Fehler. Das Website-JS holt dann
 *    ein frisches Token, wartet und sendet automatisch erneut, sodass ein
 *    (unrealistisch schneller) Mensch nie eine Anfrage verliert.
 * 3. Einmal-Token: Jedes Token ist nur einmal gueltig (Replay-Schutz).
 * 4. Honeypot-Feld "website": ausgefuellt -> still verworfen.
 * 5. Inhaltsfilter SpamFilter: Werbe-/Gluecksspiel-Spam -> still verworfen.
 * 6. Throttle pro IP (Route-Middleware).
 *
 * "Still verworfen" heisst: Die Antwort sieht wie Erfolg aus, damit Bots
 * keine Rueckmeldung zum Umgehen erhalten. Kein Ticket, keine Mail.
 */
class WebsiteContactController extends Controller
{
    /** Auswahl "Gewuenschte Leistung" im Website-Formular -> Tickettyp. */
    public const LEISTUNGEN = [
        'Kfz-Versicherung' => 'offer',
        'Krankenversicherung' => 'offer',
        'Zahnzusatzversicherung' => 'offer',
        'Kfz-Zulassungsservice' => 'offer',
        'Kennzeichen per Post' => 'offer',
        'Strom & Gas' => 'offer',
        'Sonstiges' => 'other',
    ];

    /** Schneller als so viele Sekunden nach Token-Ausgabe = Bot. */
    public const MIN_AGE_SECONDS = 5;

    /** Aelter als so viele Sekunden = Token abgelaufen (Tab lange offen). */
    public const MAX_AGE_SECONDS = 7200;

    public function token()
    {
        return response()->json(['token' => Crypt::encryptString(json_encode([
            'iat' => now()->timestamp,
            'n' => Str::random(16),
        ]))]);
    }

    public function submit(Request $request)
    {
        // Honeypot: Menschen sehen das Feld nicht, Bots fuellen es aus.
        if ($request->filled('website')) {
            \Log::info('Website-Kontakt verworfen: Honeypot ausgefuellt');
            return $this->fakeSuccess();
        }

        $meta = $this->tokenMeta($request->input('token'));
        if (!$meta) {
            return response()->json(['error' => 'token'], 422);
        }
        $age = now()->timestamp - (int) $meta['iat'];
        if ($age > self::MAX_AGE_SECONDS || $age < self::MIN_AGE_SECONDS) {
            // Zu alt (Tab lange offen) oder zu schnell (Bot-Tempo). Beides
            // meldet denselben generischen Token-Fehler: Das Website-JS holt
            // ein frisches Token, wartet die Mindestzeit und sendet erneut -
            // Menschen verlieren nichts, simple Bots scheitern.
            return response()->json(['error' => 'token'], 422);
        }
        // Einmal-Token: Wiederverwendung (Replay/Massenversand) blocken.
        if (!Cache::add('website-contact-token:' . $meta['n'], 1, self::MAX_AGE_SECONDS)) {
            return response()->json(['error' => 'token'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'kontakt' => 'required|string|max:190',
            'leistung' => ['required', 'string', Rule::in(array_keys(self::LEISTUNGEN))],
            'nachricht' => 'nullable|string|max:5000',
        ]);

        // Inhaltsbasierte Spam-Erkennung: erkannte Bot-Werbung still
        // verwerfen (kein Ticket, keine Mail), Antwort wie Erfolg.
        if ($spam = \App\Services\SpamFilter::reason([$data['name'], $data['kontakt'], $data['nachricht'] ?? null])) {
            \Log::info('Website-Kontakt als Spam verworfen: ' . $spam);
            return $this->fakeSuccess();
        }

        // Ein Feld fuer E-Mail ODER Telefon: nur gueltige Adressen als
        // E-Mail speichern, alles andere als Telefon-/Freitextkontakt.
        $kontakt = trim($data['kontakt']);
        $email = str_contains($kontakt, '@') && filter_var($kontakt, FILTER_VALIDATE_EMAIL)
            ? mb_strtolower($kontakt)
            : null;

        // Bestandskunden ueber die E-Mail-Adresse zuordnen.
        $customer = $email
            ? Customer::whereHas('user', fn ($q) => $q->where('email', $email))
                ->orWhere('email', $email)->first()
            : null;

        $nachricht = trim((string) ($data['nachricht'] ?? ''));

        $ticket = Ticket::forceCreate([
            'id' => Str::uuid(),
            'customer_id' => $customer?->id,
            'source' => 'website',
            'type' => self::LEISTUNGEN[$data['leistung']],
            'priority' => 'mittel',
            'status' => 'open',
            'subject' => 'Website-Anfrage: ' . $data['leistung'],
            'description' => $nachricht !== ''
                ? $nachricht
                : 'Keine Nachricht angegeben - Kontaktwunsch zu: ' . $data['leistung'],
            'guest_name' => $data['name'],
            'guest_email' => $email,
            'guest_phone' => $email ? null : $kontakt,
        ]);

        // Team-Glocke wie bei allen oeffentlichen Formularen.
        \App\Services\TicketNotifier::notifyNewTicket($ticket);

        // Support-Mail wie bei der Website-Lead-API - jetzt aber nur noch
        // fuer Anfragen, die alle Spam-Schichten passiert haben.
        $supportEmail = config('services.inquiry.support_email') ?: config('mail.from.address');
        if ($supportEmail) {
            try {
                \Illuminate\Support\Facades\Mail::to($supportEmail)
                    ->send(new \App\Mail\SupportInquiryMail($ticket, $customer?->customer_number));
            } catch (\Throwable $e) {
                \Log::warning('Website-Kontakt Support-Mail fehlgeschlagen: ' . $e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }

    /** Entschluesselt das Formular-Token; null bei fehlend/manipuliert. */
    private function tokenMeta(?string $token): ?array
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) && isset($data['iat'], $data['n']) ? $data : null;
    }

    /** Antwort fuer still verworfene Anfragen - identisch zum Erfolg. */
    private function fakeSuccess()
    {
        return response()->json(['success' => true]);
    }
}
