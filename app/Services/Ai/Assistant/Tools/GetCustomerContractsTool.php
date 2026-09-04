<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Contract;
use Carbon\Carbon;

/**
 * Vertraege des angemeldeten Kunden (Spezifikation Abschnitt 6).
 *
 * AKTIV vs. HISTORIE folgt strikt der EINEN Definition des Projekts
 * (CLAUDE.md, Betreiber-Vorgabe 17.08.2026): niemals `status === 'active'`
 * vergleichen, sondern `isCurrentlyActive()` / `displayStatus()`. Sonst
 * wuerde der Assistent dem Kunden eine andere Zahl nennen als seine
 * Vertragsuebersicht im Portal zeigt.
 */
class GetCustomerContractsTool implements AssistantTool
{
    public function name(): string
    {
        return 'getCustomerContracts';
    }

    public function description(): string
    {
        return 'Alle Vertraege des angemeldeten Kunden mit Sparte, Gesellschaft, '
            .'Vertragsnummer, Status (aktiv/beendet/in Bearbeitung), Beitrag und Laufzeit. '
            .'Nutze das fuer Fragen wie "welche Vertraege habe ich", "was zahle ich", '
            .'"laeuft mein Vertrag noch". Optional nur aktive Vertraege abfragen.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nur_aktive' => [
                    'type' => 'boolean',
                    'description' => 'true = nur aktuell aktive Vertraege (Standard: alle, inklusive beendeter).',
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
        $onlyActive = (bool) ($arguments['nur_aktive'] ?? false);

        $contracts = $context->customer->contracts()
            ->with(['vehicleDetail', 'energyDetail'])
            ->orderByDesc('start_date')
            ->get();

        if ($onlyActive) {
            $contracts = $contracts->filter(fn (Contract $c) => $c->isCurrentlyActive());
        }

        return [
            'anzahl' => $contracts->count(),
            'anzahl_aktiv' => $context->customer->contracts->filter(
                fn (Contract $c) => $c->isCurrentlyActive()
            )->count(),
            'vertraege' => $contracts->map(function (Contract $c) {
                $status = $c->displayStatus();

                return array_filter([
                    'sparte' => $c->typeLabel(),
                    'gesellschaft' => $c->insurer,
                    'tarif' => $c->energyDetail?->tariff,
                    // Ein ANTRAG hat keine Vertragsnummer (Betreiber-Vorgabe
                    // 02.08.2026) - dann bleibt das Feld leer statt eine
                    // Auftragsnummer als Vertragsnummer zu behaupten.
                    'vertragsnummer' => $c->contract_number,
                    'stufe' => $c->stageLabel(),
                    'status' => $status['label'],
                    'aktiv' => $c->isCurrentlyActive(),
                    'beginn' => $this->date($c->start_date),
                    'ende' => $this->date($c->end_date),
                    'beitrag' => $c->hasPremium()
                        ? number_format((float) $c->premium_amount, 2, ',', '.').' EUR '.$c->premiumIntervalLabel()
                        : null,
                    'kennzeichen' => $c->vehicleDetail?->license_plate,
                ], fn ($v) => $v !== null && $v !== '');
            })->values()->all(),
        ];
    }

    /**
     * start_date/end_date sind am Vertrag NICHT als Datum gecastet - sie
     * kommen als String. Deutsches Format fuer das Modell, ungueltige
     * Werte bleiben leer (nie ein geratenes Datum).
     */
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
