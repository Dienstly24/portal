<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use App\Services\SpamFilter;
use App\Support\ChatReferenz;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Gast-Chat `/chat` - Kontakt OHNE Portal-Anmeldung (Betreiber-Vorgabe
 * 20.11.2026, Kundenportal gesperrt). Funktioniert unabhaengig vom
 * Portal-Schalter.
 *
 * Regeln:
 *  - Der Besucher gibt Name + Telefon ODER E-Mail an. Diese Angaben sind
 *    UNGEPRUEFT: sie landen als interne Notiz an der Unterhaltung und
 *    werden NIE zum Kundenabgleich benutzt (sonst koennte ein Fremder mit
 *    einer fremden Nummer eine Kundenakte "uebernehmen", und der
 *    KI-Assistent antwortete mit deren Daten).
 *  - Die Unterhaltung haengt an einer zufaelligen Kennung in der
 *    SERVER-Sitzung - nie an einem Wert aus dem Request.
 *  - `?ref=` (ChatReferenz) erzeugt nur eine interne Notiz fuer das Team.
 *    Der Besucher sieht davon nichts, er wird nicht angemeldet, und die
 *    Unterhaltung wird nicht der Akte zugeordnet.
 *  - Gedrosselt je IP (Route) UND je Sitzung (hier).
 */
class WebChatController extends Controller
{
    private const SESSION_KENNUNG = 'webchat_kennung';
    private const SESSION_UNTERHALTUNG = 'webchat_unterhaltung';
    private const SESSION_REF = 'webchat_ref';

    /** Nachrichten je Sitzung und Zeitfenster. */
    private const MAX_JE_SITZUNG = 20;
    private const FENSTER_SEKUNDEN = 600;

    public function show(Request $request): View
    {
        if (($ref = ChatReferenz::pruefen($request->query('ref'))) !== null) {
            $request->session()->put(self::SESSION_REF, $ref);
        }

        return view('support.chat', [
            'laeuft' => $this->unterhaltung($request) !== null,
        ]);
    }

    public function send(Request $request, ConversationEngine $engine): JsonResponse
    {
        // Honeypot: Bots fuellen das unsichtbare Feld.
        if ($request->filled('website')) {
            return response()->json(['ok' => true]);
        }

        $unterhaltung = $this->unterhaltung($request);
        $regeln = ['nachricht' => 'required|string|max:2000'];
        if ($unterhaltung === null) {
            $regeln += [
                'name' => 'required|string|max:120',
                'kontakt' => ['required', 'string', 'max:190', function ($attr, $wert, $fail) {
                    $wert = trim((string) $wert);
                    $istMail = filter_var($wert, FILTER_VALIDATE_EMAIL) !== false;
                    $istTelefon = preg_match('/^\+?[0-9 ()\/-]{6,25}$/', $wert) === 1;
                    if (! $istMail && ! $istTelefon) {
                        $fail(__('Bitte geben Sie eine Telefonnummer oder E-Mail-Adresse an.'));
                    }
                }],
                'einwilligung' => 'accepted',
            ];
        }
        // Fehler IMMER als JSON: die Anwendung rendert JSON-Fehler sonst
        // nur unter api/* (bootstrap/app.php) - die Seite bekaeme eine
        // Weiterleitung statt einer lesbaren Meldung.
        $pruefung = Validator::make($request->all(), $regeln);
        if ($pruefung->fails()) {
            return response()->json(['ok' => false, 'errors' => $pruefung->errors()], 422);
        }
        $daten = $pruefung->validated();

        $kennung = $this->kennung($request);
        $schluessel = 'webchat:'.$kennung;
        if (RateLimiter::tooManyAttempts($schluessel, self::MAX_JE_SITZUNG)) {
            return response()->json([
                'ok' => false,
                'fehler' => __('Sie haben sehr viele Nachrichten geschickt. Bitte warten Sie einen Moment.'),
            ], 429);
        }
        RateLimiter::hit($schluessel, self::FENSTER_SEKUNDEN);

        // Erkannter Spam wird still verworfen (wie beim Hilfe-Formular).
        if (SpamFilter::reason([$daten['name'] ?? null, $daten['nachricht']]) !== null) {
            return response()->json(['ok' => true]);
        }

        $kanal = Channel::where('key', Channel::WEBCHAT)->where('is_active', true)->first();
        if ($kanal === null) {
            Log::warning('Website-Chat: Kanal "webchat" fehlt oder ist inaktiv (Migration gelaufen?).');

            return response()->json([
                'ok' => false,
                'fehler' => __('Der Chat ist gerade nicht verfügbar. Bitte schreiben Sie uns per WhatsApp oder E-Mail.'),
            ], 503);
        }

        $nachricht = $engine->handleInbound(new InboundMessage(
            externalUserId: 'web:'.$kennung,
            externalMessageId: 'web:'.Str::uuid(),
            text: trim($daten['nachricht']),
            senderName: $unterhaltung === null ? trim($daten['name']) : null,
            metadata: ['quelle' => 'webchat'],
        ), $kanal);

        if ($nachricht !== null && $unterhaltung === null) {
            $this->ersteNachricht($request, $nachricht->conversation_id, $daten);
        }

        return response()->json(['ok' => true]);
    }

