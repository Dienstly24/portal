<?php

namespace App\Services\Signature;

use App\Mail\SignatureInvitationMail;
use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Der Lebenslauf einer Signaturanfrage: anlegen, vorbereiten, versenden,
 * erinnern, abbrechen, ablaufen.
 *
 * Die Fachlogik liegt hier und nicht im Controller, damit derselbe Vorgang
 * aus der Kundenakte, aus der Vertragsakte, aus der Signatur-Uebersicht und
 * aus einem geplanten Lauf identisch ablaeuft - vier Wege, EINE Regel.
 */
class SignatureRequestService
{
    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignatureTokenService $tokens,
        private readonly SignatureAuditService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Legt eine Anfrage aus einem hochgeladenen PDF an.
     *
     * Das PDF wird SOFORT geprueft - Seiten lesbar, nicht verschluesselt.
     * Bewusst hier und nicht erst beim Erzeugen des Ergebnisses: ein
     * Fehlschlag NACH dem Unterschreiben waere dem Unterzeichner nicht zu
     * erklaeren und der Vorgang nicht zu retten.
     *
     * @param  array{title?: string, customer_id?: string|null, contract_id?: string|null, signing_order?: string, require_email_verification?: bool, consent_text?: string|null, document_type?: string|null, reference?: string|null, note?: string|null, expires_at?: \DateTimeInterface|null}  $attributes
     */
    public function createFromUpload(UploadedFile $file, array $attributes, ?User $user = null): SignatureRequest
    {
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false || $binary === '') {
            throw new PdfException('Die hochgeladene Datei ist leer.');
        }
        $pageCount = $this->inspect($binary);

