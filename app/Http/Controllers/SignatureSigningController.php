<?php

namespace App\Http\Controllers;

use App\Exceptions\SignatureSigningException;
use App\Mail\SignatureVerificationMail;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Signature\SignatureAuditService;
use App\Services\Signature\SignaturePageRenderer;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Die OEFFENTLICHE Seite: der Unterzeichner braucht kein Konto.
 *
 * Damit ist dieser Controller die am staerksten exponierte Stelle des
 * Moduls - jede Methode geht deshalb durch dieselbe Schleuse:
 *
 *  1. Token -> Unterzeichner (nur ueber den Hash; ein ungueltiges Token
 *     sieht aus wie ein abgelaufenes, damit sich nicht durch Ausprobieren
 *     feststellen laesst, welche Vorgaenge es gibt).
 *  2. Darf dieser Unterzeichner JETZT handeln (Zustand, Frist, Reihenfolge)?
 *  3. Ist die E-Mail bestaetigt, falls die Anfrage das verlangt?
 *
 * In der URL steht NIE eine Datenbank-ID und nie eine Angabe zum
 * Dokument - nur das Zufallstoken.
 */
class SignatureSigningController extends Controller
{
    public function __construct(
        private readonly SignatureTokenService $tokens,
        private readonly SignatureSigningService $signing,
        private readonly SignatureStorage $storage,
        private readonly SignaturePageRenderer $renderer,
        private readonly SignatureAuditService $audit,
        private readonly SignatureRequestService $requests,
    ) {
    }

    /** Einstieg aus der E-Mail. */
    public function show(string $token)
    {
        [$signer, $request] = $this->resolve($token);

        $block = $this->signing->blockReason($request, $signer);
        if ($block !== null) {
            return view('signature.blocked', [
                'signature' => $request,
                'signer' => $signer,
                'reason' => $block,
                // Der Abschluss ist kein Fehler - wer sein eigenes
                // Dokument erneut oeffnet, soll das auch sehen.
                'completed' => $request->isCompleted(),
            ]);
        }

        $this->signing->markOpened($request, $signer);

        if ($this->needsVerification($request, $signer)) {
            return view('signature.verify', [
                'signature' => $request,
                'signer' => $signer,
                'token' => $token,
                'codeSent' => session('signature_code_sent') === $signer->id,
            ]);
        }

        return view('signature.sign', [
            'signature' => $request,
            'signer' => $signer,
            'token' => $token,
            'fields' => $request->fields()->where('signature_signer_id', $signer->id)->orderBy('page')->orderBy('sort')->get(),
            'geometry' => $this->renderer->geometry($request),
            'previewAvailable' => $this->renderer->available(),
        ]);
    }

    /** Sendet den sechsstelligen Bestaetigungscode an die eingeladene Adresse. */
    public function requestCode(string $token)
    {
        [$signer, $request] = $this->resolve($token);
        $this->ensureActionable($request, $signer);

        if (! $this->needsVerification($request, $signer)) {
            return redirect()->route('signature.show', $token);
        }

        $code = $this->tokens->issueVerificationCode($signer);
        try {
            Mail::to($signer->email)->send(new SignatureVerificationMail($request, $signer, $code));
        } catch (\Throwable $e) {
            Log::error('Signatur: Bestätigungscode nicht versendbar: '.$e->getMessage());

            return back()->with('error', 'Der Code konnte nicht versendet werden. Bitte später erneut versuchen.');
        }

        $this->audit->record($request, 'verification_requested', $signer);

        return redirect()->route('signature.show', $token)
            ->with('signature_code_sent', $signer->id)
            ->with('success', 'Wir haben Ihnen einen Bestätigungscode an '.$this->maskEmail($signer->email).' gesendet.');
    }

