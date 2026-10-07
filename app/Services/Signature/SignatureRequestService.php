<?php

namespace App\Services\Signature;

use App\Mail\SignatureInvitationMail;
use App\Models\CompanySignatureAsset;
use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfEingangspruefung;
use App\Services\Pdf\PdfException;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        private readonly PdfEingangspruefung $eingang,
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
     * @param  array{title?: string, customer_id?: string|null, contract_id?: string|null, signing_order?: string, identity_check?: string, require_email_verification?: bool, consent_text?: string|null, document_type?: string|null, reference?: string|null, note?: string|null, expires_at?: \DateTimeInterface|null}  $attributes
     */
    public function createFromUpload(UploadedFile $file, array $attributes, ?User $user = null): SignatureRequest
    {
        $hochgeladen = file_get_contents($file->getRealPath());
        if ($hochgeladen === false || $hochgeladen === '') {
            throw new PdfException('Die hochgeladene Datei ist leer.');
        }
        // EINGANGSPRUEFUNG (A3, 04.10.2026): verschluesselt -> klare
        // Meldung; beschaedigt -> von qpdf neu geschrieben und erneut
        // geprueft. Die Basis fuer Felder und Stempel ist danach eine
        // Datei, die die Strukturpruefung besteht.
        $eingang = $this->eingang->pruefe($hochgeladen);
        $binary = $eingang['pdf'];
        $pageCount = $this->inspect($binary);

        $request = new SignatureRequest([
            'title' => $attributes['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'status' => SignatureStatus::DRAFT,
            'customer_id' => $attributes['customer_id'] ?? null,
            'contract_id' => $attributes['contract_id'] ?? null,
            'created_by' => $user->id ?? auth()->id(),
            'original_path' => 'wird-gesetzt',
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
            'original_hash' => hash('sha256', $binary),
            'original_size' => strlen($binary),
            'page_count' => $pageCount,
            // Neue Anfragen beziehen ihre Felder auf die CropBox - die
            // Flaeche, die jeder Betrachter zeigt (siehe FeldGeometrie).
            'feld_bezug' => SignatureRequest::BEZUG_CROPBOX,
            'signing_order' => ($attributes['signing_order'] ?? 'sequential') === 'parallel' ? 'parallel' : 'sequential',
            // Voreinstellung KEINE (Betreiber-Vorgabe 13.09.2026): eine
            // zusaetzliche Huerde wird bewusst gewaehlt, nie stillschweigend
            // gesetzt. Der alte Schalter bleibt als Uebersetzung bestehen.
            'identity_check' => $attributes['identity_check']
                ?? (array_key_exists('require_email_verification', $attributes)
                    ? ($attributes['require_email_verification']
                        ? SignatureRequest::IDENTITY_EMAIL
                        : SignatureRequest::IDENTITY_NONE)
                    : SignatureRequest::IDENTITY_NONE),
            'consent_text' => $attributes['consent_text'] ?? $this->defaultConsentText(),
            'document_type' => $attributes['document_type'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'note' => $attributes['note'] ?? null,
            'expires_at' => $attributes['expires_at'] ?? null,
            'last_activity_at' => now(),
        ]);
        $request->id = (string) Str::uuid();
        $request->original_path = $this->storage->originalPath($request);
        if ($eingang['repariert']) {
            $request->upload_original_path = $this->storage->uploadOriginalPath($request);
            $request->upload_original_hash = hash('sha256', $hochgeladen);
        }
        $request->save();

        $this->storage->disk()->put($request->original_path, $binary);
        if ($eingang['repariert']) {
            // Die hochgeladene Datei bleibt erhalten - mit ihrem Hash.
            $this->storage->disk()->put((string) $request->upload_original_path, $hochgeladen);
        }

        $this->audit->record($request, 'created', description: $request->title);
        $this->audit->record($request, 'document_uploaded', description: $request->original_name, meta: [
            'sha256' => $request->original_hash,
            'seiten' => $pageCount,
            'bytes' => $request->original_size,
            'qpdf' => $eingang['qpdf'],
        ]);
        if ($eingang['repariert']) {
            $this->audit->record($request, 'document_repaired',
                description: 'Beim Hochladen repariert (qpdf). Hochgeladen SHA-256 '.$request->upload_original_hash
                    .' -> Basis SHA-256 '.$request->original_hash,
                meta: [
                    'sha256_hochgeladen' => $request->upload_original_hash,
                    'sha256_basis' => $request->original_hash,
                    'befunde' => $eingang['meldungen'],
                ]);
        }

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
     * @param  list<array{id?: string|null, key?: string|null, name: string, email: string, locale?: string|null, date_of_birth?: string|null}>  $signers
     * @return array<string, string> Behelfs-Kennung des Editors => gespeicherte Kennung
     */
    public function syncSigners(SignatureRequest $request, array $signers): array
    {
        $map = [];
        DB::transaction(function () use ($request, $signers, &$map) {
            $keep = [];
            foreach ($signers as $position => $data) {
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
                // Sprache nur setzen, wenn sie mitgeschickt wurde: der
                // Feld-Editor sendet die Unterzeichner ohne sie, und ein
                // stilles Zuruecksetzen auf Deutsch waere fuer den
                // Mitarbeiter nicht nachvollziehbar.
                if (isset($data['locale']) && array_key_exists($data['locale'], SignatureSigner::LOCALES)) {
                    $signer->locale = $data['locale'];
                }
                $signer->locale ??= 'de';

                // GEBURTSDATUM: nur setzen, wenn ein Wert mitkommt. Ein
                // fehlender Schluessel heisst "unveraendert", nicht
                // "loeschen" - der Feld-Editor schickt es nicht mit, und ein
                // stilles Entfernen wuerde die Pruefung lautlos abschalten.
                if (array_key_exists('date_of_birth', $data)) {
                    $identity = app(SignerIdentityService::class);
                    $signer->save();
                    $identity->setDateOfBirth($signer, $data['date_of_birth']);
                }
                $signer->status ??= SignatureSigner::PENDING;
                $signer->save();
                $keep[] = $signer->id;
                // Der Editor arbeitet mit Behelfs-Kennungen ("neu-3"), bis
                // der Server die echten vergibt. Diese Zuordnung ist die
                // Bruecke: OHNE sie verlor jedes Feld, das im Editor einem
                // ebenfalls neuen Unterzeichner zugewiesen wurde, seinen
                // Besitzer - der Vorgang liess sich danach nicht versenden
                // ("kein Feld hinterlegt"), ohne dass jemand sah, warum.
                if (isset($data['key']) && $data['key'] !== '') {
                    $map[$data['key']] = $signer->id;
                }
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

        return $map;
    }

    /**
     * Ersetzt die Feldliste eines ENTWURFS. Nach dem Versand wird die
     * Aufteilung nicht mehr angefasst - sonst veraendert sich das Dokument
     * unter einem Unterzeichner, der es schon geoeffnet hat.
     *
     * @param  array<string, string>  $signerKeys  Behelfs-Kennung des Editors => echte Kennung
     * @param  list<array{id?: string|null, signer_id?: string|null, signer_key?: string|null, company_asset_id?: string|null, internal_user_id?: int|null, intern_beschriftung?: bool|null, type: string, page: int, x: float, y: float, width: float, height: float, required?: bool, label?: string|null}>  $fields
     */
    public function syncFields(SignatureRequest $request, array $fields, array $signerKeys = []): void
    {
        DB::transaction(function () use ($request, $fields, $signerKeys) {
            $signerIds = $request->signers()->pluck('id')->all();
            $keep = [];
            foreach ($fields as $sort => $data) {
                $type = in_array($data['type'], SignatureFieldType::keys(), true) ? $data['type'] : SignatureFieldType::TEXT;
                $signerId = $data['signer_id'] ?? null;
                if ($signerId === null && isset($data['signer_key'])) {
                    $signerId = $signerKeys[$data['signer_key']] ?? null;
                }
                if ($signerId !== null && ! in_array($signerId, $signerIds, true)) {
                    $signerId = null; // Niemals einem fremden Unterzeichner zuordnen.
                }

                // ENTWEDER Unterzeichner ODER Firmenbild - nie beides. Ein
                // Feld, das einem Menschen gehoert UND einen Stempel traegt,
                // waere im Protokoll nicht mehr aufzuloesen.
                // INTERNE UNTERSCHRIFT (Teil B): das Feld gehoert einem
                // MITARBEITER mit dem Recht "Darf fuer das Unternehmen
                // unterschreiben" - nie einem Unterzeichner, nie einem Kunden.
                $internalUserId = null;
                if ($type === SignatureFieldType::INTERNAL) {
                    $signerId = null;
                    $kandidat = User::find((int) ($data['internal_user_id'] ?? 0));
                    if ($kandidat === null || ! $kandidat->darfFuerFirmaUnterschreiben()) {
                        continue;
                    }
                    $internalUserId = $kandidat->id;
                }

                $assetId = null;
                if ($type === SignatureFieldType::COMPANY) {
                    $signerId = null;
                    $kandidat = $data['company_asset_id'] ?? null;
                    if ($kandidat !== null && CompanySignatureAsset::whereKey($kandidat)->exists()) {
                        $assetId = $kandidat;
                    }
                    // Ohne zugewiesenes Bild waere es ein leeres Feld, das
                    // niemand mehr fuellen kann (es wartet ja auf niemanden).
                    if ($assetId === null) {
                        continue;
                    }
                }

                $field = null;
                if (! empty($data['id'])) {
                    $field = $request->fields()->whereKey($data['id'])->first();
                }
                // Eine bereits GESETZTE interne Unterschrift steht im
                // Zwischenstand, den der Kunde sieht. Sie wird nicht mehr
                // verschoben - sonst stimmten Feld und Dokument nicht mehr
                // ueberein. Aendern heisst: zuruecknehmen, dann neu setzen.
                if ($field !== null && $field->isInternal() && $field->isFilled()) {
                    $keep[] = $field->id;

                    continue;
                }
                $field ??= new SignatureField;
                $field->fill([
                    'signature_request_id' => $request->id,
                    'signature_signer_id' => $signerId,
                    'company_asset_id' => $assetId,
                    'internal_user_id' => $internalUserId,
                    'intern_beschriftung' => $type === SignatureFieldType::INTERNAL ? (bool) ($data['intern_beschriftung'] ?? true) : true,
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
        $request->loadMissing(['signers', 'fields.internalUser']);
        $blockers = [];
        $intern = $request->interneFelder();
        // Ohne Unterzeichner geht es nur, wenn INTERN unterschrieben wird
        // (z.B. ein Dokument, das nur der Geschaeftsfuehrer zeichnet).
        if ($request->signers->isEmpty() && $intern->isEmpty()) {
            $blockers[] = 'Es ist noch kein Unterzeichner erfasst.';
        }
        foreach ($intern->groupBy('internal_user_id') as $felder) {
            $person = $felder->first()->internalUser;
            if ($person === null || ! $person->darfFuerFirmaUnterschreiben()) {
                $blockers[] = 'Die interne Unterschrift ist einer Person zugeordnet, die nicht (mehr) für das Unternehmen unterschreiben darf.';
            }
        }
        foreach ($request->signers as $signer) {
            // Firmenbilder zaehlen hier NIE mit: sie gehoeren keinem
            // Unterzeichner. Ein Vorgang, in dem nur der Stempel des
            // Betriebs steht, waere sonst versandfertig, ohne dass irgendwer
            // etwas zu unterschreiben haette.
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
    /** Ergebnis von send(): versendet, wartet auf interne Unterschrift, ohne Kunden abgeschlossen. */
    public const VERSENDET = 'versendet';

    public const WARTET_INTERN = 'wartet_intern';

    public const ABGESCHLOSSEN = 'abgeschlossen';

    public function send(SignatureRequest $request, ?User $durch = null): string
    {
        $blockers = $this->blockersForSending($request);
        if ($blockers !== []) {
            throw new \RuntimeException(implode(' ', $blockers));
        }

        // INTERNE UNTERSCHRIFTEN ZUERST (Teil B): der Kunde bekommt das
        // Dokument erst, wenn alle internen Unterschriften darauf stehen -
        // er soll die Fassung sehen, die er unterschreibt. Fehlt die EIGENE
        // Unterschrift des Versendenden, ist das ein Bedienfehler (der
        // Editor fragt sie vor dem Senden ab); fehlt die eines Kollegen,
        // wartet der Versand auf ihn und laeuft danach von selbst.
        $offen = $request->offeneInterneFelder();
        if ($offen->isNotEmpty()) {
            if ($durch !== null && $offen->contains(fn (SignatureField $f) => (int) $f->internal_user_id === (int) $durch->id)) {
                throw new \RuntimeException('Ihre eigene interne Unterschrift fehlt noch - bitte zuerst unterschreiben.');
            }
            $request->forceFill(['send_after_internal' => true, 'last_activity_at' => now()])->save();
            $request->loadMissing('fields.internalUser');
            $namen = $offen->map(fn (SignatureField $f) => $f->internalUser?->name)->filter()->unique()->values();
            $this->audit->record($request, 'send_deferred', description: 'Wartet auf: '.$namen->implode(', '));
            $this->benachrichtigeInterne($request);

            return self::WARTET_INTERN;
        }

        // QUALITAETSGATE VOR DEM VERSAND (A1, 04.10.2026): was der Betrieb
        // schon jetzt ins Dokument setzt (Unternehmenssignatur), wird im
        // Speicher gestempelt und am Bild geprueft. Ein unsichtbarer Stempel
        // faellt damit HIER auf - nicht erst, wenn der Kunde unterschrieben
        // hat und das fertige Dokument die Pruefung nicht besteht.
        $request->loadMissing(['fields.companyAsset']);
        if ($request->fields->contains(fn (SignatureField $f) => $f->isFilled())) {
            $gate = app(SignatureQualityGate::class);
            $start = hrtime(true);
            $befunde = $gate->vorschau($request);
            $gate->vermerke($request, $befunde, 'Versand', (int) round((hrtime(true) - $start) / 1_000_000));
            if ($befunde !== []) {
                throw new \RuntimeException('Vor dem Versand geprüft - nicht versendet: '.implode(' ', $befunde));
            }
        }

        // NUR INTERN: ohne Unterzeichner gibt es niemanden einzuladen - das
        // Dokument ist mit der letzten internen Unterschrift fertig und wird
        // sofort abgeschlossen (eigenes PDF mit Protokoll, keine Mail).
        if ($request->signers->isEmpty()) {
            $request->forceFill([
                'sent_at' => $request->sent_at ?? now(),
                'send_after_internal' => false,
                'last_activity_at' => now(),
            ])->save();
            app(SignatureSigningService::class)->complete($request);

            return $request->fresh()?->isCompleted() ? self::ABGESCHLOSSEN : self::VERSENDET;
        }

        $request->forceFill([
            'status' => SignatureStatus::SENT,
            'sent_at' => $request->sent_at ?? now(),
            'send_after_internal' => false,
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record($request, 'sent', description: $request->signers->count().' Unterzeichner');

        foreach ($this->signersToInvite($request) as $signer) {
            $this->invite($request, $signer);
        }

        // ZWEI STUFEN IM AKTIVITAETSCENTER (Betreiber-Vorgabe 13.09.2026):
        // "angefordert" und "unterschrieben". Ohne die erste Stufe steht im
        // Center nur das Ende - wer nachsehen will, ob ein Dokument
        // ueberhaupt rausgegangen ist, findet dort nichts. Der dedup_key
        // haelt es bei EINEM Eintrag, auch wenn erneut versendet wird.
        $this->notifyCreator($request, 'Signatur angefordert',
            '"'.$request->title.'" wurde an '.$request->signers->count().' Unterzeichner versendet.');

        return self::VERSENDET;
    }

    /**
     * "Wartet auf Ihre Unterschrift" an jeden Mitarbeiter mit offenem
     * internem Feld - EINE Glocke je Person und Vorgang (dedup_key), auch
     * wenn er an mehreren Stellen unterschreiben soll.
     */
    public function benachrichtigeInterne(SignatureRequest $request): void
    {
        $request->loadMissing('fields.internalUser');
        foreach ($request->offeneInterneFelder()->groupBy('internal_user_id') as $userId => $felder) {
            $this->audit->record($request, 'internal_requested', description: (string) ($felder->first()->internalUser->name ?? $userId));
            $this->notifications->push((int) $userId, [
                'type' => 'signature',
                'title' => 'Wartet auf Ihre Unterschrift',
                'body' => '"'.$request->title.'": Ihre interne Unterschrift ('.$felder->count().' Stelle(n)) fehlt noch, danach geht das Dokument raus.',
                'link' => route('admin.signatures.show', $request->id),
                'dedup_key' => 'signatur-intern:'.$request->id.':'.$userId,
            ]);
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

    /**
     * Mindestabstand zwischen zwei Erinnerungen an denselben Vorgang.
     *
     * Eine Erinnerung ist eine BITTE, kein Druckmittel. Ohne Abstand wird
     * aus einem ungeduldigen Klick eine Kette gleichlautender Mails beim
     * Kunden - und der naechste Schritt ist der Spam-Ordner, in dem dann
     * auch die urspruengliche Einladung liegt.
     */
    public const REMINDER_MIN_HOURS = 4;

    /** Erinnerung an alle, die noch nicht unterschrieben haben und schon eingeladen sind. */
    public function remind(SignatureRequest $request): int
    {
        if (! $request->acceptsSignatures()) {
            throw new \RuntimeException('Diese Signaturanfrage ist nicht mehr offen.');
        }
        $letzte = $request->signers
            ->filter(fn (SignatureSigner $s) => $s->reminded_at !== null)
            ->max('reminded_at');
        if ($letzte !== null && $letzte->diffInHours(now()) < self::REMINDER_MIN_HOURS) {
            throw new \RuntimeException(
                'Die letzte Erinnerung ging am '.$letzte->lokal()->format('d.m.Y H:i')
                .' Uhr raus. Frühestens '.self::REMINDER_MIN_HOURS.' Stunden später erneut erinnern.'
            );
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
        // EINE Quelle: derselbe Satz steht in lang/{de,ar,en}/signing.php.
        // Nur so laesst sich beim Anzeigen erkennen, ob der Mitarbeiter den
        // Text SELBST geschrieben hat - dann wird er nie uebersetzt.
        return (string) __('signing.consent_default', [], 'de');
    }
}
