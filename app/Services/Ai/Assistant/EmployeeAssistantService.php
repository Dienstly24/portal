<?php

namespace App\Services\Ai\Assistant;

use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use Illuminate\Support\Str;

/**
 * Der Assistent fuer MITARBEITER (Spezifikation Abschnitte 2C, 15 und 16).
 *
 * Zwei Arten von Hilfe, bewusst getrennt:
 *
 *  1. FAKTEN (Zusammenfassung, fehlende Angaben, naechster Schritt,
 *     Anliegen) - deterministisch aus den gespeicherten Daten. Kostet
 *     nichts, ist immer verfuegbar, kann nicht halluzinieren. Genau das
 *     braucht ein Mitarbeiter, der eine Unterhaltung uebernimmt: er muss
 *     sich darauf VERLASSEN koennen.
 *
 *  2. ANTWORTVORSCHLAG - der einzige Punkt, an dem ein Modell gefragt
 *     wird, weil Formulieren tatsaechlich Sprachaufgabe ist. Der
 *     Vorschlag wird NIE automatisch gesendet: der Mitarbeiter liest,
 *     aendert und schickt selbst.
 */
class EmployeeAssistantService
{
    public function __construct(
        private AssistantProviderInterface $provider,
        private KnowledgeBase $knowledge,
        private DocumentStatusReader $documents,
    ) {
    }

    /**
     * Alles, was der Mitarbeiter beim Uebernehmen sofort sehen muss
     * (Abschnitt 15) - ohne den Chat zu lesen.
     */
    public function briefing(Customer $customer, AiConversation $conversation): array
    {
        $sicht = new ConversationContext($conversation, $customer);
        $stufe = $sicht->stage();
        $fortschritt = $sicht->progress($stufe);

        $bekannt = [];
        foreach (RequirementProfile::fields($conversation->intent) as $feld) {
            $wert = $sicht->known()[$feld['key']] ?? null;
            if ($wert === null || trim((string) $wert) === '') {
                continue;
            }
            // Sensible Angaben erscheinen auch dem Mitarbeiter nur als
            // "liegt vor" (Abschnitt 22): die Kundenakte ist der Ort fuer
            // Bankdaten, nicht ein Chat-Seitenpanel.
            $bekannt[$feld['label']] = RequirementProfile::isSensitive($feld['key'])
                ? 'liegt vor'
                : (string) $wert;
        }

        $angebot = $conversation->selectedOffer;

        return [
            'kundenart' => $customer->created_at?->gt(now()->subDays(30))
                ? 'Neukunde'
                : 'Bestandskunde',
            'anliegen' => RequirementProfile::intentLabel($conversation->intent),
            'kategorie' => $conversation->category,
            'zustand' => ConversationState::label($conversation->state),
            'wartet_auf_mitarbeiter' => ConversationState::waitsForStaff($conversation->state),
            'bekannt' => $bekannt,
            'fehlend' => array_map(fn ($f) => $f['label'], $sicht->missing($stufe)),
            'fortschritt' => $fortschritt['erledigt'].'/'.$fortschritt['gesamt'],
            'angebot' => $angebot?->summary(),
            'kunde_hat_zugestimmt' => $angebot?->isSelected() ?? false,
            'pruefung' => $conversation->verification_status,
            'naechster_schritt' => $conversation->next_action
                ?: ConversationState::nextAction($conversation->state),
            'stoerung' => $conversation->isPaused() ? [
                'grund' => $conversation->paused_reason,
                'letzter_schritt' => $conversation->last_successful_step,
                'aktueller_schritt' => $conversation->current_step,
                'zeitpunkt' => $conversation->last_error_at?->lokal()->format('d.m.Y H:i'),
            ] : null,
            'fehlende_dokumente' => array_map(
                fn ($d) => (string) ($d['titel'] ?? '?'),
                $this->documents->overview($customer)['fehlt']
            ),
        ];
    }

    /**
     * Antwortvorschlag fuer den Mitarbeiter (Abschnitt 16).
     *
     * Der Vorschlag stuetzt sich auf den Gespraechsstand und die
     * freigegebene Wissensbasis - inklusive der Leitfaden-Eintraege, in
     * denen der Betrieb seinen Stil und seine Ablaeufe hinterlegt
     * (Abschnitt 17). Es wird NICHT auf Mitarbeiter-Chats trainiert.
     *
     * @return array{vorschlag: ?string, fehler: ?string}
     */
    public function suggestReply(Customer $customer, AiConversation $conversation): array
    {
        if (! $this->provider->isEnabled()) {
            return ['vorschlag' => null, 'fehler' => 'Kein KI-Anbieter konfiguriert.'];
        }

        $letzte = CustomerMessage::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->sortBy('created_at');

        $letzteKundenfrage = $letzte->last(fn ($m) => ! $m->from_staff);
        if (! $letzteKundenfrage) {
            return ['vorschlag' => null, 'fehler' => 'Es liegt keine Kundennachricht vor.'];
        }

        $briefing = $this->briefing($customer, $conversation);
        $leitfaden = $this->knowledge->search('Gesprächsleitfaden Stil Ablauf', null, 3)
            ->map(fn ($e) => '- '.$e->title.': '.Str::limit($e->content, 400))
            ->implode("\n");

        $verlauf = $letzte->map(fn ($m) => ($m->from_staff ? 'Team: ' : 'Kunde: ')
            .Str::limit(trim((string) $m->body), 400))->implode("\n");

        $stand = json_encode([
            'anliegen' => $briefing['anliegen'],
            'zustand' => $briefing['zustand'],
            'bekannt' => $briefing['bekannt'],
            'fehlend' => $briefing['fehlend'],
            'angebot' => $briefing['angebot'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $anweisung = <<<PROMPT
Du hilfst einem MITARBEITER von Dienstly24 beim Formulieren einer Antwort
an einen Kunden. Du schreibst NICHT an den Kunden - der Mitarbeiter liest
deinen Vorschlag, aendert ihn und sendet ihn selbst.

REGELN:
- Nutze ausschliesslich die unten stehenden Angaben. Erfinde nichts:
  keine Preise, keine Fristen, keine Zusagen, keine Vertragsaussagen.
- Fehlt etwas fuer eine belastbare Antwort, schreibe stattdessen eine
  Rueckfrage an den Kunden.
- Bestaetige NIE, ob eine Kundenangabe mit unseren gespeicherten Daten
  uebereinstimmt.
- Zwei bis fuenf Saetze, "Sie"-Anrede, freundlich und konkret.
- Antworte NUR mit dem Vorschlagstext, ohne Anrede an den Mitarbeiter,
  ohne Ueberschrift, ohne Erklaerung.

GESPRAECHSSTAND:
{$stand}

LEITFAeDEN DES BETRIEBS (falls vorhanden):
{$leitfaden}

LETZTE NACHRICHTEN:
{$verlauf}
PROMPT;

        try {
            $turn = $this->provider->turn(
                $anweisung,
                [['role' => 'user', 'text' => 'Bitte formuliere den Antwortvorschlag.']],
                [],
                500,
            );
        } catch (\Throwable $e) {
            return ['vorschlag' => null, 'fehler' => Str::limit($e->getMessage(), 200)];
        }

        $text = trim($turn->text);

        return $text === ''
            ? ['vorschlag' => null, 'fehler' => 'Der Dienst hat keinen Vorschlag geliefert.']
            : ['vorschlag' => $text, 'fehler' => null];
    }
}
