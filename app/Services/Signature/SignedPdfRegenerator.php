<?php

namespace App\Services\Signature;

use App\Mail\SignatureCompletedMail;
use App\Models\SignatureRequest;
use App\Support\SignatureStatus;
use Illuminate\Support\Facades\Mail;

/**
 * Erzeugt das unterschriebene PDF eines BEREITS abgeschlossenen Vorgangs
 * neu - aus den gespeicherten Daten: Original, Feldpositionen, den
 * gespeicherten Unterschriftsbildern und den Firmenbildern.
 *
 * Anlass (03.10.2026): 11 von 17 Vorgaengen standen als "Abgeschlossen" da,
 * ihr Dokument zeigte die Unterschrift aber nicht (KI-055/056/062). Die
 * Unterschriften selbst sind vorhanden und unveraendert - falsch war nur
 * ihre Darstellung im PDF.
 *
 * REGELN:
 *  - KEINE UNTERSCHRIFT WIRD VERAENDERT, kein Zeitpunkt, kein Unterzeichner.
 *  - Das ALTE PDF wird NICHT geloescht und nicht ueberschrieben. Es bleibt
 *    unter seinem Pfad liegen - es ist die Datei, die damals verschickt
 *    wurde, und gehoert zum Nachweis. Das neue liegt daneben.
 *  - Das neue PDF muss den Selbsttest bestehen, sonst bleibt alles wie es
 *    ist.
 *  - Das Protokoll im PDF und das Ereignisprotokoll sagen ausdruecklich
 *    "neu erzeugt", mit altem und neuem SHA-256.
 *  - Eine Kopie an die Unterzeichner geht nur auf ausdruecklichen Wunsch.
 */
class SignedPdfRegenerator
{
    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignedPdfBuilder $builder,
        private readonly SignatureQualityGate $gate,
        private readonly SignatureAuditService $audit,
        private readonly SignatureSigningService $signing,
    ) {
    }

    /**
     * @return array{ergebnis: string, befunde: list<string>, alt: ?string, neu: ?string}
     *                                                                                    ergebnis: neu_erzeugt | abgeschlossen | nicht_bestanden | uebersprungen
     */
    public function neuErzeugen(SignatureRequest $request, bool $kopieSenden = false): array
    {
        $request->loadMissing(['signers', 'fields']);

        // Gescheiterter Abschluss: das ist KEINE Neuerzeugung, sondern der
        // regulaere Abschluss - mit Mails und Glocke wie sonst auch.
        if ($request->status === SignatureStatus::COMPLETION_FAILED) {
            $this->signing->complete($request);
            $request->refresh();

            return [
                'ergebnis' => $request->isCompleted() ? 'abgeschlossen' : 'nicht_bestanden',
                'befunde' => $request->isCompleted() ? [] : ['Selbsttest erneut nicht bestanden - siehe Protokoll.'],
                'alt' => null,
                'neu' => $request->signed_hash,
            ];
        }

        if (! $request->isCompleted()) {
            return ['ergebnis' => 'uebersprungen', 'befunde' => ['Vorgang ist nicht abgeschlossen - das Dokument entsteht beim Abschluss.'], 'alt' => null, 'neu' => null];
        }

        $original = $this->storage->read($request->original_path);
        if ($original === null) {
            return ['ergebnis' => 'nicht_bestanden', 'befunde' => ['Original fehlt im Speicher.'], 'alt' => $request->signed_hash, 'neu' => null];
        }

        $jetzt = now();
        $start = hrtime(true);
        $result = $this->builder->build($request, $jetzt);
        $befunde = $this->gate->pruefe($request, $original, $result['pdf'], $result['bilder']);
        $dauer = (int) round((hrtime(true) - $start) / 1_000_000);
        if ($befunde !== []) {
            $this->gate->vermerke($request, $befunde, 'Neu erzeugen', $dauer);

            return ['ergebnis' => 'nicht_bestanden', 'befunde' => $befunde, 'alt' => $request->signed_hash, 'neu' => null];
        }

        $pfad = $this->storage->directory($request).'/unterschrieben-'.$jetzt->format('YmdHis').'.pdf';
        if ($this->storage->disk()->put($pfad, $result['pdf']) === false) {
            return ['ergebnis' => 'nicht_bestanden', 'befunde' => ['Neues PDF konnte nicht abgelegt werden.'], 'alt' => $request->signed_hash, 'neu' => null];
        }

        $alt = $request->signed_hash;
        $altPfad = $request->signed_path;
        $request->forceFill([
            'signed_path' => $pfad,
            'signed_hash' => $result['hash'],
            'signed_size' => strlen($result['pdf']),
        ])->save();
        $this->gate->vermerke($request, [], 'Neu erzeugen', $dauer);

        $this->audit->record($request, 'pdf_regenerated',
            description: 'SHA-256 alt '.substr((string) $alt, 0, 16).'… → neu '.substr($result['hash'], 0, 16).'… · Selbsttest bestanden',
            meta: [
                'sha256_alt' => $alt,
                'sha256_neu' => $result['hash'],
                'sha256_original' => $request->original_hash,
                'pfad_alt' => $altPfad,
                'selbsttest' => 'bestanden',
            ]);

        if ($kopieSenden) {
            foreach ($request->signers as $signer) {
                if (! $signer->hasSigned()) {
                    continue;
                }
                try {
                    Mail::to($signer->email)->send(new SignatureCompletedMail($request, $signer, $result['pdf']));
                } catch (\Throwable $e) {
                    $this->audit->record($request, 'pdf_regenerated', $signer,
                        'Kopie nicht zustellbar: '.mb_substr($e->getMessage(), 0, 200));
                }
            }
        }

        return ['ergebnis' => 'neu_erzeugt', 'befunde' => [], 'alt' => $alt, 'neu' => $result['hash']];
    }
}
