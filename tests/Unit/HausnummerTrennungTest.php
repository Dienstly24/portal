<?php

namespace Tests\Unit;

use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use Tests\TestCase;

/**
 * Strasse und Hausnummer trennen - EINE Regel fuer ALLE Quellen.
 *
 * Gemeldet am CHECK24-Beratungsprotokoll ("die Hausnummer wird nicht
 * erkannt"). Die Vorlagen-Parser spalten die Nummer selbst ab, die
 * KI-Antwort aber nicht: schreibt das Modell die Anschrift so ab, wie sie
 * im Dokument steht ("Hintere Gasse 23"), blieb `house_number` leer und in
 * der Kundenakte fehlte die Hausnummer - obwohl sie gelesen wurde. Seit der
 * Trennung in `ValidatesExtractedFields` gilt die Regel fuer jede Quelle.
 */
class HausnummerTrennungTest extends TestCase
{
    /** Auszug aus einem echten Protokoll (Kopfblock, 3-Spalten-Layout). */
    private function protokoll(): string
    {
        return <<<'TXT'
        Vorläufiges Beratungsprotokoll zur Kfz-Versicherung

        Vermittler

        CHECK24 Vergleichsportal                  E-Mail: kfz-beratung@check24.de
        für Kfz-Versicherungen GmbH
        Erika-Mann-Str. 66
        80636 München

        Versicherungsnehmer
        Herr                                      Anschrift:                               E-Mail:
        Mazen Abou Allaban                        Hintere Gasse 23                         maznabwallbn33@gmail.com
        Geboren am 01.01.2001                     91074 Herzogenaurach                     017621623024

        von Seiten des Vermittlers aufgezeigten Angeboten wählte der Versicherungsnehmer selbstständig folgenden Tarif:

        Cosmos Direkt Basis mit Werkstattbindung
        Angaben ohne Gewähr
        TXT;
    }

    public function test_vorlagen_parser_liest_hausnummer_aus_dem_kopfblock(): void
    {
        $result = (new Check24KfzProtocolParser)->parse($this->protokoll());

        $this->assertNotNull($result);
        $person = $result['data']['person'];
        $this->assertSame('Hintere Gasse', $person['street']);
        $this->assertSame('23', $person['house_number']);
    }

    /**
     * Der Versicherer wird auch in der Schreibweise MIT Leerzeichen erkannt.
     * Ohne sie griff der Notbehelf "erstes Wort" und der Vertrag entstand
     * unter "Cosmos" mit dem Tarif "Direkt Basis mit Werkstattbindung".
     */
    public function test_versicherer_cosmos_direkt_wird_nicht_am_leerzeichen_zerschnitten(): void
    {
        $result = (new Check24KfzProtocolParser)->parse($this->protokoll());

        $this->assertSame('Cosmos Direkt', $result['data']['versicherung']['insurer']);
        $this->assertSame('Basis mit Werkstattbindung', $result['data']['versicherung']['tariff']);
    }

    /**
     * Der eigentliche Kern: liefert die KI die Nummer IN der Strasse, wird
     * sie trotzdem in das eigene Feld getrennt.
     */
    public function test_ki_antwort_mit_nummer_in_der_strasse_wird_getrennt(): void
    {
        $person = $this->personAusKiAntwort(['street' => 'Hintere Gasse 23']);

        $this->assertSame('Hintere Gasse', $person['street']);
        $this->assertSame('23', $person['house_number']);
    }

    public function test_hausnummer_mit_zusatzbuchstabe_und_bereich(): void
    {
        $this->assertSame(
            ['Mittelstr.', '21 b'],
            $this->adresseAusKiAntwort('Mittelstr. 21 b')
        );
        $this->assertSame(
            ['Bahnhofstraße', '7a'],
            $this->adresseAusKiAntwort('Bahnhofstraße 7a')
        );
        $this->assertSame(
            ['Hauptstr.', '12-14'],
            $this->adresseAusKiAntwort('Hauptstr. 12-14')
        );
    }

    /**
     * Eine Strasse OHNE Hausnummer bleibt unveraendert - erfunden wird
     * nichts. Ebenso wenig darf ein Strassenname, der auf eine Zahl im
     * Namen endet, zerschnitten werden.
     */
    public function test_ohne_hausnummer_bleibt_die_strasse_unangetastet(): void
    {
        $this->assertSame(['Am Markt', null], $this->adresseAusKiAntwort('Am Markt'));
        $this->assertSame(['Straße des 17. Juni', null], $this->adresseAusKiAntwort('Straße des 17. Juni'));
    }

    /** Aus einer blossen Nummer wird nie eine Strasse ohne Namen. */
    public function test_reine_nummer_wird_nicht_zur_strasse_ohne_namen(): void
    {
        $this->assertSame(['23', null], $this->adresseAusKiAntwort('23'));
    }

    /**
     * Nennt die KI die Nummer DOPPELT (in der Strasse und im eigenen Feld),
     * steht sie danach nur noch einmal in der Akte.
     */
    public function test_doppelt_genannte_hausnummer_steht_nur_einmal_in_der_akte(): void
    {
        $person = $this->personAusKiAntwort([
            'street' => 'Hintere Gasse 23',
            'house_number' => '23',
        ]);

        $this->assertSame('Hintere Gasse', $person['street']);
        $this->assertSame('23', $person['house_number']);
    }

    /**
     * WIDERSPRUCH: Strasse und Feld nennen VERSCHIEDENE Nummern. Dann wird
     * nicht geraten, welche stimmt - beide Angaben bleiben unveraendert und
     * der Mitarbeiter sieht sie im Review.
     */
    public function test_widerspruechliche_hausnummer_wird_nicht_geraten(): void
    {
        $person = $this->personAusKiAntwort([
            'street' => 'Hintere Gasse 23',
            'house_number' => '25',
        ]);

        $this->assertSame('Hintere Gasse 23', $person['street']);
        $this->assertSame('25', $person['house_number']);
    }

    /** @return array{0:?string,1:?string} [Strasse, Hausnummer] */
    private function adresseAusKiAntwort(string $street): array
    {
        $person = $this->personAusKiAntwort(['street' => $street]);

        return [$person['street'] ?? null, $person['house_number'] ?? null];
    }

    /**
     * Faehrt eine (erfundene) KI-Antwort durch DIESELBE Validierung, die
     * jede echte Antwort des Anbieters durchlaeuft.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function personAusKiAntwort(array $person): array
    {
        $raw = json_encode([
            'type' => 'sonstiges',
            'confidence' => 60,
            'summary' => 'Test',
            'data' => ['person' => $person],
        ], JSON_THROW_ON_ERROR);

        $provider = app(ClaudeDocumentAiProvider::class);
        $method = new \ReflectionMethod($provider, 'validatedOutput');
        $method->setAccessible(true);

        return $method->invoke($provider, $raw)['data']['person'];
    }
}
