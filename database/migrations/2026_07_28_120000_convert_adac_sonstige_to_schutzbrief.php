<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bestandsdaten-Umzug (Betreiber-Vorgabe 28.07.2026): ADAC-Mitgliedschaften
 * und andere Schutzbrief-/Mobilclub-Vertraege wurden bisher als Sparte
 * "Sonstige" mit Freitext (type_other, z.B. "ADAC Schutzbrief") erfasst.
 * Seit es die eigene Sparte 'schutzbrief' gibt, ziehen diese Vertraege dorthin
 * um - Schutzbrief statt Sonstiges.
 *
 * Erkennung bewusst NUR innerhalb der Sparte "Sonstige" (type='andere'):
 * ADAC-KFZ-Policen (Sparte kfz) bleiben unberuehrt. Ein Treffer liegt vor,
 * wenn Anbieter ODER Freitext einen Automobilclub bzw. Schutzbrief nennen.
 * Steht im Text eine Mitgliedschafts-Stufe (Basis/Plus/Premium), wird sie als
 * Untergruppe uebernommen - sonst bleibt die Stufe leer (keine erfundenen
 * Daten). Der Freitext wird geleert (die Sparte traegt die Information jetzt
 * selbst); sein alter Wert steht in der Version History (contract_revisions,
 * Quelle System), genau wie der Sparten- und Stufen-Wechsel.
 *
 * Bewusst reine DB-Operationen (keine Eloquent-Events): es darf weder eine
 * Provisions-Buchung noch ein anderer Model-Hook anspringen - der Vertrag
 * selbst aendert sich fachlich nicht, nur seine Einordnung.
 */
return new class extends Migration {
    /** Anzeige-Labels zum Zeitpunkt dieser Migration (fix eingefroren). */
    private const LABEL_ALT = 'Sonstige';
    private const LABEL_NEU = 'Schutzbrief / Mobilclub';
    private const STUFEN = [
        'basis'   => 'Basis-Mitgliedschaft',
        'plus'    => 'Plus-Mitgliedschaft',
        'premium' => 'Premium-Mitgliedschaft',
    ];

    public function up(): void
    {
        // Kandidaten sind ausschliesslich "Sonstige"-Vertraege; die Menge ist
        // klein, daher Textpruefung robust in PHP (LIKE-Gross/Kleinschreibung
        // verhaelt sich auf MySQL und SQLite unterschiedlich).
        $rows = DB::table('contracts')
            ->where('type', 'andere')
            ->select('id', 'insurer', 'type_other', 'subtype')
            ->get();

        $now = now();

        foreach ($rows as $row) {
            $haystack = mb_strtolower(trim(($row->insurer ?? '') . ' ' . ($row->type_other ?? '')));
            if (!$this->isSchutzbrief($haystack)) {
                continue;
            }

            // Stufe nur uebernehmen, wenn sie ausdruecklich im Text steht und
            // noch keine Untergruppe gesetzt ist.
            $stufe = $row->subtype ?: $this->detectStufe($haystack);

            DB::table('contracts')->where('id', $row->id)->update([
                'type' => 'schutzbrief',
                'subtype' => $stufe,
                'type_other' => null,
                'updated_at' => $now,
            ]);

            // Version History: ein Batch je Vertrag, Quelle System.
            $batch = (string) Str::uuid();
            $revision = fn (string $field, string $label, ?string $alt, ?string $neu) => [
                'id' => (string) Str::uuid(),
                'contract_id' => $row->id,
                'batch_id' => $batch,
                'field' => $field,
                'label' => $label,
                'old_value' => $alt,
                'new_value' => $neu,
                'source' => 'system',
                'source_document_id' => null,
                'changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $entries = [
                $revision('type', 'Sparte', self::LABEL_ALT, self::LABEL_NEU),
            ];
            if (!blank($row->type_other)) {
                $entries[] = $revision('type_other', 'Sonstige-Bezeichnung', $row->type_other, null);
            }
            if ($stufe !== null && $row->subtype === null) {
                $entries[] = $revision('subtype', 'Mitgliedschaft / Tarifstufe', null, self::STUFEN[$stufe]);
            }

            DB::table('contract_revisions')->insert($entries);
        }
    }

    public function down(): void
    {
        // Keine automatische Rueckabwicklung: der alte Freitext liesse sich
        // nur aus der Version History rekonstruieren. Die Eintraege dort
        // bleiben ohnehin als Beleg erhalten.
    }

    /** Nennt der Text einen Automobilclub oder Schutzbrief? */
    private function isSchutzbrief(string $haystack): bool
    {
        foreach (['adac', 'schutzbrief', 'mobilclub', 'mobil-club', 'mobil club', 'automobilclub', 'automobil-club'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Mitgliedschafts-Stufe aus dem Text lesen (nur eindeutige Nennungen). */
    private function detectStufe(string $haystack): ?string
    {
        if (str_contains($haystack, 'premium')) {
            return 'premium';
        }
        if (preg_match('/\bplus\b/', $haystack) === 1) {
            return 'plus';
        }
        if (str_contains($haystack, 'basis') || str_contains($haystack, 'basic')) {
            return 'basis';
        }
        return null;
    }
};
