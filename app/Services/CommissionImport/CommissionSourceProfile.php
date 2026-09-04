<?php

namespace App\Services\CommissionImport;

/**
 * WOHER kommt diese Datei? (Betreiber-Vorgabe 26.08.2026: "wir haben mehr
 * als einen Vermittler, mehr als ein Portal und mehr als eine Dateiart")
 *
 * Der Betrieb bekommt Abrechnungen aus mehreren Quellen, und jede schreibt
 * ihre Spalten anders: der eine nennt die Kennung `Id`, der naechste
 * `Vertragsnummer intern`, das Energie-Vertriebsportal `Auftr.-Nr.`. Ein
 * Import, der auf EINE Spalte besteht, ist damit strukturell auf einen
 * einzigen Lieferanten festgelegt.
 *
 * WICHTIG - dies ist eine ERKENNUNG, keine VORAUSSETZUNG: Eine unbekannte
 * Quelle wird NICHT abgelehnt. Sie laeuft ueber die normale
 * Spaltenzuordnung wie bisher; das Profil sagt dem Mitarbeiter nur, was das
 * System erkannt hat, und stellt die Betriebsart passend ein. Der naechste
 * Maklerpool darf ohne Code-Aenderung importiert werden - sonst waere aus
 * "mehrere Quellen" wieder "eine Quelle, nur eine andere".
 */
class CommissionSourceProfile
{
    /**
     * Bekannte Quellen.
     *
     * `signature` = Spalten, die ALLE vorhanden sein muessen (normalisiert).
     * Bewusst knapp gehalten: je mehr Spalten verlangt werden, desto eher
     * scheitert die Erkennung an einer Zusatzspalte des naechsten Exports.
     *
     * @var array<string,array{label:string,hint:string,signature:array<int,string>,mode:string,importer:string}>
     */
    public const PROFILES = [
        'tarifcheck24' => [
            'label' => 'TARIFCHECK24 (Vergleichsportal)',
            'hint' => 'Abrechnung des Vergleichsportals – Kennung ist die Spalte „Id“. '
                .'Diese Datei enthält keinen Kundennamen, daher entstehen daraus keine Verträge.',
            'signature' => ['id', 'provision', 'status'],
            'mode' => ColumnMap::MODE_ABRECHNUNG,
            // Fuer diese Quelle gibt es zusaetzlich den spezialisierten
            // Abgleich unter /admin/vermittler-abrechnung.
            'importer' => 'vermittler',
        ],
        'maklerpool' => [
            'label' => 'Maklerpool-Abrechnung',
            'hint' => 'Provisionsabrechnung des Pools – Kennung ist die „Vertragsnummer intern“.',
            'signature' => ['vertragsnummerintern', 'provisionsbetrag'],
            'mode' => ColumnMap::MODE_ABRECHNUNG,
            'importer' => 'intern',
        ],
        'energie_vertriebsportal' => [
            'label' => 'Energie-Vertriebsportal (Auftragsliste)',
            'hint' => 'Auftragsliste ohne Beträge – Kennung ist die „Auftr.-Nr.“. '
                .'Daraus entstehen Kunden und Verträge, nie eine Provision.',
            'signature' => ['auftrnr', 'kunden', 'anschrift'],
            'mode' => ColumnMap::MODE_AUFTRAGSLISTE,
            'importer' => 'intern',
        ],
    ];

    /** Anzeige, wenn keine bekannte Quelle passt. */
    public const UNKNOWN_LABEL = 'Unbekannte Quelle – Spalten bitte prüfen';

    /**
     * Quelle aus der Kopfzeile bestimmen.
     *
     * @param array<int,string> $header
     * @return string|null Profil-Schluessel oder null
     */
    public static function detect(array $header): ?string
    {
        $columns = [];
        foreach ($header as $label) {
            $key = ColumnMap::normalize((string) $label);
            if ($key !== '') {
                $columns[$key] = true;
            }
        }

        // Das SPEZIFISCHERE Profil gewinnt: gemessen wird an der Zahl der
        // geforderten Spalten, damit ein knappes Profil ein ausfuehrlicheres
        // nicht verdraengt.
        $best = null;
        $bestSize = 0;
        foreach (self::PROFILES as $key => $profile) {
            $size = count($profile['signature']);
            if ($size <= $bestSize) {
                continue;
            }
            foreach ($profile['signature'] as $needed) {
                if (! isset($columns[$needed])) {
                    continue 2;
                }
            }
            $best = $key;
            $bestSize = $size;
        }
        return $best;
    }

    public static function label(?string $key): string
    {
        return self::PROFILES[$key]['label'] ?? self::UNKNOWN_LABEL;
    }

    public static function hint(?string $key): ?string
    {
        return self::PROFILES[$key]['hint'] ?? null;
    }

    /** Betriebsart, die zu dieser Quelle gehoert (null = am Inhalt entscheiden). */
    public static function mode(?string $key): ?string
    {
        return self::PROFILES[$key]['mode'] ?? null;
    }

    /**
     * Gehoert diese Quelle in den spezialisierten Vermittler-Abgleich?
     * Nur TARIFCHECK24 hat dort eine eigene Auswertung (Status-Codes,
     * "Nicht in Abrechnung gefunden", Bestaetigungsquote).
     */
    public static function belongsToVermittlerImport(?string $key): bool
    {
        return ($self = self::PROFILES[$key] ?? null) !== null && $self['importer'] === 'vermittler';
    }
}
