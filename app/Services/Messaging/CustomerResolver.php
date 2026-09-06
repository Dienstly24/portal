<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Services\Messaging\Dto\InboundMessage;

/**
 * Wer hat da geschrieben?
 *
 * Die Reihenfolge geht von TRENNSCHARF nach schwach, wie im
 * Provisions-Abgleich: eine gespeicherte Kanal-Identitaet ist ein
 * Beleg, eine uebereinstimmende Telefonnummer ein Indiz, ein Name gar
 * nichts.
 *
 * ZWEI REGELN, die den Rest bestimmen:
 * 1. NIE RATEN. Treffen zwei Kunden zu, wird keiner genommen - eine
 *    falsch zugeordnete Nachricht zeigt einem Kunden die Unterhaltung
 *    eines anderen, und das ist schlimmer als eine unzugeordnete.
 * 2. Der NAME zaehlt nie. "Mohamad Ali" gibt es mehrfach.
 *
 * Wird niemand gefunden, ist das KEIN Fehler: die Unterhaltung
 * entsteht trotzdem, nur ohne Kundenakte. Ein Mitarbeiter ordnet sie
 * spaeter zu - eine verworfene Nachricht bekaeme niemand zurueck.
 */
class CustomerResolver
{
    public function resolve(InboundMessage $message, Channel $channel, ?ChannelAccount $account): ?Customer
    {
        // 1) Gespeicherte Identitaet - der einzige echte BELEG.
        $identity = CustomerChannelIdentity::query()
            ->where('channel_id', $channel->id)
            ->where('external_user_id', $message->externalUserId)
            ->when($account, fn ($q) => $q->where('channel_account_id', $account->id))
            ->first();

        if ($identity?->customer) {
            return $identity->customer;
        }

        // 2) Telefonnummer bzw. E-Mail aus der Nachricht. Beides sind
        //    Kennungen, die der Kunde selbst gesetzt hat - aber sie
        //    zaehlen nur, wenn sie GENAU EINEN Kunden treffen.
        $customer = $this->byPhone($message->senderPhone)
            ?? $this->byEmail($message->senderEmail);

        if ($customer) {
            // Ab jetzt ist die Zuordnung ein Beleg: beim naechsten Mal
            // greift Stufe 1 und wir suchen nicht mehr.
            $this->remember($customer, $channel, $account, $message);
        }

        return $customer;
    }

    /** Kanal-Identitaet festhalten - idempotent. */
    public function remember(Customer $customer, Channel $channel, ?ChannelAccount $account, InboundMessage $message): CustomerChannelIdentity
    {
        return CustomerChannelIdentity::updateOrCreate(
            [
                'channel_account_id' => $account?->id,
                'external_user_id' => $message->externalUserId,
            ],
            [
                'customer_id' => $customer->id,
                'channel_id' => $channel->id,
                'external_username' => $message->senderName,
            ]
        );
    }

    /**
     * Telefonnummern werden auf ihre ZIFFERN reduziert verglichen: die
     * Plattform liefert "491701234567", die Kundenakte fuehrt
     * "0170 123 45 67". Ohne Normalisierung findet man nie etwas.
     * Verglichen werden die letzten neun Ziffern - Landesvorwahl und
     * fuehrende Null unterscheiden sich je nach Schreibweise, der Rest
     * nicht.
     */
    private function byPhone(?string $phone): ?Customer
    {
        $ziffern = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($ziffern) < 9) {
            return null;
        }
        $endung = substr($ziffern, -9);

        $treffer = Customer::query()
            ->where(function ($q) use ($endung) {
                foreach (['phone', 'mobile'] as $spalte) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$spalte},''),' ',''),'-',''),'/',''),'+','') LIKE ?",
                        ['%'.$endung]
                    );
                }
            })
            ->limit(2)->get();

        // Genau ein Treffer, sonst nichts - siehe Regel 1.
        return $treffer->count() === 1 ? $treffer->first() : null;
    }

    private function byEmail(?string $email): ?Customer
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        // Interne Platzhalter sind keine echten Adressen und duerfen nie
        // als Kennung dienen (dieselbe Regel wie beim Passwort-Reset).
        if (str_ends_with(strtolower($email), '@dienstly24.internal')) {
            return null;
        }

        $treffer = Customer::query()
            ->where(fn ($q) => $q->where('email', $email)->orWhere('email2', $email))
            ->limit(2)->get();

        return $treffer->count() === 1 ? $treffer->first() : null;
    }
}
