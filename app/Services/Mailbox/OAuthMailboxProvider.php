<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;

/**
 * Platzhalter für Gmail-API- und Microsoft-Graph-Anbindung (Architekturplan
 * Abschnitt 3.1). Der OAuth-Consent-Flow (Redirect, Callback, Refresh-Token-
 * Speicherung) ist als eigener Ausbauschritt geplant und bewusst noch nicht
 * Teil dieser Umsetzung, da er App-Registrierungen bei Google/Microsoft
 * voraussetzt, die außerhalb dieses Systems angelegt werden müssen.
 * Die Admin-UI kann den Kontotyp bereits auswählen; "Verbinden" meldet
 * bis dahin klar zurück, dass die Anbindung noch aussteht.
 */
class OAuthMailboxProvider implements MailboxProviderInterface
{
    public function testConnection(EmailAccount $account): bool
    {
        throw new \RuntimeException(
            'OAuth-Anbindung für ' . (EmailAccount::PROVIDERS[$account->provider] ?? $account->provider)
            . ' ist noch nicht konfiguriert - der OAuth-Consent-Flow ist als Folgeschritt geplant (siehe Systemanalyse Abschnitt 3.1).'
        );
    }

    public function fetchNewMessages(EmailAccount $account, int $limit = 50): array
    {
        return [];
    }
}
