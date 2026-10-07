<?php

namespace App\Services\Signature;

use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\SignatureStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Das QUALITAETSGATE des Signatur-Moduls (Betreiber-Auftrag 04.10.2026, A1).
 *
 * Ziel: keine Unterschrift kann mehr "erfasst, aber unsichtbar" sein, ohne
 * dass jemand davon weiss. Bis 03.10.2026 galt ein Vorgang als fertig,
 * sobald irgendein PDF geschrieben war - 11 von 17 waren es nicht, und
 * aufgefallen ist es erst durch einen Kunden.
 *
 * DREI ANLAESSE, EINE PRUEFUNG (SignedPdfVerifier):
 *  - beim Abschluss und beim Neu-Erzeugen: besteht das Ergebnis nicht,
 *    wird es weder gespeichert noch verschickt (SignatureSigningService,
 *    SignedPdfRegenerator);
 *  - beim Versenden: die bereits gesetzten Felder (Unternehmenssignatur)
 *    werden im Speicher gestempelt und geprueft - ein unsichtbarer Stempel
 *    faellt auf, BEVOR der Kunde das Dokument sieht;
 *  - jede Nacht ueber den gesamten Bestand (`signaturen:qualitaet-pruefen`).
 *
 * Das Ergebnis steht an der Anfrage (quality_*), damit die Beraterwelt eine
 * Liste der betroffenen Vorgaenge zeigen kann - und nicht erst eine
 * Kommandozeile gefragt werden muss.
 */
