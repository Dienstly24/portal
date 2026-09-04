<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Vermittler\VermittlerVorgangslisteParser;

/**
 * Erkennt die VORGANGSLISTE des Vermittlers im Dokumenten-Eingang - und
 * sagt dem Mitarbeiter, dass sie dort NICHT hingehoert.
 *
 * WARUM ES DIESEN PARSER GIBT (gemeldeter Fall 21.08.2026): der Betreiber
 * hat die Uebersicht der offenen Vorgaenge als Screenshot in den
 * Dokumenten-Eingang geladen, um die Bruecke Referenz-Nr. -> Id herzustellen.
 * Der Eingang ordnet aber IMMER EIN Dokument EINEM Kunden zu - eine Liste mit
 * den Vorgaengen vieler Kunden kann er strukturell nicht verarbeiten. Das
 * Ergebnis war "Sonstiges Dokument / Kein Kunde gefunden": technisch richtig,
 * fuer den Betrieb aber eine Sackgasse ohne Hinweis.
 *
 * Jetzt wird die Liste als solche benannt, kostet KEINEN KI-Aufruf und der
 * Eingang zeigt den Weg zur Vermittler-Abrechnung, wo sie hingehoert.
 */
class VermittlerVorgangslisteHinweisParser implements DocumentTemplateParser
{
    public function __construct(private readonly VermittlerVorgangslisteParser $liste)
    {
    }

    public function parse(string $text): ?array
    {
        if (! $this->liste->looksLikeVorgangsliste($text)) {
            return null;
        }

        $parsed = $this->liste->parse($text);
        $anzahl = count($parsed['rows']);
        $mitReferenz = count(array_filter($parsed['rows'], fn ($r) => $r['reference_number'] !== null));

        return [
            'type' => 'vermittler_vorgangsliste',
            'confidence' => 80,
            'summary' => 'Vorgangsliste des Vermittlers mit '.$anzahl.' Vorgaengen'
                .($mitReferenz > 0 ? ' ('.$mitReferenz.' davon mit Referenznummer)' : '')
                .'. Das ist KEIN Kundendokument: die Liste enthaelt Vorgaenge mehrerer Kunden und laesst sich '
                .'deshalb keinem einzelnen Kunden zuordnen. Sie gehoert unter '
                .'"Vermittler-Abrechnung -> Vorgangsliste einlesen" - dort stellt sie die Verbindung '
                .'Referenz-Nr. -> Vermittler-ID fuer jeden einzelnen Vertrag her. '
                .'Gratis erkannt (ohne KI).',
            'title' => 'Vermittler-Vorgangsliste ('.$anzahl.' Vorgaenge)',
            'data' => [
                'person' => [],
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }
}
