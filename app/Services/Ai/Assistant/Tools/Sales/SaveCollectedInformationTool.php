<?php
namespace App\Services\Ai\Assistant\Tools\Sales;

use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use App\Services\Ai\Assistant\Tools\AssistantTool;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;

/**
 * Angaben des Kunden festhalten (Spezifikation Abschnitte 3, 9 und 14).
 *
 * Damit fragt der Assistent nie zweimal dasselbe und verliert nach einer
 * Stoerung nichts.
 *
 * ZWEI HARTE SPERREN:
 *  1. Nur Felder, die das Profil des Anliegens kennt - das Modell kann
 *     keine Fantasiefelder in die Akte schreiben.
 *  2. SENSIBLE Felder (IBAN, Geburtsdatum, E-Mail, Telefon) werden hier
 *     ABGELEHNT. Sie erreichen das Modell gar nicht erst (SlotExtractor
 *     zieht sie vorher aus der Nachricht) - kaeme so ein Wert trotzdem
 *     ueber diesen Weg zurueck, waere er entweder geraten oder aus dem
 *     Kontext rekonstruiert. Beides darf nicht in die Akte.
 */
class SaveCollectedInformationTool implements AssistantTool
{
    public function __construct(private ConversationJournal $journal)
    {
    }

    public function name(): string
    {
        return 'saveCollectedInformation';
    }

    public function description(): string
    {
        return 'Speichere Angaben, die der Kunde im Gespraech genannt hat, damit sie '
            . 'nicht erneut erfragt werden. Erlaubt sind nur nicht-sensible Angaben, '
            . 'z.B. installation_address, situation, current_provider, current_tariff, '
            . 'contract_end, desired_speed, desired_start, change_reason, full_name, '
            . 'billing_address. Bankverbindung, Geburtsdatum, E-Mail und Telefonnummer '
            . 'erfasst das System selbst - sende sie NICHT.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angaben' => [
                    'type' => 'object',
                    'description' => 'Schluessel/Wert-Paare, z.B. {"installation_address": "Musterweg 5, 71522 Backnang"}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['angaben'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $angaben = $arguments['angaben'] ?? [];
        if (!is_array($angaben) || $angaben === []) {
            return ['fehler' => 'Keine Angaben uebergeben.'];
        }

        $conversation = $context->conversation;
        $uebernommen = [];
        $abgelehnt = [];

        foreach ($angaben as $key => $wert) {
            $key = (string) $key;

            if (RequirementProfile::isSensitive($key)) {
                $abgelehnt[$key] = 'wird vom System erfasst';
                continue;
            }
            if (!RequirementProfile::knows($conversation->intent, $key)) {
                $abgelehnt[$key] = 'gehoert nicht zu diesem Anliegen';
                continue;
            }
            if (!is_scalar($wert) || trim((string) $wert) === '') {
                $abgelehnt[$key] = 'leerer Wert';
                continue;
            }

            $uebernommen[$key] = mb_substr(trim((string) $wert), 0, 300);
        }

        if ($uebernommen !== []) {
            $conversation->remember($uebernommen);
            $this->journal->collected($conversation, array_keys($uebernommen));
            $context->recordAction('angaben_erfasst', ['felder' => array_keys($uebernommen)]);
        }

        // Sind alle Pflichtangaben des Bedarfs da, wartet der Vorgang auf
        // das Angebot des Mitarbeiters (Phase 1 - die KI sucht nichts).
        $sicht = new ConversationContext($conversation->fresh(), $context->customer);
        $offen = $sicht->missing('bedarf');

        if ($offen === []
            && in_array($conversation->intent, RequirementProfile::SALES_INTENTS, true)
            && in_array($conversation->state, [ConversationState::COLLECTING_REQUIREMENTS, ConversationState::COLLECTING_ADDRESS], true)) {
            $vorher = (string) $conversation->state;
            if ($conversation->moveTo(ConversationState::WAITING_FOR_OFFER, 'Bedarf vollstaendig')) {
                $this->journal->stateChanged($conversation, $vorher, $conversation->state);
            }
        }

        return [
            'gespeichert' => array_keys($uebernommen),
            'abgelehnt' => $abgelehnt,
            'noch_offen' => array_map(fn ($f) => $f['label'], $offen),
            'zustand' => $conversation->fresh()->state,
        ];
    }
}
