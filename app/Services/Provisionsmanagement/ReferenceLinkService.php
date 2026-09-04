<?php

namespace App\Services\Provisionsmanagement;

use App\Models\CommissionReferenceLink;
use App\Models\Contract;
use App\Services\Vermittler\VermittlerReference;

/**
 * REFERENZ-NR. <-> POOL-ID (Betreiber-Vorgabe 02.09.2026, §14/§15).
 *
 * Der Ablauf im Betrieb sieht so aus:
 *   Abschluss  -> wir haben die Referenz-Nr. der Antragsstrecke.
 *   1. Datei   -> fuehrt Referenz-Nr. UND die Id des Pools: das Paar wird
 *                 gespeichert und die Id am Vertrag ergaenzt.
 *   spaeter    -> eine Datei fuehrt nur noch die Id. Ueber das gespeicherte
 *                 Paar findet sie trotzdem den Vertrag.
 *
 * ZWEI REGELN, damit daraus keine Fehlzuordnung wird:
 *  - Ein Paar wird nur gespeichert, wenn BEIDE Kennungen lang genug sind
 *    (dieselbe Mindestlaenge wie im Vermittler-Abgleich). Kurze Zahlen
 *    treffen halbe Bestaende.
 *  - Traegt eine Id bereits eine ANDERE Referenz, wird nichts still
 *    ueberschrieben: beide Zeilen bleiben stehen, und die Aufloesung liefert
 *    dann bewusst KEINEN Vertrag - das ist ein Fall fuer die Pruefliste.
 */
class ReferenceLinkService
{
    /**
     * Ein gesehenes Paar festhalten.
     *
     * @return CommissionReferenceLink|null null, wenn eine Kennung fehlt
     */
    public function remember(?string $pool, ?string $reference, ?string $externalId, ?Contract $contract = null, string $source = 'import'): ?CommissionReferenceLink
    {
        $refKey = VermittlerReference::key($reference);
        $extKey = VermittlerReference::key($externalId);
        if ($pool === null || $pool === '' || $refKey === null || $extKey === null) {
            return null;
        }

        $link = CommissionReferenceLink::firstOrNew([
            'pool' => $pool,
            'reference_key' => $refKey,
            'external_key' => $extKey,
        ]);
        $link->reference_number = VermittlerReference::display($reference) ?? (string) $reference;
        $link->external_id = VermittlerReference::display($externalId) ?? (string) $externalId;
        // Der Vertrag wird nur ERGAENZT: eine spaetere Datei, die ihn nicht
        // kennt, darf eine bestehende Bruecke nicht kappen.
        $link->contract_id = $link->contract_id ?: $contract?->id;
        $link->source = $link->exists ? $link->source : $source;
        $link->save();

        return $link;
    }

    /**
     * Vertrag zu einer Pool-Id ueber das gespeicherte Paar.
     *
     * @return array{contract:?Contract,reference:?string,note:?string}
     */
    public function resolveByExternalId(?string $pool, ?string $externalId): array
    {
        $extKey = VermittlerReference::key($externalId);
        if ($extKey === null) {
            return ['contract' => null, 'reference' => null, 'note' => null];
        }

        $links = CommissionReferenceLink::query()
            ->when($pool !== null && $pool !== '', fn ($q) => $q->where('pool', $pool))
            ->where('external_key', $extKey)
            ->get();

        if ($links->isEmpty()) {
            return ['contract' => null, 'reference' => null, 'note' => null];
        }
        // Zwei Referenzen zur selben Id: die Zuordnung ist mehrdeutig. Eine
        // davon zu waehlen hiesse, Geld an einen fremden Vertrag zu haengen.
        if ($links->pluck('reference_key')->unique()->count() > 1) {
            return [
                'contract' => null,
                'reference' => null,
                'note' => 'Zur Pool-Id „'.$externalId.'“ sind '.$links->count()
                    .' verschiedene Referenz-Nummern gespeichert. Es wurde bewusst nichts zugeordnet.',
            ];
        }

        $link = $links->first(fn ($l) => $l->contract_id !== null) ?? $links->first();
        $contract = $link->contract_id ? Contract::with('customer.user')->find($link->contract_id) : null;

        return [
            'contract' => $contract,
            'reference' => $link->reference_number,
            'note' => null,
        ];
    }

    /**
     * Die Pool-Id am Vertrag ergaenzen (nie ueberschreiben) - dasselbe
     * Prinzip wie beim uebrigen Provisions-Import: eine vorhandene Kennung
     * gehoert einem anderen Vorgang und wird nicht angefasst.
     */
    public function attachToContract(Contract $contract, ?string $externalId): bool
    {
        $wert = VermittlerReference::display($externalId);
        if ($wert === null || VermittlerReference::key($wert) === null || filled($contract->vermittler_id)) {
            return false;
        }
        $contract->vermittler_id = $wert;
        $contract->saveQuietly();
        return true;
    }
}
