<?php

namespace App\Services\Ai\Assistant\Tools;

/**
 * Stammdaten des ANGEMELDETEN Kunden (Spezifikation Abschnitt 6).
 *
 * Datenminimierung (Abschnitt 21): bewusst NUR Anrede-/Kontaktrahmen und
 * Portal-Zustand. Ausdruecklich NICHT an das Modell: IBAN/BIC/Kontoinhaber,
 * Steuer-ID, Gesundheits- und Sozialversicherungsnummern, Ausweisdaten.
 * Fuer eine Kundenservice-Antwort braucht es sie nicht - und was nicht
 * uebertragen wird, kann nicht verloren gehen.
 */
class GetCustomerProfileTool implements AssistantTool
{
    public function name(): string
    {
        return 'getCustomerProfile';
    }

    public function description(): string
    {
        return 'Stammdaten des angemeldeten Kunden (Name, Kundennummer, Anschrift, '
            .'Kontaktdaten, Sprache). Nutze das, um den Kunden korrekt anzusprechen '
            .'oder eine Frage zu seinen hinterlegten Daten zu beantworten. '
            .'Bankdaten und Ausweisdaten sind hier absichtlich NICHT enthalten.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $customer = $context->customer->loadMissing('user');

        return [
            'kundennummer' => $customer->customer_number,
            'name' => $customer->user?->name
                ?: trim(($customer->company_name ?? '')) ?: null,
            'anschrift' => $customer->fullAddress() ?: null,
            'telefon' => $customer->phone ?: $customer->mobile ?: null,
            'email' => $customer->user?->email && ! str_contains($customer->user->email, '@dienstly24.internal')
                ? $customer->user->email
                : null,
            'geburtsdatum_hinterlegt' => $customer->birth_date !== null,
            'sprache' => $customer->preferred_lang ?: 'de',
            'kunde_seit' => $customer->created_at?->lokal()->format('d.m.Y'),
        ];
    }
}
