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
    /** Max. Mails pro Sync-Lauf und Postfach (muss zum Provider-Default passen). */
    private const FETCH_LIMIT = 50;

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
        try {
            $messages = $this->providerFactory->make($account)->fetchNewMessages($account, self::FETCH_LIMIT);
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);
            return 0;
        }

        $stored = 0;
        $maxReceived = null; // hoechstes tatsaechlich verarbeitetes Empfangsdatum
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

        // Wasserstandsmarke nur bis zum hoechsten TATSAECHLICH verarbeiteten
        // Empfangsdatum vorruecken statt bedingungslos auf now() (Audit INT-4):
        // Wurde die Fetch-Grenze erreicht und blieben aeltere Mails ungeholt,
        // fischt der naechste Lauf sie ueber die -1h-Marge wieder ein, statt sie
        // dauerhaft zu verlieren. Nur wenn ein voller Batch kam (== Limit) und
        // damit weitere Mails moeglich sind, halten wir bei $maxReceived; sonst
        // ist das Postfach geleert und wir duerfen bis now() gehen.
        $watermark = ($maxReceived && count($messages) >= self::FETCH_LIMIT)
            ? $maxReceived
            : now();
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
