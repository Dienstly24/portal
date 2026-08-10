<?php
namespace App\Services\Ai;

use App\Services\Ai\Contracts\DocumentAiProviderInterface;

/**
 * Ausdruecklich DEAKTIVIERTER KI-Anbieter der Dokumentanalyse. Wird ueber
 * AI_DOCUMENT_PROVIDER=none|off|disabled ausgewaehlt, wenn der Betrieb die
 * kostenpflichtige KI-Stufe bewusst abschalten will - die kostenlose
 * Basisebene (PDF-Textebene, Vorlagen-Parser, OCR) laeuft weiter.
 *
 * isEnabled() ist immer false; DocumentAnalyzer ueberspringt die
 * KI-Eskalation dann genau wie bei fehlendem API-Key. So ist der env-Schalter
 * ein ECHTER Schalter (frueher fiel jeder unbekannte Wert still auf Claude
 * zurueck - der Schalter konnte nichts abschalten).
 */
class NullDocumentAiProvider implements DocumentAiProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function model(): string
    {
        return 'none';
    }

    public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
    {
        return null;
    }
}
