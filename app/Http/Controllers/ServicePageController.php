<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ServicePage;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Oeffentliche Leistungsseiten (/leistungen und /leistungen/{slug}).
 * Das Anfrageformular je Seite erzeugt - wie das Hilfe-Formular und der
 * WordPress-Lead-Endpoint - ein Ticket (source=website) und benachrichtigt
 * das Team. Da die Seite im Portal selbst gerendert wird, greift der
 * regulaere CSRF-Schutz; zusaetzlich Honeypot + Throttle gegen Spam.
 */
class ServicePageController extends Controller
{
    public function index()
    {
        $pages = ServicePage::active()->ordered()->get();
        return view('services.index', compact('pages'));
    }

    public function show(string $slug)
    {
        $page = ServicePage::active()->where('slug', $slug)->firstOrFail();
        return view('services.show', compact('page'));
    }

    public function submit(Request $request, string $slug)
    {
        $page = ServicePage::active()->where('slug', $slug)->firstOrFail();

        // Honeypot: echte Nutzer fuellen das versteckte Feld nie aus.
        if ($request->filled('website')) {
            return redirect()->route('services.show', $page->slug)->with('sent', true);
        }

        $data = $request->validate([
            'name' => 'required|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|max:50',
            'message' => 'nullable|max:5000',
            'consent' => 'accepted',
        ], [], [
            'consent' => __('Einwilligung'),
        ]);

        // Mindestens eine Kontaktmoeglichkeit ist erforderlich.
        if (empty($data['email']) && empty($data['phone'])) {
            return back()->withInput()
                ->withErrors(['email' => __('Bitte geben Sie E-Mail oder Telefon an.')]);
        }

        // Bestandskunden ueber die E-Mail zuordnen (nur wenn E-Mail vorhanden).
        $customer = null;
        if (!empty($data['email'])) {
            $customer = Customer::whereHas('user', fn ($q) => $q->where('email', $data['email']))
                ->orWhere('email', $data['email'])->first();
        }

        $leistung = $page->title_de;
        $ticket = Ticket::forceCreate([
            'id' => Str::uuid(),
            'customer_id' => $customer?->id,
            'source' => 'website',
            'type' => 'offer',
            'priority' => 'mittel',
            'status' => 'open',
            'subject' => 'Anfrage ' . $leistung . ' von ' . $data['name'],
            'description' => ($data['message'] ?? '') !== ''
                ? $data['message']
                : ('Anfrage zur Leistung "' . $leistung . '" ueber die Website.'),
            'guest_name' => $data['name'],
            'guest_email' => $customer ? null : ($data['email'] ?? null),
            'guest_phone' => $data['phone'] ?? null,
        ]);

        \App\Services\TicketNotifier::notifyNewTicket($ticket);

        $supportEmail = config('services.inquiry.support_email') ?: config('mail.from.address');
        if ($supportEmail) {
            try {
                \Illuminate\Support\Facades\Mail::to($supportEmail)
                    ->send(new \App\Mail\SupportInquiryMail($ticket, $customer?->customer_number));
            } catch (\Throwable $e) {
                \Log::warning('Service-Anfrage-Mail fehlgeschlagen: ' . $e->getMessage());
            }
        }

        return redirect()->route('services.show', $page->slug)->with('sent', true);
    }
}
