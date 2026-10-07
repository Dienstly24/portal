<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InterneUnterschriftException;
use App\Http\Controllers\Controller;
use App\Models\SignatureHandoff;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Signature\InternalSigningService;
use App\Services\Signature\InterneFreigabe;
use App\Services\Signature\SignatureHandoffService;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\UserSignatureService;
use App\Support\Bildverarbeitung;
use App\Support\QrCode;
use App\Support\SignatureFieldType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Die INTERNE Unterschrift in der Beraterwelt (Teil B, 07.10.2026):
 * "Meine Unterschrift" im Profil, "Auf dem Handy unterschreiben" und das
 * Unterschreiben eines Dokuments vor dem Versand.
 *
 * JEDER Weg prueft dieselben drei Dinge: das Recht "Darf fuer das
 * Unternehmen unterschreiben", den zweiten Faktor (Zeitfenster oder Code)
 * und - beim Dokument - den Bestaetigungssatz. Die Fachlogik steht in den
 * Diensten; hier wird nur gelesen, geprueft und geantwortet.
 */
class InternalSignatureController extends Controller
{
    public function __construct(
        private readonly UserSignatureService $signatures,
        private readonly InterneFreigabe $freigabe,
        private readonly SignatureHandoffService $handoffs,
        private readonly InternalSigningService $internal,
        private readonly SignatureRequestService $requests,
    ) {
    }

    /** Profil "Meine Unterschrift". */
    public function profile(Request $request)
    {
        $user = $this->berechtigt($request);

        return view('admin.signatures.my_signature', [
            'user' => $user,
            'unterschrift' => $this->signatures->aktive($user, UserSignature::UNTERSCHRIFT),
            'paraphe' => $this->signatures->aktive($user, UserSignature::PARAPHE),
            'archiv' => UserSignature::where('user_id', $user->id)->where('active', false)->latest('archived_at')->limit(10)->get(),
            'freigabe' => $this->freigabe->gueltig($request, $user),
            'stunden' => InterneFreigabe::stunden(),
            'bestaetigung' => InternalSigningService::bestaetigungssatz($user),
        ]);
    }