    public function verlauf(Request $request): JsonResponse
    {
        $unterhaltung = $this->unterhaltung($request);
        if ($unterhaltung === null) {
            return response()->json(['nachrichten' => []]);
        }

        $nachrichten = CustomerMessage::where('conversation_id', $unterhaltung->id)
            // Zweiter Schluessel, damit die Reihenfolge bei gleicher Sekunde
            // wenigstens STABIL ist (MySQL liefert Gleichstaende sonst in
            // beliebiger Reihenfolge, CLAUDE.md "Antwortweg").
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get(['id', 'direction', 'body', 'created_at'])
            ->reverse()->values()
            ->map(fn (CustomerMessage $m) => [
                'id' => $m->id,
                'von' => $m->direction === CustomerMessage::DIRECTION_OUTGOING ? 'team' : 'sie',
                'text' => (string) $m->body,
                'zeit' => LocalTime::for($m->created_at)?->format('d.m.Y H:i'),
            ]);

        return response()->json(['nachrichten' => $nachrichten]);
    }

    private function kennung(Request $request): string
    {
        $kennung = $request->session()->get(self::SESSION_KENNUNG);
        if (! is_string($kennung) || ! Str::isUuid($kennung)) {
            $kennung = (string) Str::uuid();
            $request->session()->put(self::SESSION_KENNUNG, $kennung);
        }

        return $kennung;
    }

    private function unterhaltung(Request $request): ?Conversation
    {
        $id = $request->session()->get(self::SESSION_UNTERHALTUNG);

        return is_string($id) ? Conversation::find($id) : null;
    }

    /** Gastangaben und ggf. den Bezug aus dem Mail-Link als INTERNE Notiz. */
    private function ersteNachricht(Request $request, string $conversationId, array $daten): void
    {
        $request->session()->put(self::SESSION_UNTERHALTUNG, $conversationId);

        $conversation = Conversation::find($conversationId);
        $conversation?->forceFill(['subject' => Str::limit('Website-Chat: '.trim($daten['name']), 180)])->save();

        $zeilen = [
            'Website-Chat (Gast, Angaben UNGEPRUEFT):',
            'Name: '.trim($daten['name']),
            'Kontakt: '.trim($daten['kontakt']),
            'Datenschutzhinweis bestaetigt: '.LocalTime::for(now())?->format('d.m.Y H:i'),
        ];

        $ref = $request->session()->get(self::SESSION_REF);
        if (is_array($ref) && ($kunde = Customer::find($ref['customer_id'] ?? null)) !== null) {
            $vertrag = ! empty($ref['contract_id']) ? Contract::find($ref['contract_id']) : null;
            $zeilen[] = '';
            $zeilen[] = 'Kam ueber einen signierten Link aus einer E-Mail an Kunde '
                .($kunde->customer_number ?: '(ohne Kundennummer)')
                .($vertrag ? ', Vertrag '.($vertrag->contract_number ?: $vertrag->insurer ?: 'ohne Nummer') : '')
                .'.';
            $zeilen[] = 'NICHT automatisch zugeordnet - eine weitergeleitete Mail belegt nicht, wer schreibt. '
                .'Bitte Identitaet pruefen, bevor Vertragsdaten genannt werden.';
        }

        ConversationNote::create([
            'conversation_id' => $conversationId,
            'user_id' => null,
            'body' => implode("\n", $zeilen),
            'visibility' => ConversationNote::VISIBILITY_INTERNAL,
        ]);
    }
}
