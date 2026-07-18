<?php
namespace App\Services\Health;

/**
 * Erkennt in den Analyse-Ergebnissen eines Dokument-Vorgangs (Buendel aus
 * Gesundheitskarten, Ausweisen, Geburtsurkunden, Familienbescheinigung,
 * Gehaltsabrechnung) die beteiligten PERSONEN einer Familie.
 *
 * Quellen je Dokument: data.person (Hauptperson) + data.personen (weitere
 * Personen, z.B. je Gesundheitskarte im Buendel). Dedupliziert ueber den
 * normalisierten Namen; Attribute (Geburtsdatum, Geschlecht, KV-Nummer,
 * Krankenkasse) werden ueber alle Dokumente hinweg zusammengefuehrt (erster
 * nicht-leerer Wert gewinnt).
 *
 * Wichtig: Der Dienst ENTSCHEIDET nichts - wer hauptversichert ist, fragt
 * die UI den Mitarbeiter (Betreiber-Vorgabe: meist der Vater, aber es gibt
 * keine sichere Regel).
 */
class FamilyBundleService
{
    /**
     * @param iterable<\App\Models\Document> $documents
     * @return list<array<string,mixed>> Personen mit first_name, last_name,
     *         birth_date?, gender?, health_insurance_number?, company?
     */
    public function detectPersons(iterable $documents): array
    {
        $persons = [];

        foreach ($documents as $doc) {
            $data = $doc->ai_extracted ?? [];
            if (!is_array($data)) {
                continue;
            }
            $company = $data['gesundheit']['health_insurance_company'] ?? null;

            $candidates = [];
            if (!empty($data['person']) && is_array($data['person'])) {
                $main = $data['person'];
                // KV-Nummer der Hauptperson steckt in data.gesundheit.
                if (!empty($data['gesundheit']['health_insurance_number'])) {
                    $main['health_insurance_number'] = $data['gesundheit']['health_insurance_number'];
                }
                $candidates[] = $main;
            }
            foreach (($data['personen'] ?? []) as $extra) {
                if (is_array($extra)) {
                    $candidates[] = $extra;
                }
            }

            foreach ($candidates as $candidate) {
                $key = $this->nameKey($candidate);
                if ($key === '') {
                    continue;
                }
                $persons[$key] ??= ['first_name' => null, 'last_name' => null, 'birth_date' => null,
                    'gender' => null, 'health_insurance_number' => null, 'company' => null];
                foreach (['first_name', 'last_name', 'birth_date', 'gender', 'health_insurance_number'] as $field) {
                    if (blank($persons[$key][$field]) && filled($candidate[$field] ?? null)) {
                        $persons[$key][$field] = $candidate[$field];
                    }
                }
                if (blank($persons[$key]['company']) && filled($company)) {
                    $persons[$key]['company'] = $company;
                }
            }
        }

        // Deterministische Reihenfolge (alphabetisch nach Namens-Schluessel):
        // UI und Server muessen dieselben Indizes sehen, unabhaengig von der
        // Lade-Reihenfolge der Dokumente.
        ksort($persons);

        return array_values(array_map(
            fn ($p) => array_filter($p, fn ($v) => $v !== null),
            $persons,
        ));
    }

    /**
     * Vorschlag, wer hauptversichert ist (nur ein VORSCHLAG fuer die UI -
     * der Mitarbeiter entscheidet): der aelteste Mann, sonst die aelteste
     * Person. Liefert den Index in der Personenliste.
     *
     * @param list<array<string,mixed>> $persons
     */
    public function suggestHauptIndex(array $persons): int
    {
        $best = 0;
        $bestScore = -1;
        foreach ($persons as $i => $p) {
            $age = isset($p['birth_date']) ? -strtotime((string) $p['birth_date']) : 0;
            // Maenner bevorzugen (Betreiber: ~90% der Faelle ist der Vater
            // hauptversichert), dann nach Alter.
            $score = (($p['gender'] ?? null) === 'male' ? PHP_INT_MAX / 2 : 0) + $age / 1000;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        return $best;
    }

    private function nameKey(array $person): string
    {
        $name = mb_strtolower(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
        $name = preg_replace('/[^a-zäöüß ]/u', '', $name) ?? $name;
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
