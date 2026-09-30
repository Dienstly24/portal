<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Ticket;

/**
 * Wem gehoert eine Anfrage aus einem OEFFENTLICHEN Formular? (KI-033)
 *
 * Bisher suchte jedes der vier Formulare selbst nach der E-Mail-Adresse
 * und haengte die Anfrage an die gefundene Kundenakte - als waere sie vom
 * Kunden. Eine E-Mail-Adresse ist aber kein Nachweis: wer die Adresse
 * eines Kunden kennt, legte so einen Vorgang in dessen Akte und Portal an,
 * und der Mitarbeiter sah "Anfrage von Frau X".
 *
 * Die Zuordnung BLEIBT (sie spart dem Team die Suche), aber sie ist ein
 * INDIZ: der Vorgang traegt `absender_status = ungeprueft`, bleibt dem
 * Kunden verborgen, und der Mitarbeiter bestaetigt oder loest ihn.
 * Vertrauenswuerdig ist ein Absender nur, wenn die Anwendung ihn selbst
 * kennt: angemeldet oder mit dem verschluesselten Token aus der
 * Willkommensmail (/hilfe). Dann ist nichts zu pruefen.
 */
final class FormularAbsender
{
    public function __construct(
        public readonly ?Customer $customer,
        public readonly ?string $absenderStatus,
    ) {}

    public static function ermitteln(?string $email, ?Customer $vertrauenswuerdig = null): self
    {
        if ($vertrauenswuerdig !== null) {
            return new self($vertrauenswuerdig, null);
        }

        $email = is_string($email) ? trim($email) : '';
        if ($email === '') {
            return new self(null, null);
        }

        $kunde = Customer::whereHas('user', fn ($q) => $q->where('email', $email))
            ->orWhere('email2', $email)->first();

        return $kunde === null
            ? new self(null, null)
            : new self($kunde, Ticket::ABSENDER_UNGEPRUEFT);
    }

    /** Nur per Adresse zugeordnet - der Kunde hat es nicht nachweislich selbst geschrieben. */
    public function ungeprueft(): bool
    {
        return $this->absenderStatus === Ticket::ABSENDER_UNGEPRUEFT;
    }

    /**
     * Die Angaben des Absenders gehoeren an den Vorgang, sobald er nicht
     * nachweislich der Kunde ist - sonst sieht der Mitarbeiter bei der
     * Pruefung nur die Akte und nicht, was WIRKLICH eingegeben wurde
     * (fremde Rufnummer, abweichender Name).
     */
    public function gastdatenBehalten(): bool
    {
        return $this->customer === null || $this->ungeprueft();
    }
}
