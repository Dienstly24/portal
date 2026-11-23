<?php

namespace App\Services\Dokumente;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DokumentPapierkorb;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Doppelte Dokumente einer Kundenakte finden und in den Papierkorb legen
 * (Betreiber-Auftrag 23.11.2026, KI-128).
 *
 * "Doppelt" heisst: GLEICHER INHALT (SHA-256) in DERSELBEN Akte - nie der
 * Dateiname (zwei Fassungen "Police.pdf" sind zwei Dokumente) und nie
 * akteuebergreifend (dieselbe Gesundheitskarte gehoert in jede Akte der
 * Familie, die sie vorlegt).
 *
 * NIE RATEN - eine Kopie bleibt stehen, wenn
 *  - die Kopien an VERSCHIEDENEN Vertraegen haengen (welcher stimmt, weiss
 *    nur ein Mensch),
 *  - die zu entfernende Kopie fuer den KUNDEN sichtbar ist, die behaltene
 *    nicht (sonst verschwaende ein Dokument aus seiner Sicht),
 *  - die Kopie anderswo verknuepft ist (Signatur, Dokumentanforderung,
 *    Chat-Anhang, Zaehlerstand, SF-Nachweis, Provisionsrechnung) - der
 *    Verweis ginge beim Entfernen verloren.
 * Behalten wird: die Kopie mit Vertrag, dann die verknuepfte, dann die fuer
 * den Kunden sichtbare, dann die AELTESTE.
 */
class DokumentDublettenService
{
    /** Tabellen/Spalten, die ein Dokument festhalten. */
    private const VERWEISE = [
        'customer_message_attachments' => 'document_id',
        'document_requests' => 'document_id',
        'meter_readings' => 'document_id',
        'vehicle_sf_references' => 'proof_document_id',
        'contract_commissions' => 'invoice_document_id',
        'signature_requests' => 'completed_document_id',
    ];

    /**
     * Plan fuer EINE Akte, ohne etwas zu aendern.
     *
     * @return array{entfernen: list<array{doc: Document, behalten: Document}>, uebersprungen: list<array{doc: Document, grund: string}>}
     */
    public function plan(Customer|string $customer): array
    {
        $customerId = $customer instanceof Customer ? (string) $customer->id : $customer;
        $docs = Document::where('customer_id', $customerId)
            ->whereNotNull('content_hash')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $entfernen = [];
        $uebersprungen = [];

        foreach ($docs->groupBy('content_hash') as $gruppe) {
            if ($gruppe->count() < 2) {
                continue;
            }
            $vertraege = $gruppe->pluck('contract_id')->filter()->unique();
            if ($vertraege->count() > 1) {
                foreach ($gruppe as $d) {
                    $uebersprungen[] = ['doc' => $d, 'grund' => 'Kopien haengen an verschiedenen Vertraegen'];
                }
                continue;
            }

            $verknuepft = $this->verknuepfteIds($gruppe->pluck('id')->all());
            $behalten = $this->waehleBehalten($gruppe, $verknuepft);

            foreach ($gruppe as $d) {
                if ($d->id === $behalten->id) {
                    continue;
                }
                if (($d->visibility ?? 'customer') === 'customer' && ($behalten->visibility ?? 'customer') !== 'customer') {
                    $uebersprungen[] = ['doc' => $d, 'grund' => 'Nur diese Kopie ist fuer den Kunden sichtbar'];
                    continue;
                }
                if (isset($verknuepft[$d->id])) {
                    $uebersprungen[] = ['doc' => $d, 'grund' => 'Verknuepft mit '.$verknuepft[$d->id]];
                    continue;
                }
                $entfernen[] = ['doc' => $d, 'behalten' => $behalten];
            }
        }

        return ['entfernen' => $entfernen, 'uebersprungen' => $uebersprungen];
    }

