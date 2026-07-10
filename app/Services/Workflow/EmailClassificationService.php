<?php
namespace App\Services\Workflow;

use App\Models\EmailMessage;

/**
 * Regelbasierte E-Mail-Kategorisierung (Architekturplan Abschnitt 4,
 * Stufe 1). Deterministisch und ohne Modellaufruf - reicht für den
 * Großteil der Post (Absenderdomain, Betreff-Schlüsselwörter). Eine
 * KI-gestützte zweite Stufe für uneindeutige Fälle ist als Ausbaustufe
 * geplant (Abschnitt 12), aber bewusst nicht Teil dieser Klasse, damit
 * die Regel-Pipeline unabhängig von Modellverfügbarkeit/-kosten bleibt.
 */
class EmailClassificationService
{
    /** @var array<string, string[]> */
    private const KEYWORDS = [
        'fonds_finanz' => ['fonds finanz', 'fondsfinanz'],
        'versicherung' => ['versicherung', 'police', 'schaden', 'schadenmeldung', 'deckung', 'vertrag benötigt', 'unterlagen einreichen'],
        'energie' => ['energie', 'strom', 'gas-', 'gasvertrag', 'netzbetreiber', 'zählerstand', 'stromvertrag'],
        'provisionen' => ['provision', 'gutschrift', 'courtage', 'abrechnung'],
        'dokumente' => ['dokument', 'anlage', 'unterlage', 'anhang'],
        'kundenanfrage' => ['anfrage', 'frage', 'hilfe', 'problem', 'kündigung', 'kündigen'],
    ];

    public function classify(EmailMessage $message): string
    {
        $haystack = mb_strtolower(($message->subject ?? '') . ' ' . ($message->body_text ?? ''));

        $domain = $this->domain($message->from_address);
        if (str_contains($domain, 'fondsfinanz')) {
            // Auch die Fonds Finanz schickt Provisionsabrechnungen - die
            // gehören in den Provisions-Workflow, nicht in den Vertragsimport.
            foreach (self::KEYWORDS['provisionen'] as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return 'provisionen';
                }
            }
            return 'fonds_finanz';
        }

        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $category;
                }
            }
        }

        return 'sonstige';
    }

    private function domain(string $email): string
    {
        return mb_strtolower((string) (explode('@', $email)[1] ?? ''));
    }
}
