<?php
namespace App\Mail;

use App\Models\Customer;
use App\Models\SystemSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Willkommens-/Einladungsmail für das Kundenportal.
 * Modi:
 * - 'birthdate': Startpasswort = Geburtsdatum (TT.MM.JJJJ) - die Mail
 *   nennt die REGEL, nicht das Datum (kein Klartext-Passwort).
 * - 'setlink':  kein Geburtsdatum hinterlegt - sicherer Link zum
 *   Selbst-Setzen des Passworts.
 * - 'manual':   Berater hat ein Passwort vergeben und teilt es mit.
 *
 * Ziel: Der Kunde kann sich ohne Rückfrage beim Support einloggen.
 *
 * Bewusst NICHT queued: Diese Mail ist der einzige Zugangsweg des
 * Kunden. Als Queue-Job haengt der Versand am Queue-Worker – steht
 * der Worker, kommt die Mail nie an und niemand merkt es. Synchron
 * schlaegt der Versand sofort fehl und der Aufrufer (Admin-UI,
 * Batch, autoInvite) sieht/loggt den Fehler.
 */
class CustomerWelcomeMail extends Mailable
{
    use SerializesModels;

    public string $loginEmail;
    public string $customerName;
    public string $lang;

    /** Magischer Erst-Login: signierter Link, 90 Tage gültig (nur Kunden). */
    public ?string $magicLoginUrl = null;

    /** Hilfe-Button: vorbefülltes Kontaktformular, legt automatisch ein Ticket an. */
    public string $supportUrl;

    /** Basis-URL des Kundenportals – alle Kundenlinks zeigen hierauf. */
    public string $portalBase;

    public function __construct(
        public Customer $customer,
        public string $mode,
        public ?string $plainPassword = null,
        public ?string $setPasswordUrl = null,
    ) {
        $this->loginEmail = (string) $customer->user?->email;
        $this->customerName = (string) ($customer->user?->name ?? '');
        $this->lang = $customer->preferred_lang ?? 'de';

        // Kundenmails werden meist aus der Beraterwelt (admin.dienstly24.de)
        // ausgeloest. route()/url() wuerden dann den Admin-Host verwenden.
        // Deshalb bauen wir ALLE Kundenlinks explizit auf der Portal-Domain,
        // damit der Kunde nie auf admin.dienstly24.de landet.
        $this->portalBase = rtrim(SystemSetting::get('portal_url', 'https://portal.dienstly24.de'), '/');

        $previousRoot = URL::to('/');
        URL::forceRootUrl($this->portalBase);
        URL::forceScheme('https');
        try {
            if ($customer->user) {
                $this->magicLoginUrl = URL::temporarySignedRoute(
                    'magic.login', now()->addDays(90), ['user' => $customer->user->id]
                );
            }
            $this->supportUrl = route('support.form', ['t' => \App\Http\Controllers\SupportFormController::tokenFor($customer)]);
            // Der Set-Link wird im PortalAccessService per route() gebaut
            // und traegt dort den Host der AUSLOESENDEN Anfrage (meist
            // admin.dienstly24.de). Hier auf die Portal-Domain umschreiben.
            if ($this->setPasswordUrl !== null) {
                $teile = parse_url($this->setPasswordUrl);
                $this->setPasswordUrl = $this->portalBase
                    . ($teile['path'] ?? '/')
                    . (isset($teile['query']) ? '?' . $teile['query'] : '');
            }
        } finally {
            // Urspruenglichen Root UND Schema wiederherstellen (fuer die
            // laufende Admin-Anfrage) - forceScheme('https') wuerde sonst
            // nach dem Mailbau bestehen bleiben.
            URL::forceRootUrl($previousRoot);
            URL::forceScheme(str_starts_with($previousRoot, 'https://') ? 'https' : 'http');
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? '🎉 أهلاً بك في Dienstly24 – بوابتك جاهزة الآن'
            : '🎉 Willkommen bei Dienstly24 – Ihr Kundenportal ist bereit');
    }

    public function content(): Content
    {
        // Zusaetzlich eine Text-Variante (multipart/alternative): reine
        // HTML-Mails erhoehen den Spam-Score bei Outlook/Gmail. Die
        // Text-Version verbessert die Zustellbarkeit und dient als Fallback.
        return new Content(
            view: 'emails.customer_welcome',
            text: 'emails.customer_welcome_text',
        );
    }
}