        $request = new SignatureRequest([
            'title' => $attributes['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'status' => SignatureStatus::DRAFT,
            'customer_id' => $attributes['customer_id'] ?? null,
            'contract_id' => $attributes['contract_id'] ?? null,
            'created_by' => $user?->id ?? auth()->id(),
            'original_path' => 'wird-gesetzt',
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
            'original_hash' => hash('sha256', $binary),
            'original_size' => strlen($binary),
            'page_count' => $pageCount,
            'signing_order' => ($attributes['signing_order'] ?? 'sequential') === 'parallel' ? 'parallel' : 'sequential',
            'require_email_verification' => $attributes['require_email_verification'] ?? true,
            'consent_text' => $attributes['consent_text'] ?? $this->defaultConsentText(),
            'document_type' => $attributes['document_type'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'note' => $attributes['note'] ?? null,
            'expires_at' => $attributes['expires_at'] ?? null,
            'last_activity_at' => now(),
        ]);
        $request->id = (string) \Illuminate\Support\Str::uuid();
        $request->original_path = $this->storage->originalPath($request);
        $request->save();

        $this->storage->disk()->put($request->original_path, $binary);

        $this->audit->record($request, 'created', description: $request->title);
        $this->audit->record($request, 'document_uploaded', description: $request->original_name, meta: [
            'sha256' => $request->original_hash,
            'seiten' => $pageCount,
            'bytes' => $request->original_size,
        ]);

        return $request;
    }

    /**
     * Prueft ein PDF und liefert die Seitenzahl. Wirft mit einer Meldung,
     * die dem MITARBEITER sagt, was er tun kann.
     */
    public function inspect(string $binary): int
    {
        if (str_contains(substr($binary, -3000), '/Encrypt')) {
            throw new PdfException(
                'Das PDF ist geschuetzt (verschluesselt). Bitte ohne Passwortschutz erneut hochladen.'
            );
        }
        $document = PdfDocument::open($binary);
        $count = $document->pageCount();
        if ($count < 1 || $count > 200) {
            throw new PdfException('Das PDF hat '.$count.' Seiten - unterstuetzt sind 1 bis 200.');
        }

        return $count;
    }

    /**
     * Ersetzt die Unterzeichner-Liste. Wer bereits unterschrieben hat, wird
     * NIE entfernt: seine Unterschrift ist Teil des Vorgangs, und ein
     * Entfernen liesse Felder mit einer fremden Unterschrift zurueck.
     *
     * @param  list<array{id?: string|null, name: string, email: string}>  $signers
     */
    public function syncSigners(SignatureRequest $request, array $signers): void
    {
        DB::transaction(function () use ($request, $signers) {
            $keep = [];
            foreach (array_values($signers) as $position => $data) {
                $signer = null;
                if (! empty($data['id'])) {
                    $signer = $request->signers()->whereKey($data['id'])->first();
                }
                $signer ??= new SignatureSigner(['signature_request_id' => $request->id]);
                $wasNew = ! $signer->exists;
                $signer->fill([
                    'signature_request_id' => $request->id,
                    'name' => mb_substr(trim($data['name']), 0, 160),
                    'email' => mb_strtolower(trim($data['email'])),
                    'signing_order' => $position + 1,
                ]);
                $signer->status ??= SignatureSigner::PENDING;
                $signer->save();
                $keep[] = $signer->id;
                if ($wasNew) {
                    $this->audit->record($request, 'signer_added', $signer, $signer->name.' <'.$signer->email.'>');
                }
            }

            $removable = $request->signers()->whereNotIn('id', $keep ?: ['-'])->get();
            foreach ($removable as $signer) {
                if ($signer->hasSigned()) {
                    continue;
                }
                $this->audit->record($request, 'signer_removed', description: $signer->name.' <'.$signer->email.'>');
                // Die Felder des Entfernten verlieren ihren Besitzer, statt
                // still an jemand anderen zu fallen - der Mitarbeiter muss
                // sie bewusst neu zuordnen.
                $signer->fields()->update(['signature_signer_id' => null]);
                $signer->delete();
            }
            $request->unsetRelation('signers');
        });
    }

    /**
     * Ersetzt die Feldliste eines ENTWURFS. Nach dem Versand wird die
     * Aufteilung nicht mehr angefasst - sonst veraendert sich das Dokument
     * unter einem Unterzeichner, der es schon geoeffnet hat.
     *
     * @param  list<array{id?: string|null, signer_id?: string|null, type: string, page: int, x: float, y: float, width: float, height: float, required?: bool, label?: string|null}>  $fields
     */
    public function syncFields(SignatureRequest $request, array $fields): void
    {
        DB::transaction(function () use ($request, $fields) {
            $signerIds = $request->signers()->pluck('id')->all();
            $keep = [];
            foreach (array_values($fields) as $sort => $data) {
                $type = in_array($data['type'], SignatureFieldType::keys(), true) ? $data['type'] : SignatureFieldType::TEXT;
                $signerId = $data['signer_id'] ?? null;
                if ($signerId !== null && ! in_array($signerId, $signerIds, true)) {
                    $signerId = null; // Niemals einem fremden Unterzeichner zuordnen.
                }

                $field = null;
                if (! empty($data['id'])) {
                    $field = $request->fields()->whereKey($data['id'])->first();
                }
                $field ??= new SignatureField;
                $field->fill([
                    'signature_request_id' => $request->id,
                    'signature_signer_id' => $signerId,
                    'type' => $type,
                    'page' => max(1, min((int) $data['page'], (int) $request->page_count)),
                    'pos_x' => $this->clamp((float) $data['x']),
                    'pos_y' => $this->clamp((float) $data['y']),
                    'width' => $this->clamp((float) $data['width'], 0.01),
                    'height' => $this->clamp((float) $data['height'], 0.005),
                    'required' => (bool) ($data['required'] ?? true),
                    'label' => isset($data['label']) ? mb_substr((string) $data['label'], 0, 120) : null,
                    'sort' => $sort,
                ]);
                $field->save();
                $keep[] = $field->id;
            }
            $request->fields()->whereNotIn('id', $keep ?: ['-'])->whereNull('filled_at')->delete();
            $request->unsetRelation('fields');
        });

        $this->audit->record($request, 'fields_saved', description: count($fields).' Felder');
    }

    private function clamp(float $value, float $min = 0.0): float
    {
        return max($min, min(1.0, round($value, 6)));
    }

    /**
     * Was fehlt noch zum Versand? Liefert Klartext-Gruende statt eines
     * blossen "false" - der Mitarbeiter soll wissen, was zu tun ist.
     *
     * @return list<string>
     */
    public function blockersForSending(SignatureRequest $request): array
    {
        $request->loadMissing(['signers', 'fields']);
        $blockers = [];
        if ($request->signers->isEmpty()) {
            $blockers[] = 'Es ist noch kein Unterzeichner erfasst.';
        }
        foreach ($request->signers as $signer) {
            $own = $request->fields->where('signature_signer_id', $signer->id);
            if ($own->isEmpty()) {
                $blockers[] = 'Für '.$signer->name.' ist noch kein Feld gesetzt.';
            } elseif ($own->whereIn('type', SignatureFieldType::DRAWN)->isEmpty()) {
                $blockers[] = 'Für '.$signer->name.' fehlt ein Unterschriftsfeld.';
            }
        }
        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            $blockers[] = 'Das Ablaufdatum liegt in der Vergangenheit.';
        }

        return $blockers;
    }

