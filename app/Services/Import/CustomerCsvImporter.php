<?php

namespace App\Services\Import;

use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Matching\CustomerMatchingService;
use League\Csv\Reader;

/**
 * Gemeinsame CSV-Import-Logik fuer Kunden. Wird sowohl von der Vorschau
 * (ImportExportController) als auch vom Hintergrund-Import (ImportCustomersJob)
 * genutzt, damit Vorschau und tatsaechlicher Import garantiert dasselbe
 * Mapping und dieselbe Duplikaterkennung verwenden.
 */
class CustomerCsvImporter
{
    public function __construct(
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerAutoCreationService $creator,
    ) {
    }

    /** Datei robust einlesen (Encoding + Trennzeichen) und Zeilen liefern. */
    public function readRecords(string $path): iterable
    {
        // Rohinhalt normalisieren: Fremdexporte (Lexoffice u. a.) kommen oft
        // als Windows-1252/Latin-1 mit Semikolon-Trennung. Frueher wurden
        // solche Dateien falsch gelesen -> E-Mail-Spalte nicht erkannt ->
        // alle Kunden mit Platzhalter-Adresse angelegt.
        $content = $this->toUtf8((string) file_get_contents($path));
        $csv = Reader::createFromString($content);
        $csv->setDelimiter($this->detectDelimiter($content));
        $csv->setHeaderOffset(0);

        return $csv->getRecords();
    }

