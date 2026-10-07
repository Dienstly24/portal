<?php

namespace App\Services\Signature;

use App\Exceptions\InterneUnterschriftException;
use App\Models\ActivityLog;
use App\Models\SignatureField;
use App\Models\SignatureInternalSigning;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\UserSignature;
use App\Support\Unterschriftsbild;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Die INTERNE Unterschrift (Betreiber-Auftrag 04./07.10.2026, Teil B): ein
 * Mitarbeiter oder der Geschaeftsfuehrer unterschreibt das Dokument selbst,
 * BEVOR es an den Kunden geht.
 *
 * DER ABLAUF, und jede Stufe kann vorher beenden:
 *  1. Recht "Darf fuer das Unternehmen unterschreiben" (sonst nichts).
 *  2. Es gibt offene Felder, die DIESEM Mitarbeiter gehoeren - eine fremde
 *     Unterschrift kann niemand setzen, auch nicht ueber ein nachgebautes
 *     Formular (das Bild kommt aus SEINER Ablage oder seiner Zeichnung).
 *  3. Der Bestaetigungssatz "Ich unterschreibe dieses Dokument als ..." ist
 *     angehakt - bei JEDEM Dokument, das Zeitfenster spart ihn nie.
 *  4. Zwei-Faktor: gueltiges Zeitfenster (InterneFreigabe) oder Code.
 *  5. Stempeln auf die aktuelle Basis -> ZWISCHENSTAND, mit DERSELBEN
 *     Pruefung wie beim Abschluss (Qualitaetsgate, Sichtbarkeit am Bild).
 *     Besteht er nicht, wird NICHTS gespeichert.
 *  6. Speichern + Protokoll (append-only) mit Dokument-Hash davor und danach.
 *  7. War der Versand bereits angestossen und wartete nur noch auf diese
 *     Unterschrift, geht das Dokument JETZT raus (bzw. ist ohne Kunden
 *     sofort abgeschlossen).
 */
class InternalSigningService
{
    public const QUELLE_GESPEICHERT = 'gespeichert';

    public const QUELLE_NEU = 'neu';

