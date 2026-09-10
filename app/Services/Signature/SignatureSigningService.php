<?php

namespace App\Services\Signature;

use App\Exceptions\SignatureSigningException;
use App\Mail\SignatureCompletedMail;
use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Die Seite des UNTERZEICHNERS: oeffnen, bestaetigen, ausfuellen,
 * abschliessen.
 *
 * GRUNDSATZ: dem Browser wird NICHTS geglaubt. Welche Felder ausgefuellt
 * werden duerfen, entscheidet der Server aus der Zuordnung (Feld gehoert zu
 * DIESEM Unterzeichner in DIESER Anfrage); ob ueberhaupt unterschrieben
 * werden darf, entscheidet der Server aus Zustand, Frist und Reihenfolge.
 * Ein manipuliertes Formular kann damit nichts erreichen, was der Vorgang
 * nicht ohnehin erlaubt.
 */
class SignatureSigningService
{
    /** Groesse des Unterschriftsbildes - genug fuer den Druck, wenig fuers Netz. */
    private const MAX_SIGNATURE_PX = 1600;

    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly SignedPdfBuilder $pdf,
        private readonly SignatureRequestService $requests,
    ) {
    }

    /**
     * Darf dieser Unterzeichner JETZT handeln? Liefert null, wenn ja, sonst
     * den Grund im Klartext fuer die Sperrseite.
     */
    public function blockReason(SignatureRequest $request, SignatureSigner $signer): ?string
    {
        if (! $signer->tokenIsLive()) {
            return 'Dieser Link ist nicht mehr gültig.';
        }
        if ($signer->hasSigned()) {
            return 'Sie haben dieses Dokument bereits unterschrieben.';
        }
        if ($signer->hasDeclined()) {
            return 'Sie haben die Unterschrift zu diesem Dokument abgelehnt.';
        }
        if ($request->status === SignatureStatus::CANCELLED) {
            return 'Diese Signaturanfrage wurde zurückgezogen.';
        }
        if ($request->hasExpired() || $request->status === SignatureStatus::EXPIRED) {
            return 'Die Frist für dieses Dokument ist abgelaufen.';
        }
        if (! $request->isOpen()) {
            return 'Dieses Dokument steht nicht (mehr) zur Unterschrift bereit.';
        }
        if ($request->isSequential()) {
            $current = $request->currentSigner();
            if ($current !== null && $current->id !== $signer->id) {
                return 'Dieses Dokument wird nacheinander unterschrieben. '
                    .'Sie erhalten eine E-Mail, sobald Sie an der Reihe sind.';
            }
        }

        return null;
    }

    /** Erster Aufruf des Links: Zustand und Protokoll nachziehen. */
    public function markOpened(SignatureRequest $request, SignatureSigner $signer): void
    {
        $first = $signer->viewed_at === null;
        $signer->forceFill([
            'viewed_at' => $signer->viewed_at ?? now(),
            'status' => $signer->status === SignatureSigner::SENT ? SignatureSigner::VIEWED : $signer->status,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ])->save();

        if ($request->status === SignatureStatus::SENT) {
            $request->forceFill(['status' => SignatureStatus::VIEWED])->save();
        }
        $request->forceFill(['last_activity_at' => now()])->save();

        if ($first) {
            $this->audit->record($request, 'opened', $signer);
        }
    }

    /**
     * Uebernimmt die Eingaben und schliesst diesen Unterzeichner ab.
     *
     * ALLES ODER NICHTS: entweder alle Pflichtfelder liegen vor und der
     * Unterzeichner gilt als fertig, oder es wird gar nichts gespeichert.
     * Ein halb ausgefuellter Zustand waere fuer niemanden zu deuten - weder
     * fuer den Mitarbeiter noch fuer den Unterzeichner, der neu anfangen
     * muesste.
     *
     * @param  array<string, string>  $values  Feld-ID => Wert (Text) bzw. data:-URL (Handschrift)
     * @return list<string> Fehler in Klartext; leer = erfolgreich
     */
    public function sign(SignatureRequest $request, SignatureSigner $signer, array $values): array
    {
        // IDEMPOTENZ: ein zweiter Klick (Doppel-Absenden, Neuladen der Seite,
        // zweiter Reiter) darf nie ein zweites Mal schreiben und nie einen
        // Fehler erzeugen. Wer schon unterschrieben hat, ist fertig - das ist
        // der WAHRE Zustand, kein Fehlerfall.
        if ($signer->hasSigned()) {
            return [];
        }

        $fields = $request->fields()->where('signature_signer_id', $signer->id)->get();
        if ($fields->isEmpty()) {
            return ['Für Sie ist in diesem Dokument kein Feld hinterlegt.'];
        }

        $errors = [];
        $prepared = [];
        foreach ($fields as $field) {
            $raw = $values[$field->id] ?? '';
            if ($field->isDrawn()) {
                $png = $raw === '' ? null : $this->decodeSignature($raw);
                if ($png === null) {
                    if ($field->required) {
                        $errors[] = 'Bitte unterschreiben Sie im Feld "'.($field->label ?: $field->typeLabel()).'".';
                    }

                    continue;
                }
                $prepared[] = ['field' => $field, 'png' => $png];

                continue;
            }

            $value = trim(mb_substr($raw, 0, 500));
            if ($field->type === SignatureFieldType::CHECKBOX) {
                $value = in_array(strtolower($value), ['1', 'on', 'ja', 'true'], true) ? 'ja' : '';
            }
            if ($value === '' && $field->required) {
                $errors[] = 'Bitte füllen Sie das Feld "'.($field->label ?: $field->typeLabel()).'" aus.';

                continue;
            }
            if ($value !== '') {
                $prepared[] = ['field' => $field, 'value' => $value];
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        // Die BILDER liegen ausserhalb der Datenbank und koennen deshalb
        // nicht mit zurueckgerollt werden - sie werden VOR der Transaktion
        // geschrieben und einzeln geprueft. Eine verwaiste Datei ohne
        // Datenbankzeile ist harmlos; eine Datenbankzeile, die auf eine nie
        // geschriebene Datei zeigt, waere eine Unterschrift, die es nicht
        // gibt.
        foreach ($prepared as $index => $entry) {
            if (! isset($entry['png'])) {
                continue;
            }
            /** @var SignatureField $field */
            $field = $entry['field'];
            $path = $this->storage->fieldImagePath($request, $field->id);
            try {
                $written = $this->storage->disk()->put($path, $entry['png']);
            } catch (\Throwable $e) {
                throw new SignatureSigningException('Unterschriftsbild nicht schreibbar: '.$e->getMessage(), previous: $e);
            }
            // put() meldet einen Fehlschlag AUCH ohne Ausnahme mit false
            // (volle Platte, fehlende Rechte). Das ungeprueft zu uebergehen
            // hiess, den Unterzeichner als fertig zu fuehren, obwohl seine
            // Handschrift nirgends liegt.
            if ($written === false) {
                throw new SignatureSigningException('Unterschriftsbild nicht schreibbar (Platte meldet false): '.$path);
            }
            $prepared[$index]['path'] = $path;
        }

        try {
            DB::transaction(function () use ($signer, $prepared) {
                foreach ($prepared as $entry) {
                    /** @var SignatureField $field */
                    $field = $entry['field'];
                    if (isset($entry['path'])) {
                        $field->image_path = $entry['path'];
                    } else {
                        $field->value = $entry['value'];
                    }
                    $field->filled_at = now();
                    $field->save();
                }

                $signer->forceFill([
                    'status' => SignatureSigner::SIGNED,
                    'signed_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
                ])->save();
            });
        } catch (\Throwable $e) {
            throw new SignatureSigningException('Unterschrift nicht speicherbar: '.$e->getMessage(), previous: $e);
        }

        // AB HIER IST UNTERSCHRIEBEN. Alles Weitere - Protokoll, Glocke,
        // Einladung des Naechsten, fertiges PDF - ist Nachlauf und darf die
        // Unterschrift nicht mehr in Frage stellen. Vorher lief der ganze
        // Rest ungeschuetzt in derselben Anfrage: eine Glocke, die einmal
        // nicht schreiben konnte, wurde dem Unterzeichner als HTTP 500
        // gezeigt, obwohl seine Unterschrift laengst sicher lag.
        $this->audit->record($request, 'signed', $signer, count($prepared).' Felder ausgefüllt');
        $request->unsetRelation('signers');
        $request->unsetRelation('fields');

        try {
            $this->advance($request);
        } catch (\Throwable $e) {
            Log::error('Signatur: Nachlauf nach der Unterschrift gescheitert: '.$e->getMessage(), [
                'signature_request_id' => $request->id,
                'signature_signer_id' => $signer->id,
            ]);
            $this->audit->record($request, 'signed', $signer,
                'Nachlauf gescheitert: '.mb_substr($e->getMessage(), 0, 200));
        }

        return [];
    }

    /** Ablehnung - ein Endzustand fuer den gesamten Vorgang. */
    public function decline(SignatureRequest $request, SignatureSigner $signer, ?string $reason): void
    {
        $signer->forceFill([
            'status' => SignatureSigner::DECLINED,
            'declined_at' => now(),
            'decline_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ])->save();

        $request->forceFill([
            'status' => SignatureStatus::DECLINED,
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'declined', $signer, $reason);
        $this->requests->notifyCreator($request, 'Unterschrift abgelehnt',
            $signer->name.' hat "'.$request->title.'" nicht unterschrieben.');
    }

    /**
     * Nach einer Unterschrift: entweder den Naechsten einladen oder den
     * Vorgang abschliessen.
     */
    private function advance(SignatureRequest $request): void
    {
        $request->load(['signers', 'fields']);
        $open = $request->signers->filter(fn (SignatureSigner $s) => $s->isPending());

        if ($open->isEmpty()) {
            $this->complete($request);

            return;
        }

        $request->forceFill([
            'status' => SignatureStatus::PARTIALLY_SIGNED,
            'last_activity_at' => now(),
        ])->save();

        if ($request->isSequential()) {
            $next = $open->first();
            if ($next->invited_at === null) {
                $this->requests->invite($request, $next);
            }
        }

        $this->requests->notifyCreator($request, 'Signatur: ein Unterzeichner fertig',
            '"'.$request->title.'" - '.$request->signedCount().' von '.$request->signers->count().' unterschrieben.');
    }

    /**
     * Abschluss: fertiges PDF erzeugen, Hash sichern, alle Beteiligten
     * benachrichtigen.
     *
     * Scheitert die Erzeugung, bleibt der Vorgang in Bearbeitung UND wird
     * gemeldet - er darf niemals still als "abgeschlossen" gelten, wenn es
     * kein unterschriebenes Dokument gibt.
     */
    public function complete(SignatureRequest $request): void
    {
        try {
            $result = $this->pdf->build($request);
            $path = $this->storage->signedPath($request);
            if ($this->storage->disk()->put($path, $result['pdf']) === false) {
                throw new \RuntimeException('Das fertige PDF konnte nicht abgelegt werden.');
            }
        } catch (\Throwable $e) {
            Log::error('Signatur: unterschriebenes PDF konnte nicht erzeugt werden: '.$e->getMessage(), [
                'signature_request_id' => $request->id,
            ]);
            $this->audit->record($request, 'pdf_generated', description: 'FEHLGESCHLAGEN: '.mb_substr($e->getMessage(), 0, 200));
            $this->requests->notifyCreator($request, 'Signatur: PDF konnte nicht erzeugt werden',
                'Alle Unterschriften liegen vor, das fertige PDF von "'.$request->title.'" fehlt aber. Bitte prüfen.');

            return;
        }

        $request->forceFill([
            'status' => SignatureStatus::COMPLETED,
            'completed_at' => now(),
            'signed_path' => $path,
            'signed_hash' => $result['hash'],
            'signed_size' => strlen($result['pdf']),
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'pdf_generated', description: 'SHA-256 '.$result['hash'], meta: [
            'sha256' => $result['hash'],
            'bytes' => strlen($result['pdf']),
        ]);
        $this->audit->record($request, 'completed');

        foreach ($request->signers as $signer) {
            if (! $signer->hasSigned()) {
                continue;
            }
            try {
                Mail::to($signer->email)->send(new SignatureCompletedMail($request, $signer, $result['pdf']));
            } catch (\Throwable $e) {
                // Ein Postfach, das die Kopie nicht annimmt, darf den
                // Abschluss nicht ruecknehmen - das Dokument liegt sicher im
                // Portal, und der Mitarbeiter sieht den Fehlschlag.
                Log::warning('Signatur: Kopie an Unterzeichner nicht zustellbar: '.$e->getMessage());
                $this->audit->record($request, 'completed', $signer,
                    'Kopie nicht zustellbar: '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        $this->requests->notifyCreator($request, 'Signatur abgeschlossen',
            '"'.$request->title.'" ist vollständig unterschrieben.');
    }

    /**
     * Nimmt die gezeichnete Unterschrift entgegen.
     *
     * Es wird NUR ein PNG aus dem Zeichenfeld akzeptiert, und es wird neu
     * gerendert (GD liest und schreibt es) statt durchgereicht: eine vom
     * Browser gelieferte Datei ist Fremdmaterial, und ein durchgereichtes
     * Bild landete unbesehen in einem PDF, das der Betrieb weitergibt.
     */
    private function decodeSignature(string $dataUrl): ?string
    {
        // Die Laenge wird VOR dem Ausdruck geprueft, nicht in ihm: ein
        // {64,4000000} sprengt die Grenze des Regex-Motors (65535) und
        // haette den ganzen Vorgang mit einem Fehler beendet.
        if (strlen($dataUrl) > 6_000_000) {
            return null;
        }
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]{64,})$#', $dataUrl, $m)) {
            return null;
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($binary === false || strlen($binary) > 4_000_000) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || $info['mime'] !== 'image/png') {
            return null;
        }
        if ($info[0] < 8 || $info[1] < 8) {
            return null;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        // ZU GROSS heisst VERKLEINERN, nicht verwerfen. Die Zeichenflaeche
        // wird in Geraetepixeln aufgenommen: ein 820 CSS-Pixel breites Feld
        // auf einem Geraet mit Verhaeltnis 2 liefert 1640 px. Frueher fiel
        // genau diese Unterschrift durch die Obergrenze - und weil ein
        // fehlendes Pflichtfeld wie "nicht unterschrieben" aussieht, sah der
        // Unterzeichner auf einem grossen Bildschirm oder modernen Telefon
        // nur die Aufforderung, doch bitte zu unterschreiben.
        $image = $this->downscale($image);
        // Leere Flaeche = nicht unterschrieben. Ohne diese Pruefung genuegte
        // ein Klick auf "Bestaetigen", um ein leeres Feld als Unterschrift
        // durchgehen zu lassen.
        if (! $this->hasIink($image)) {
            imagedestroy($image);

            return null;
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 8);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png === false ? null : $png;
    }

    /** Bringt ein zu grosses Bild auf die Hoechstkantenlaenge - Seitenverhaeltnis bleibt. */
    private function downscale(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $max = max($w, $h);
        if ($max <= self::MAX_SIGNATURE_PX) {
            return $image;
        }
        $factor = self::MAX_SIGNATURE_PX / $max;
        // VOR dem Skalieren: ohne diese zwei Zeilen rechnet GD den
        // Alphakanal weg, und aus der durchscheinenden Handschrift wird ein
        // schwarzer Kasten ueber dem Vertragstext.
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $small = @imagescale($image, max(8, (int) round($w * $factor)), max(8, (int) round($h * $factor)));
        if ($small === false) {
            return $image;
        }
        imagedestroy($image);
        imagealphablending($small, false);
        imagesavealpha($small, true);

        return $small;
    }

    /** Enthaelt das Bild ueberhaupt Striche? */
    private function hasIink(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 60));
        $ink = 0;
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 60) {
                    $ink++;
                    if ($ink > 12) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
