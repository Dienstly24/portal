<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Öffentliches Hilfe-/Kontaktformular (/hilfe).
 *
 * Der Button „Brauchen Sie Hilfe?" in der Willkommens-E-Mail führt hierher –
 * mit einem verschlüsselten Kunden-Token, sodass Name, E-Mail und
 * Kundennummer bereits ausgefüllt sind. Der Kunde wählt nur noch die
 * gewünschte Leistung, beschreibt sein Anliegen und sendet ab. Daraus
 * entsteht automatisch ein Ticket, das mit der Kundenakte verknüpft ist.
 *
 * Ohne Token (z. B. Link von der Website) funktioniert das Formular auch:
 * Besucher geben Name + E-Mail selbst an; kann die E-Mail einem Kunden
 * zugeordnet werden, wird das Ticket trotzdem verknüpft, sonst als
 * Gast-Ticket gespeichert.
 */
class SupportFormController extends Controller
{
    /** Auswahl „Gewünschte Leistung" -> interner Tickettyp. */
    public const LEISTUNGEN = [
        'login' => ['label' => 'Login / Zugang zum Portal', 'type' => 'other'],
        'vertrag' => ['label' => 'Frage zu einem Vertrag', 'type' => 'change'],
        'angebot' => ['label' => 'Neues Angebot anfragen', 'type' => 'offer'],
        'dokumente' => ['label' => 'Dokumente / Unterlagen', 'type' => 'other'],
        'datenaenderung' => ['label' => 'Meine Daten ändern', 'type' => 'data_update'],
        'schaden' => ['label' => 'Schaden melden', 'type' => 'damage'],
        'sonstiges' => ['label' => 'Sonstiges Anliegen', 'type' => 'other'],
    ];

    /** Gültigkeit des Vorbefüllungs-Tokens aus der Willkommens-E-Mail. */
    private const TOKEN_DAYS = 180;

    public static function tokenFor(Customer $customer): string
    {
        return Crypt::encryptString(json_encode([
            'c' => $customer->id,
            'exp' => now()->addDays(self::TOKEN_DAYS)->timestamp,
        ]));
    }

    private function customerFromToken(?string $token): ?Customer
    {
        if (!$token) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        return Customer::find($data['c'] ?? null);
    }

    public function show(Request $request)
    {
        // Eingeloggte Kunden brauchen kein Token
        $customer = null;
        if ($request->user() && $request->user()->role === 'customer') {
            $customer = $request->user()->customer;
        }
        $customer ??= $this->customerFromToken($request->query('t'));

        return view('support.form', [
            'customer' => $customer,
            'token' => $customer ? ($request->query('t') ?: self::tokenFor($customer)) : null,
            'leistungen' => self::LEISTUNGEN,
        ]);
    }

    public function submit(Request $request)
    {
        // Honeypot: Bots füllen das unsichtbare Feld aus
        if ($request->filled('website')) {
            abort(422);
        }

        // "Vertrauenswürdig" identifiziert = per Token aus der Willkommens-Mail
        // oder eingeloggt. Nur dann darf die Antwortseite Kundenbezug zeigen.
        $trusted = $this->customerFromToken($request->input('t'));
        if (!$trusted && $request->user() && $request->user()->role === 'customer') {
            $trusted = $request->user()->customer;
        }
        $customer = $trusted;

        $rules = [
            'leistung' => 'required|in:' . implode(',', array_keys(self::LEISTUNGEN)),
            'message' => 'required|string|max:5000',
        ];
        if (!$customer) {
            $rules['name'] = 'required|string|max:150';
            $rules['email'] = 'required|email|max:190';
        }
        $data = $request->validate($rules);

        // Inhaltsbasierte Spam-Erkennung: erkannte Bot-Werbung still verwerfen
        // (kein Ticket) und - wie beim Honeypot - die Dankeseite anzeigen.
        if ($spam = \App\Services\SpamFilter::reason([$data['name'] ?? null, $data['message']])) {
            \Log::info('Hilfe-Anfrage als Spam verworfen: ' . $spam);
            return view('support.thanks', ['ticketRef' => null, 'customer' => $trusted]);
        }

        // Gast-Anfrage: per E-Mail trotzdem der Kundenakte zuordnen, wenn
        // möglich - aber NUR intern. Die Antwortseite verrät die Zuordnung
        // nicht (sonst könnten Fremde per E-Mail-Raten Kundenkonten erkennen).
        if (!$customer) {
            $customer = Customer::whereHas('user', fn ($q) => $q->where('email', $data['email']))
                ->orWhere('email', $data['email'])->first();
        }

        $leistung = self::LEISTUNGEN[$data['leistung']];

        $ticket = Ticket::create([
            'id' => Str::uuid(),
            'customer_id' => $customer?->id,
            'type' => $leistung['type'],
            'priority' => 'mittel',
            'subject' => 'Hilfe-Anfrage: ' . $leistung['label'],
            'description' => $data['message'],
            'status' => 'open',
            'source' => 'hilfe-formular',
            'guest_name' => $customer ? null : $data['name'],
            'guest_email' => $customer ? null : $data['email'],
        ]);

        ActivityLog::create([
            'user_id' => $customer?->user_id,
            'action' => 'support_request_created',
            'entity_type' => Ticket::class,
            'entity_id' => $ticket->id,
            'meta' => json_encode([
                'leistung' => $leistung['label'],
                'kunde' => $customer?->customer_number,
                'gast' => $customer ? null : ($data['email'] ?? null),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Team ueber die neue Anfrage informieren (Glocke).
        \App\Services\TicketNotifier::notifyNewTicket($ticket);

        return view('support.thanks', [
            // Einheitliche Vorgangsnummer (T-...) statt UUID-Fragment -
            // dieselbe Nummer, die auch in Mails und Beraterwelt steht.
            'ticketRef' => $ticket->ticket_number,
            'customer' => $trusted,
        ]);
    }
}
