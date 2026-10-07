<?php

namespace App\Services\Signature;

use App\Exceptions\SignatureSigningException;
use App\Mail\SignatureCompletedMail;
use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Support\SignatureFieldType;
use App\Support\SignatureGroup;
use App\Support\SignatureStatus;
use App\Support\Unterschriftsbild;
use Illuminate\Support\Facades\Cache;
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
    /** So lange zeigt der Link nach dem Abschluss noch das fertige Dokument. */
    public const NACHLAUF_TAGE = 7;

    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly SignedPdfBuilder $pdf,
        private readonly SignatureRequestService $requests,
        private readonly SignatureQualityGate $gate,
    ) {
    }

    /**
     * Darf dieser Unterzeichner JETZT handeln? Liefert null, wenn ja, sonst
     * den Grund im Klartext fuer die Sperrseite.
     */
    public function blockReason(SignatureRequest $request, SignatureSigner $signer): ?string
    {
        if (! $signer->tokenIsLive()) {
            return __('signing.blocked_link_dead');
        }
        if ($signer->hasSigned()) {
            return __('signing.blocked_already_signed');
        }
        if ($signer->hasDeclined()) {
            return __('signing.blocked_already_declined');
        }
        if ($request->status === SignatureStatus::CANCELLED) {
            return __('signing.blocked_cancelled');
        }
        if ($request->hasExpired() || $request->status === SignatureStatus::EXPIRED) {
            return __('signing.blocked_expired');
        }
        if (! $request->isOpen()) {
            return __('signing.blocked_not_open');
        }
        if ($request->isSequential()) {
            $current = $request->currentSigner();
            if ($current !== null && $current->id !== $signer->id) {
                return __('signing.blocked_wait_turn');
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
    /**
     * @param  array<string,string>  $values    Werte der TIPP-Felder, je Feld-ID
     * @param  array<string,string>  $drawings  Handschrift je GRUPPE (Feldart), nicht je Feld
     */
    public function sign(SignatureRequest $request, SignatureSigner $signer, array $values, array $drawings = []): array
    {
        // GLEICHZEITIGES ABSENDEN (KI-030): die Pruefung "schon
        // unterschrieben?" war ohne Sperre - zwei Anfragen im selben Moment
        // (Doppelklick, zweiter Reiter) liefen beide durch. Folge: der
        // Vorgang wurde zweimal abgeschlossen (PDF und Abschluss-Mails
        // doppelt), und die zweite Zeichnung ueberschrieb die Bilddatei
        // NACH dem Abschluss - das gespeicherte Bild waere nicht mehr das
        // im PDF. Die Sperre gilt je Unterzeichner; der Zustand wird
        // INNERHALB neu gelesen.
        return Cache::lock('signatur-unterschrift:'.$signer->id, 60)
            ->block(15, function () use ($request, $signer, $values, $drawings) {
                $signer->refresh();

                return $this->signUnterSperre($request, $signer, $values, $drawings);
            });
    }

    /** @return array<int,string> Fehlermeldungen; leer = unterschrieben */
    private function signUnterSperre(SignatureRequest $request, SignatureSigner $signer, array $values, array $drawings): array
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
            return [__('signing.error_no_field')];
        }

        $errors = [];
        $prepared = [];

        // EINE ZEICHNUNG JE GRUPPE - der Kern der Umstellung.
        //
        // Vorher stand hier `$values[$field->id]`: jedes Feld holte sich
        // seine EIGENE Zeichnung, sieben Felder verlangten sieben. Jetzt
        // kommt die Handschrift aus der Gruppe (Unterzeichner + Art) und
        // wird auf alle ihre Felder verteilt. Der Unterzeichner zeichnet
        // einmal; dass ueberall dasselbe steht, ist keine Zusage mehr,
        // sondern eine Eigenschaft der Ablage - es gibt nur EIN Bild.
        foreach (SignatureGroup::forSigner($signer, $fields) as $group) {
            $raw = (string) ($drawings[$group->key()] ?? '');
            $png = $raw === '' ? null : $this->decodeSignature($raw);
            if ($png === null) {
                if ($group->required()) {
                    $errors[] = __('signing.error_signature_missing', ['field' => $group->label()]);
                }

                continue;
            }

            // Der leere Rand der Zeichenflaeche faellt weg, BEVOR das Bild
            // abgelegt wird: sonst stuende die Handschrift winzig in der
            // Mitte eines fast leeren Feldes.
            $png = Unterschriftsbild::zuschneiden($png);
            $prepared[] = ['group' => $group, 'png' => $png];
        }

        foreach ($fields as $field) {
            if ($field->isDrawn()) {
                continue; // Oben ueber die Gruppe erledigt.
            }
            $raw = $values[$field->id] ?? '';

            $value = trim(mb_substr($raw, 0, 500));
            if ($field->type === SignatureFieldType::CHECKBOX) {
                $value = in_array(strtolower($value), ['1', 'on', 'ja', 'true'], true) ? 'ja' : '';
            }
            if ($value === '' && $field->required) {
                $errors[] = __('signing.error_field_missing', ['field' => $field->label ?: $field->typeLabel()]);

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
            /** @var SignatureGroup $group */
            $group = $entry['group'];
            // EIN Pfad je Gruppe - nicht je Feld. Sieben Felder zeigen
            // anschliessend auf dieselbe Datei; es liegen keine sieben
            // Kopien herum, die auseinanderlaufen koennten.
            $path = $this->storage->fieldImagePath($request, $group->imageKey());
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
                    if (isset($entry['path'])) {
                        /** @var SignatureGroup $group */
                        $group = $entry['group'];
                        // Dieselbe Datei in jedes Feld der Gruppe. Damit
                        // sind alle Stellen in EINEM Zug erledigt - der
                        // Unterzeichner bekommt nie wieder "Seite 2 fehlt
                        // noch" zu sehen.
                        foreach ($group->fields as $feld) {
                            $feld->image_path = $entry['path'];
                            $feld->filled_at = now();
                            $feld->save();
                        }

                        continue;
                    }

                    /** @var SignatureField $field */
                    $field = $entry['field'];
                    $field->value = $entry['value'];
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
        // WER hat unterschrieben - und, falls bekannt, FUER WEN.
        //
        // Betreiber-Vorgabe 7: im Protokoll muss ohne Nachschlagen stehen,
        // wer unterschrieben hat und fuer welche Firma. Die Firma wird NUR
        // genannt, wenn sie in der verknuepften Kundenakte wirklich steht -
        // erfunden wird sie nie, und eine neue Spalte braucht es dafuer
        // nicht. Ein FIRMENBILD ist ausdruecklich KEINE Unterschrift und
        // erscheint weiterhin getrennt als "eingesetzt von".
        $stellen = 0;
        foreach ($prepared as $eintrag) {
            $stellen += isset($eintrag['group']) ? $eintrag['group']->count() : 1;
        }
        $firma = $request->customer?->company_name;
        $this->audit->record($request, 'signed', $signer,
            $stellen.' Stelle(n) ausgefüllt'.($firma ? ' · für '.$firma : ''));
        $request->unsetRelation('signers');
        $request->unsetRelation('fields');

        try {
            $this->advance($request, $signer);
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
    private function advance(SignatureRequest $request, SignatureSigner $signer): void
    {
        $request->load(['signers', 'fields']);
        $open = $request->signers->filter(fn (SignatureSigner $s) => $s->isPending());

        // DIE PERSONEN-MELDUNG KOMMT IMMER - auch beim letzten (oder
        // einzigen) Unterzeichner. Vorher stand sie NACH der Abzweigung:
        // bei genau einem Unterzeichner - dem Normalfall - erfuhr der
        // Mitarbeiter also nie "der Kunde hat unterschrieben", sondern nur
        // "Signatur abgeschlossen". Beide Meldungen sagen etwas anderes:
        // die eine, dass ein MENSCH fertig ist, die andere, dass das
        // DOKUMENT fertig ist (mit dem erzeugten PDF).
        $this->requests->notifyCreator($request, 'Dokument unterschrieben',
            $signer->name.' hat "'.$request->title.'" unterschrieben ('
            .$request->signedCount().' von '.$request->signers->count().').');

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
        // Genau EIN Abschluss je Vorgang (KI-030): unterschreiben zwei
        // Personen im selben Moment, sehen beide Nachlaeufe "alle fertig".
        // Ohne Sperre entstand das PDF zweimal und jede Abschluss-Mail ging
        // doppelt hinaus.
        Cache::lock('signatur-abschluss:'.$request->id, 120)->block(30, function () use ($request) {
            $request->refresh();
            if ($request->completed_at !== null || $request->status === SignatureStatus::COMPLETED) {
                return;
            }
            $this->completeUnterSperre($request);
        });
    }

    private function completeUnterSperre(SignatureRequest $request): void
    {
        $start = hrtime(true);
        try {
            $result = $this->pdf->build($request);
            // SELBSTTEST VOR "Abgeschlossen" (KI-058): ein Dokument, das
            // die Unterschrift nicht zeigt, wird weder gespeichert noch
            // verschickt - der Vorgang steht dann sichtbar auf "Fehler bei
            // Fertigstellung" und laesst sich neu erzeugen.
            $original = (string) $this->storage->read($request->original_path);
            $befunde = $this->gate->pruefe($request, $original, $result['pdf'], $result['bilder']);
            $this->gate->vermerke($request, $befunde, 'Abschluss', $this->ms($start));
            if ($befunde !== []) {
                throw new \RuntimeException('Selbsttest nicht bestanden: '.implode(' ', $befunde));
            }
            $path = $this->storage->signedPath($request);
            if ($this->storage->disk()->put($path, $result['pdf']) === false) {
                throw new \RuntimeException('Das fertige PDF konnte nicht abgelegt werden.');
            }
        } catch (\Throwable $e) {
            Log::error('Signatur: unterschriebenes PDF konnte nicht erzeugt werden: '.$e->getMessage(), [
                'signature_request_id' => $request->id,
                'dauer_ms' => $this->ms($start),
            ]);
            if (! isset($befunde)) {
                // Fehler VOR der Pruefung (Erzeugung selbst gescheitert) -
                // auch das gehoert in die Qualitaetsliste.
                $this->gate->vermerke($request, ['Erzeugung gescheitert: '.mb_substr($e->getMessage(), 0, 200)], 'Abschluss', $this->ms($start));
            }
            $request->forceFill([
                'status' => SignatureStatus::COMPLETION_FAILED,
                'last_activity_at' => now(),
            ])->save();
            $this->audit->record($request, 'pdf_generated', description: 'FEHLGESCHLAGEN: '.mb_substr($e->getMessage(), 0, 200));
            $this->requests->notifyCreator($request, 'Signatur: Fehler bei Fertigstellung',
                'Alle Unterschriften liegen vor, das fertige PDF von "'.$request->title.'" hat die Prüfung aber nicht bestanden. '
                .'In der Signaturanfrage „Erneut erzeugen" wählen.');

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

        // BEIDE Hashes im Protokoll: das Original belegt den Vertragstext,
        // das Ergebnis die fertige Datei.
        $this->audit->record($request, 'pdf_generated', description: 'SHA-256 '.$result['hash'].' · Selbsttest bestanden', meta: [
            'sha256' => $result['hash'],
            'sha256_original' => $request->original_hash,
            'sha256_hochgeladen' => $request->upload_original_hash,
            'bytes' => strlen($result['pdf']),
            'selbsttest' => 'bestanden',
            'dauer_ms' => $request->render_ms,
        ]);
        $this->audit->record($request, 'completed');

        // NACH DEM ABSCHLUSS (A4, 04.10.2026): der Link kann nichts mehr
        // ausloesen - wer unterschrieben hat, wird nur noch auf die
        // Abschlussseite gefuehrt. Lesen darf er das fertige Dokument noch
        // NACHLAUF_TAGE lang (die Kopie liegt ohnehin als Anhang in seinem
        // Postfach); danach ist der Link tot statt bis zu 30 Tage lang ein
        // Zugang zu einem unterschriebenen Vertrag.
        $grenze = now()->addDays(self::NACHLAUF_TAGE);
        foreach ($request->signers as $signer) {
            if ($signer->token_hash !== null && ($signer->token_expires_at === null || $signer->token_expires_at->gt($grenze))) {
                $signer->forceFill(['token_expires_at' => $grenze])->save();
            }
        }

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

    private function ms(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
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
        // EINE Stelle fuer das Annehmen einer Zeichnung - Unterzeichner-Seite,
        // "Meine Unterschrift", Handy-QR und Einmal-Zeichnung im Editor
        // durchlaufen dieselbe Pruefung.
        return Unterschriftsbild::ausZeichnung($dataUrl);
    }
}
