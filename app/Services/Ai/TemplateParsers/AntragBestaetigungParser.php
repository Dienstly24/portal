<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die ABSCHLUSS-SEITE einer Online-Antragsstrecke
 * ("Vielen Dank, Ihr Antrag ist bei uns eingegangen"), die der Betrieb als
 * Screenshot hochlaedt - z.B. nach einem Kfz-Antrag bei AdmiralDirekt ueber
 * ein Vergleichsportal. Sie traegt genau die Angaben, mit denen sich der
 * Vorgang spaeter wiederfinden laesst:
 *
 *   Referenznummer      1477-6741-9200-53
 *   Versicherungsbeginn Tag der Zulassung        (KEIN Datum!)
 *   eVB-Nummer          SHTC3HB
 *   Bestaetigung an     kunde@example.com
 *
 * Regeln (Betreiber-Vorgaben):
 *  - Stufe 'antrag': es gibt noch KEINE Vertragsnummer. Die Referenznummer
 *    wandert in das eigene Feld `reference_number` (Vorgangs-Bruecke), die
 *    eVB-Nummer bleibt Zusatzinfo - beides ist NIE eine Vertragsnummer.
 *  - "Tag der Zulassung" ist kein Datum und wird nie geraten; der Beginn
 *    bleibt leer, bis die Police ihn nennt.
 *  - Die Gesellschaft kommt aus dem Satz "Ihr Antrag wurde an die <X>
 *    uebermittelt" bzw. aus dem Seitentext.
 */
class AntragBestaetigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /**
     * Gesellschaften, die in dieser Bestaetigungsseite auftauchen koennen.
     * Der Anbietername steht als Fliesstext, nicht als beschriftetes Feld -
     * deshalb eine feste Liste statt einer Rate-Heuristik.
     *
     * @var array<string,string>
     */
    private const INSURERS = [
        'admiraldirekt'        => 'AdmiralDirekt',
        'huk24'                => 'HUK24',
        'huk-coburg'           => 'HUK-COBURG',
        'da direkt'            => 'DA Direkt',
        'da-direkt'            => 'DA Direkt',
        'verti'                => 'Verti',
        'wgv'                  => 'WGV',
        'allianz'              => 'Allianz',
        'europa'               => 'EUROPA',
        'sparkassen direkt'    => 'Sparkassen DirektVersicherung',
        'adac'                 => 'ADAC',
    ];

    private string $text = '';

    public function parse(string $text): ?array
    {
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        // Abschluss-Seite einer Antragsstrecke: Dankes-/Eingangssatz UND eine
        // Referenznummer. Ein Versicherungsschein traegt diese Kombination
        // nicht (er hat eine Vertragsnummer und keinen "Antrag eingegangen").
        if (!str_contains($upper, 'REFERENZNUMMER')
            || (!str_contains($upper, 'ANTRAG IST BEI UNS EINGEGANGEN')
                && !str_contains($upper, 'ANTRAG WURDE AN')
                && !str_contains($upper, 'VERSICHERUNG BEANTRAGT'))) {
            return null;
        }

        $referenz = $this->referenznummer();
        if ($referenz === null) {
            return null;
        }

        $person = $this->parsePerson();
        $insurance = $this->parseInsurance($referenz);
        $evb = $this->evbNummer();

        return [
            'type' => 'versicherungsvertrag',
            'confidence' => 72,
            'summary' => 'Antrags-Bestaetigung (Online-Antragsstrecke)'
                . (isset($insurance['insurer']) ? ' - ' . $insurance['insurer'] : '')
                . ' - Referenznummer ' . $referenz
                . ' (keine Vertragsnummer - die bringt erst der Versicherungsschein).'
                . ($evb !== null ? ' eVB-Nummer ' . $evb . ' (fuer die Zulassung, keine Vertragsnummer).' : '')
                . ($this->beginnText() !== null ? ' Versicherungsbeginn laut Seite: ' . $this->beginnText() . '.' : '')
                . (isset($insurance['start_date']) ? '' : ' Kein Beginndatum genannt - wird nicht geraten.')
                . ' Felder gratis aus der Bestaetigungsseite gelesen (ohne KI).',
            'title' => 'Antrags-Bestaetigung'
                . (isset($insurance['insurer']) ? ' ' . $insurance['insurer'] : '')
                . ' - Ref. ' . $referenz,
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];
        // Die Seite nennt nur die Bestaetigungs-E-Mail des Kunden.
        if (preg_match('/Bestätigung an\s+(\S+@\S+?)\s+gesendet/u', $this->text, $m)
            || preg_match('/\b([\w.+\-]+@[\w.\-]+\.\w{2,})\b/u', $this->text, $m)) {
            $mail = rtrim($m[1], '.,;');
            if (!preg_match('/@(admiraldirekt|huk|verti|allianz|check24|adac)\./iu', $mail)) {
                $raw['email'] = mb_strtolower($mail);
            }
        }

        return $this->validatedPerson($raw);
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInsurance(string $referenz): array
    {
        $raw = [
            // Ein Antrag ist noch keine Bestaetigung des Versicherers.
            'document_stage' => Contract::STAGE_ANTRAG,
            'reference_number' => $referenz,
        ];

        $klein = mb_strtolower($this->text);
        foreach (self::INSURERS as $muster => $name) {
            if (str_contains($klein, $muster)) {
                $raw['insurer'] = $name;
                break;
            }
        }

        // Beginn NUR als echtes Datum - "Tag der Zulassung" ist keins.
        $beginn = $this->beginnText();
        if ($beginn !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $beginn, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Sparte nur, wenn die Seite sie eindeutig nennt (eVB/Zulassung =
        // Kfz); sonst bleibt sie leer und der Mitarbeiter waehlt.
        if (preg_match('/\beVB\b|Zulassungsstelle|Kfz-Versicherung/u', $this->text)) {
            $raw['sparte'] = 'kfz';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Referenznummer der Antragsstrecke ("1477-6741-9200-53"). */
    private function referenznummer(): ?string
    {
        if (preg_match('/Referenznummer\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{4,40})/iu', $this->text, $m)) {
            return trim($m[1], '-/');
        }
        return null;
    }

    /** Text hinter "Versicherungsbeginn" (kann "Tag der Zulassung" sein). */
    private function beginnText(): ?string
    {
        if (preg_match('/Versicherungsbeginn\s*:?\s*(\S[^\r\n]{0,60}?)\s*$/mu', $this->text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * eVB-Nummer (elektronische Versicherungsbestaetigung, 7 Zeichen) - sie
     * steht gross und allein in einem Kasten. Nur uebernehmen, wenn die
     * Seite sie ausdruecklich als eVB bezeichnet.
     */
    private function evbNummer(): ?string
    {
        if (!preg_match('/\beVB\b/u', $this->text)) {
            return null;
        }
        if (preg_match('/eVB[- ]?Nummer\s*:?\s*([A-Z0-9]{7})\b/u', $this->text, $m)) {
            return $m[1];
        }
        // Sonst die alleinstehende 7-stellige Kennung im Kasten.
        if (preg_match('/^\s*([A-Z0-9]{7})\s*$/mu', $this->text, $m)) {
            return $m[1];
        }
        return null;
    }
}
