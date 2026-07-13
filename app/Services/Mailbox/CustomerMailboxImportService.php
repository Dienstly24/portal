<?php
namespace App\Services\Mailbox;

use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\SystemSetting;

/**
 * Einwilligungs- und zweckgebundene Import-Pipeline (Variante A der
 * Konzeptstudie docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md).
 *
 * Kunden richten bei ihrem Anbieter eine Auto-Weiterleitung
 * VERTRAGSBEZOGENER Post an ihre persoenliche Adresse
 * import+<token>@<domain> ein. Dieses Postfach ist als
 * is_customer_import markiert. Fuer solche Mails gilt:
 *
 *  1. Der Token muss zu einer AKTIVEN Einwilligung gehoeren, sonst wird
 *     die Mail verworfen (nicht gespeichert) - keine Verarbeitung ohne
 *     nachweisbare Zustimmung (Art. 6/7 DSGVO).
 *  2. Die Absenderdomain muss auf der Whitelist stehen (Zweckbindung /
 *     Data Minimization) - private/fremde Weiterleitungen werden
 *     verworfen, nie gespeichert.
 *
 * Erst wenn beide Bedingungen erfuellt sind, steht der Kunde DETERMINISTISCH
 * fest (kein Score-Matching) und die Mail laeuft durch die bestehende
 * Workflow-Pipeline.
 */
class CustomerMailboxImportService
{
    /**
     * Kunde mit aktiver Einwilligung, dessen Import-Token in den
     * Empfaenger-Headern der Mail steht - oder null.
     */
    public function resolveConsentingCustomer(MailboxMessageData $data): ?Customer
    {
        $token = $this->extractToken($data);
        if ($token === null) {
            return null;
        }

        $consent = CustomerConsent::emailProcessing()->active()
            ->where('import_token', $token)->first();

        return $consent?->customer()->with('user')->first();
    }

    /** Absenderdomain auf der Whitelist? (Zweckbindung auf Vertragspost.) */
    public function isAllowedSender(string $fromAddress): bool
    {
        $domain = mb_strtolower(trim((string) (explode('@', $fromAddress)[1] ?? '')));
        if ($domain === '') {
            return false;
        }

        foreach ($this->allowedDomains() as $allowed) {
            // Exakte Domain oder Subdomain (z. B. mail.allianz.de zu allianz.de).
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Persoenliche Import-Adresse fuer eine Einwilligung. */
    public function importAddressFor(CustomerConsent $consent): string
    {
        return sprintf(
            '%s+%s@%s',
            config('mailbox.import_local_part', 'import'),
            $consent->import_token,
            config('mailbox.import_domain', 'dienstly24.de')
        );
    }

    /**
     * Token aus den Empfaenger-Feldern ziehen. Bei Weiterleitung bleibt der
     * urspruengliche "To"-Header oft die Kundenadresse; die tatsaechliche
     * Zustelladresse import+<token>@ steht in Delivered-To / X-Original-To.
     * Deshalb wird der gesamte Header-Block plus to_address durchsucht.
     */
    private function extractToken(MailboxMessageData $data): ?string
    {
        $prefix = preg_quote(config('mailbox.import_local_part', 'import'), '/');
        $haystack = mb_strtolower($this->recipientHaystack($data));

        if (preg_match('/' . $prefix . '\+([a-z0-9]{8,64})@/', $haystack, $m)) {
            return $m[1];
        }

        return null;
    }

    private function recipientHaystack(MailboxMessageData $data): string
    {
        $parts = [(string) $data->toAddress];

        // Header-Struktur ist providerabhaengig (Strings, Arrays, Address-
        // Objekte). Robust flach als JSON serialisieren; scheitert das,
        // bleibt wenigstens to_address als Signal.
        $encoded = json_encode($data->headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $parts[] = $encoded;
        }

        return implode(' ', array_filter($parts));
    }

    /** @return string[] */
    private function allowedDomains(): array
    {
        $override = SystemSetting::get('email_import_allowed_domains');
        if (is_string($override) && trim($override) !== '') {
            $list = preg_split('/[\s,;]+/', mb_strtolower($override)) ?: [];
        } else {
            $list = config('mailbox.import_allowed_domains', []);
        }

        return array_values(array_filter(array_map(
            fn ($d) => ltrim(mb_strtolower(trim((string) $d)), '@'),
            $list
        )));
    }
}
