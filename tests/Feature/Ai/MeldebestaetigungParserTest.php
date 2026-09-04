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
        $r = (new MeldebestaetigungParser)->parse($this->letterText());

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

    // Audit PARSER-1: die (amtlich uebliche) Pluralform "Vornamen:" darf nicht
    // als Praefix von "Vorname" verlesen werden - fruener lief ein "n:" in den
    // Vornamen ("n: Jana Maria").
    public function test_plural_vornamen_label_is_read_cleanly(): void
    {
        $text = implode("\n", [
            'Stadt Backnang',
            'Meldebestaetigung',
            'Anmeldedatum:         01.03.2026',
            'Anschrift:            Musterweg 3',
            '                      71522 Backnang',
            'Familienname:         Beispiel',
            'Vornamen:             Jana Maria',
            'Geburtsdatum:         14.07.1994',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Jana Maria', $r['data']['person']['first_name']);
        $this->assertSame('Beispiel', $r['data']['person']['last_name']);
    }

    public function test_type_is_registered_and_heuristic_classifies_it(): void
    {
        $this->assertArrayHasKey('meldebescheinigung', Document::AI_TYPES);
        $this->assertSame('identity', Document::AI_TYPES['meldebescheinigung']['category']);

        $r = (new HeuristicDocumentClassifier)->classify("Stadt Rendsburg\nMeldebestaetigung\nFamilienname: Muster");
        $this->assertNotNull($r);
        $this->assertSame('meldebescheinigung', $r['type']);
    }

    /**
     * Zweite verbreitete Bauform (Stadt Backnang u.a.): Spaltenlayout OHNE
     * Doppelpunkt, Beschriftung mit Klammer-Zusatz ("Vorname(n)") und die
     * Ueberschrift GESPERRT gesetzt ("M e l d e b e s t ä t i g u n g").
     */
    private function backnangText(string $vorname = 'Khaled', string $nachname = 'Najm', string $geburt = '13.12.2024'): string
    {
        return implode("\n", [
            'Stadt Backnang',
            'Rechts- und Ordnungsamt',
            'Bürgeramt',
            'Im Biegel 13',
            '71522 Backnang',
            'Stadt Backnang, Am Rathaus 1, 71522 Backnang',
            $vorname.' '.$nachname,
            'Gartenstraße 105',
            '71522 Backnang',
            'Sachbearbeitung: Lydia Klass',
            'Telefon: 07191 894-444',
            'E-Mail: l.klass@backnang.de',
            'Datum: 04.08.2026',
            'M e l d e b e s t ä t i g u n g (gemäß § 24 Abs. 2 BMG)',
            'Familienname          '.$nachname,
            'Vorname(n)            '.$vorname,
            'Geburtsdatum          '.$geburt,
            'Angemeldete Wohnung',
            'Wohnungsstatus        alleinige Wohnung',
            'Einzugsdatum          01.08.2026',
            'Anmeldedatum          04.08.2026',
            'Anschrift             Gartenstraße 105',
            '                      71522 Backnang',
            'Backnang, 04.08.2026',
        ]);
    }

    public function test_parses_column_layout_with_spaced_heading(): void
    {
        $r = (new MeldebestaetigungParser)->parse($this->backnangText());

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Najm', $p['last_name']);
        $this->assertSame('Khaled', $p['first_name']);
        $this->assertSame('2024-12-13', $p['birth_date']);
        // Die NEUE Anschrift des Kunden, nicht die der Behoerde (Im Biegel 13).
        $this->assertSame('Gartenstraße', $p['street']);
        $this->assertSame('105', $p['house_number']);
        $this->assertSame('71522', $p['zip']);
        $this->assertSame('Backnang', $p['city']);

        // Umzugs-Eckdaten stehen fuer den Mitarbeiter in der Zusammenfassung.
        $this->assertStringContainsString('eingezogen 01.08.2026', $r['summary']);
        $this->assertStringContainsString('angemeldet 04.08.2026', $r['summary']);
        $this->assertStringContainsString('Gartenstraße 105', $r['summary']);

        // Die Sachbearbeiterin der Behoerde ist NICHT der Kunde.
        $this->assertArrayNotHasKey('email', $p);
        $this->assertSame([], $r['data']['bank']);
    }

    /**
     * Dritte Bauform - dieselbe Bescheinigung als HANDYFOTO. Die OCR eines
     * Fotos kennt kein Spaltenraster und setzt zwischen Beschriftung und Wert
     * oft nur EIN Leerzeichen; genau daran scheiterte die Erkennung
     * vollstaendig (kein Name, kein Geburtsdatum, keine Anschrift).
     */
    public function test_parses_photo_ocr_with_single_spaces(): void
    {
        $text = implode("\n", [
            'Stadt Backnang',
            'Rechts- und Ordnungsamt',
            'Mohamad Najim',
            'Gartenstraße 105',
            '71522 Backnang',
            'Sachbearbeitung: Lydia Klass',
            'Telefon: 07191 894-444',
            'Datum: 04.08.2026',
            'M e l d e b e s t ä t i g u n g (gemäß § 24 Abs. 2 BMG)',
            'Familienname Najim',
            'Vorname(n) Mohamad',
            'Geburtsdatum 10.02.1999',
            'Angemeldete Wohnung',
            'Wohnungsstatus alleinige Wohnung',
            'Einzugsdatum 01.08.2026',
            'Anmeldedatum 04.08.2026',
            'Anschrift Gartenstraße 105',
            '71522 Backnang',
            'Backnang, 04.08.2026',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Najim', $p['last_name']);
        $this->assertSame('Mohamad', $p['first_name']);
        $this->assertSame('1999-02-10', $p['birth_date']);
        $this->assertSame('Gartenstraße', $p['street']);
        $this->assertSame('105', $p['house_number']);
        $this->assertSame('71522', $p['zip']);
        $this->assertSame('Backnang', $p['city']);
        $this->assertStringContainsString('eingezogen 01.08.2026', $r['summary']);
    }

    /**
     * Produktionsfall 06.08.2026: die Foto-OCR verliest das Label "Anschrift"
     * - Name und Geburtsdatum kamen an, die neue Adresse fehlte. Fallback:
     * das ANSCHRIFTFELD des Briefes traegt dieselbe neue Meldeadresse direkt
     * unter dem Kundennamen (Namens-Anker); die rechte Briefkopf-Spalte
     * (Sachbearbeitung/Telefon/...) klebt die OCR mit EINEM Leerzeichen an
     * und wird abgeschnitten.
     */
    public function test_unreadable_anschrift_label_falls_back_to_letter_window(): void
    {
        $text = implode("\n", [
            'Stadt Backnang',
            'Rechts- und Ordnungsamt',
            'Bürgeramt',
            'Im Biegel 13',
            '71522 Backnang',
            'Stadt Backnang, Am Rathaus 1, 71522 Backnang',
            'Mohamad Najim Sachbearbeitung: Lydia Klass',
            'Gartenstraße 105 Telefon: 07191 894-444',
            '71522 Backnang Telefax: 07191 894-133',
            'E-Mail: l.klass@backnang.de',
            'Datum: 04.08.2026',
            'M e l d e b e s t ä t i g u n g (gemäß § 24 Abs. 2 BMG)',
            'Familienname Najim',
            'Vorname(n) Mohamad',
            'Geburtsdatum 10.02.1999',
            'Angemeldete Wohnung',
            'Wohnungsstatus alleinige Wohnung',
            'Einzugsdatum 01.08.2026',
            'Anmeldedatum 04.08.2026',
            'Anschritt Gartenstraße 105',
            '71522 Backnang',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Najim', $p['last_name']);
        $this->assertSame('Mohamad', $p['first_name']);
        $this->assertSame('1999-02-10', $p['birth_date']);
        // Adresse aus dem Anschriftfeld des Briefes - NICHT "Im Biegel 13"
        // (Behoerde) und ohne die angeklebte rechte Spalte.
        $this->assertSame('Gartenstraße', $p['street']);
        $this->assertSame('105', $p['house_number']);
        $this->assertSame('71522', $p['zip']);
        $this->assertSame('Backnang', $p['city']);
        // Die Kontaktdaten der Sachbearbeiterin bleiben draussen.
        $this->assertArrayNotHasKey('email', $p);
        $this->assertArrayNotHasKey('phone', $p);
    }

    public function test_letter_window_never_takes_authority_address(): void
    {
        // Ohne Namens-Anker (kein Kundenname ueber einer Adresse) bleibt die
        // Adresse leer - die Behoerdenadresse landet NIE in der Kundenakte.
        $text = implode("\n", [
            'Stadt Backnang',
            'Bürgeramt',
            'Im Biegel 13',
            '71522 Backnang',
            'Meldebestätigung',
            'Familienname Muster',
            'Vorname(n) Karim',
            'Geburtsdatum 10.02.1999',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Muster', $r['data']['person']['last_name']);
        $this->assertArrayNotHasKey('street', $r['data']['person']);
        $this->assertArrayNotHasKey('zip', $r['data']['person']);
    }

    public function test_value_on_the_next_line_is_read(): void
    {
        // Die OCR bricht Beschriftung und Wert manchmal in zwei Zeilen um.
        $text = implode("\n", [
            'Meldebestätigung',
            'Familienname',
            'Najm',
            'Vorname(n)',
            'Khaled',
            'Geburtsdatum',
            '13.12.2024',
            'Anschrift',
            'Gartenstraße 105',
            '71522 Backnang',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Najm', $r['data']['person']['last_name']);
        $this->assertSame('Khaled', $r['data']['person']['first_name']);
        $this->assertSame('2024-12-13', $r['data']['person']['birth_date']);
        $this->assertSame('Gartenstraße', $r['data']['person']['street']);
    }

    public function test_empty_field_stays_empty_instead_of_taking_the_next_label(): void
    {
        // Leeres Feld: unter der Beschriftung folgt gleich die naechste -
        // dann bleibt der Wert leer statt die Beschriftung zu uebernehmen.
        $text = implode("\n", [
            'Meldebestätigung',
            'Familienname Najm',
            'Vorname(n)',
            'Geburtsdatum 13.12.2024',
        ]);

        $r = (new MeldebestaetigungParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Najm', $r['data']['person']['last_name']);
        $this->assertArrayNotHasKey('first_name', $r['data']['person']);
        $this->assertSame('2024-12-13', $r['data']['person']['birth_date']);
    }

    public function test_marks_minors_and_leaves_adults_unmarked(): void
    {
        // Kind (2024 geboren, Schreiben von 2026) -> Hinweis fuer den
        // Mitarbeiter; die Haushalts-Verknuepfung haengt daran.
        $kind = (new MeldebestaetigungParser)->parse($this->backnangText());
        $this->assertStringContainsString('MINDERJAEHRIG', $kind['summary']);

        $erwachsen = (new MeldebestaetigungParser)->parse(
            $this->backnangText('Mohamad', 'Najim', '10.02.1999')
        );
        $this->assertStringNotContainsString('MINDERJAEHRIG', $erwachsen['summary']);
        $this->assertSame('1999-02-10', $erwachsen['data']['person']['birth_date']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new MeldebestaetigungParser)->parse('Irgendein anderes Dokument'));
    }
}
