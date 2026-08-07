<?php
namespace App\Services\ChangeRequest;

use App\Models\ChangeRequestDocument;
use App\Models\CustomerChangeRequest;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Automatische Pruefung der Nachweise einer Kundenaenderung
 * (Betreiber-Vorgabe 29.07.2026).
 *
 * Arbeitsweise wie beim Smart Document Upload "kostenlos zuerst": PDFs
 * werden ueber die Textebene gelesen (gratis, fehlerfrei), Fotos ueber die
 * bestehende OCR-Stufe (Tesseract). KEIN KI-Aufruf - die Frage ist nicht
 * "was steht im Dokument", sondern nur "steht der BEANTRAGTE Wert drin".
 * Das beantwortet ein Textvergleich zuverlaessig und kostenlos.
 *
 * Ergebnis je Pruefpunkt: gefunden / nicht gefunden. Gespeichert wird
 * NUR dieses Ergebnis, niemals der Rohtext des Ausweises
 * (Datenminimierung - der Rohtext enthaelt weit mehr als wir brauchen).
 *
 * Wichtig fuer die Bewertung: ein Treffer beweist, dass der beantragte
 * Wert wirklich auf dem Beleg steht - er beweist NICHT, dass der Beleg
 * echt ist. Deshalb ist das Ergebnis eine Entscheidungshilfe; die
 * automatische Freigabe ist eine bewusst zuschaltbare Einstellung.
 */
class ChangeProofVerifier
{
    /** Zeichen, die OCR bei Ziffern regelmaessig verwechselt. */
    private const CONFUSABLES = [
        'O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1',
        'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', 'T' => '7',
    ];

    public function __construct(
        private readonly TextExtractorInterface $ocr,
        private readonly PdfTextLayerExtractor $pdfText,
        private readonly ChangeProofPolicy $policy,
    ) {
    }

    /** Steht ueberhaupt eine Lesestufe zur Verfuegung? (sonst bleibt alles manuell) */
    public function isAvailable(): bool
    {
        return $this->ocr->isAvailable() || $this->pdfText->isAvailable();
    }

    /**
     * Prueft alle Nachweise des Antrags und schreibt das Ergebnis an den
     * Antrag (proof_status/proof_result) und an jedes Dokument.
     *
     * @return array{status: string, checks: array, readable: int, documents: int}
     */
    public function verify(CustomerChangeRequest $request): array
    {
        $documents = $request->documents()->get();
        $checks = $this->policy->checks($request);

        if ($documents->isEmpty()) {
            return $this->store($request, 'missing', $checks, 0, 0);
        }
        if ($checks === []) {
            return $this->store($request, 'none', [], 0, $documents->count());
        }

        $readable = 0;
        $found = [];   // check-key => ['kind' => ..., 'tolerant' => bool]

        foreach ($documents as $document) {
            $text = $this->readText($document);
            if ($text === '') {
                $document->update(['check_status' => 'unreadable', 'check_result' => null]);
                continue;
            }
            $readable++;

            $perDocument = [];
            foreach ($checks as $check) {
                $hit = $this->matches($check, $text);
                $perDocument[$check['key']] = $hit['passed'];
                if ($hit['passed'] && !isset($found[$check['key']])) {
                    $found[$check['key']] = ['kind' => $document->kind, 'tolerant' => $hit['tolerant']];
                }
            }

            $hits = count(array_filter($perDocument));
            $document->update([
                'check_status' => $hits === 0 ? 'no_match' : ($hits === count($perDocument) ? 'match' : 'partial'),
                'check_result' => $perDocument,
            ]);
        }

        $result = [];
        foreach ($checks as $check) {
            $result[] = [
                'key' => $check['key'],
                'label' => $check['label'],
                'required' => $check['required'],
                'passed' => isset($found[$check['key']]),
                'document' => $found[$check['key']]['kind'] ?? null,
                'tolerant' => $found[$check['key']]['tolerant'] ?? false,
            ];
        }

        $requiredChecks = array_filter($result, fn($c) => $c['required']);
        $requiredPassed = array_filter($requiredChecks, fn($c) => $c['passed']);

        $status = match (true) {
            $readable === 0 => 'unreadable',
            $requiredChecks === [] => (array_filter($result, fn($c) => $c['passed']) !== [] ? 'verified' : 'mismatch'),
            count($requiredPassed) === count($requiredChecks) => 'verified',
            $requiredPassed !== [] => 'partial',
            default => 'mismatch',
        };

        return $this->store($request, $status, $result, $readable, $documents->count());
    }

