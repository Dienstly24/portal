<?php
namespace App\Services\Mailbox;

use App\Models\Document;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Audit-Fix H1: Anhänge eingehender Mails werden IMMER zuerst nur als
 * Dateien unter email_attachments/<message_id>/ abgelegt (Meta am
 * EmailMessage-Datensatz). Ein Document in der Kundenakte entsteht
 * erst, wenn die Kundenzuordnung BESTÄTIGT ist (auto >90% oder
 * Mitarbeiter-Bestätigung im Posteingang) - nie bei bloßen Vorschlägen.
 */
class EmailAttachmentService
{
    private const DISK = 'local';

    public function __construct(private readonly AttachmentAnalysisService $analysis)
    {
    }

    /**
     * Rohdateien speichern und Meta am Message-Datensatz hinterlegen.
     * Muss VOR der Workflow-Verarbeitung laufen, damit Analyse-Stufen
     * (z. B. PDF-Auswertung) auf die Dateien zugreifen können.
     *
     * @param array<int, array{filename: string, mime: string, content: string}> $attachments
     */
    public function storeFiles(EmailMessage $message, array $attachments): void
    {
        if (empty($attachments)) {
            return;
        }

        $meta = [];
        foreach ($attachments as $attachment) {
            $path = 'email_attachments/' . $message->id . '/' . Str::random(8) . '_' . $this->sanitizeFilename($attachment['filename']);
            Storage::disk(self::DISK)->put($path, $attachment['content']);
            $meta[] = [
                'filename' => $attachment['filename'],
                'mime' => $attachment['mime'],
                'path' => $path,
                'size' => strlen($attachment['content']),
                'document_id' => null, // wird bei Übernahme in die Akte gesetzt
            ];
        }

        $message->forceFill(['attachments_meta' => $meta])->save();
    }

    /**
     * Übernahme in die Kundenakte - ausschließlich bei bestätigter
     * Zuordnung. Idempotent: bereits übernommene Einträge (document_id
     * gesetzt) werden übersprungen.
     */
    public function createDocuments(EmailMessage $message): void
    {
        if ($message->match_status !== 'confirmed' || $message->customer_id === null) {
            return;
        }

        $meta = $message->attachments_meta ?? [];
        $changed = false;

        foreach ($meta as $i => $entry) {
            if (!empty($entry['document_id'])) {
                continue;
            }
            if (!Storage::disk(self::DISK)->exists($entry['path'])) {
                continue; // Datei bereits bereinigt - nichts zu übernehmen
            }

            // Phase 2 (Dokumentenerkennung): Kategorie aus Dateiname +
            // PDF-Textanfang bestimmen; kein Treffer bleibt 'other'.
            $pdfText = str_contains(mb_strtolower($entry['mime'] ?? ''), 'pdf')
                ? app(PdfTextExtractor::class)->extractFromStorage(self::DISK, $entry['path'])
                : null;

            $document = Document::create([
                'customer_id' => $message->customer_id,
                'category' => $this->analysis->categorize($entry['filename'], $pdfText),
                'file_name' => $entry['filename'],
                'file_path' => $entry['path'],
                'disk' => self::DISK,
                'visibility' => 'internal', // Sichtbarkeit für Kunden entscheidet weiterhin ein Mitarbeiter
                'file_size' => $entry['size'] ?? null,
            ]);

            $meta[$i]['document_id'] = (string) $document->id;
            $changed = true;
        }

        if ($changed) {
            $message->forceFill(['attachments_meta' => $meta])->save();
        }
    }

    /**
     * Phase 2 (automatische Vorgangs-Zuordnung): bereits übernommene
     * Dokumente dieser Mail zusätzlich einem Vertrag zuordnen -
     * genutzt vom Fonds-Finanz-Import nach Vertragsanlage/-update.
     */
    public function linkDocumentsToContract(EmailMessage $message, \App\Models\Contract $contract): void
    {
        foreach ($message->attachments_meta ?? [] as $entry) {
            if (!empty($entry['document_id'])) {
                Document::where('id', $entry['document_id'])
                    ->whereNull('contract_id')
                    ->update(['contract_id' => $contract->id]);
            }
        }
    }

    /**
     * Physische Dateien einer Mail entfernen (DSGVO-Bereinigung,
     * Kundenlöschung). Bereits in die Akte übernommene Dateien werden
     * über die Document-Löschung behandelt, nicht hier doppelt.
     */
    public function deleteFiles(EmailMessage $message): void
    {
        Storage::disk(self::DISK)->deleteDirectory('email_attachments/' . $message->id);
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'anhang';
    }
}
