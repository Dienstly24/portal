<?php

namespace App\Http\Controllers;

use App\Mail\SupportInquiryMail;
use App\Mail\WebsiteInquiryConfirmationMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\SpamFilter;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Marketing-Website auf dem Haupt-Domain (www.dienstly24.de), serverseitig
 * gerendert aus Laravel (Merge-Entscheidung 30.07.2026, Arbeitsauftrag §1).
 *
 * Sprachversionen sind ECHTE URLs: / (Deutsch) und /ar (Arabisch, RTL,
 * Hocharabisch) - kein JavaScript-Textaustausch mehr, damit Google beide
 * Versionen indexieren kann (hreflang in den Views).
 *
 * Das Kontaktformular ersetzt das alte "mailto:"-Formular (P0-1): POST
 * /kontakt legt ein Ticket (source=website) an, protokolliert die
 * DSGVO-Einwilligung (Zeitpunkt/IP/Text), schickt eine Support-Mail ans
 * Team und eine Bestaetigung an den Absender - danach /kontakt/danke.
 */
class WebsiteController extends Controller
{
    /** Rechtsseiten der Website (aus der statischen Website uebernommen). */
    public const LEGAL_PAGES = [
        'impressum' => 'Impressum',
        'datenschutz' => 'Datenschutzerklärung',
        'agb' => 'Allgemeine Geschäftsbedingungen',
        'widerruf' => 'Widerrufsbelehrung',
        'erstinformation' => 'Erstinformation nach § 15 VersVermV',
        'cookie-richtlinie' => 'Cookie-Richtlinie',
        'bildnachweise' => 'Bildnachweise',
    ];

    public function home()
    {
        return view('website.home', ['websitePath' => '/', 'onHome' => true]);
    }

    public function thanks()
    {
        return view('website.danke', ['websitePath' => '/kontakt/danke']);
    }

    public function legal(string $page)
    {
        abort_unless(array_key_exists($page, self::LEGAL_PAGES), 404);

        return view('website.legal.'.str_replace('-', '_', $page), [
            'pageTitle' => self::LEGAL_PAGES[$page],
            'pageSlug' => $page,
        ]);
    }

    public function submitContact(Request $request)
    {
        // Sprache des Absenders (Formular-Feld) - bestimmt Fehlermeldungen,
        // Ziel-Seite und die Sprache der Bestaetigungs-Mail.
        $lang = $request->input('lang') === 'ar' ? 'ar' : 'de';
        app()->setLocale($lang);
        $thanksRoute = $lang === 'ar' ? 'ar.website.thanks' : 'website.thanks';

        // Honeypot: Menschen sehen das Feld nie - ausgefuellt = Bot. Antwort
        // sieht wie Erfolg aus, damit Bots kein Feedback zum Umgehen erhalten.
        if ($request->filled('website')) {
            \Log::info('Website-Kontakt (Formular) verworfen: Honeypot ausgefuellt');
            return redirect()->route($thanksRoute);
        }

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'kontakt' => 'required|string|max:190',
            'leistung' => ['required', 'string', Rule::in(array_keys(WebsiteContactController::LEISTUNGEN))],
            'nachricht' => 'nullable|string|max:5000',
            'consent' => 'accepted',
        ], [], [
            'name' => __('Name'),
            'kontakt' => __('E-Mail oder Telefon'),
            'leistung' => __('Gewünschte Leistung'),
            'nachricht' => __('Ihre Nachricht'),
            'consent' => __('Einwilligung'),
        ]);

        // Inhaltsbasierte Spam-Erkennung: still verwerfen, Erfolg vortaeuschen.
        if ($spam = SpamFilter::reason([$data['name'], $data['kontakt'], $data['nachricht'] ?? null])) {
            \Log::info('Website-Kontakt (Formular) als Spam verworfen: '.$spam);
            return redirect()->route($thanksRoute);
        }

        // Ein Feld fuer E-Mail ODER Telefon (bewusst niedrigschwellig).
        $kontakt = trim($data['kontakt']);
        $email = str_contains($kontakt, '@') && filter_var($kontakt, FILTER_VALIDATE_EMAIL)
            ? mb_strtolower($kontakt)
            : null;

        $customer = $email
            ? Customer::whereHas('user', fn ($q) => $q->where('email', $email))
                ->orWhere('email2', $email)->first()
            : null;

        $nachricht = trim((string) ($data['nachricht'] ?? ''));

        // DSGVO-Nachweis: exakt der Satz, dem der Absender zugestimmt hat,
        // in der Sprache, in der er ihn gesehen hat (P0-1).
        $consentText = $lang === 'ar'
            ? 'أوافق على معالجة بياناتي لغرض الرد على طلبي.'
            : 'Ich stimme der Verarbeitung meiner Angaben zur Bearbeitung meiner Anfrage zu.';

        $ticket = Ticket::forceCreate([
            'id' => Str::uuid(),
            'customer_id' => $customer?->id,
            'source' => 'website',
            'type' => WebsiteContactController::LEISTUNGEN[$data['leistung']],
            'priority' => 'mittel',
            'status' => 'open',
            'subject' => 'Website-Anfrage: '.$data['leistung'],
            'description' => $nachricht !== ''
                ? $nachricht
                : 'Keine Nachricht angegeben - Kontaktwunsch zu: '.$data['leistung'],
            'guest_name' => $data['name'],
            'guest_email' => $email,
            'guest_phone' => $email ? null : $kontakt,
            'consent_given_at' => now(),
            'consent_ip' => $request->ip(),
            'consent_text' => $consentText,
        ]);

        TicketNotifier::notifyNewTicket($ticket);

        // Support-Mail ans Team (wie bei allen Website-Anfragen).
        $supportEmail = config('services.inquiry.support_email') ?: config('mail.from.address');
        if ($supportEmail) {
            try {
                Mail::to($supportEmail)->send(new SupportInquiryMail($ticket, $customer?->customer_number));
            } catch (\Throwable $e) {
                \Log::warning('Website-Kontakt Support-Mail fehlgeschlagen: '.$e->getMessage());
            }
        }

        // Eingangsbestaetigung an den Absender (nur bei gueltiger E-Mail).
        if ($email) {
            try {
                Mail::to($email)->send(new WebsiteInquiryConfirmationMail($ticket, $lang));
            } catch (\Throwable $e) {
                \Log::warning('Website-Kontakt Bestaetigungs-Mail fehlgeschlagen: '.$e->getMessage());
            }
        }

        return redirect()->route($thanksRoute);
    }
}
