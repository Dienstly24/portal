<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Workflow\EmailWorkflowService;

/**
 * Orchestriert den kompletten Zyklus je Postfach (Architekturplan
 * Abschnitt 3/4): abrufen -> roh speichern (inkl. Anhang-DATEIEN) ->
 * Kategorisierung/Matching (EmailWorkflowService) -> Dokumente in der
 * Kundenakte entstehen erst bei BESTÄTIGTER Zuordnung (Audit-Fix H1:
 * ein bloßer 70-90%-Vorschlag legt nichts in eine Akte; bestätigt der
 * Mitarbeiter später im Posteingang, übernimmt der EmailInboxController
 * die Anhänge über denselben EmailAttachmentService).
 */
class MailboxSyncService
{
    /** Max. Mails pro Fetch-Runde und Postfach (muss zum Provider-Default passen). */
    private const FETCH_LIMIT = 50;

    /** Sicherheitskappe fuer die Pagination je Lauf (max. 20*50 = 1000 Mails). */
    private const MAX_SYNC_ROUNDS = 20;

    public function __construct(
        private readonly MailboxProviderFactory $providerFactory,
        private readonly EmailWorkflowService $workflow,
        private readonly EmailAttachmentService $attachments,
    ) {
    }

    /** @return array<string, int> Anzahl neu gespeicherter Mails je Postfach */
    public function syncAllActive(): array
    {
        $results = [];
        foreach (EmailAccount::where('is_active', true)->get() as $account) {
            $results[$account->email_address] = $this->syncAccount($account);
        }
        return $results;
    }

    public function syncAccount(EmailAccount $account): int
    {
        $stored = 0;
        $overallMax = null; // hoechstes ueber alle Runden verarbeitetes Empfangsdatum
        $drained = false;   // true, sobald ein Batch < Limit kam (Postfach leer)
        $rounds = 0;

        // Pagination innerhalb EINES Laufs (Audit INT-4 / Re-Audit): bei vollem
        // Batch wird der Cursor (last_synced_at) im Speicher auf das juengste
        // verarbeitete Datum vorgerueckt und weitergeholt, bis das Postfach
        // geleert ist oder das Rundenlimit greift. Ohne diese Schleife konnte
        // die Marke unter Dauerlast (>=Limit Mails je -1h-Fenster) stehenbleiben
        // und neuere Mails jenseits des Limits nie geholt werden.
        do {
            $rounds++;
            try {
                $messages = $this->providerFactory->make($account)->fetchNewMessages($account, self::FETCH_LIMIT);
            } catch (\Throwable $e) {
                $account->update(['last_error' => $e->getMessage()]);
                return $stored;
            }

        $maxReceived = null; // hoechstes tatsaechlich verarbeitetes Empfangsdatum dieser Runde
        foreach ($messages as $data) {
            // Kundenseitiges Import-Postfach (Variante A): nur Mails mit
            // gueltigem Einwilligungs-Token UND erlaubter Absenderdomain
            // werden ueberhaupt gespeichert. Alles andere (fremde/private
            // Weiterleitung) wird sofort verworfen - Data Minimization.
            $importCustomer = null;
            if ($account->is_customer_import) {
                $importCustomer = $this->importService()->resolveConsentingCustomer($data);
                if ($importCustomer === null || !$this->importService()->isAllowedSender($data->fromAddress)) {
                    continue;
                }
            }

            $message = EmailMessage::firstOrCreate(
                ['email_account_id' => $account->id, 'message_uid' => $data->uid],
                [
                    'from_address' => $data->fromAddress,
                    'from_name' => $data->fromName,
                    'to_address' => $data->toAddress,
                    'subject' => $data->subject,
                    'body_text' => $data->bodyText,
                    'body_html' => $data->bodyHtml,
                    'received_at' => $data->receivedAt,
                    'raw_headers' => $data->headers,
                ]
            );

            // Empfangsdatum aller GESEHENEN (auch bereits bekannten) Mails
            // beruecksichtigen, damit die Wasserstandsmarke nicht ueber noch
            // ungeholte Mails hinausspringt (Audit INT-4).
            if ($data->receivedAt && (!$maxReceived || $data->receivedAt->gt($maxReceived))) {
                $maxReceived = $data->receivedAt->copy();
            }

            if (!$message->wasRecentlyCreated) {
                continue; // bereits bekannt (Unique-Constraint email_account_id+message_uid) - kein Duplikat verarbeiten
            }

            $stored++;

            // Dateien VOR der Verarbeitung sichern, damit Analyse-Stufen
            // (PDF-Auswertung) darauf zugreifen können - aber noch ohne
            // Kundenakte-Bezug.
            $this->attachments->storeFiles($message, $data->attachments);

            if ($importCustomer !== null) {
                // Kunde steht durch das Token DETERMINISTISCH fest - kein
                // Score-Matching, direkt bestaetigte Zuordnung.
                $this->workflow->processForCustomer($message, $importCustomer);
            } else {
                $this->workflow->process($message);
            }

            // In die Akte übernehmen NUR bei bestätigter Zuordnung (H1).
            $this->attachments->createDocuments($message->fresh());
        }

            if ($maxReceived && (!$overallMax || $maxReceived->gt($overallMax))) {
                $overallMax = $maxReceived;
            }

            $full = count($messages) >= self::FETCH_LIMIT;
            // Nur weiterpaginieren, wenn ein voller Batch kam UND der Cursor
            // echt vorwaerts wandert (sonst Endlosschleife). Andernfalls Abbruch:
            // $drained = true, falls der Batch < Limit war (Postfach leer).
            if ($full && $maxReceived && (!$account->last_synced_at || $maxReceived->gt($account->last_synced_at))) {
                $account->last_synced_at = $maxReceived; // In-Memory-Cursor vorruecken
            } else {
                $drained = !$full;
                break;
            }
        } while ($rounds < self::MAX_SYNC_ROUNDS);

        // Geleert -> bis now(); sonst (Rundenlimit/kein Fortschritt) beim
        // hoechsten verarbeiteten Empfangsdatum halten, damit der Rest beim
        // naechsten Lauf ueber die -1h-Marge folgt (kein Verlust).
        $watermark = $drained ? now() : ($overallMax ?? now());
        $account->update(['last_synced_at' => $watermark, 'last_error' => null]);

        return $stored;
    }

    /**
     * Lazy aufgeloest, damit der bestehende 3-Argumente-Konstruktor (und
     * die darauf aufbauenden Tests) unveraendert bleibt.
     */
    private function importService(): CustomerMailboxImportService
    {
        return app(CustomerMailboxImportService::class);
    }
}
