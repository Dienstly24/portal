<?php
namespace App\Services\Ai\Assistant\Tools;

use App\Models\ActivityLog;
use App\Models\DocumentRequest;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\Ai\Assistant\DocumentStatusReader;
use Illuminate\Support\Str;

/**
 * Dokumentenanforderung erzeugen (Spezifikation Abschnitt 8 -
 * requestDocument).
 *
 * Der Assistent schreibt damit nicht nur Text: es entsteht eine echte
 * `DocumentRequest` - dieselbe Tabelle, die die Mitarbeiter nutzen. Dadurch
 * zeigt das Portal dem Kunden automatisch den passenden Upload-Bereich, und
 * ein Upload wird ueber den bestehenden Weg
 * (PortalController::documentRequestUpload) dem richtigen Kunden UND Vorgang
 * zugeordnet - ohne neue Logik.
 *
 * Duplikat-Schutz: derselbe Nachweis wird nie zweimal angefordert.
 * Die inhaltliche PRUEFUNG des Dokuments bleibt Mitarbeiter-Sache
 * (Abschnitt 18/23) - der Assistent fordert nur an.
 */
class RequestDocumentTool implements AssistantTool
{
    public function __construct(
        private AssistantSettings $settings,
        private DocumentStatusReader $reader,
    ) {
    }

    public function name(): string
    {
        return 'requestDocument';
    }

    public function description(): string
    {
        return 'Fordert ein bestimmtes Dokument beim angemeldeten Kunden an (z.B. '
            . 'Meldebescheinigung, Personalausweis, Kontonachweis). Dadurch erscheint im '
            . 'Kundenportal der passende Upload-Bereich. Nutze das nur, wenn das Dokument '
            . 'fuer den Vorgang wirklich benoetigt wird und noch nicht angefordert ist '
            . '(pruefe zuerst getMissingDocuments).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dokument' => [
                    'type' => 'string',
                    'description' => 'Bezeichnung des benoetigten Dokuments, z.B. "Meldebescheinigung".',
                ],
                'hinweis' => [
                    'type' => 'string',
                    'description' => 'Kurzer Hinweis fuer den Kunden, warum bzw. was genau benoetigt wird.',
                ],
                'vorgangsnummer' => [
                    'type' => 'string',
                    'description' => 'Optional: Vorgang, zu dem das Dokument gehoert (Format T-JJ#####).',
                ],
            ],
            'required' => ['dokument'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        if (!$this->settings->autoDocumentRequest()) {
            return [
                'angefordert' => false,
                'hinweis' => 'Die automatische Dokumentenanforderung ist abgeschaltet. Bitte den '
                    . 'Kunden, das Dokument im Portal hochzuladen, und uebergib mit '
                    . 'escalateToTeam an das Team.',
            ];
        }

        $title = trim((string) ($arguments['dokument'] ?? ''));
        if ($title === '') {
            return ['angefordert' => false, 'hinweis' => 'Es wurde kein Dokument benannt.'];
        }

        // Duplikat-Schutz: schon offen oder gerade in Pruefung?
        $existing = $this->reader->findOpenRequestByTitle($context->customer, $title);
        if ($existing) {
            return [
                'angefordert' => false,
                'bereits_angefordert' => $existing->title,
                'status' => $existing->statusLabel(),
                'hinweis' => 'Dieses Dokument ist bereits angefordert. Verweise den Kunden auf den '
                    . 'vorhandenen Upload-Bereich statt es erneut anzufordern.',
            ];
        }

        // Hinweistext fuer den Kunden. Nennt das Modell einen Vorgang, wird
        // er nur uebernommen, wenn er wirklich diesem Kunden gehoert - eine
        // fremde Nummer wird stillschweigend ignoriert, nie angezeigt.
        $note = trim((string) ($arguments['hinweis'] ?? ''));
        $ticketNumber = trim((string) ($arguments['vorgangsnummer'] ?? ''));
        if ($ticketNumber !== '') {
            $ticket = $context->customer->tickets()
                ->where('ticket_number', $ticketNumber)
                ->first();
            if ($ticket) {
                $note = trim($note . ' (Vorgang ' . $ticket->ticket_number . ')');
            }
        }

        $documentRequest = DocumentRequest::create([
            'customer_id' => $context->customer->id,
            'title' => Str::limit($title, 200),
            'description' => $note !== '' ? $note : null,
            'status' => 'open',
            // requested_by bleibt leer: angefordert hat der Assistent, kein
            // Mitarbeiter. Ehrlicher als einen Kollegen einzutragen, der
            // nichts davon weiss.
            'requested_by' => null,
        ]);

        ActivityLog::create([
            'user_id' => null,
            'action' => 'ai_document_request_created',
            'entity_type' => 'document_request',
            'entity_id' => $documentRequest->id,
            'meta' => json_encode([
                'customer_id' => (string) $context->customer->id,
                'title' => $documentRequest->title,
                'source' => 'ai_assistant',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $context->recordAction('document_requested', [
            'title' => $documentRequest->title,
            'document_request_id' => (string) $documentRequest->id,
        ]);

        return [
            'angefordert' => true,
            'dokument' => $documentRequest->title,
            'hinweis' => 'Der Upload-Bereich ist im Kundenportal unter "Dokumente" sichtbar. '
                . 'Bitte den Kunden, das Dokument dort hochzuladen.',
        ];
    }
}
