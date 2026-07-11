<?php
namespace App\Services\Mailbox;

use App\Models\EmailMessage;

/**
 * Analyse gespeicherter Anhänge (Phase 2): Text aus PDF-Anhängen für
 * die Fach-Parser bereitstellen und Dokument-Kategorien erkennen.
 * Nutzt ausschließlich die BESTEHENDEN Document-Kategorien - keine
 * neue Parallel-Taxonomie.
 */
class AttachmentAnalysisService
{
    /** @var array<string, string[]> Document-Kategorie => Schlüsselwörter (Dateiname + Textanfang, lowercase) */
    private const CATEGORY_KEYWORDS = [
        'police' => ['police', 'versicherungsschein', 'policennummer'],
        'invoice' => ['rechnung', 'invoice', 'gutschrift', 'abrechnung'],
        'contract' => ['vertrag', 'antrag', 'contract'],
        'claim' => ['schaden', 'schadenmeldung', 'schadennummer'],
        'identity' => ['ausweis', 'personalausweis', 'reisepass'],
    ];

    public function __construct(private readonly PdfTextExtractor $pdf)
    {
    }

    /**
     * Gesamter extrahierbarer Text aller PDF-Anhänge einer Mail -
     * Eingabe für die bestehenden Label-Parser (Fonds Finanz,
     * Provisionen), wenn der Mail-Text selbst nichts hergibt.
     */
    public function textFromPdfAttachments(EmailMessage $message): string
    {
        $texts = [];
        foreach ($message->attachments_meta ?? [] as $entry) {
            if (!str_contains(mb_strtolower($entry['mime'] ?? ''), 'pdf')) {
                continue;
            }
            $text = $this->pdf->extractFromStorage('local', $entry['path']);
            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Dokument-Kategorie aus Dateiname + Textanfang bestimmen.
     * Kein Treffer -> 'other' (bestehender Standard), nie geraten.
     */
    public function categorize(string $filename, ?string $text = null): string
    {
        $haystack = mb_strtolower($filename . ' ' . mb_substr((string) $text, 0, 2000));

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}