    /**
     * Eine CSV-Zeile auf das Kundendaten-Array abbilden. Gibt null zurueck,
     * wenn kein sinnvoller Name ermittelbar ist (Zeile wird uebersprungen).
     */
    public function mapRow(array $row): ?array
    {
        // Spalten werden ueber mehrere moegliche Kopfzeilen-Namen gefunden.
        // Neben den schlanken Vorlagen-Namen (Straße, PLZ) auch die
        // nummerierten Varianten typischer Fremdexporte (Straße 1, PLZ 1,
        // E-Mail 1, Telefon 1, Firmenname ...).
        $col = fn (array $keys) => collect($keys)
            ->map(fn ($k) => trim((string) ($row[$k] ?? '')))
            ->first(fn ($v) => $v !== '') ?: null;

        $firstName = $col(['first_name', 'Vorname']);
        $lastName = $col(['last_name', 'Nachname']);
        $company = $col(['company', 'Firma', 'Firmenname']);
        // Name aus Vor-/Nachname; sonst Kontakt-/Name-Spalte; sonst Firma.
        $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''))
            ?: $col(['name', 'Name', 'Kontakt'])
            ?: $company;
        if (!$name) {
            return null; // ohne Namen kein sinnvoller Datensatz
        }

        $email = $col(['email', 'E-Mail', 'e-mail', 'E-Mail 1', 'EMail 1', 'Email 1']);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null; // ungueltige Adresse ignorieren, Kunde trotzdem anlegen
        }

        $email2 = $col(['email2', 'E-Mail 2', 'EMail 2', 'Email 2']);
        if ($email2 && !filter_var($email2, FILTER_VALIDATE_EMAIL)) {
            $email2 = null;
        }

        $originalNumber = $col(['customer_number', 'Kundennummer', 'kundennummer', 'Kundennr']);

        $data = array_filter([
            'full_name'     => $name,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'email2'        => $email2,
            'phone'         => $col(['phone', 'Telefon', 'Telefon 1', 'mobile', 'Mobil', 'Telefon 2']),
            'birth_date'    => $this->parseDate($col(['birth_date', 'Geburtsdatum'])),
            'street'        => $col(['street', 'Straße', 'Strasse', 'Straße 1', 'Strasse 1']),
            'house_number'  => $col(['street_nr', 'Hausnummer']),
            'zip'           => $col(['plz', 'PLZ', 'PLZ 1']),
            'city'          => $col(['city', 'Ort', 'Stadt', 'Ort 1']),
            'iban'          => $col(['iban', 'IBAN']),
            'gender'        => $this->genderFromAnrede($col(['Anrede', 'gender', 'Geschlecht'])),
            'company_name'  => $company,
            'company_type'  => $col(['company_type', 'Rechtsform']),
            'customer_type' => $company ? 'firma' : 'privat',
            'import_number' => $originalNumber,
        ], fn ($v) => $v !== null && $v !== '');

        if ($originalNumber) {
            $data['external_references'] = [
                ['type' => 'import_number', 'value' => $originalNumber, 'source' => 'import'],
            ];
        }

        return $data;
    }

    /**
     * Trockenlauf: analysiert die Datei ohne Schreibzugriff und liefert die
     * Vorschau-Zahlen samt Beispielzeilen fuer die Bestaetigungsseite.
     */
    public function analyze(string $path): array
    {
        $new = [];
        $duplicates = [];
        $skipped = 0;
        $noEmail = 0;
        $errors = [];
        $seenEmail = [];

        foreach ($this->readRecords($path) as $i => $row) {
            try {
                $data = $this->mapRow($row);
                if ($data === null) {
                    $skipped++;
                    continue;
                }

                $email = $data['email'] ?? null;
                $emailKey = $email ? mb_strtolower($email) : null;

                // Duplikat bereits INNERHALB der Datei (gleiche E-Mail zweimal).
                if ($emailKey && isset($seenEmail[$emailKey])) {
                    $duplicates[] = [
                        'name' => $data['full_name'],
                        'email' => $email,
                        'reason' => 'Doppelt in der Datei',
                    ];
                    continue;
                }

                // Duplikat gegen den Bestand (Name + Geburtsdatum + E-Mail ...).
                $match = $this->matcher->match($data);
                if ($match->tier() !== 'manual') {
                    $duplicates[] = [
                        'name' => $data['full_name'],
                        'email' => $email,
                        'reason' => 'Bereits im System' . ($match->customer?->customer_number ? ' (Nr. ' . $match->customer->customer_number . ')' : ''),
                    ];
                    continue;
                }

                if ($emailKey) {
                    $seenEmail[$emailKey] = true;
                } else {
                    $noEmail++;
                }

                $new[] = [
                    'name' => $data['full_name'],
                    'email' => $email,
                    'number' => !empty($data['import_number']) ? '25' . preg_replace('/[^A-Za-z0-9]/', '', (string) $data['import_number']) : '(neu)',
                    'city' => $data['city'] ?? null,
                    'has_email' => (bool) $email,
                ];
            } catch (\Exception $e) {
                $errors[] = 'Zeile ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }

        return [
            'total'         => count($new) + count($duplicates) + $skipped + count($errors),
            'new_count'     => count($new),
            'dup_count'     => count($duplicates),
            'skipped'       => $skipped,
            'no_email'      => $noEmail,
            'error_count'   => count($errors),
            'new'           => $new,
            'duplicates'    => $duplicates,
            'errors'        => $errors,
        ];
    }

    /** Schreibender Lauf: legt die Kunden tatsaechlich an. */
    public function commit(string $path, ?int $actorId = null): array
    {
        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->readRecords($path) as $i => $row) {
            try {
                $data = $this->mapRow($row);
                if ($data === null) {
                    $skipped++;
                    continue;
                }

                $match = $this->matcher->match($data);
                if ($match->tier() !== 'manual') {
                    $duplicates++;
                    continue;
                }

                $this->creator->createFromUnmatched($data, 'import', $actorId);
                $imported++;
            } catch (DuplicateCustomerException $e) {
                $duplicates++;
            } catch (\Exception $e) {
                $errors[] = 'Zeile ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped + $duplicates,
            'errors' => array_slice($errors, 0, 5),
        ];
    }

    /** Inhalt nach UTF-8 wandeln; Fremdexporte sind oft Windows-1252/Latin-1. */
    private function toUtf8(string $content): string
    {
        // BOM entfernen, falls vorhanden.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** Trennzeichen aus der Kopfzeile bestimmen (Semikolon, Tab oder Komma). */
    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
            ','  => substr_count($firstLine, ','),
        ];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /** Anrede -> Geschlecht (Herr = maennlich, Frau = weiblich). */
    private function genderFromAnrede(?string $anrede): ?string
    {
        $a = mb_strtolower(trim((string) $anrede));
        if ($a === '') {
            return null;
        }
        if (str_starts_with($a, 'herr')) {
            return 'male';
        }
        if (str_starts_with($a, 'frau')) {
            return 'female';
        }

        return null;
    }

    /** Datum aus verschiedenen gaengigen Formaten nach Y-m-d normalisieren. */
    private function parseDate($date): ?string
    {
        if (!$date) {
            return null;
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $format) {
            $d = \DateTime::createFromFormat($format, trim($date));
            if ($d) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