    /**
     * Fehlende Inhalts-Bestimmungen einer Akte nachtragen (Altbestand vor
     * der Duplikat-Erkennung). Gibt die Anzahl ergaenzter Dokumente zurueck.
     */
    public function hashesNachtragen(string $customerId): int
    {
        $n = 0;
        Document::where('customer_id', $customerId)->whereNull('content_hash')
            ->whereNotNull('file_path')
            ->each(function (Document $d) use (&$n) {
                $hash = Document::hashStoredFile($d->disk ?: 'public', $d->file_path);
                if ($hash !== null) {
                    $d->forceFill(['content_hash' => $hash])->saveQuietly();
                    $n++;
                }
            });

        return $n;
    }

    /**
     * Plan ausfuehren: jede Kopie in den Papierkorb. Ein Fehler bei einer
     * Kopie beendet nie den ganzen Lauf.
     *
     * @return array{entfernt: int, uebersprungen: int, fehler: int}
     */
    public function bereinigen(string $customerId, ?int $userId): array
    {
        $this->hashesNachtragen($customerId);
        $plan = $this->plan($customerId);
        $entfernt = 0;
        $fehler = 0;

        foreach ($plan['entfernen'] as $eintrag) {
            try {
                $this->inPapierkorb($eintrag['doc'], $eintrag['behalten'], $userId);
                $entfernt++;
            } catch (\Throwable $e) {
                $fehler++;
                report($e);
            }
        }

        if ($entfernt > 0) {
            ActivityLog::record('documents_duplicates_removed', 'customer', $customerId, [
                'customer_id' => $customerId,
                'entfernt' => $entfernt,
                'uebersprungen' => count($plan['uebersprungen']),
            ], $userId);
        }

        return ['entfernt' => $entfernt, 'uebersprungen' => count($plan['uebersprungen']), 'fehler' => $fehler];
    }

    /** Eine Kopie in den Papierkorb legen (Datei + Datensatz). */
    public function inPapierkorb(Document $doc, Document $behalten, ?int $userId): DokumentPapierkorb
    {
        $quelleDisk = $doc->disk ?: 'public';
        $ziel = 'customers/'.$doc->customer_id.'/papierkorb/'.$doc->id.'_'.Str::limit(basename((string) $doc->file_path), 120, '');

        // Erst KOPIEREN, dann den Datensatz umziehen, ZULETZT die Quelle
        // loeschen: scheitert etwas dazwischen, ist nichts verloren.
        $stream = Storage::disk($quelleDisk)->readStream($doc->file_path);
        if (! is_resource($stream) || ! Storage::disk('local')->writeStream($ziel, $stream)) {
            throw new \RuntimeException('Datei konnte nicht in den Papierkorb kopiert werden: '.$doc->id);
        }
        fclose($stream);

        $eintrag = DB::transaction(function () use ($doc, $behalten, $userId, $ziel) {
            $daten = $doc->getAttributes();
            // KI-Protokoll merken: beim Loeschen setzt der Fremdschluessel
            // die Verknuepfung auf leer, beim Wiederherstellen kommt sie zurueck.
            $daten['_ai_decisions'] = DB::table('ai_decisions')->where('document_id', $doc->id)->pluck('id')->all();

            // Andere Kopien, die auf diese zeigten, zeigen jetzt auf die behaltene.
            Document::where('duplicate_of', $doc->id)->update(['duplicate_of' => $behalten->id]);

            $eintrag = DokumentPapierkorb::create([
                'customer_id' => $doc->customer_id,
                'document_id' => $doc->id,
                'behalten_document_id' => $behalten->id,
                'file_name' => (string) $doc->file_name,
                'disk' => 'local',
                'file_path' => $ziel,
                'daten' => $daten,
                'grund' => 'duplikat',
                'entfernt_von' => $userId,
                'entfernt_at' => now(),
                'endgueltig_ab' => now()->addDays(DokumentPapierkorb::AUFBEWAHRUNG_TAGE),
            ]);
            $doc->delete();

            return $eintrag;
        });

        try {
            Storage::disk($quelleDisk)->delete($doc->file_path);
        } catch (\Throwable $e) {
            // Original bleibt liegen - unschaedlich, der Datensatz ist umgezogen.
        }

        return $eintrag;
    }

