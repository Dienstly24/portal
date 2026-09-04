<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Document;
use App\Services\Ai\Assistant\DocumentStatusReader;

/**
 * "Ist mein Dokument angekommen?" (Spezifikation Abschnitt 6/9 -
 * getDocumentStatus).
 *
 * Zwei Quellen: die Anforderungen (`document_requests`) und der
 * Dokumenteneingang (`documents`) - denn ein Kunde laedt auch ohne
 * Anforderung etwas hoch. Der Eingang wird ehrlich als "eingegangen"
 * gemeldet, nie als "geprueft" oder "akzeptiert": die inhaltliche Pruefung
 * ist Mitarbeiter-Sache (Abschnitt 18/23).
 */
class GetDocumentStatusTool implements AssistantTool
{
    public function __construct(private DocumentStatusReader $reader)
    {
    }

    public function name(): string
    {
        return 'getDocumentStatus';
    }

    public function description(): string
    {
        return 'Pruefe, welche Dokumente des angemeldeten Kunden EINGEGANGEN sind und in '
            .'welchem Pruefstand sie stehen. Nutze das, wenn der Kunde sagt, er habe '
            .'etwas hochgeladen, oder nach dem Stand eines Dokuments fragt. Ein '
            .'eingegangenes Dokument ist NICHT automatisch geprueft oder anerkannt - '
            .'sage nur, dass es eingegangen ist und geprueft wird.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        $overview = $this->reader->overview($context->customer);

        // Zuletzt vom Kunden selbst hochgeladene Dokumente (Portal-Upload).
        $uploads = Document::where('customer_id', $context->customer->id)
            ->when(
                $context->customer->user_id,
                fn ($q) => $q->where('uploaded_by', $context->customer->user_id)
            )
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'in_pruefung' => $overview['in_pruefung'],
            'fehlt' => $overview['fehlt'],
            'letzte_uploads' => $uploads->map(fn (Document $d) => [
                'dateiname' => $d->file_name,
                'art' => $d->aiTypeLabel() ?: ($d->category ?: null),
                'eingegangen_am' => $d->created_at?->lokal()->format('d.m.Y'),
                // Ehrlich: die Analyse laeuft ggf. noch, geprueft hat sie
                // niemand. Kein "akzeptiert" behaupten.
                'stand' => $d->aiInProgress() ? 'Eingegangen, wird verarbeitet' : 'Eingegangen',
            ])->values()->all(),
        ];
    }
}
