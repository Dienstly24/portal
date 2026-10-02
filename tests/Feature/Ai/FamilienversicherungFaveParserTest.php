<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\FamilienversicherungParser;
use App\Services\Ai\TemplateParsers\GeburtsurkundeParser;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Antrag auf beitragsfreie Familienversicherung (KKH-Vordruck 0765, "FAVE").
 * Betreiber-Auftrag 02.10.2026: "wenn ich den Antrag hochlade, soll das System
 * das Mitglied, die Ehefrau und die Kinder erkennen und sie automatisch unter
 * der Akte des Mitglieds anlegen."
 *
 * GEMESSENER AUSGANGSZUSTAND (echte PDF-Textebene, pdftotext -layout):
 * die Kette gab `geburtsurkunde` zurueck und darin als "Kind" den VORNAMEN DER
 * EHEFRAU - Mitglied, Partnerin und beide Kinder waren verloren. Ursache war
 * nicht eine, sondern ZWEI Luecken:
 *
 *  (1) Der vorhandene `FamilienversicherungParser` kannte nur die aeltere
 *      Bauform ("Vorname Name des Mitglieds", Spalte "Ehegatte"). Der
 *      KKH-Vordruck schreibt "Name, Vorname (Mitglied)" und
 *      "Ehe-/Lebenspartner" - die Erkennung griff nie.
 *  (2) Der `GeburtsurkundeParser` liess sich vom KLEINGEDRUCKTEN ausloesen:
 *      der Vordruck nennt dort die Urkunden, mit denen ein abweichender
 *      Familienname nachzuweisen ist ("z. B. Eheurkunde,
 *      Lebenspartnerschaftsurkunde, Geburtsurkunde"). Ein Hinweis auf eine
 *      Urkunde ist keine Urkunde.
 *
 * Die Vorlage unten ist ERFUNDEN, aber strukturgleich: Zeilen- und
 * Spaltenpositionen, Kaestchen-Glyphen und die zerlegte Geschlechts-Zeile
 * stammen 1:1 aus der gemessenen Textebene, nur die Personendaten sind
 * ausgetauscht (echte Kundendaten gehoeren nie ins Repository).
 */
class FamilienversicherungFaveParserTest extends TestCase
{
    use RefreshDatabase;

    private function antrag(): string
    {
        return <<<'TEXT'
Angaben zur beitragsfreien
Familienversicherung
Keine Lust auf Papier? Stellen Sie den Antrag doch einfach online! Mehr Informationen unter:
kkh.de/meine-kkh/antrag-familienversicherung

 Allgemeine Angaben des Mitglieds

Name, Vorname (Mitglied) Beispiel, Hamid

Anschrift                    Musterweg 12 24105 Kiel

Geburtsdatum                 14.04.1978                             KVNR      A123456789

Familienstand                ledig             X verheiratet
                                                                            getrennt lebend                       geschieden              verwitwet
                                                                                                                              1)
                             Eingetragene Lebenspartnerschaft nach dem Lebenspartnerschaftsgesetz – LPartG
Anlass für die Aufnahme meines/meiner Angehörigen in die Familienversicherung: X Beginn meiner Mitgliedschaft                            Geburt des Kindes  Heirat
                         Beendigung der vorherigen Mitgliedschaft des Angehörigen  Sonstiges
Ich bin tagsüber unter der Telefon-Nr.                                         (Angabe freiwillig)    Mobil-Nummer                                          (Angabe freiwillig)

oder per E-Mail                                                                                      (Angabe freiwillig) zu erreichen.


 Angaben zum Ehe-/Lebenspartner bei Familienversicherung von Kindern
Nachfolgende Daten sind grundsätzlich nur für solche Angehörigen erforderlich, die bei uns familienversichert werden sollen. Abweichend hiervon benö-
tigen wir einzelne Angaben zu Ihrem Ehe-/Lebenspartner auch dann, wenn bei uns ausschließlich die Familienversicherung für Ihre Kinder durchgeführt
werden soll und Ihr Ehe-/Lebenspartner mit diesen Kindern verwandt ist. In diesem Fall sind neben den allgemeinen Angaben die Informationen zur Ver-
sicherung des Ehe-/Lebenspartners und – sofern dieser nicht gesetzlich versichert ist – zusätzlich Angaben zu seinem Einkommen erforderlich; hierbei
sind die Einnahmen zwingend durch Einkommensnachweise zu belegen; Zuschläge, die mit Rücksicht auf den Familienstand gezahlt werden, bleiben bei
den Angaben zu den Einkünften unberücksichtigt.

Name, Vorname Nadia Beispiel                                            ggf. abweichende Anschrift

Mein Ehe-/Lebenspartner ist         selbst gesetzlich krankenversichert (Mitglied)         X familienversichert
                                                                                                                         nicht gesetzlich versichert.
Die bisherige Versicherung besteht weiter bei (Name der Krankenkasse/Krankenversicherung) BIG

Höhe der monatl. Einkünfte 0                                 !
Einkommensart (z. B. Gewinn aus selbstständiger Tätigkeit, Arbeitsentgelt etc.)


 Allgemeine Angaben zu Familienangehörigen

                                                                    Xw  m  x  d2) Kind  w 
                                                 Ehe-/Lebenspartner                          X m  x  d2)                                       X m  x  d2)
                                                                                                                                         Kind  w 

