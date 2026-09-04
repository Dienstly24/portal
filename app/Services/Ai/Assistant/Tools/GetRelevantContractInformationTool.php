<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Contract;
use Carbon\Carbon;

/**
 * Details EINES Vertrags des angemeldeten Kunden (Spezifikation
 * Abschnitt 6 - getRelevantContractInformation).
 *
 * WICHTIG (Abschnitt 4): dieses Tool liefert nur ERFASSTE Felder. Es
 * interpretiert keinen Vertrag und liest keine Bedingungen - steht eine
 * Angabe nicht in der Akte, fehlt sie hier, und der Assistent muss
 * uebergeben statt zu vermuten. Deshalb wird jedes leere Feld weggelassen
 * (nicht als "unbekannt" beschrieben, das verleitet zum Raten).
 */
class GetRelevantContractInformationTool implements AssistantTool
{
    public function name(): string
    {
        return 'getRelevantContractInformation';
    }

    public function description(): string
    {
        return 'Erfasste Details EINES Vertrags des angemeldeten Kunden (Sparte, '
            .'Gesellschaft, Vertragsnummer, Status, Beitrag, Laufzeit, Kuendigung, '
            .'Fahrzeug- bzw. Energie-Angaben). Auswahl per Vertragsnummer ODER Sparte. '
            .'Enthaelt NUR gespeicherte Felder - Versicherungsbedingungen und Deckungs'
            .'fragen stehen hier nicht. Fehlt eine Angabe, nutze escalateToTeam statt zu raten.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vertragsnummer' => [
                    'type' => 'string',
                    'description' => 'Vertragsnummer, falls der Kunde sie genannt hat.',
                ],
                'sparte' => [
                    'type' => 'string',
                    'description' => 'Alternativ die Sparte, z.B. kfz, strom, gas, haftpflicht, internet.',
                ],
            ],
            'required' => [],
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $number = trim((string) ($arguments['vertragsnummer'] ?? ''));
        $type = trim((string) ($arguments['sparte'] ?? ''));

        $query = $context->customer->contracts()->with(['vehicleDetail', 'energyDetail', 'internetDetail']);

        if ($number !== '') {
            $query->where('contract_number', $number);
        } elseif ($type !== '') {
            $query->where('type', mb_strtolower($type));
        }

        $contracts = $query->orderByDesc('start_date')->limit(3)->get();

        if ($contracts->isEmpty()) {
            return [
                'gefunden' => false,
                'hinweis' => 'Kein passender Vertrag dieses Kunden. Nutze getCustomerContracts, '
                    .'um die vorhandenen Vertraege zu sehen.',
            ];
        }

        // Mehrere Treffer: alle melden, damit das Modell nicht raet, welcher
        // gemeint ist - es soll dann nachfragen.
        return [
            'gefunden' => true,
            'eindeutig' => $contracts->count() === 1,
            'vertraege' => $contracts->map(fn (Contract $c) => $this->details($c))->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function details(Contract $c): array
    {
        $status = $c->displayStatus();

        $data = [
            'sparte' => $c->typeLabel(),
            'gesellschaft' => $c->insurer,
            'vertragsnummer' => $c->contract_number,
            'stufe' => $c->stageLabel(),
            'status' => $status['label'],
            'aktiv' => $c->isCurrentlyActive(),
            'beginn' => $this->date($c->start_date),
            'ablauf' => $this->date($c->end_date),
            'kuendigung_eingereicht' => $this->date($c->cancellation_date),
            'wirksames_ende' => $c->effectiveCancellationDate()?->format('d.m.Y'),
            'beitrag' => $c->hasPremium()
                ? number_format((float) $c->premium_amount, 2, ',', '.').' EUR '.$c->premiumIntervalLabel()
                : null,
        ];

        if ($vehicle = $c->vehicleDetail) {
            $data['fahrzeug'] = array_filter([
                'kennzeichen' => $vehicle->license_plate,
                'hersteller' => $vehicle->manufacturer,
                'modell' => $vehicle->model,
                'erstzulassung' => $this->date($vehicle->first_registration),
                'teilkasko' => $vehicle->has_teilkasko ? 'ja' : null,
                'vollkasko' => $vehicle->has_vollkasko ? 'ja' : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($energy = $c->energyDetail) {
            $data['energie'] = array_filter([
                'tarif' => $energy->tariff,
                'zaehlernummer' => $energy->meter_number,
                'verbrauch_kwh' => $energy->consumption_kwh,
                'abschlag' => $energy->payment_amount,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($internet = $c->internetDetail) {
            $data['internet'] = array_filter([
                'tarif' => $internet->tariff,
                'download_mbit' => $internet->speed,
                'upload_mbit' => $internet->upload_speed,
                'mindestlaufzeit_monate' => $internet->min_duration_months,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function date($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d.m.Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
