<?php

namespace App\Services\Matching;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\CustomerTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Macht eine Zusammenfuehrung rueckgaengig (KI-064, PR-3b) - innerhalb von
 * `CustomerMerge::RUECKGAENGIG_TAGE` und nur auf Grundlage des Protokolls,
 * das `CustomerMergeService` geschrieben hat.
 *
 * Grundsatz: zurueck geht NUR, was seit dem Merge unveraendert am
 * Hauptkunden liegt. Eine Zeile, die inzwischen woanders haengt oder
 * geloescht wurde, bleibt, wo sie ist - sie wird gezaehlt und gemeldet,
 * nie geraten. Was NACH dem Merge am Hauptkunden neu entstanden ist (ein
 * neuer Vertrag, eine neue Nachricht), bleibt beim Hauptkunden: niemand
 * kann wissen, zu welcher der beiden Personen es gehoert.
 */
class CustomerMergeUndoService
{
    /**
     * Gruende, aus denen die Zusammenfuehrung NICHT zurueckgenommen werden
     * kann. Leer = moeglich.
     *
     * @return list<string>
     */
    public function hindernisse(CustomerMerge $merge): array
    {
        $gruende = [];
        if ($merge->undone_at !== null) {
            $gruende[] = 'Bereits rueckgaengig gemacht.';
        }
        if ($merge->created_at === null || $merge->created_at->lt(now()->subDays(CustomerMerge::RUECKGAENGIG_TAGE))) {
            $gruende[] = 'Die Frist von '.CustomerMerge::RUECKGAENGIG_TAGE.' Tagen ist abgelaufen.';
        }
        if (($merge->protokoll['abgeschlossen'] ?? false) === true) {
            $gruende[] = 'Das Protokoll wurde nach Fristablauf geleert.';
        }
        $huelle = Customer::mitArchiv()->find($merge->duplicate_customer_id);
        if (! $huelle || ! $huelle->isArchived()) {
            $gruende[] = 'Die archivierte Akte existiert nicht mehr.';
        }
        $haupt = Customer::mitArchiv()->find($merge->primary_customer_id);
        if (! $haupt) {
            $gruende[] = 'Die Hauptakte existiert nicht mehr.';
        } elseif ($haupt->isArchived()) {
            // Erst die spaetere Zusammenfuehrung zuruecknehmen - sonst
            // wuerden Zeilen an eine Akte gehaengt, die selbst eine Huelle ist.
            $gruende[] = 'Die Hauptakte wurde inzwischen selbst zusammengefuehrt - zuerst diese spaetere Zusammenfuehrung rueckgaengig machen.';
        }

        return $gruende;
    }