    /**
     * Versendet die Einladungen und macht aus dem Entwurf einen laufenden
     * Vorgang.
     *
     * REIHENFOLGE ist eine Entscheidung des Mitarbeiters: bei "nacheinander"
     * bekommt nur der Erste die Einladung, der Naechste erst nach der
     * Unterschrift des Vorgaengers. Bei "gleichzeitig" alle sofort.
     */
    public function send(SignatureRequest $request): void
    {
        $blockers = $this->blockersForSending($request);
        if ($blockers !== []) {
            throw new \RuntimeException(implode(' ', $blockers));
        }

        $request->forceFill([
            'status' => SignatureStatus::SENT,
            'sent_at' => $request->sent_at ?? now(),
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'sent', description: $request->signers->count().' Unterzeichner');

        foreach ($this->signersToInvite($request) as $signer) {
            $this->invite($request, $signer);
        }
    }

    /** @return iterable<SignatureSigner> */
    private function signersToInvite(SignatureRequest $request): iterable
    {
        $pending = $request->signers->filter(fn (SignatureSigner $s) => $s->isPending());

        return $request->isSequential() ? $pending->take(1) : $pending;
    }

    /**
     * Einladung an EINEN Unterzeichner: frischer Zugang, frische Mail.
     *
     * Der Versand darf den Vorgang nicht mitreissen - eine kaputte Adresse
     * eines von drei Unterzeichnern beendet sonst den ganzen Lauf (dieselbe
     * Lehre wie bei den Batch-Laeufen, 19.08.2026). Der Fehlschlag steht im
     * Protokoll und als Glocke beim Ersteller.
     */
    public function invite(SignatureRequest $request, SignatureSigner $signer, bool $isReminder = false): bool
    {
        $token = $this->tokens->issue($signer, $request->expires_at);

        try {
            Mail::to($signer->email)->send(new SignatureInvitationMail($request, $signer, $token, $isReminder));
        } catch (\Throwable $e) {
            Log::error('Signatur-Einladung konnte nicht versendet werden: '.$e->getMessage(), [
                'signature_request_id' => $request->id,
            ]);
            $this->audit->record($request, $isReminder ? 'reminder_sent' : 'sent', $signer,
                'Versand fehlgeschlagen: '.mb_substr($e->getMessage(), 0, 200));
            $this->notifyCreator($request, 'Signatur: E-Mail nicht zustellbar',
                'Die Einladung an '.$signer->email.' konnte nicht versendet werden.');

            return false;
        }

        $signer->forceFill([
            'status' => $signer->status === SignatureSigner::PENDING ? SignatureSigner::SENT : $signer->status,
            'invited_at' => $signer->invited_at ?? now(),
            'reminded_at' => $isReminder ? now() : $signer->reminded_at,
            'reminder_count' => $isReminder ? $signer->reminder_count + 1 : $signer->reminder_count,
        ])->save();

        $this->audit->record($request, $isReminder ? 'reminder_sent' : 'sent', $signer, $signer->email);

        return true;
    }

    /** Erinnerung an alle, die noch nicht unterschrieben haben und schon eingeladen sind. */
    public function remind(SignatureRequest $request): int
    {
        if (! $request->acceptsSignatures()) {
            throw new \RuntimeException('Diese Signaturanfrage ist nicht mehr offen.');
        }
        $sent = 0;
        foreach ($this->signersToInvite($request) as $signer) {
            if ($this->invite($request, $signer, true)) {
                $sent++;
            }
        }
        $request->forceFill(['last_activity_at' => now()])->save();

        return $sent;
    }

    /**
     * Bricht die Anfrage ab. Die Zugaenge werden WIDERRUFEN - ein Link, der
     * nach dem Abbruch noch funktioniert, ist der Abbruch nicht wert.
     */
    public function cancel(SignatureRequest $request, ?string $reason = null): void
    {
        if ($request->isCompleted()) {
            throw new \RuntimeException('Eine abgeschlossene Signaturanfrage kann nicht abgebrochen werden.');
        }
        foreach ($request->signers as $signer) {
            $this->tokens->revoke($signer);
        }
        $request->forceFill([
            'status' => SignatureStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'cancelled', description: $reason);
    }

    /** Laesst eine Anfrage ablaufen (Tageslauf). Zugaenge werden widerrufen. */
    public function expire(SignatureRequest $request): void
    {
        foreach ($request->signers as $signer) {
            $this->tokens->revoke($signer);
        }
        $request->forceFill([
            'status' => SignatureStatus::EXPIRED,
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'expired', description: 'Frist '.optional($request->expires_at)->format('d.m.Y'));
        $this->notifyCreator($request, 'Signaturanfrage abgelaufen',
            '"'.$request->title.'" wurde nicht rechtzeitig unterschrieben.');
    }

    public function notifyCreator(SignatureRequest $request, string $title, string $body): void
    {
        if ($request->created_by === null) {
            return;
        }
        $this->notifications->push((int) $request->created_by, [
            'type' => 'signature',
            'title' => $title,
            'body' => $body,
            'link' => route('admin.signatures.show', $request->id),
            'dedup_key' => 'signature:'.$request->id.':'.md5($title),
        ]);
    }

    /**
     * Vorbelegter Zustimmungstext. Er behauptet bewusst NICHT, dass die
     * Unterschrift einer handschriftlichen gleichsteht - die Einordnung
     * haengt am Geschaeftsfall und gehoert in die Rechtspruefung, nicht in
     * eine Voreinstellung.
     */
    public function defaultConsentText(): string
    {
        return 'Mit dem Klick auf "Unterschrift bestätigen" geben Sie eine elektronische '
            .'Unterschrift ab. Datum, Uhrzeit, IP-Adresse und Geraeteangaben werden zum '
            .'Nachweis gespeichert. Sie erhalten das unterschriebene Dokument per E-Mail.';
    }
}