    /**
     * @param array<int, array> $checks
     * @return array{status: string, checks: array, readable: int, documents: int}
     */
    private function store(CustomerChangeRequest $request, string $status, array $checks, int $readable, int $documents): array
    {
        $result = [
            'status' => $status,
            'checks' => $checks,
            'readable' => $readable,
            'documents' => $documents,
            'engine_available' => $this->isAvailable(),
        ];

        $request->forceFill([
            'proof_status' => $status,
            'proof_result' => $result,
            'proof_checked_at' => now(),
        ])->save();

        return $result;
    }

    /** Text des Nachweises lesen - PDF ueber die Textebene, Bilder per OCR. */
    private function readText(ChangeRequestDocument $document): string
    {
        try {
            $disk = Storage::disk($document->disk ?: 'local');
            if (!$disk->exists($document->file_path)) {
                return '';
            }
            $binary = $disk->get($document->file_path);
        } catch (\Throwable $e) {
            Log::warning('Nachweis nicht lesbar: ' . $e->getMessage());
            return '';
        }

        $mime = $document->mimeType();

        if ($mime === 'application/pdf' && $this->pdfText->isAvailable()) {
            $text = $this->pdfText->extract($binary);
            if ($text !== '') {
                return $text;
            }
        }

        return $this->ocr->isAvailable() ? $this->ocr->extract($binary, $mime) : '';
    }

    /**
     * Steht der beantragte Wert im Dokumenttext?
     *
     * @param array{key: string, label: string, value: string, mode: string, required: bool} $check
     * @return array{passed: bool, tolerant: bool}
     */
    private function matches(array $check, string $text): array
    {
        return match ($check['mode']) {
            'iban' => $this->matchIban($check['value'], $text),
            'name' => ['passed' => $this->matchName($check['value'], $text), 'tolerant' => false],
            'street' => ['passed' => $this->matchStreet($check['value'], $text), 'tolerant' => false],
            'date' => ['passed' => $this->matchDate($check['value'], $text), 'tolerant' => false],
            default => ['passed' => $this->matchText($check['value'], $text), 'tolerant' => false],
        };
    }

    /**
     * IBAN-Vergleich: erst exakt (Leerzeichen/Trennzeichen egal), dann
     * OCR-tolerant (O/0, I/1, S/5 ... werden regelmaessig verwechselt).
     * Der tolerante Treffer wird als solcher markiert, damit die Review-UI
     * ihn kennzeichnen kann.
     *
     * @return array{passed: bool, tolerant: bool}
     */
    private function matchIban(string $iban, string $text): array
    {
        $needle = $this->squash($iban);
        if ($needle === '') {
            return ['passed' => false, 'tolerant' => false];
        }
        $haystack = $this->squash($text);

        if (str_contains($haystack, $needle)) {
            return ['passed' => true, 'tolerant' => false];
        }
        if (str_contains($this->foldConfusables($haystack), $this->foldConfusables($needle))) {
            return ['passed' => true, 'tolerant' => true];
        }
        return ['passed' => false, 'tolerant' => false];
    }

    /**
     * Name: Nachname muss stehen, dazu mindestens die Haelfte der uebrigen
     * Namensteile. So bestehen "Mohammad Al Shaikh" und "Mohammad
     * Alshaikh" dieselbe Pruefung, ein voellig fremder Name aber nicht.
     */
    private function matchName(string $name, string $text): bool
    {
        $haystack = $this->normalize($text);
        $squashedHaystack = $this->squash($text);
        $parts = array_values(array_filter(
            preg_split('/\s+/', $this->normalize($name)) ?: [],
            fn($p) => mb_strlen($p) >= 3
        ));
        if ($parts === []) {
            return false;
        }

        $hits = 0;
        foreach ($parts as $part) {
            if (str_contains($haystack, $part) || str_contains($squashedHaystack, $this->squash($part))) {
                $hits++;
            }
        }
        $last = $parts[count($parts) - 1];
        $lastFound = str_contains($haystack, $last) || str_contains($squashedHaystack, $this->squash($last));

        return $lastFound && $hits >= (int) ceil(count($parts) / 2);
    }