    /**
     * @return array{zurueck: int, nicht_zurueck: int, wiederhergestellt: int}
     *
     * @throws \RuntimeException wenn `hindernisse()` etwas meldet.
     */
    public function rueckgaengig(CustomerMerge $merge, ?int $actorId = null): array
    {
        $gruende = $this->hindernisse($merge);
        if ($gruende !== []) {
            throw new \RuntimeException(implode(' ', $gruende));
        }

        return DB::transaction(function () use ($merge, $actorId) {
            $p = (string) $merge->primary_customer_id;
            $d = (string) $merge->duplicate_customer_id;
            $protokoll = $merge->protokoll;
            $bilanz = ['zurueck' => 0, 'nicht_zurueck' => 0, 'wiederhergestellt' => 0];

            // 1) Umgehaengte Zeilen zurueck - nur, wenn sie noch am Hauptkunden liegen.
            foreach ($protokoll['umgehaengt'] ?? [] as $table => $eintraege) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($eintraege as $eintrag) {
                    $ok = match ($table) {
                        'customer_relationships' => $this->beziehungZurueck((array) $eintrag, $p),
                        'customer_family_relations' => $this->familieZurueck((array) $eintrag, $p, $d),
                        'external_references' => DB::table($table)->where('id', $eintrag)
                            ->where('referenceable_type', Customer::class)->where('referenceable_id', $p)
                            ->update(['referenceable_id' => $d]) > 0,
                        default => DB::table($table)->where('id', $eintrag)->where('customer_id', $p)
                            ->update(['customer_id' => $d]) > 0,
                    };
                    $ok ? $bilanz['zurueck']++ : $bilanz['nicht_zurueck']++;
                }
            }

            // 2) Verworfene Kollisionszeilen wieder einfuegen (mit ihrer alten Kennung).
            foreach ($protokoll['verworfen'] ?? [] as $table => $zeilen) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($zeilen as $zeile) {
                    try {
                        DB::table($table)->insert((array) $zeile);
                        $bilanz['wiederhergestellt']++;
                    } catch (\Throwable) {
                        // Schluessel inzwischen belegt - die Zeile bleibt im
                        // (geleerten) Protokoll nicht stehen, sie zaehlt als offen.
                        $bilanz['nicht_zurueck']++;
                    }
                }
            }

            $haupt = Customer::mitArchiv()->with('user')->findOrFail($p);
            $huelle = Customer::mitArchiv()->findOrFail($d);

            // 3) Vom Duplikat ergaenzte Felder leeren - nur, wenn der Wert seither
            //    unveraendert ist (sonst hat ihn jemand bewusst gepflegt).
            foreach ($protokoll['ergaenzt'] ?? [] as $feld) {
                if ($feld === 'unsubscribed_at') {
                    if ((string) $haupt->unsubscribed_at === (string) $huelle->unsubscribed_at) {
                        $haupt->unsubscribed_at = null;
                        $haupt->marketing_consent = $protokoll['marketing_consent_vorher'] ?? $haupt->marketing_consent;
                    }

                    continue;
                }
                if ($haupt->$feld == $huelle->$feld) {
                    $haupt->$feld = null;
                }
            }
            if (array_key_exists('last_contact_vorher', $protokoll)
                && (string) $haupt->last_contact === (string) $huelle->last_contact) {
                $haupt->last_contact = $protokoll['last_contact_vorher'];
            }

            // 4) Portalzugang: Tausch zuruecknehmen, stillgelegten Zugang wieder oeffnen.
            $konto = $protokoll['konto'] ?? [];
            if (($konto['getauscht'] ?? false) && ! empty($konto['user_vorher']) && User::find($konto['user_vorher'])) {
                $haupt->user_id = $konto['user_vorher'];
                if (($konto['sprache_vorher'] ?? null) !== null) {
                    $haupt->preferred_lang = $konto['sprache_vorher'];
                }
            }
            $verlierer = ! empty($konto['verlierer_user_id']) ? User::find($konto['verlierer_user_id']) : null;
            if ($verlierer && $haupt->email2 === $verlierer->email && ($konto['email2_vorher'] ?? null) !== $haupt->email2) {
                $haupt->email2 = $konto['email2_vorher'] ?? null;
            }
            if ($verlierer && $verlierer->role === 'customer' && ($konto['verlierer_war_aktiv'] ?? true)
                && isset($verlierer->is_active) && ! $verlierer->is_active) {
                $verlierer->forceFill(['is_active' => true])->save();
            }
            $haupt->save();

            // 5) Huelle wird wieder eine lebende Akte. Huellen, die beim Merge
            //    vom Duplikat auf den Hauptkunden umgehaengt wurden, zeigen
            //    wieder auf das Duplikat.
            DB::table('customers')->where('id', $d)->update(['merged_into_id' => null, 'archived_at' => null, 'updated_at' => now()]);
            $fruehere = CustomerMerge::where('primary_customer_id', $d)->pluck('duplicate_customer_id');
            if ($fruehere->isNotEmpty()) {
                DB::table('customers')->whereIn('id', $fruehere)->where('merged_into_id', $p)
                    ->update(['merged_into_id' => $d]);
            }

            $merge->forceFill(['undone_at' => now()])->save();

            ActivityLog::record('customer_merge_undone', 'customer', $p, [
                'merge_id' => $merge->id,
                'duplicate_id' => $d,
                'duplicate_number' => $merge->duplicate_number,
                'bilanz' => $bilanz,
            ]);
            if (Schema::hasTable('customer_timeline')) {
                foreach ([$p, $d] as $kundeId) {
                    CustomerTimeline::create([
                        'customer_id' => $kundeId,
                        'user_id' => $actorId,
                        'type' => 'merge',
                        'title' => 'Zusammenfuehrung rueckgaengig gemacht',
                        'description' => 'Die Akte mit der Kundennummer '.($merge->duplicate_number ?? '-').' ist wieder eigenstaendig. '
                            .$bilanz['zurueck'].' Datensaetze zurueckgehaengt, '.$bilanz['wiederhergestellt'].' wiederhergestellt'
                            .($bilanz['nicht_zurueck'] > 0 ? ', '.$bilanz['nicht_zurueck'].' inzwischen veraendert und deshalb nicht zurueckgeholt' : '').'.',
                        'meta' => ['merge_id' => $merge->id, 'bilanz' => $bilanz],
                    ]);
                }
            }

            app(DuplicateDetectionService::class)->forgetCount();

            return $bilanz;
        });
    }

    /** Beziehungszeile: urspruengliches Paar wiederherstellen, solange sie noch am Hauptkunden haengt. */
    private function beziehungZurueck(array $original, string $p): bool
    {
        $zeile = DB::table('customer_relationships')->where('id', $original['id'] ?? 0)->first();
        if (! $zeile || ((string) $zeile->customer_a_id !== $p && (string) $zeile->customer_b_id !== $p)) {
            return false;
        }
        try {
            return DB::table('customer_relationships')->where('id', $zeile->id)->update([
                'customer_a_id' => $original['customer_a_id'],
                'customer_b_id' => $original['customer_b_id'],
                'parent_customer_id' => $original['parent_customer_id'] ?? null,
            ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Familienzeile: die beim Merge umgehaengte Spalte zurueck auf das Duplikat. */
    private function familieZurueck(array $eintrag, string $p, string $d): bool
    {
        $spalte = $eintrag['spalte'] ?? null;
        if (! in_array($spalte, ['customer_id', 'related_customer_id'], true)) {
            return false;
        }
        try {
            return DB::table('customer_family_relations')->where('id', $eintrag['id'] ?? 0)
                ->where($spalte, $p)->update([$spalte => $d]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