    /** Dokument aus dem Papierkorb zurueckholen - unter derselben Kennung. */
    public function wiederherstellen(DokumentPapierkorb $eintrag, ?int $userId): Document
    {
        $daten = $eintrag->daten;
        $aiDecisions = $daten['_ai_decisions'] ?? [];
        unset($daten['_ai_decisions']);

        $zielPfad = 'customers/'.$eintrag->customer_id.'/documents/'.basename($eintrag->file_path);
        if (! Storage::disk('local')->move($eintrag->file_path, $zielPfad)) {
            throw new \RuntimeException('Datei konnte nicht zurueckgeholt werden: '.$eintrag->document_id);
        }

        return DB::transaction(function () use ($eintrag, $daten, $aiDecisions, $zielPfad, $userId) {
            $daten['disk'] = 'local';
            $daten['file_path'] = $zielPfad;
            // Rohe Zeile zurueckschreiben - ohne die Anlage-Hooks, die Hash
            // und Duplikat-Verweis neu bestimmen wuerden.
            DB::table('documents')->insert($daten);
            if ($aiDecisions !== []) {
                DB::table('ai_decisions')->whereIn('id', $aiDecisions)->whereNull('document_id')
                    ->update(['document_id' => $eintrag->document_id]);
            }
            $eintrag->delete();

            ActivityLog::record('document_restored', 'document', $eintrag->document_id, [
                'customer_id' => (string) $eintrag->customer_id,
                'file' => $eintrag->file_name,
            ], $userId);

            return Document::findOrFail($eintrag->document_id);
        });
    }

    /** Abgelaufene Eintraege endgueltig loeschen. Gibt die Anzahl zurueck. */
    public function abgelaufeneLoeschen(): int
    {
        $n = 0;
        DokumentPapierkorb::where('endgueltig_ab', '<=', now())->each(function (DokumentPapierkorb $e) use (&$n) {
            try {
                Storage::disk($e->disk)->delete($e->file_path);
            } catch (\Throwable $ex) {
                // Datei schon weg - Eintrag trotzdem entfernen.
            }
            $e->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param Collection<int, Document> $gruppe
     * @param array<string, string> $verknuepft
     */
    private function waehleBehalten(Collection $gruppe, array $verknuepft): Document
    {
        $rang = fn (Document $d) => [
            $d->contract_id ? 1 : 0,
            isset($verknuepft[$d->id]) ? 1 : 0,
            ($d->visibility ?? 'customer') === 'customer' ? 1 : 0,
        ];

        return $gruppe->sort(function (Document $a, Document $b) use ($rang) {
            return $rang($b) <=> $rang($a)
                ?: [$a->created_at?->getTimestamp() ?? 0, (string) $a->id] <=> [$b->created_at?->getTimestamp() ?? 0, (string) $b->id];
        })->first();
    }

    /**
     * Welche dieser Dokumente sind anderswo verknuepft?
     *
     * @param list<string> $ids
     * @return array<string, string> document_id => Beschreibung
     */
    private function verknuepfteIds(array $ids): array
    {
        $namen = [
            'customer_message_attachments' => 'einem Chat-Anhang',
            'document_requests' => 'einer Dokumentanforderung',
            'meter_readings' => 'einem Zaehlerstand',
            'vehicle_sf_references' => 'einem SF-Nachweis',
            'contract_commissions' => 'einer Provisionsrechnung',
            'signature_requests' => 'einer Signatur',
        ];
        $treffer = [];
        foreach (self::VERWEISE as $tabelle => $spalte) {
            foreach (DB::table($tabelle)->whereIn($spalte, $ids)->pluck($spalte) as $id) {
                $treffer[(string) $id] ??= $namen[$tabelle];
            }
        }

        return $treffer;
    }
}
