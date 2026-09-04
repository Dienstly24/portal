<?php

namespace App\Services\Ai\Assistant\Website\Tools;

use App\Models\AiConversationEvent;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Website\LeadContext;
use App\Services\Ai\Assistant\Website\LeadTool;

/**
 * Angaben eines Website-Interessenten festhalten (Abschnitte 19 und 20).
 *
 * Dieselbe Regel wie im Portal: sensible Werte (IBAN, Geburtsdatum)
 * erfasst der Website-Assistent GAR NICHT. Ein Interessent ist noch kein
 * Kunde - Vertragsdaten werden erst nach der Identitaetspruefung durch
 * einen Mitarbeiter erhoben.
 */
class SaveLeadInformationTool implements LeadTool
{
    /** Was ein Interessent sinnvoll nennen kann - mehr nicht. */
    private const ALLOWED = [
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'phone' => 'Telefonnummer',
        'installation_address' => 'Anschlussadresse',
        'situation' => 'Umzug oder bestehender Anschluss',
        'current_provider' => 'Aktueller Anbieter',
        'desired_speed' => 'Gewuenschte Geschwindigkeit',
        'desired_start' => 'Gewuenschter Starttermin',
        'service' => 'Gewuenschte Leistung',
        'note' => 'Anmerkung',
    ];

    public function __construct(private ConversationJournal $journal)
    {
    }

    public function name(): string
    {
        return 'saveLeadInformation';
    }

    public function description(): string
    {
        return 'Speichere, was der Interessent genannt hat: name, email, phone, '
            .'installation_address, situation, current_provider, desired_speed, '
            .'desired_start, service, note. Frage NIE nach Bankverbindung, '
            .'Geburtsdatum oder Ausweisdaten - das erhebt spaeter ein Mitarbeiter.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angaben' => [
                    'type' => 'object',
                    'description' => 'Schluessel/Wert-Paare der genannten Angaben.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['angaben'],
        ];
    }

    public function run(array $arguments, LeadContext $context): array
    {
        $angaben = $arguments['angaben'] ?? [];
        if (! is_array($angaben) || $angaben === []) {
            return ['fehler' => 'Keine Angaben uebergeben.'];
        }

        $lead = $context->lead;
        $uebernommen = [];
        $kontakt = $lead->contactData();

        foreach ($angaben as $key => $wert) {
            $key = (string) $key;
            if (! isset(self::ALLOWED[$key]) || ! is_scalar($wert) || trim((string) $wert) === '') {
                continue;
            }

            $wert = mb_substr(trim((string) $wert), 0, 300);
            $uebernommen[$key] = $wert;

            // Kontaktangaben liegen getrennt - der Mitarbeiter braucht sie
            // sofort sichtbar, ohne den ganzen Datensatz zu lesen.
            if (in_array($key, ['name', 'email', 'phone'], true)) {
                $kontakt[$key] = $wert;
            }
        }

        if ($uebernommen === []) {
            return ['fehler' => 'Keine gueltigen Angaben. Erlaubt: '.implode(', ', array_keys(self::ALLOWED))];
        }

        $lead->remember($uebernommen);
        $lead->forceFill([
            'contact' => $kontakt,
            'address' => $uebernommen['installation_address'] ?? $lead->address,
            'service' => $uebernommen['service'] ?? $lead->service,
        ])->save();

        if ($lead->state === ConversationState::NEW) {
            $lead->forceFill(['state' => ConversationState::COLLECTING_REQUIREMENTS])->save();
        }

        $this->journal->record(null, AiConversationEvent::EVENT_COLLECTED, [
            'felder' => array_map(fn ($k) => self::ALLOWED[$k], array_keys($uebernommen)),
        ], AiConversationEvent::ACTOR_AI, null, null, null, $lead);

        $context->recordAction('lead_angaben', ['felder' => array_keys($uebernommen)]);

        $fehlend = array_values(array_diff(
            ['installation_address', 'situation', 'name'],
            array_keys($lead->collectedData())
        ));

        return [
            'gespeichert' => array_keys($uebernommen),
            'noch_offen' => array_map(fn ($k) => self::ALLOWED[$k] ?? $k, $fehlend),
        ];
    }
}