    public function verify(Request $request, string $token)
    {
        [$signer, $signature] = $this->resolve($token);
        $this->ensureActionable($signature, $signer);

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $this->tokens->verifyCode($signer, trim($data['code']))) {
            $this->audit->record($signature, 'verification_failed', $signer);

            return back()->with('error', 'Der Code stimmt nicht oder ist abgelaufen. Bitte fordern Sie einen neuen an.');
        }

        $this->audit->record($signature, 'verified', $signer);

        return redirect()->route('signature.show', $token);
    }

    /** Seitenbild des Dokuments - nur mit gueltigem, bestaetigtem Zugang. */
    public function page(string $token, int $page)
    {
        [$signer, $request] = $this->resolve($token);
        if ($this->signing->blockReason($request, $signer) !== null && ! $request->isCompleted()) {
            abort(403);
        }
        if ($this->needsVerification($request, $signer)) {
            abort(403);
        }

        $png = $this->renderer->page($request, max(1, min($page, (int) $request->page_count)));
        if ($png === null) {
            abort(404);
        }
        if ($page === 1) {
            $this->audit->record($request, 'document_viewed', $signer);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * Das ECHTE PDF zum Nachlesen. Wichtig fuer die Beweiskraft: der
     * Unterzeichner sieht auf der Seite Bilder, muss aber das Original
     * pruefen koennen - "was du gesehen hast" soll belegbar dieselbe Datei
     * sein.
     */
    public function document(string $token)
    {
        [$signer, $request] = $this->resolve($token);
        if ($this->needsVerification($request, $signer)) {
            abort(403);
        }
        $completed = $request->isCompleted() && $signer->hasSigned();
        if (! $completed && $this->signing->blockReason($request, $signer) !== null) {
            abort(403);
        }

        $binary = $this->storage->read($completed ? $request->signed_path : $request->original_path);
        if ($binary === null) {
            abort(404);
        }
        $this->audit->record($request, 'downloaded', $signer, $completed ? 'Unterschriebenes PDF' : 'Original');

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dokument.pdf"',
        ]);
    }

    public function sign(Request $request, string $token)
    {
        [$signer, $signature] = $this->resolve($token);

        // DOPPELTES ABSENDEN ist kein Angriff, sondern der Normalfall:
        // langsame Verbindung, zweiter Klick, Neuladen, zweiter Reiter. Wer
        // bereits unterschrieben hat, wird auf die Abschluss-Seite gefuehrt
        // statt auf eine 403-Fehlerseite - dort stand fuer ihn "es hat nicht
        // geklappt", obwohl es geklappt hatte.
        if ($signer->hasSigned()) {
            return redirect()->route('signature.done', $token);
        }

        $this->ensureActionable($signature, $signer);

        if ($this->needsVerification($signature, $signer)) {
            return redirect()->route('signature.show', $token)
                ->with('error', 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse.');
        }

        $data = $request->validate([
            'zustimmung' => ['accepted'],
            'felder' => ['array', 'max:200'],
            'felder.*' => ['nullable', 'string', 'max:4000000'],
        ], [
            'zustimmung.accepted' => 'Bitte bestätigen Sie den Hinweis zur elektronischen Unterschrift.',
        ]);

        $this->audit->record($signature, 'signing_started', $signer);

        // DIE SCHLEUSE: hinter dieser Zeile darf KEIN technischer Fehler mehr
        // ungefiltert zum Unterzeichner durchschlagen. Er ist ein Fremder
        // ohne Konto in einem Vorgang, den er nicht wiederholen kann, wenn er
        // ihn nicht versteht; ein roher HTTP 500 laesst ihn ausserdem im
        // Unklaren, ob seine Unterschrift angekommen ist. Die Ursache gehoert
        // in Log UND Protokoll - ein Fehlschlag, den nur die Logdatei kennt,
        // faellt im Alltag niemandem auf (Lehre der Systemzustand-Seite).
        try {
            $errors = $this->signing->sign($signature, $signer, $data['felder'] ?? []);
        } catch (\Throwable $e) {
            Log::error('Signatur: Unterschreiben fehlgeschlagen: '.$e->getMessage(), [
                'signature_request_id' => $signature->id,
                'signature_signer_id' => $signer->id,
                'exception' => $e,
            ]);
            $this->audit->record($signature, 'signing_failed', $signer,
                mb_substr($e->getMessage(), 0, 200));

            // Der Betrieb muss davon erfahren, OHNE in die Logdatei zu
            // schauen: der Unterzeichner meldet sich erfahrungsgemaess nicht,
            // er versucht es spaeter noch einmal - oder gar nicht mehr.
            try {
                $this->requests->notifyCreator($signature, 'Signatur: Unterschreiben fehlgeschlagen',
                    $signer->name.' konnte "'.$signature->title.'" nicht unterschreiben. Bitte prüfen.');
            } catch (\Throwable) {
                // Eine gestoerte Glocke darf die Fehlerseite nicht ihrerseits
                // zum Fehler machen.
            }

            $friendly = $e instanceof SignatureSigningException
                ? $e->userMessage()
                : 'Ihre Unterschrift konnte gerade nicht gespeichert werden. Bitte versuchen Sie es in einem Moment erneut.';

            return back()->with('error', $friendly);
        }

        if ($errors !== []) {
            // Die gezeichnete Unterschrift wird BEWUSST nicht mit
            // zurueckgegeben: sie ist als data:-URL schnell 40 kB und mehr
            // und laege dann in der Sitzung. Das Feld haelt der Browser
            // selbst (sessionStorage), die Sitzung bleibt klein.
            return back()->withInput($request->except('felder'))->with('error', implode(' ', $errors));
        }

        return redirect()->route('signature.done', $token);
    }

    public function decline(Request $request, string $token)
    {
        [$signer, $signature] = $this->resolve($token);
        $this->ensureActionable($signature, $signer);

        $data = $request->validate(['grund' => ['nullable', 'string', 'max:500']]);
        $this->signing->decline($signature, $signer, $data['grund'] ?? null);

        return redirect()->route('signature.done', $token);
    }

    /** Abschluss-Seite. Sie bleibt erreichbar, auch wenn der Zugang endet. */
    public function done(string $token)
    {
        [$signer, $signature] = $this->resolve($token, allowExpired: true);

        return view('signature.done', [
            'signature' => $signature,
            'signer' => $signer,
            'token' => $token,
            'declined' => $signer->hasDeclined(),
        ]);
    }

    /**
     * @return array{0: SignatureSigner, 1: SignatureRequest}
     */
    private function resolve(string $token, bool $allowExpired = false): array
    {
        $signer = $this->tokens->find($token);
        if ($signer === null) {
            // BEWUSST dieselbe Antwort wie bei einem abgelaufenen Zugang:
            // aus der Fehlermeldung darf nicht hervorgehen, ob es diesen
            // Vorgang gibt.
            abort(404, 'Dieser Link ist nicht gültig.');
        }
        if (! $allowExpired && $signer->token_revoked_at !== null) {
            $this->audit->record($signer->request, 'access_denied', $signer, 'Zugang widerrufen');
            abort(410, 'Dieser Link ist nicht mehr gültig.');
        }
        $request = $signer->request()->with(['signers', 'fields'])->first();
        if ($request === null) {
            abort(404);
        }

        return [$signer, $request];
    }

    private function ensureActionable(SignatureRequest $request, SignatureSigner $signer): void
    {
        if ($this->signing->blockReason($request, $signer) !== null) {
            abort(403, 'Für dieses Dokument ist keine Aktion mehr möglich.');
        }
    }

    private function needsVerification(SignatureRequest $request, SignatureSigner $signer): bool
    {
        return $request->require_email_verification && $signer->verified_at === null;
    }

    /** "ma***@example.com" - die Adresse bestaetigen, ohne sie preiszugeben. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