    public const QUELLE_HANDY = 'handy';

    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignedPdfBuilder $builder,
        private readonly SignatureQualityGate $gate,
        private readonly SignatureAuditService $audit,
        private readonly UserSignatureService $signatures,
        private readonly InterneFreigabe $freigabe,
        private readonly SignatureRequestService $requests,
        private readonly SignatureHandoffService $handoffs,
    ) {
    }

    /** @return Collection<int, SignatureField> */
    public function offeneFelder(SignatureRequest $request, User $user): Collection
    {
        return $request->offeneInterneFelder()
            ->filter(fn (SignatureField $f) => (int) $f->internal_user_id === (int) $user->id)
            ->values();
    }

    /** Der Bestaetigungssatz - EINE Formulierung fuer Dialog, Protokoll und PDF. */
    public static function bestaetigungssatz(User $user): string
    {
        return 'Ich unterschreibe dieses Dokument als '.$user->name.', '.$user->signaturFunktion().'.';
    }

    /**
     * @param  array{quelle?: string, zeichnung?: string|null, handoff_id?: string|null, als_standard?: bool, bestaetigt?: bool, code?: string|null}  $optionen
     *
     * @throws InterneUnterschriftException
     */
    public function unterschreiben(Request $http, SignatureRequest $request, User $user, array $optionen): SignatureInternalSigning
    {
        if (! $user->darfFuerFirmaUnterschreiben()) {
            throw new InterneUnterschriftException('Ihnen fehlt das Recht „Darf für das Unternehmen unterschreiben".');
        }
        if (! $request->isDraft()) {
            throw new InterneUnterschriftException('Intern unterschrieben wird vor dem Versand. Diese Anfrage ist bereits '.$request->statusLabel().'.');
        }
        if ($this->offeneFelder($request, $user)->isEmpty()) {
            throw new InterneUnterschriftException('Für Sie ist in diesem Dokument kein offenes Unterschriftsfeld gesetzt.');
        }
        if (! ($optionen['bestaetigt'] ?? false)) {
            throw new InterneUnterschriftException('Bitte bestätigen: „'.self::bestaetigungssatz($user).'"');
        }

        $freigabe = $this->freigabe->gueltig($http, $user);
        if ($freigabe === null) {
            $code = trim((string) ($optionen['code'] ?? ''));
            if ($code === '') {
                throw new InterneUnterschriftException($user->hasTwoFactor()
                    ? 'Bitte den Code aus Ihrer Authenticator-App eingeben.'
                    : 'Für Ihr Konto ist die Zwei-Faktor-Anmeldung noch nicht eingerichtet. Bitte zuerst unter „Sicherheit" einrichten.',
                    brauchtCode: $user->hasTwoFactor());
            }
            $fehler = $this->freigabe->pruefeCode($http, $user, $code);
            if ($fehler !== null) {
                throw new InterneUnterschriftException($fehler, brauchtCode: true);
            }
            $freigabe = $this->freigabe->gueltig($http, $user);
            if ($freigabe === null) {
                throw new InterneUnterschriftException('Die Bestätigung konnte nicht vermerkt werden. Bitte erneut versuchen.', brauchtCode: true);
            }
        }

        [$png, $methode, $hinterlegt] = $this->bild($user, $optionen);

        $eintrag = Cache::lock('signatur-intern:'.$request->id, 120)->block(30, function () use ($http, $request, $user, $png, $methode, $hinterlegt, $freigabe) {
            $request->refresh();
            $request->unsetRelation('fields');

            return $this->stempelnUnterSperre($http, $request, $user, $png, $methode, $hinterlegt, $freigabe);
        });

        $this->nachlauf($request->fresh(), $user);

        return $eintrag;
    }

    /**
     * Woher das Bild kommt: hinterlegt, neu gezeichnet, oder am Handy.
     *
     * @return array{0: string, 1: string, 2: ?UserSignature}
     */
    private function bild(User $user, array $optionen): array
    {
        $quelle = (string) ($optionen['quelle'] ?? self::QUELLE_GESPEICHERT);

        if ($quelle === self::QUELLE_GESPEICHERT) {
            $sig = $this->signatures->aktive($user);
            $png = $sig === null ? null : $this->signatures->lesen($sig);
            if ($sig === null || $png === null) {
                throw new InterneUnterschriftException('Es ist noch keine Unterschrift hinterlegt. Bitte jetzt zeichnen oder hochladen.');
            }
            // Das Bild gehoert dem, dessen Ablage es ist - aktive() fragt
            // ausschliesslich nach DIESEM Benutzer. Eine fremde Kennung im
            // Formular hat damit keine Wirkung.
            return [$png, $sig->method, $sig];
        }

        if ($quelle === self::QUELLE_HANDY) {
            $png = $this->handoffs->ergebnis($user, (string) ($optionen['handoff_id'] ?? ''));
            if ($png === null) {
                throw new InterneUnterschriftException('Die Unterschrift vom Handy liegt nicht (mehr) vor. Bitte erneut zeichnen.');
            }
            $methode = UserSignature::HANDY_QR;
        } else {
            $png = Unterschriftsbild::ausZeichnung((string) ($optionen['zeichnung'] ?? ''));
            if ($png === null) {
                throw new InterneUnterschriftException('Die Zeichnung ist leer oder nicht lesbar. Bitte erneut zeichnen.');
            }
            $png = Unterschriftsbild::zuschneiden($png);
            $methode = UserSignature::GEZEICHNET;
        }

        // Eine nur fuer dieses Dokument gezeichnete Unterschrift wird NICHT
        // hinterlegt - ausser der Haken "Als Standard speichern" ist gesetzt.
        if ($optionen['als_standard'] ?? false) {
            $sig = $this->signatures->ablegen($user, $png, UserSignature::UNTERSCHRIFT, $methode,
                mb_substr((string) request()->userAgent(), 0, 160) ?: null);

            return [$png, $methode, $sig];
        }

        return [$png, $methode, null];
    }

    /** @param  array{at: int, methode: string}  $freigabe */
    private function stempelnUnterSperre(Request $http, SignatureRequest $request, User $user, string $png, string $methode, ?UserSignature $hinterlegt, array $freigabe): SignatureInternalSigning
    {
        $felder = $this->offeneFelder($request, $user);
        if ($felder->isEmpty()) {
            throw new InterneUnterschriftException('Diese Unterschrift wurde soeben schon gesetzt.');
        }
        $basis = $this->storage->read($request->basisPfad());
        if ($basis === null) {
            throw new InterneUnterschriftException('Das Dokument fehlt im Speicher.');
        }
        $davor = hash('sha256', $basis);
        $start = hrtime(true);
        $zeit = now();

        $ergebnis = null;
        try {
            $ergebnis = $this->builder->stempleInterneFelder($request, $basis, $felder, $png,
                SignedPdfBuilder::beschriftung($user->name, $user->signaturFunktion(), $zeit));
            $befunde = $this->gate->pruefe($request, $basis, $ergebnis['pdf'], $ergebnis['bilder'], $felder->pluck('id')->all());
        } catch (\Throwable $e) {
            $befunde = ['Das Dokument lässt sich nicht stempeln: '.mb_substr($e->getMessage(), 0, 160)];
        }
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);
        if ($befunde !== [] || $ergebnis === null) {
            $this->gate->vermerke($request, $befunde, 'Interne Unterschrift', $ms);
            $this->audit->record($request, 'internal_failed', description: mb_substr(implode(' ', $befunde), 0, 480));
            throw new InterneUnterschriftException('Die Unterschrift wäre im Dokument nicht sichtbar - es wurde nichts gespeichert. '.implode(' ', $befunde));
        }

        $bildPfad = $this->storage->internalImagePath($request, (int) $user->id);
        $pdfPfad = $this->storage->zwischenstandPath($request);
        if ($this->storage->disk()->put($bildPfad, $png) === false || $this->storage->disk()->put($pdfPfad, $ergebnis['pdf']) === false) {
            throw new InterneUnterschriftException('Die Unterschrift konnte nicht gespeichert werden.');
        }

        $eintrag = DB::transaction(function () use ($http, $request, $user, $felder, $png, $methode, $hinterlegt, $freigabe, $davor, $ergebnis, $bildPfad, $pdfPfad, $zeit) {
            foreach ($felder as $feld) {
                $feld->forceFill([
                    'filled_at' => $zeit,
                    'image_path' => $bildPfad,
                    'user_signature_id' => $hinterlegt?->id,
                ])->save();
            }
            $request->forceFill([
                'zwischenstand_path' => $pdfPfad,
                'zwischenstand_hash' => $ergebnis['hash'],
                'last_activity_at' => now(),
            ])->save();

            return SignatureInternalSigning::create([
                'signature_request_id' => $request->id,
                'user_id' => $user->id,
                'name' => mb_substr($user->name, 0, 160),
                'funktion' => mb_substr($user->signaturFunktion(), 0, 80),
                'ip' => $http->ip(),
                'user_agent' => mb_substr((string) $http->userAgent(), 0, 255) ?: null,
                'reauth_method' => $freigabe['methode'],
                'reauth_at' => Carbon::createFromTimestamp($freigabe['at']),
                'image_method' => $methode,
                'image_hash' => hash('sha256', $png),
                'user_signature_id' => $hinterlegt?->id,
                'document_hash_before' => $davor,
                'document_hash_after' => $ergebnis['hash'],
                'bestaetigung' => self::bestaetigungssatz($user),
                'felder' => $felder->pluck('id')->values()->all(),
                'created_at' => $zeit,
            ]);
        });

        $this->gate->vermerke($request, [], 'Interne Unterschrift');
        $this->audit->record($request, 'internal_signed',
            description: $user->name.' ('.$user->signaturFunktion().'), '.$felder->count().' Stelle(n)',
            meta: [
                'sha256_davor' => $davor,
                'sha256_danach' => $ergebnis['hash'],
                'sha256_bild' => hash('sha256', $png),
                'bild' => $methode,
                'zwei_faktor' => $freigabe['methode'],
                'dauer_ms' => $ms,
            ]);
        ActivityLog::record('signature_internal_signed', 'signature_request', $request->id, [
            'felder' => $felder->count(), 'bild' => $methode, 'zwei_faktor' => $freigabe['methode'],
        ]);

        return $eintrag;
    }

    /**
     * Nach der Unterschrift: wartete der Versand nur noch darauf, geht das
     * Dokument JETZT raus. Ein Fehler hier nimmt die Unterschrift NIE
     * zurueck - sie ist gespeichert; der Ersteller erfaehrt es per Glocke.
     */
    private function nachlauf(SignatureRequest $request, User $user): void
    {
        $offen = $request->offeneInterneFelder();
        if ($offen->isNotEmpty()) {
            return;
        }
        if (! $request->send_after_internal) {
            $this->requests->notifyCreator($request, 'Intern unterschrieben',
                $user->name.' hat "'.$request->title.'" intern unterschrieben.');

            return;
        }
        try {
            $this->requests->send($request->load(['signers', 'fields']));
        } catch (\Throwable $e) {
            Log::warning('Signatur: Versand nach interner Unterschrift gescheitert: '.$e->getMessage(), [
                'signature_request_id' => $request->id,
            ]);
            $this->requests->notifyCreator($request, 'Signatur: Versand nicht möglich',
                'Alle internen Unterschriften liegen vor, "'.$request->title.'" konnte aber nicht versendet werden: '
                .mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Nimmt die internen Unterschriften eines ENTWURFS zurueck (z.B. weil
     * das Dokument doch noch geaendert werden muss). Geloescht wird nichts:
     * der Zwischenstand und das Protokoll bleiben, nur die Felder sind
     * wieder offen und der Kunde saehe wieder das Original.
     */
    public function zuruecknehmen(SignatureRequest $request): int
    {
        if (! $request->isDraft()) {
            throw new InterneUnterschriftException('Nach dem Versand werden interne Unterschriften nicht mehr zurückgenommen.');
        }
        $felder = $request->interneFelder()->filter(fn (SignatureField $f) => $f->isFilled());
        DB::transaction(function () use ($request, $felder) {
            foreach ($felder as $feld) {
                $feld->forceFill(['filled_at' => null, 'image_path' => null, 'user_signature_id' => null])->save();
            }
            $request->forceFill([
                'zwischenstand_path' => null,
                'zwischenstand_hash' => null,
                'send_after_internal' => false,
                'last_activity_at' => now(),
            ])->save();
        });
        $this->audit->record($request, 'internal_reset', description: $felder->count().' Stelle(n)');

        return $felder->count();
    }
}
