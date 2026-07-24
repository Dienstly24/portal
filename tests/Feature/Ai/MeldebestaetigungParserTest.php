<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\Ai\TemplateParsers\MeldebestaetigungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die Meldebestaetigung/Meldebescheinigung eines deutschen
 * Buergerbueros: liest die amtlich bestaetigte Meldeadresse des Kunden (Name,
 * Geburtsdatum, Anschrift). Kontaktdaten und Bankverbindung der Behoerde
 * werden bewusst NICHT uebernommen. Synthetische Daten, gleiche Struktur wie
 * das Original (pdftotext -layout).
 */
class MeldebestaetigungParserTest extends TestCase
{
    private function letterText(): string
    {
        return implode("\n", [
            'Stadt Rendsburg',
            'Fachdienst Buergerbuero/Standesamt',
            'Hausanschrift       Am Gymnasium 4',
            '                    24768 Rendsburg',
            'Frau',
            'Safa Kutaish',
            'Breslauer Strasse 57',
            '24768 Rendsburg',
            'Telefon:            04331/206-1226',
            'E-Mail:             buergerbuero@rendsburg.de',
            '15.05.2026',
            'Meldebestaetigung',
            'Gemeindeschluessel:   01058135',
            'Anschrift:            Breslauer Strasse 57',
            '                      24768 Rendsburg',
            'Wohnungsstatus:       Einzige Wohnung',
            'Einzugsdatum:         15.05.2026',
            'Anmeldedatum:         15.05.2026',
            'Die Anmeldung erfolgte fuer:',
            'Familienname:         Kutaish',
            'Vorname:              Safa',
            'Geburtsdatum:         05.02.2001',
            'Bankverbindung:',
            'Sparkasse Mittelholstein AG  BIC: NOLADE21RDB - IBAN: DE27214500000000008600',
        ]);
    }

    public function test_parses_registration_confirmation(): void
    {
        $r = (new MeldebestaetigungParser())->parse($this->letterText());

        $this->assertNotNull($r);
        $this->assertSame('meldebescheinigung', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Kutaish', $p['last_name']);
        $this->assertSame('Safa', $p['first_name']);
        $this->assertSame('2001-02-05', $p['birth_date']);
        $this->assertSame('female', $p['gender']);
        // Kunden-Anschrift (Breslauer Strasse), NICHT die Behoerden-Hausanschrift.
        $this->assertSame('Breslauer Strasse', $p['street']);
        $this->assertSame('57', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);

        // Behoerden-Bankverbindung/-Kontakt duerfen NICHT uebernommen werden.
        $this->assertSame([], $r['data']['bank']);
        $this->assertArrayNotHasKey('email', $p);
        $this->assertArrayNotHasKey('phone', $p);
    }

    public function test_type_is_registered_and_heuristic_classifies_it(): void
    {
        $this->assertArrayHasKey('meldebescheinigung', Document::AI_TYPES);
        $this->assertSame('identity', Document::AI_TYPES['meldebescheinigung']['category']);

        $r = (new HeuristicDocumentClassifier())->classify("Stadt Rendsburg\nMeldebestaetigung\nFamilienname: Muster");
        $this->assertNotNull($r);
        $this->assertSame('meldebescheinigung', $r['type']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new MeldebestaetigungParser())->parse('Irgendein anderes Dokument'));
    }
}