class SignatureQualityGate
{
    public function __construct(
        private readonly SignedPdfVerifier $verifier,
        private readonly SignedPdfBuilder $builder,
        private readonly SignatureStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param  list<array{page: int, name: string, object: int}>  $bilder
     * @param  list<string>|null  $nurFelder
     * @return list<string> Befunde; leer = bestanden
     */
    public function pruefe(SignatureRequest $request, string $original, string $signiert, array $bilder = [], ?array $nurFelder = null): array
    {
        return $this->verifier->pruefe($request, $original, $signiert, $bilder, $nurFelder);
    }

    /**
     * Ab dieser Dauer wird eine Fertigstellung im Log gemeldet (KI-090,
     * Betreiber-Vorgabe 07.10.2026): der Abschluss laeuft synchron im
     * Unterschreiben-Request. Die Warnung sagt, WANN er in einen Job gehoert -
     * gemessen statt geschaetzt.
     */
    public const LANGSAM_MS = 5000;

    /**
     * Prueft den GESPEICHERTEN Stand einer Anfrage: das fertige PDF, wenn es
     * eines gibt, sonst eine Vorschau mit den bereits gesetzten Feldern.
     *
     * @return array{befunde: list<string>, geprueft: string}
     *                                                        geprueft: "fertiges_pdf" | "vorschau" | "nichts"
     */
    public function pruefeBestand(SignatureRequest $request): array
    {
        $original = $this->storage->read($request->original_path);
        if ($original === null) {
            return ['befunde' => ['Das Original-PDF fehlt im Speicher.'], 'geprueft' => 'nichts'];
        }
        $request->loadMissing(['fields.companyAsset', 'signers']);

        if ($request->signed_path !== null) {
            $signiert = $this->storage->read($request->signed_path);
            if ($signiert === null) {
                return ['befunde' => ['Das unterschriebene PDF fehlt im Speicher.'], 'geprueft' => 'nichts'];
            }
            if ($request->signed_hash !== null && hash('sha256', $signiert) !== $request->signed_hash) {
                return ['befunde' => ['Das gespeicherte PDF weicht von seinem Hash ab.'], 'geprueft' => 'fertiges_pdf'];
            }

            return ['befunde' => $this->pruefe($request, $original, $signiert), 'geprueft' => 'fertiges_pdf'];
        }

        if ($request->fields->contains(fn ($f) => $f->isFilled())) {
            return ['befunde' => $this->vorschau($request), 'geprueft' => 'vorschau'];
        }

        return ['befunde' => [], 'geprueft' => 'nichts'];
    }

    /**
     * Stempelt die bereits gesetzten Felder im SPEICHER und prueft das
     * Ergebnis. Gespeichert wird nichts.
     *
     * @return list<string>
     */
    public function vorschau(SignatureRequest $request): array
    {
        $original = $this->storage->read($request->original_path);
        if ($original === null) {
            return ['Das Original-PDF fehlt im Speicher.'];
        }
        try {
            $ergebnis = $this->builder->build($request);
        } catch (\Throwable $e) {
            return ['Das Dokument laesst sich nicht stempeln: '.mb_substr($e->getMessage(), 0, 160)];
        }

        return $this->pruefe($request, $original, $ergebnis['pdf'], $ergebnis['bilder']);
    }

    /**
     * Haelt das Ergebnis an der Anfrage fest. Ein NEUER Fehler (vorher ok
     * oder ungeprueft) steht im Protokoll und laeutet bei der Leitung;
     * derselbe Fehler Nacht fuer Nacht laeutet nicht erneut - die
     * Tageszusammenfassung erinnert daran.
     *
     * @param  list<string>  $befunde
     */
    public function vermerke(SignatureRequest $request, array $befunde, string $anlass, ?int $dauerMs = null): void
    {
        $vorher = $request->quality_status;
        $neu = $befunde === [] ? SignatureRequest::QUALITAET_OK : SignatureRequest::QUALITAET_FEHLER;
        try {
            $request->forceFill(array_filter([
                'quality_status' => $neu,
                'quality_checked_at' => now(),
                'quality_findings' => $befunde === [] ? null : $befunde,
                'render_ms' => $dauerMs,
            ], fn ($v, $k) => $v !== null || $k === 'quality_findings', ARRAY_FILTER_USE_BOTH))->save();

            if ($dauerMs !== null && $dauerMs > self::LANGSAM_MS) {
                Log::warning('Signatur: Fertigstellung dauerte '.$dauerMs.' ms (Grenze '.self::LANGSAM_MS.' ms) - Kandidat fuer einen Hintergrund-Job (KI-090).', [
                    'signature_request_id' => $request->id,
                    'anlass' => $anlass,
                    'seiten' => $request->page_count,
                    'dauer_ms' => $dauerMs,
                ]);
            }

            Log::info('Signatur-Qualitaet', [
                'signature_request_id' => $request->id,
                'anlass' => $anlass,
                'ergebnis' => $neu,
                'dauer_ms' => $dauerMs,
                'befunde' => count($befunde),
            ]);

            if ($neu === SignatureRequest::QUALITAET_FEHLER && $vorher !== SignatureRequest::QUALITAET_FEHLER) {
                $this->audit->record($request, 'quality_failed',
                    description: mb_substr($anlass.': '.implode(' ', $befunde), 0, 480));
                $this->meldeLeitung($request, $befunde);
            } elseif ($neu === SignatureRequest::QUALITAET_OK && $vorher === SignatureRequest::QUALITAET_FEHLER) {
                $this->audit->record($request, 'quality_passed', description: $anlass);
            }
        } catch (\Throwable $e) {
            // Das Festhalten darf den Vorgang NIE scheitern lassen.
            Log::warning('Signatur-Qualitaet konnte nicht vermerkt werden: '.$e->getMessage());
        }
    }

    /** @param  list<string>  $befunde */
    private function meldeLeitung(SignatureRequest $request, array $befunde): void
    {
        $this->notifications->pushMany(
            User::query()->where('role', 'admin')->where('is_active', true)->pluck('id'),
            [
                'type' => 'signature',
                'title' => 'Signatur: Qualitätsprüfung nicht bestanden',
                'body' => '"'.$request->title.'": '.mb_substr(implode(' ', $befunde), 0, 300),
                'link' => route('admin.signatures.quality'),
                'dedup_key' => 'signatur-qualitaet:'.$request->id,
            ],
        );
    }

    /**
     * Vorgaenge, deren letzte Pruefung nicht bestanden ist, oder die beim Abschluss scheiterten.
     *
     * @return Builder<SignatureRequest>
     */
    public static function betroffene(): Builder
    {
        return SignatureRequest::query()
            ->where(fn ($q) => $q->where('quality_status', SignatureRequest::QUALITAET_FEHLER)
                ->orWhere('status', SignatureStatus::COMPLETION_FAILED));
    }
}