Familienversicherung wird beantragt ab (Datum)        01.09.2026                                     01.09.2026                           01.09.2026
Nachname                                              Beispiel                                Muster                                      Muster
Bei abweichendem Familiennamen zwischen dem Mitglied und dem Familienangehörigen sind die Personenstandsverhältnisse durch geeignete Urkunden (z. B. Eheurkunde,
Lebenspartnerschaftsurkunde, Geburtsurkunde) oder – sofern deren Vorlage nicht möglich ist – durch andere geeignete Unterlagen (z. B. Bescheid über Kindergeld) einmalig
nachzuweisen.

Vorname                                               Nadia                                   Jonas                                      Lina
                                                      09.07.1987                              11.02.2014                                 23.08.2019
Geburtsdatum

ggf. abweichende Anschrift



Verwandtschaftsverhältnis zum Mitglied                                                        X leibl. Kind
                                                                                              
                                                                                                              3)
                                                                                                                       Stiefkind        X leibl. Kind
                                                                                                                                         
                                                                                                                                                       3)
                                                                                                                                                              Stiefkind
                                                                                               Enkel                  Pflegekind        Enkel              Pflegekind
Der Ehe-/Lebenspartner des Mitglieds ist mit dem Kind verwandt.                                nein                                      nein
(Bitte nur beim fehlenden Verwandtschaftsverhältnis ankreuzen)


 Angaben zur Vorversicherung der Familienangehörigen
                                                                                                       Jonas                                    Lina
                                                     Ehe-/Lebenspartner                       Kind                                       Kind
Die bisherige Versicherung
                                                      30.08.2026                                30.08.2026                                30.08.2026
endete am (Datum)
                                                      BIG                                      BIG                                        BIG
bestand bei (Name der Krankenkasse)

Art der bisherigen Versicherung                       gesetzlich versichert (Mitglied)  gesetzlich versichert (Mitglied)  gesetzlich versichert (Mitglied)
                                                     X gesetzlich familienversichert
                                                                                         X gesetzlich familienversichert
                                                                                                                              X gesetzlich familienversichert
                                                                                                                               
                                                      privat/nicht gesetzlich versichert  privat/nicht gesetzlich versichert  privat/nicht gesetzlich versichert
Zuletzt familienversichert über die
Mitgliedschaft von (Name, Vorname)

 Angaben zur Vergabe einer Krankenversichertennummer für familienversicherte Angehörige (bitte immer ausfüllen)

                                            Ehe-/Lebenspartner                 Kind   Jonas                         Kind    Lina

Rentenversicherungsnummer

Die folgenden Angaben werden nur dann benötigt, wenn noch keine Rentenversicherungsnummer vergeben wurde:

Geburtsname
                                               LARNAKA                         LARNAKA                               Buchholz
Geburtsort
                                               LBN                             LBN                                   LBN
