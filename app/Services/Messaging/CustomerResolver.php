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
        //
        //    WELCHES der beiden Verfahren gegriffen hat, wird
        //    mitgefuehrt: eine so entstandene Zuordnung ist ein INDIZ,
        //    kein Beleg (Auftrag Abschnitt 8). Eine Rufnummer kann
        //    weitergegeben oder von einem Familienmitglied benutzt
        //    werden - meistens stimmt der Treffer, aber niemand hat
        //    hingesehen. Das Postfach zeigt diese Zuordnungen deshalb
        //    mit einer Bitte um Bestaetigung an.
        $customer = $this->byPhone($message->senderPhone);
        $methode = CustomerChannelIdentity::METHOD_PHONE;

        if (! $customer) {
            $customer = $this->byEmail($message->senderEmail);
            $methode = CustomerChannelIdentity::METHOD_EMAIL;
        }

        if ($customer) {
            // Ab jetzt ist die Zuordnung gespeichert: beim naechsten Mal
            // greift Stufe 1 und wir suchen nicht mehr. Sie bleibt
            // trotzdem unbestaetigt, bis ein Mensch sie bestaetigt -
            // einmal gespeichert heisst nicht einmal geprueft.
            $this->remember($customer, $channel, $account, $message, $methode);
        }

        return $customer;
    }

    /**
     * Kanal-Identitaet festhalten - idempotent.
     *
     * Die HERKUNFT wird nur beim Anlegen gesetzt bzw. wenn sie sich
     * verbessert: eine von einem Menschen bestaetigte Zuordnung darf
     * eine spaetere automatische Erkennung nie wieder auf "Indiz"
     * zuruecksetzen. Sonst waere die Bestaetigung bei der naechsten
     * Nachricht wieder weg, und niemand koennte sich je durch die Liste
     * arbeiten.
     */
    public function remember(
        Customer $customer,
        Channel $channel,
        ?ChannelAccount $account,
        InboundMessage $message,
        string $methode = CustomerChannelIdentity::METHOD_MANUAL,
    ): CustomerChannelIdentity {
        $identity = CustomerChannelIdentity::firstOrNew([
            'channel_account_id' => $account?->id,
            'external_user_id' => $message->externalUserId,
        ]);

        $identity->fill([
            'customer_id' => $customer->id,
            'channel_id' => $channel->id,
            'external_username' => $message->senderName,
        ]);

        // Herkunft nur setzen, wenn noch keine da ist - oder wenn ein
        // MENSCH zuordnet (das ist immer die bessere Auskunft).
        if (! $identity->match_method || $methode === CustomerChannelIdentity::METHOD_MANUAL) {
            $identity->match_method = $methode;
        }

        $identity->save();

        return $identity;
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
