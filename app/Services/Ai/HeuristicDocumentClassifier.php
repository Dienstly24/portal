<?php
namespace App\Services\Ai;

use App\Models\Document;
use App\Services\Ai\Concerns\ValidatesExtractedFields;

/**
 * Kostenloser Fallback OHNE KI-Anbieter: einfache Stichwort-Erkennung des
 * Dokumenttyps und punktuelle Regex-Extraktion (IBAN, Kennzeichen, FIN,
 * E-Mail) auf dem Tesseract-OCR-Text.
 *
 * Bewusst konservativ, wie die KI-Validierung: Ein Feld wird nur
 * uebernommen, wenn es aus einer klar abgegrenzten Textzeile stammt -
 * sonst lieber leer lassen als durch zusammengewuerfelten Freitext
 * falsche Kundendaten erzeugen. Entsprechend niedrig ist die Konfidenz
 * (max. 40 von 100) im Vergleich zur Vision-Analyse eines KI-Anbieters -
 * das Ergebnis ist ein Ausgangspunkt fuer die Mitarbeiter-Pruefung, keine
 * verlaessliche automatische Erkennung.
 */
class HeuristicDocumentClassifier
{
    use ValidatesExtractedFields;

    private const KEYWORDS = [
        'fuehrerschein' => ['FÜHRERSCHEIN', 'FAHRERLAUBNIS', 'DRIVING LICENCE'],
        'personalausweis' => ['PERSONALAUSWEIS', 'IDENTITY CARD'],
        'reisepass' => ['REISEPASS', 'PASSPORT'],
        'fahrzeugschein' => ['ZULASSUNGSBESCHEINIGUNG TEIL I', 'FAHRZEUGSCHEIN'],
        'fahrzeugbrief' => ['ZULASSUNGSBESCHEINIGUNG TEIL II', 'FAHRZEUGBRIEF'],
        'gesundheitskarte' => ['GESUNDHEITSKARTE', 'VERSICHERTENKARTE', 'KRANKENVERSICHERUNGSKARTE'],
        'versicherungspolice' => ['VERSICHERUNGSPOLICE', 'VERSICHERUNGSSCHEIN'],
        'versicherungsvertrag' => ['VERSICHERUNGSVERTRAG', 'VERSICHERUNGSANTRAG'],
        'beratungsprotokoll' => ['BERATUNGSPROTOKOLL', 'BERATUNGSVERZICHT'],
        'rechnung' => ['RECHNUNG', 'INVOICE'],
        'sepa_mandat' => ['SEPA-LASTSCHRIFTMANDAT', 'SEPA MANDAT', 'MANDATSREFERENZ'],
        'schadenmeldung' => ['SCHADENMELDUNG', 'SCHADENANZEIGE'],
    ];

    /** @return array{type: string, confidence: int, summary: string, title: ?string, data: array}|null */
    public function classify(string $ocrText): ?array
    {
        $text = trim($ocrText);
        if ($text === '') {
            return null;
        }

        $type = $this->detectType($text);

        $raw = [
            'person' => ['email' => $this->findEmail($text)],
            'kfz' => [
                'license_plate' => $this->findLicensePlate($text),
                'vin' => $this->findVin($text),
            ],
            'bank' => ['iban' => $this->findIban($text)],
        ];

        $snippet = mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 150);

        return [
            'type' => $type,
            'confidence' => $type === 'sonstiges' ? 20 : 40,
            'summary' => 'Ohne KI per OCR erkannt - bitte pruefen: ' . $snippet,
            'title' => null,
            'data' => [
                'person' => $this->validatedPerson($raw['person']),
                'versicherung' => [],
                'kfz' => $this->validatedVehicle($raw['kfz']),
                'gesundheit' => [],
                'bank' => $this->validatedBank($raw['bank']),
            ],
        ];
    }

    private function detectType(string $text): string
    {
        $normalized = mb_strtoupper($text);
        foreach (self::KEYWORDS as $candidate => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle) && isset(Document::AI_TYPES[$candidate])) {
                    return $candidate;
                }
            }
        }
        return 'sonstiges';
    }

    /** IBAN nur uebernehmen, wenn eine Zeile fast ausschliesslich daraus besteht oder klar mit "IBAN" beschriftet ist. */
    private function findIban(string $text): ?string
    {
        foreach ($this->lines($text) as $line) {
            $compact = strtoupper((string) preg_replace('/\s+/', '', $line));
            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $compact)) {
                return $compact;
            }
            if (preg_match('/IBAN\s*[:\-]?\s*([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}\s?[A-Z0-9]{0,3})/i', $line, $m)) {
                $candidate = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
                if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function findVin(string $text): ?string
    {
        foreach ($this->lines($text) as $line) {
            if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/', strtoupper($line), $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function findLicensePlate(string $text): ?string
    {
        foreach ($this->lines($text) as $line) {
            if (preg_match('/\b([A-ZÄÖÜ]{1,3}[\s-][A-ZÄÖÜ]{1,2}[\s-]?\d{1,4}[EH]?)\b/u', mb_strtoupper($line), $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    private function findEmail(string $text): ?string
    {
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $m)) {
            return strtolower($m[0]);
        }
        return null;
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return preg_split('/\R/', $text) ?: [];
    }
}