Geburtsland
                                              LBN                               LBN                                  LBN
Staatsangehörigkeit
TEXT;
    }

    private function parse(?string $text = null): ?array
    {
        return (new FamilienversicherungParser)->parse($text ?? $this->antrag());
    }

    public function test_der_vordruck_wird_als_familienversicherung_erkannt(): void
    {
        $r = $this->parse();

        $this->assertNotNull($r, 'Der KKH-Vordruck 0765 wird nicht erkannt.');
        $this->assertSame('familienversicherung', $r['type']);
    }

    public function test_das_mitglied_steht_im_kopf_mit_nachname_vorname(): void
    {
        $person = $this->parse()['data']['person'];

        // Die Beschriftung nennt die Reihenfolge: "Name, Vorname (Mitglied)".
        $this->assertSame('Hamid', $person['first_name']);
        $this->assertSame('Beispiel', $person['last_name']);
        $this->assertSame('1978-04-14', $person['birth_date']);
        $this->assertSame('verheiratet', $person['marital_status']);
    }

    public function test_die_anschrift_des_mitglieds_steht_ohne_trennzeichen_in_einer_zelle(): void
    {
        $person = $this->parse()['data']['person'];

        // "Musterweg 12 24105 Kiel" - Anker ist die PLZ, der Vordruck hat kein
        // Trennzeichen. Die Hausnummer trennt die gemeinsame Feldpruefung.
        $this->assertSame('Musterweg', $person['street']);
        $this->assertSame('12', $person['house_number']);
        $this->assertSame('24105', $person['zip']);
        $this->assertSame('Kiel', $person['city']);
    }

    public function test_partner_und_beide_kinder_stehen_in_personen(): void
    {
        $personen = $this->parse()['data']['personen'];

        $this->assertCount(3, $personen, 'Partner und beide Kinder muessen gelesen werden.');
        $this->assertSame(['Nadia', 'Jonas', 'Lina'], array_column($personen, 'first_name'));
    }

    public function test_die_rolle_kommt_aus_der_kopfzeile_nicht_aus_dem_nachnamen(): void
    {
        $personen = $this->parse()['data']['personen'];

        // Der Vordruck rechnet ausdruecklich mit abweichenden Familiennamen -
        // hier heissen Mitglied (Beispiel), Partnerin (Beispiel) und Kinder
        // (Muster) nicht gleich. Ein Namensabgleich haette nichts verknuepft.
        $this->assertSame('ehepartner', $personen[0]['relation']);
        $this->assertSame('kind', $personen[1]['relation']);
        $this->assertSame('kind', $personen[2]['relation']);
        $this->assertSame('Beispiel', $personen[0]['last_name']);
        $this->assertSame('Muster', $personen[1]['last_name']);
    }

    public function test_geburtsdaten_stehen_ueber_ihrer_beschriftung(): void
    {
        $personen = $this->parse()['data']['personen'];

        $this->assertSame('1987-07-09', $personen[0]['birth_date']);
        $this->assertSame('2014-02-11', $personen[1]['birth_date']);
        $this->assertSame('2019-08-23', $personen[2]['birth_date']);
    }

    public function test_geburtsort_und_staatsangehoerigkeit_kommen_von_seite_zwei(): void
    {
        $personen = $this->parse()['data']['personen'];

        // Seite 2 hat ein EIGENES Spaltenraster (gemessen 47/79/117 gegen
        // 54/94/137 auf Seite 1). Mit dem Raster der ersten Seite fielen alle
        // drei Werte auf dieselbe Spalte und nur der erste ueberlebte.
        $this->assertSame('LARNAKA', $personen[0]['birth_place']);
        $this->assertSame('LARNAKA', $personen[1]['birth_place']);
        $this->assertSame('Buchholz', $personen[2]['birth_place']);
        $this->assertSame('LBN', $personen[2]['nationality']);
    }

    public function test_das_geschlecht_kommt_aus_dem_angekreuzten_kaestchen(): void
    {
        $personen = $this->parse()['data']['personen'];

        // Die Kaestchen-Zeile kommt aus der Textebene ZERLEGT an (das Kreuz
        // ist ein eigenes Textobjekt); gelesen wird der Buchstabe direkt
        // hinter dem Kreuz.
        $this->assertSame('female', $personen[0]['gender']);
        $this->assertSame('male', $personen[1]['gender']);
    }

    public function test_ein_zweites_kreuz_in_derselben_spalte_laesst_das_geschlecht_leer(): void
    {
        // Zwei Kreuze sind ein Lesefehler, keine Angabe - ein geratenes
        // Geschlecht erzeugt eine falsche Anrede im Schreiben.
        $text = str_replace('X m '."\u{F0A3}".' x', 'X m X x', $this->antrag());
        $personen = $this->parse($text)['data']['personen'];

        $this->assertArrayNotHasKey('gender', $personen[1]);
    }

    public function test_stiefkind_bekommt_keine_kind_rolle(): void
    {
        // Ein Stiefkind ist KEIN Kind des Mitglieds. Die Beziehung entsteht,
        // die Rolle vergibt ein Mensch.
        $text = str_replace('X leibl. Kind', 'X Stiefkind', $this->antrag());
        $personen = $this->parse($text)['data']['personen'];

        $this->assertSame('angehoerig', $personen[1]['relation']);
        $this->assertSame('angehoerig', $personen[2]['relation']);
    }

    public function test_ein_antrag_traegt_keine_vertragsnummer(): void
    {
        $versicherung = $this->parse()['data']['versicherung'];

        $this->assertSame('antrag', $versicherung['document_stage']);
        $this->assertArrayNotHasKey('contract_number', $versicherung);
        $this->assertSame('krankenversicherung', $versicherung['sparte']);
        // Frueheste beantragte Familienversicherung.
        $this->assertSame('2026-09-01', $versicherung['start_date']);
    }

    public function test_kasse_kvnr_und_vorkasse(): void
    {
        $gesundheit = $this->parse()['data']['gesundheit'];

        $this->assertSame('KKH', $gesundheit['health_insurance_company']);
        $this->assertSame('gesetzlich', $gesundheit['health_insurance_type']);
        $this->assertSame('A123456789', $gesundheit['health_insurance_number']);
        $this->assertSame('BIG', $gesundheit['previous_insurer']);
    }

    public function test_die_service_adresse_der_kasse_wird_nie_kundenkontakt(): void
    {
        $data = $this->parse()['data'];

        // Der Brieffuss nennt service@kkh.de - die OCR-Heuristik hatte diese
        // Adresse als E-Mail des Kunden uebernommen (dieselbe Klasse wie
        // KI-050 bei der Mitgliedsbescheinigung).
        $this->assertStringNotContainsStringIgnoringCase('@kkh.de', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_die_echte_kette_aus_dem_container_liefert_den_antrag(): void
    {
        // Ein Parser zu schreiben genuegt nicht - er muss VOR jedem
        // generischeren stehen, der denselben Text ebenfalls beansprucht.
        // Gemessen lieferte die Kette hier "geburtsurkunde".
        $r = app(DocumentTemplateParser::class)->parse($this->antrag());

        $this->assertNotNull($r);
        $this->assertSame('familienversicherung', $r['type']);
    }

    public function test_der_geburtsurkunden_parser_beansprucht_den_antrag_nicht_mehr(): void
    {
        // Das Wort steht nur im Kleingedruckten ("z. B. Eheurkunde,
        // Lebenspartnerschaftsurkunde, Geburtsurkunde").
        $this->assertStringContainsString('Geburtsurkunde', $this->antrag());
        $this->assertNull((new GeburtsurkundeParser)->parse($this->antrag()));
    }

    public function test_die_aeltere_bauform_wird_weiter_gelesen(): void
    {
        // Bauform (1) bleibt unberuehrt: Werte stehen UEBER der Beschriftung.
        $alt = implode("\n", [
            'Fragebogen zur Familienversicherung',
            'Hussam Beispiel',
            'Vorname Name des Mitglieds',
            'Beginn der Familienversicherung: 01.03.2026',
            '                    Ehegatte            Kind                Kind',
            'Vorname             Leila               Omar                Yara',
            'Geburtsdatum        01.02.1990          02.03.2015          03.04.2019',
        ]);

        $r = $this->parse($alt);

        $this->assertNotNull($r);
        $this->assertSame('familienversicherung', $r['type']);
        $this->assertSame('Hussam', $r['data']['person']['first_name']);
    }

    public function test_fremde_dokumente_loesen_nicht_aus(): void
    {
        $this->assertNull($this->parse('Irgendein anderes Dokument'));
        // Nur das Stichwort genuegt nicht.
        $this->assertNull($this->parse('Hinweise zur Familienversicherung Ihrer Angehoerigen'));
    }

    public function test_der_zweck_des_vordrucks_sind_die_angehoerigen(): void
    {
        // "Zweck vor erkannt": dieser Antrag wird FUER die Angehoerigen
        // hochgeladen. Ein Ergebnis mit dem Mitglied allein ist nicht fertig,
        // auch wenn die Erkennung formal gegriffen hat.
        $r = $this->parse();

        $this->assertSame(['personen'], $r['pflichtangaben']);
    }

    public function test_ohne_lesbare_angehoerige_liest_die_ki_das_formular(): void
    {
        // Spaltentabelle zerstoert (ein Scan, der das Raster verliert): der
        // Parser greift weiter, aber das Ergebnis traegt keine Angehoerigen.
        $ohneTabelle = (string) preg_replace('/^(Nachname|Vorname)\s{2,}.*$/mu', '$1', $this->antrag());

        $ki = $this->kiProvider([
            'type' => 'familienversicherung', 'confidence' => 90, 'summary' => 'ok', 'title' => null,
            'data' => [
                'person' => ['first_name' => 'Hamid', 'last_name' => 'Beispiel'],
                'personen' => [['first_name' => 'Nadia', 'last_name' => 'Beispiel', 'relation' => 'ehepartner']],
            ],
        ]);

        $r = $this->analysieren($ohneTabelle, $ki);

        $this->assertTrue($ki->called, 'Ohne Angehoerige muss die KI das Formular lesen.');
        $this->assertCount(1, $r['data']['personen']);
        // Das Steuersignal wird nie gespeichert.
        $this->assertArrayNotHasKey('pflichtangaben', $r);
    }

    public function test_ein_vollstaendig_gelesener_antrag_kostet_keine_ki(): void
    {
        $ki = $this->kiProvider(null);

        $r = $this->analysieren($this->antrag(), $ki);

        $this->assertFalse($ki->called, 'Der vollstaendig gelesene Antrag darf nichts kosten.');
        $this->assertSame('template', $r['source']);
        $this->assertSame('familienversicherung', $r['type']);
        $this->assertCount(3, $r['data']['personen']);
        $this->assertArrayNotHasKey('pflichtangaben', $r);
    }

    /** @param array<string,mixed> $antwort */
    private function analysieren(string $text, DocumentAiProviderInterface $ki): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/antrag.jpg', 'bild');
        $dokument = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'antrag.jpg',
            'file_path' => 'docs/antrag.jpg',
            'disk' => 'local',
            'visibility' => 'staff',
            'ai_status' => 'pending',
        ]);

        $ocr = new class($text) implements TextExtractorInterface {
            public function __construct(private string $text) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $binary, string $mime): string
            {
                return $this->text;
            }
        };
        $keineTextebene = new class extends PdfTextLayerExtractor {
            public function __construct() {}

            public function isAvailable(): bool
            {
                return false;
            }

            public function extract(string $binary): string
            {
                return '';
            }
        };

        return (new DocumentAnalyzer($ki, $ocr, $keineTextebene, new RelevantPageSelector, app(DocumentTemplateParser::class)))
            ->analyze($dokument);
    }

    private function kiProvider(?array $antwort): DocumentAiProviderInterface
    {
        return new class($antwort) implements DocumentAiProviderInterface {
            public bool $called = false;

            public function __construct(private ?array $antwort) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function model(): string
            {
                return 'fake';
            }

            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
            {
                $this->called = true;

                return $this->antwort;
            }
        };
    }
}