    /**
     * Strasse: Strassenname und Hausnummer werden getrennt geprueft -
     * "Musterstr. 12" und "Musterstrasse 12a" gelten als dieselbe Strasse.
     */
    private function matchStreet(string $street, string $text): bool
    {
        $haystack = $this->normalizeStreet($text);
        $normalized = $this->normalizeStreet($street);

        preg_match('/\d+\s*[A-Z]?/', $normalized, $number);
        $namePart = trim(preg_replace('/\d.*$/', '', $normalized) ?? '');

        if (mb_strlen($namePart) < 3) {
            return str_contains($haystack, $normalized);
        }
        if (!str_contains($haystack, $namePart)) {
            return false;
        }
        if ($number === []) {
            return true;
        }
        // Hausnummer muss in der Naehe des Strassennamens stehen (gleiche Zeile).
        $number = trim(str_replace(' ', '', $number[0]));
        foreach (preg_split('/\R/', $haystack) ?: [] as $line) {
            if (str_contains($line, $namePart) && preg_match('/\b' . preg_quote($number, '/') . '\b/', str_replace(' ', '', $line))) {
                return true;
            }
        }
        return str_contains(str_replace(' ', '', $haystack), $namePart . $number);
    }

    /** Geburtsdatum in den ueblichen Schreibweisen (01.02.1990 / 1990-02-01 / 01021990). */
    private function matchDate(string $date, string $text): bool
    {
        try {
            $carbon = \Carbon\Carbon::parse($date);
        } catch (\Throwable) {
            return false;
        }
        $haystack = $this->squash($text);
        foreach (['d.m.Y', 'dmY', 'Y-m-d', 'd/m/Y', 'j.n.Y'] as $format) {
            if (str_contains($haystack, $this->squash($carbon->format($format)))) {
                return true;
            }
        }
        return false;
    }

    private function matchText(string $value, string $text): bool
    {
        $needle = $this->normalize($value);
        return $needle !== '' && str_contains($this->normalize($text), $needle);
    }

    /** Grossbuchstaben, Umlaute ausgeschrieben, Sonderzeichen zu Leerzeichen. */
    private function normalize(string $text): string
    {
        $text = mb_strtoupper($text);
        $text = strtr($text, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS', 'ẞ' => 'SS']);
        $text = preg_replace('/[^A-Z0-9\s]/u', ' ', $text) ?? '';
        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
    }

    /** Wie normalize(), zusaetzlich ohne JEDEN Zwischenraum (IBAN, Namen).
     *  ALLE Whitespaces (auch Zeilenumbrueche) entfernen: normalize() laesst
     *  \n/\r stehen, sodass eine ueber zwei Zeilen umbrochene IBAN
     *  ("DE89...\n...3000") sonst nie auf die einzeilige Nadel passt und ein
     *  echter Nachweis faelschlich als "mismatch" gilt (Audit VERIFY-1). */
    private function squash(string $text): string
    {
        return preg_replace('/\s+/u', '', $this->normalize($text)) ?? '';
    }

    /** "STRASSE"/"STR."/"STR" auf eine Form bringen, Zeilen erhalten. */
    private function normalizeStreet(string $text): string
    {
        $text = mb_strtoupper($text);
        $text = strtr($text, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS', 'ẞ' => 'SS']);
        $text = preg_replace('/[^A-Z0-9\s]/u', ' ', $text) ?? '';
        $text = preg_replace('/\b(STRASSE|STRASE|STR)\b/', 'STR', $text) ?? '';
        $text = preg_replace('/(?<=[A-Z])(STRASSE|STRASE)\b/', 'STR', $text) ?? '';
        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
    }

    /** Haeufige OCR-Verwechslungen auf eine Form ziehen (nur fuer den Zweitversuch). */
    private function foldConfusables(string $text): string
    {
        return strtr($text, self::CONFUSABLES);
    }
}
