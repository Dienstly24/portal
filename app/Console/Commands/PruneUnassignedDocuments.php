<?php
namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * DSGVO-Löschkonzept fuer den Dokumenten-Eingang (Smart Document Upload),
 * analog zu emails:prune-unmatched: Dokumente ohne Kundenzuordnung duerfen
 * nicht unbegrenzt gespeichert werden - sie tragen (verschluesselt) KI-
 * extrahierte personenbezogene Daten. Standard-Aufbewahrung 90 Tage,
 * aenderbar ueber SystemSetting 'document_inbox_retention_days'.
 * Zugeordnete Dokumente (Teil der Kundenakte) sind NICHT betroffen.
 */
class PruneUnassignedDocuments extends Command
{
    protected $signature = 'documents:prune-unassigned {--dry-run : Nur zaehlen, nichts loeschen}';
    protected $description = 'Loescht nicht zugeordnete Eingangs-Dokumente nach Ablauf der Aufbewahrungsfrist (DSGVO)';

    public function handle(): int
    {
        $days = (int) (SystemSetting::get('document_inbox_retention_days') ?: 90);
        if ($days < 1) {
            $this->warn('document_inbox_retention_days < 1 – Bereinigung uebersprungen.');
            return self::SUCCESS;
        }

        $query = Document::inbox()->where('created_at', '<', now()->subDays($days));
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("$count nicht zugeordnete Dokument(e) aelter als $days Tage (dry-run, nichts geloescht).");
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($query->cursor() as $document) {
            try {
                Storage::disk($document->disk ?: 'local')->delete($document->file_path);
            } catch (\Throwable $e) {
                \Log::warning('Eingangs-Dokumentdatei nicht entfernbar: ' . $document->file_path);
            }
            // Anders als bei einer Kundenloeschung gibt es hier keinen
            // Kunden-Bezug, der einen Audit-Trail rechtfertigt - das
            // KI-Entscheidungsprotokoll wird mit geloescht, nicht nur redigiert.
            $document->aiDecisions()->delete();
            $document->delete();
            $deleted++;
        }

        if ($deleted > 0) {
            ActivityLog::create([
                'user_id' => null,
                'action' => 'documents_pruned',
                'entity_type' => 'document',
                'entity_id' => null,
                'meta' => json_encode(['deleted' => $deleted, 'retention_days' => $days], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->info("$deleted nicht zugeordnete(s) Dokument(e) geloescht (Aufbewahrung: $days Tage).");
        return self::SUCCESS;
    }
}
