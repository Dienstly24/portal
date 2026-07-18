<?php
namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * KI-Analyse eines hochgeladenen Dokuments (Smart Document Upload).
 * Ablauf: Datei an Claude (Vision/PDF) -> validiertes Ergebnis ->
 * Dokument kategorisieren + Daten speichern -> Kunde/Vertrag verknuepfen.
 *
 * Zuordnungs-Regeln:
 * - Dokument mit Kunde (Portal-Upload / Upload in der Kundenakte):
 *   nur Vertrag verknuepfen (Vertragsnummer/Kennzeichen), nie den
 *   Kunden wechseln.
 * - Eingangs-Dokument ohne Kunde: Matching; nur bei eindeutigem
 *   Treffer (Score > 90) automatische Zuordnung, sonst Vorschlag fuer
 *   die Mitarbeiter-Review (Freigabe-Gateway wie bei ai_decisions).
 */
class AnalyzeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;
    public int $backoff = 45;

    public function __construct(public string $documentId) {}

    public function handle(DocumentAnalyzer $analyzer, DocumentIntakeService $intake): void
    {
        // Atomarer Uebernahme-Claim (pending -> processing): verhindert eine
        // doppelte, kostenpflichtige Analyse, wenn ein freigegebener Retry-
        // Job und ein vom Scheduler (AnalyzePendingDocuments) erneut
        // angestossener Job gleichzeitig in der Queue liegen - z.B. nach
        // laengerem Worker-Ausfall. Nur der zuerst laufende Job gewinnt
        // diesen Uebergang; jeder weitere findet den Status bereits auf
        // 'processing' vor und beendet sich folgenlos.
        $claimed = Document::whereKey($this->documentId)
            ->where('ai_status', 'pending')
            ->update(['ai_status' => 'processing']);
        if (!$claimed) {
            return;
        }

        $document = Document::find($this->documentId);
        if (!$document) {
            return;
        }
        if (!$analyzer->isEnabled()) {
            $document->update(['ai_status' => 'none']);
            return;
        }

        try {
            $result = $analyzer->analyze($document);
        } catch (\Throwable $e) {
            // Auf echten Queues (database) einmal erneut versuchen; im
            // Sync-Betrieb (Tests, QUEUE_CONNECTION=sync) sofort als
            // Fehler markieren statt die HTTP-Antwort zu zerstoeren.
            $isSync = $this->job === null || $this->job->getConnectionName() === 'sync';
            if (!$isSync && $this->attempts() < $this->tries) {
                $document->update(['ai_status' => 'pending']);
                $this->release($this->backoff);
                return;
            }
            $this->markFailed($document, $e->getMessage());
            return;
        }

        if ($result === null) {
            $this->markFailed($document, 'Keine verwertbare Analyse-Antwort.');
            return;
        }

        $extracted = $result['data'];

        // Eingangs-Dokument: Kunden-Matching (Vorschlag oder Auto-Zuordnung).
        $match = null;
        if (!$document->customer_id) {
            $match = $intake->findMatch($extracted);
            $extracted['match'] = $match;
        }

        $updates = [
            'ai_status' => 'done',
            'ai_type' => $result['type'],
            'ai_confidence' => $result['confidence'],
            'ai_source' => $result['source'] ?? 'ai',
            'ai_summary' => $result['summary'],
            'ai_extracted' => $extracted,
            'ai_error' => null,
            'ai_processed_at' => now(),
        ];

        // Kategorie nur setzen, wenn keine bewusst gewaehlte vorliegt
        // (Smart-Uploads starten mit 'other').
        if ($document->category === 'other') {
            $updates['category'] = Document::AI_TYPES[$result['type']]['category'];
        }

        // Scans bekommen den erkannten Titel als sprechenden Dateinamen.
        if ($result['title'] && str_starts_with($document->file_name, 'Scan ')) {
            $updates['file_name'] = $this->safeFileName($result['title']) . '.pdf';
        }

        $document->update($updates);

        // Freigabe-Protokoll wie bei der E-Mail-Klassifikation.
        $disk = $document->disk ?: 'local';
        AiDecision::create([
            'document_id' => $document->id,
            'skill' => DocumentAnalyzer::SKILL,
            'model' => $analyzer->model(),
            'input_hash' => Storage::disk($disk)->exists($document->file_path)
                ? hash('sha256', Storage::disk($disk)->get($document->file_path))
                : hash('sha256', $document->id),
            'output' => [
                'type' => $result['type'],
                'confidence' => $result['confidence'],
                'summary' => $result['summary'],
                'match' => $match,
            ],
            'confidence' => $result['confidence'],
            'status' => 'suggested',
        ]);

        if ($document->customer_id) {
            $customer = Customer::find($document->customer_id);
            if ($customer) {
                $intake->linkMatchingContract($document, $customer);
            }
        } elseif ($match && $match['tier'] === 'auto') {
            $customer = Customer::find($match['customer_id']);
            if ($customer && $intake->assignToCustomer($document, $customer, $document->uploaded_by, auto: true)) {
                $intake->linkMatchingContract($document, $customer);
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if ($document && in_array($document->ai_status, ['pending', 'processing'], true)) {
            $this->markFailed($document, $e?->getMessage() ?? 'Unbekannter Fehler.');
        }
    }

    private function markFailed(Document $document, string $message): void
    {
        Log::warning('Dokument-Analyse fehlgeschlagen (' . $document->id . '): ' . $message);
        $document->update([
            'ai_status' => 'failed',
            'ai_error' => mb_substr($message, 0, 300),
        ]);
    }

    /** Erkannten Titel in einen gefahrlosen Dateinamen umwandeln. */
    private function safeFileName(string $title): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N} ._-]/u', '', $title));
        return $clean !== '' ? mb_substr($clean, 0, 80) : 'Dokument';
    }
}