    /** Das Bild einer hinterlegten Unterschrift - nur die EIGENE. */
    public function image(Request $request, string $id)
    {
        $user = $this->berechtigt($request);
        $sig = UserSignature::whereKey($id)->where('user_id', $user->id)->firstOrFail();
        $png = $this->signatures->lesen($sig);
        abort_if($png === null, 404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    /** Gezeichnet (am Geraet oder am Handy) hinterlegen. */
    public function storeDrawing(Request $request): JsonResponse
    {
        $user = $this->berechtigt($request);
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(UserSignature::KINDS))],
            'zeichnung' => ['nullable', 'string', 'max:3000000'],
            'handoff_id' => ['nullable', 'string', 'max:64'],
            'code' => ['nullable', 'string', 'max:16'],
        ]);
        if ($antwort = $this->zweiFaktor($request, $user, $data['code'] ?? null)) {
            return $antwort;
        }
        try {
            if (! empty($data['handoff_id'])) {
                $png = $this->handoffs->ergebnis($user, $data['handoff_id']);
                if ($png === null) {
                    return $this->fehler('Die Unterschrift vom Handy liegt nicht (mehr) vor. Bitte erneut zeichnen.');
                }
                $sig = $this->signatures->ablegen($user, $png, $data['kind'], UserSignature::HANDY_QR, $this->geraet($request));
            } else {
                $sig = $this->signatures->speichereZeichnung($user, (string) ($data['zeichnung'] ?? ''), $data['kind'], $this->geraet($request));
            }
        } catch (\RuntimeException $e) {
            return $this->fehler($e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => UserSignature::KINDS[$sig->kind].' gespeichert.', 'reload' => true]);
    }

    /**
     * Hochladen (nur Personal). Mit `vorschau=1` wird das aufbereitete Bild
     * nur ZURUECKGEGEBEN - gespeichert wird erst nach dem Blick darauf.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $this->berechtigt($request);
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(UserSignature::KINDS))],
            'datei' => ['required', 'file', 'max:10240'],
            'vorschau' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:16'],
        ]);
        if (! Bildverarbeitung::verfuegbar()) {
            return $this->fehler(Bildverarbeitung::meldungFuerOberflaeche());
        }
        $endung = strtolower((string) $request->file('datei')->getClientOriginalExtension());
        if (! in_array($endung, ['png', 'jpg', 'jpeg', 'heic', 'heif'], true)) {
            return $this->fehler('Erlaubt sind PNG, JPG und HEIC.');
        }
        $binary = (string) file_get_contents($request->file('datei')->getRealPath());

        if ($request->boolean('vorschau')) {
            try {
                $png = $this->signatures->aufbereiten($binary);
            } catch (\RuntimeException $e) {
                return $this->fehler($e->getMessage());
            }

            return response()->json(['ok' => true, 'vorschau' => 'data:image/png;base64,'.base64_encode($png)]);
        }

        // Zwei-Faktor BEIM HOCHLADEN (Betreiber-Vorgabe 07.10.2026): ein
        // Foto einer Unterschrift ist leichter zu beschaffen als die Hand,
        // die sie zeichnet.
        if ($antwort = $this->zweiFaktor($request, $user, $data['code'] ?? null)) {
            return $antwort;
        }
        try {
            $sig = $this->signatures->ablegen($user, $this->signatures->aufbereiten($binary), $data['kind'],
                UserSignature::HOCHGELADEN, $this->geraet($request));
        } catch (\RuntimeException $e) {
            return $this->fehler($e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => UserSignature::KINDS[$sig->kind].' gespeichert.', 'reload' => true]);
    }

    /** Admins pflegen ihre Funktion selbst (sie haben keine Maske ueber sich). */
    public function storeFunction(Request $request)
    {
        $user = $this->berechtigt($request);
        abort_unless($user->role === 'admin', 403);
        $data = $request->validate(['signatur_funktion' => ['nullable', 'string', 'max:80']]);
        $user->forceFill(['signatur_funktion' => trim((string) ($data['signatur_funktion'] ?? '')) ?: null])->save();

        return back()->with('success', 'Funktion gespeichert.');
    }

    /** QR-Code fuer "Auf dem Handy unterschreiben". */
    public function handoffCreate(Request $request): JsonResponse
    {
        $user = $this->berechtigt($request);
        $neu = $this->handoffs->erstellen($request, $user);

        return response()->json([
            'ok' => true,
            'id' => $neu['handoff']->id,
            'qr' => QrCode::svg($neu['url'], 5, 3, 'QR-Code: auf dem Handy unterschreiben'),
            'gueltig_bis' => $neu['handoff']->expires_at->toIso8601String(),
        ]);
    }

    /** Fragt der Rechner nach: ist am Handy schon gezeichnet worden? */
    public function handoffStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->berechtigt($request);
        // Nur der EIGENE Handy-Zugang - ein fremder sieht aus wie keiner.
        abort_unless(SignatureHandoff::whereKey($id)->where('user_id', $user->id)->exists(), 404);
        $png = $this->handoffs->ergebnis($user, $id);

        return response()->json([
            'fertig' => $png !== null,
            'bild' => $png === null ? null : 'data:image/png;base64,'.base64_encode($png),
        ]);
    }

    /**
     * Ein Dokument INTERN unterschreiben - auf Wunsch im selben Zug den
     * Editor-Stand speichern und danach senden.
     */
    public function sign(Request $request, string $id): JsonResponse
    {
        $signature = SignatureRequest::findOrFail($id);
        $user = $request->user();

        // Der Editor schickt seinen Stand mit (das Feld kann gerade erst
        // gesetzt worden sein) - gespeichert wird nur, wer bearbeiten darf.
        if ($signature->isDraft() && $request->has('fields') && Gate::allows('update', $signature)) {
            app(SignatureController::class)->speichereEditorStand($request, $signature);
            $signature = SignatureRequest::findOrFail($id);
        }
        Gate::authorize('signInternal', $signature);

        $data = $request->validate([
            'quelle' => ['required', 'in:gespeichert,neu,handy'],
            'zeichnung' => ['nullable', 'string', 'max:3000000'],
            'handoff_id' => ['nullable', 'string', 'max:64'],
            'als_standard' => ['nullable', 'boolean'],
            'bestaetigt' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:16'],
            'und_senden' => ['nullable', 'boolean'],
        ]);

        try {
            $this->internal->unterschreiben($request, $signature, $user, [
                'quelle' => $data['quelle'],
                'zeichnung' => $data['zeichnung'] ?? null,
                'handoff_id' => $data['handoff_id'] ?? null,
                'als_standard' => (bool) ($data['als_standard'] ?? false),
                'bestaetigt' => (bool) ($data['bestaetigt'] ?? false),
                'code' => $data['code'] ?? null,
            ]);
        } catch (InterneUnterschriftException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'code_noetig' => $e->brauchtCode], 422);
        }

        $signature = SignatureRequest::with(['signers', 'fields'])->findOrFail($id);
        $meldung = 'Intern unterschrieben.';
        if (($data['und_senden'] ?? false) && $signature->isDraft() && Gate::allows('send', $signature)) {
            try {
                $ergebnis = $this->requests->send($signature, $user);
                $meldung = match ($ergebnis) {
                    SignatureRequestService::WARTET_INTERN => 'Intern unterschrieben. Der Versand wartet noch auf weitere interne Unterschriften.',
                    SignatureRequestService::ABGESCHLOSSEN => 'Intern unterschrieben - das Dokument ist fertig.',
                    default => 'Intern unterschrieben und versendet.',
                };
            } catch (\RuntimeException $e) {
                return response()->json(['ok' => false, 'message' => 'Intern unterschrieben, aber nicht versendet: '.$e->getMessage(),
                    'redirect' => route('admin.signatures.show', $id)], 422);
            }
        }

        return response()->json(['ok' => true, 'message' => $meldung, 'redirect' => route('admin.signatures.show', $id)]);
    }

    /** Interne Unterschriften eines Entwurfs zuruecknehmen. */
    public function reset(Request $request, string $id)
    {
        $signature = SignatureRequest::findOrFail($id);
        Gate::authorize('resetInternal', $signature);
        try {
            $anzahl = $this->internal->zuruecknehmen($signature);
        } catch (InterneUnterschriftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $anzahl.' interne Unterschriftsstelle(n) zurückgenommen.');
    }

    /** Mitarbeiter, denen ein internes Feld zugewiesen werden kann. */
    public static function berechtigtePersonen(): Collection
    {
        return User::query()->whereIn('role', ['admin', 'manager', 'support', 'employee'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('role', 'admin')->orWhere('can_sign_for_company', true))
            ->orderBy('name')->get(['id', 'name', 'role', 'signatur_funktion']);
    }

    private function berechtigt(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->darfFuerFirmaUnterschreiben(), 403,
            'Ihnen fehlt das Recht „Darf für das Unternehmen unterschreiben".');

        return $user;
    }

    /** Zwei-Faktor fuer das Speichern einer Unterschrift: Zeitfenster oder Code. */
    private function zweiFaktor(Request $request, User $user, ?string $code): ?JsonResponse
    {
        if ($this->freigabe->gueltig($request, $user) !== null) {
            return null;
        }
        if ($code === null || trim($code) === '') {
            return response()->json(['ok' => false, 'code_noetig' => $user->hasTwoFactor(), 'message' => $user->hasTwoFactor()
                ? 'Bitte den Code aus Ihrer Authenticator-App eingeben.'
                : 'Für Ihr Konto ist die Zwei-Faktor-Anmeldung noch nicht eingerichtet.'], 422);
        }
        $fehler = $this->freigabe->pruefeCode($request, $user, $code);

        return $fehler === null ? null : response()->json(['ok' => false, 'code_noetig' => true, 'message' => $fehler], 422);
    }

    private function fehler(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], 422);
    }

    private function geraet(Request $request): ?string
    {
        return mb_substr((string) $request->userAgent(), 0, 160) ?: null;
    }

    /** Fuer den Editor: Darf der Nutzer selbst intern unterschreiben, und hat er schon eine Unterschrift? */
    public static function editorDaten(User $user): array
    {
        $darf = $user->darfFuerFirmaUnterschreiben();
        $sig = $darf ? app(UserSignatureService::class)->aktive($user) : null;

        return [
            'darf' => $darf,
            'hat_unterschrift' => $sig !== null,
            'bild' => $sig !== null ? route('admin.meine_unterschrift.image', $sig->id) : null,
            'name' => $user->name,
            'funktion' => $user->signaturFunktion(),
            'bestaetigung' => InternalSigningService::bestaetigungssatz($user),
            'freigabe' => $darf && app(InterneFreigabe::class)->gueltig(request(), $user) !== null,
            'typ' => SignatureFieldType::INTERNAL,
        ];
    }
}
