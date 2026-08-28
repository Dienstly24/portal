<?php

namespace Tests\Feature;

use App\Models\CommissionAuditLog;
use App\Models\CommissionImport;
use App\Models\CommissionImportRow;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Models\Customer;
use App\Models\User;
use App\Services\CommissionImport\CommissionImportService;
use App\Services\CommissionImport\ColumnMap;
use App\Services\CommissionImport\ColumnMap as Cols;
use App\Services\CommissionImport\CommissionSourceProfile;
use App\Services\CommissionImport\CsvTableReader;
use App\Services\CommissionImport\PersonNameParser;
use App\Services\CommissionImport\ValueParser;
use App\Services\CommissionImport\XlsxTableReader;
use App\Support\CommissionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import von Provisionsdaten aus Fremdsystemen und ihre Bindung an den
 * Vertrag (Betreiber-Auftrag 26.08.2026).
 *
 * Die Tests halten die Zusagen fest, auf die sich der Betrieb verlaesst:
 *  - CSV und Excel werden AM INHALT erkannt, nicht an der Endung,
 *  - deutsche Trennzeichen, BOM und Latin-1 sind kein Sonderfall,
 *  - zugeordnet wird ueber die Interne Vertragsnummer (bzw. Id/Auftr.-Nr.),
 *  - nichts wird geschrieben, bevor der Admin bestaetigt,
 *  - derselbe Datensatz entsteht nie zweimal,
 *  - Provisionsdaten erreichen den Kunden NIRGENDS.
 */
class ContractCommissionImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ----------------------------------------------------------- Hilfsmittel

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name = 'Max Mustermann'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($name . $user->id), 0, 8)),
        ]);
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'Allianz',
            'status' => 'active',
        ], $overrides));
    }

    /** Datei im Testverzeichnis ablegen und Pfad zurueckgeben. */
    private function file(string $content, string $name = 'abrechnung.csv'): string
    {
        $dir = storage_path('framework/testing/commission');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . uniqid() . '-' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** Die Bauform des Maklerpool-Exports: UTF-8 mit BOM, Semikolon. */
    private function poolCsv(array $rows): string
    {
        $header = 'Abrechnungsnummer;Abrechnungsdatum;Vertragsnummer extern;Vertragsnummer intern;'
            . 'Kunde;Produktname;Gesellschaft;Sparte;Provisionsbetrag;Provisionsart;Kontoinhaber';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(';', [
                $row['abrechnung'] ?? '5335200-26',
                $row['datum'] ?? '2026-08-25 11:18:55',
                $row['extern'] ?? '2793227640',
                $row['intern'] ?? 'V19613073',
                $row['kunde'] ?? 'VN Mustermann, Max',
                $row['produkt'] ?? 'Gewerbe-Haftpflicht',
                $row['gesellschaft'] ?? 'Dialog Versicherung AG',
                $row['sparte'] ?? 'Sach',
                $row['betrag'] ?? '4,10',
                $row['art'] ?? 'Abschlussprovision',
                $row['empfaenger'] ?? 'Ahmad Albhre',
            ]);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private function service(): CommissionImportService
    {
        return app(CommissionImportService::class);
    }

    // ------------------------------------------------ 1. Dateien LESEN

    public function test_csv_mit_bom_und_semikolon_wird_erkannt(): void
    {
        $path = $this->file($this->poolCsv([[]]));
        $table = app(\App\Services\CommissionImport\TableReader::class)->read($path);

        $this->assertSame('csv', $table->format);
        $this->assertSame(';', $table->delimiter);
        $this->assertSame('UTF-8 (BOM)', $table->encoding);
        // Ohne BOM-Entfernung hiesse die erste Spalte "\xEF\xBB\xBFAbrechnungsnummer"
        // und wuerde vom Spalten-Vorschlag nie getroffen.
        $this->assertSame('Abrechnungsnummer', $table->header[0]);
        $this->assertSame('Vertragsnummer intern', $table->header[3]);
    }

    public function test_latin1_datei_behaelt_ihre_umlaute(): void
    {
        $utf8 = "VP-Name;Auftr.-Nr.;Kunden;Tarif/Produkt;Provision\n"
            . "Herr Müller;1672525;Frau Schröder;RheinEnergie AG - Fair Ökostrom 24;25,00\n";
        $path = $this->file((string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'), 'order.csv');

        $table = (new CsvTableReader())->read($path);

        $this->assertStringContainsString('ISO-8859-1', (string) $table->encoding);
        $this->assertSame('Frau Schröder', $table->rows[0][2]);
        $this->assertStringContainsString('Ökostrom', $table->rows[0][3]);
    }

    public function test_komma_in_adressen_kippt_die_trennzeichen_erkennung_nicht(): void
    {
        // Genau der Fall aus der echten Datei: die Adresse enthaelt ein
        // Komma, das Trennzeichen ist aber das Semikolon.
        $csv = "Auftr.-Nr.;Kunden;Anschrift;Provision\n"
            . "1672525;\"Herr Muster\";\"Alte Kieler Landstr. 141, 24768 Rendsburg\";25,00\n"
            . "1672519;\"Frau Muster\";\"Ostlandstr. 40, 24768 Rendsburg\";25,00\n";

        $this->assertSame(';', (new CsvTableReader())->detectDelimiter($csv));
    }

    public function test_tabulator_getrennte_datei_wird_erkannt(): void
    {
        $csv = "Interne Vertragsnummer\tProvision\tProvisionsdatum\n"
            . "V19613073\t100,00\t15.08.2026\n";
        $table = (new CsvTableReader())->readString($csv);

        $this->assertSame("\t", $table->delimiter);
        $this->assertSame(['Interne Vertragsnummer', 'Provision', 'Provisionsdatum'], $table->header);
    }

    public function test_excel_datei_wird_am_inhalt_erkannt_auch_bei_falscher_endung(): void
    {
        // Eine echte XLSX-Datei, aber unter dem Namen "abrechnung.csv" -
        // genau so kommen Dateien aus Fremdsystemen regelmaessig an.
        $path = $this->file($this->buildXlsx(), 'abrechnung.csv');

        $reader = app(\App\Services\CommissionImport\TableReader::class);
        $this->assertSame('xlsx', $reader->detectFormat($path));

        $table = $reader->read($path);
        $this->assertSame('xlsx', $table->format);
        $this->assertSame(['Interne Vertragsnummer', 'Provision', 'Provisionsdatum'], $table->header);
        $this->assertSame('V19613073', $table->rows[0][0]);
        $this->assertSame('850', $table->rows[0][1]);
        // Die Seriennummer wird nur dann zum Datum, wenn ihr Zellformat eines
        // ist - sonst stuende hier "46249".
        $this->assertSame('15.08.2026', $table->rows[0][2]);
    }

    public function test_excel_mit_mehreren_blaettern_nennt_alle_und_liest_das_gewaehlte(): void
    {
        $path = $this->file($this->buildXlsx(twoSheets: true), 'mappe.xlsx');
        $reader = new XlsxTableReader();

        $this->assertSame(['Deckblatt', 'Abrechnung'], $reader->sheetNames($path));

        // Das ERSTE Blatt ist bewusst NICHT das richtige.
        $first = $reader->read($path);
        $this->assertSame('Deckblatt', $first->sheetName);

        $chosen = $reader->read($path, 'Abrechnung');
        $this->assertSame('Abrechnung', $chosen->sheetName);
        $this->assertSame('V19613073', $chosen->rows[0][0]);
    }

    public function test_altes_xls_format_wird_gelesen(): void
    {
        // .xls ist kein ZIP, sondern ein OLE-Verbunddokument. Der Test baut
        // eine echte (minimale) Datei, damit der Leser gegen die STRUKTUR
        // geprueft wird und nicht gegen unsere Annahme darueber.
        $path = $this->file($this->buildXls(), 'abrechnung.xls');

        $reader = app(\App\Services\CommissionImport\TableReader::class);
        $this->assertSame('xls', $reader->detectFormat($path));

        $table = $reader->read($path);
        $this->assertSame('xls', $table->format);
        $this->assertSame('Abrechnung', $table->sheetName);
        $this->assertSame(['Interne Vertragsnummer', 'Provision', 'Provisionsdatum'], $table->header);
        $this->assertSame('V19613073', $table->rows[0][0]);
        $this->assertSame('850', $table->rows[0][1]);
        // Die Seriennummer wird nur dann zum Datum, wenn ihr Zellformat eines
        // ist - sonst stuende hier "46249".
        $this->assertSame('15.08.2026', $table->rows[0][2]);
    }

    public function test_xls_datei_laesst_sich_importieren(): void
    {
        $contract = $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->buildXls(), 'abrechnung.xls'), 'abrechnung.xls');

        $this->assertSame('xls', $import->format);
        $this->assertSame(1, $import->rows_new);

        $this->service()->confirm($import);
        $commission = ContractCommission::sole();
        $this->assertSame($contract->id, $commission->contract_id);
        $this->assertSame('850.00', (string) $commission->amount);
        $this->assertSame('2026-08-15', $commission->commission_date?->format('Y-m-d'));
    }

    // ------------------------------------------------ 2. Werte DEUTEN

    public function test_deutsche_und_englische_zahlen(): void
    {
        $this->assertSame(1234.56, ValueParser::amount('1.234,56'));
        $this->assertSame(1234.56, ValueParser::amount('1,234.56'));
        $this->assertSame(4.1, ValueParser::amount('4,10'));
        $this->assertSame(75.0, ValueParser::amount('75'));
        $this->assertSame(12.34, ValueParser::amount('12.34'));
        $this->assertSame(1234.0, ValueParser::amount('1.234'));
        $this->assertSame(-50.0, ValueParser::amount('-50,00 €'));
        $this->assertNull(ValueParser::amount(''));
        $this->assertNull(ValueParser::amount('keine Angabe'));
    }

    public function test_datumsformate_und_das_was_kein_datum_ist(): void
    {
        $this->assertSame('2026-08-15', ValueParser::date('15.08.2026')?->format('Y-m-d'));
        $this->assertSame('2026-08-25', ValueParser::date('2026-08-25 11:18:55')?->format('Y-m-d'));
        $this->assertSame('2026-08-15', ValueParser::date('15/08/2026')?->format('Y-m-d'));
        // Kein gueltiger Tag - wird NIE still auf den Folgemonat geschoben.
        $this->assertNull(ValueParser::date('31.02.2026'));
        $this->assertNull(ValueParser::date('schnellstmöglich'));
    }

    public function test_spaltenvorschlag_erkennt_die_echten_formate(): void
    {
        $pool = ColumnMap::suggest(['Abrechnungsnummer', 'Abrechnungsdatum', 'Vertragsnummer extern',
            'Vertragsnummer intern', 'Kunde', 'Produktname', 'Gesellschaft', 'Sparte',
            'Provisionsbetrag', 'Provisionsart', 'Kontoinhaber']);

        $this->assertSame(3, $pool['internal_contract_number']);
        $this->assertSame(2, $pool['external_contract_number']);
        $this->assertSame(8, $pool['amount']);
        $this->assertSame(1, $pool['commission_date']);
        $this->assertSame(10, $pool['recipient_name']);

        $portal = ColumnMap::suggest(['Datum', 'Produkt', 'Id', 'Status', 'Provision', 'Tracking-Id',
            'Stornogrund', 'Referenz-Nr.']);
        $this->assertSame(2, $portal['vermittler_id']);
        $this->assertSame(4, $portal['amount']);
        $this->assertSame(7, $portal['reference_number']);
    }

    public function test_ihre_auftragsnummer_stiehlt_der_auftragsnummer_nicht_die_spalte(): void
    {
        // Beide Spalten stehen in der echten Datei nebeneinander. Ein
        // Teiltreffer wuerde die falsche nehmen.
        $map = ColumnMap::suggest(['VP-Name', 'Auftr.-Nr.', 'Ihre Auftr.-Nr.', 'Anlagedatum', 'Provision']);
        $this->assertSame(1, $map['order_number']);
    }

    // ------------------------------------------------ 3. Der ENTWURF schreibt nichts

    public function test_analyse_legt_nur_einen_entwurf_an(): void
    {
        $contract = $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $path = $this->file($this->poolCsv([[]]));

        $import = $this->service()->analyze($path, 'abrechnung.csv');

        $this->assertSame(CommissionImport::ENTWURF, $import->status);
        $this->assertSame(1, $import->rows_total);
        $this->assertSame(1, $import->rows_new);
        // ENTSCHEIDEND: noch keine einzige Provision in der Datenbank.
        $this->assertSame(0, ContractCommission::count());
        $this->assertSame(1, CommissionImportRow::where('import_id', $import->id)->count());
        $this->assertSame($contract->id, CommissionImportRow::first()->contract_id);
    }

    public function test_bestaetigung_schreibt_und_ordnet_dem_vertrag_zu(): void
    {
        $contract = $this->contract($this->customer('Max Mustermann'), ['internal_contract_number' => 'V19613073']);
        $path = $this->file($this->poolCsv([[]]));

        $import = $this->service()->analyze($path, 'abrechnung.csv');
        $import = $this->service()->confirm($import);

        $this->assertSame(CommissionImport::IMPORTIERT, $import->status);
        $commission = ContractCommission::sole();
        $this->assertSame($contract->id, $commission->contract_id);
        $this->assertSame((string) $contract->customer_id, (string) $commission->customer_id);
        $this->assertSame('V19613073', $commission->internal_contract_number);
        $this->assertSame('4.10', (string) $commission->amount);
        $this->assertSame('Abschlussprovision', $commission->commission_type);
        $this->assertSame('Ahmad Albhre', $commission->recipient_name);
        $this->assertSame(CommissionStatus::OFFEN, $commission->status);
        $this->assertSame('Max Mustermann', $commission->customer_label);
    }

    public function test_verworfener_entwurf_schreibt_nichts(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv');

        $this->service()->discard($import);

        $this->assertSame(CommissionImport::VERWORFEN, $import->fresh()->status);
        $this->assertSame(0, ContractCommission::count());
    }

    // ------------------------------------------------ 4. Kein DUPLIKAT

    public function test_dieselbe_datei_zweimal_erzeugt_keine_zweite_provision(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $csv = $this->poolCsv([[]]);

        $first = $this->service()->confirm($this->service()->analyze($this->file($csv), 'abrechnung.csv'));
        $this->assertSame(1, ContractCommission::count());

        $second = $this->service()->analyze($this->file($csv), 'abrechnung.csv');
        $this->assertSame(0, $second->rows_new);
        $this->assertSame(1, $second->rows_duplicate);

        $this->service()->confirm($second);
        $this->assertSame(1, ContractCommission::count());
    }

    public function test_geaenderte_zeile_wird_aktualisiert_nicht_verdoppelt(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'lauf1.csv'));

        // Dieselbe Position, jetzt mit Stornogrund/Status - der Betrag bleibt
        // gleich, deshalb derselbe natuerliche Schluessel.
        $csv = "Abrechnungsnummer;Abrechnungsdatum;Vertragsnummer intern;Provisionsbetrag;Provisionsart;Status\n"
            . "5335200-26;2026-08-25 11:18:55;V19613073;4,10;Abschlussprovision;bezahlt\n";
        $second = $this->service()->analyze($this->file($csv), 'lauf2.csv');

        $this->assertSame(1, $second->rows_updated);
        $this->service()->confirm($second);

        $this->assertSame(1, ContractCommission::count());
        $this->assertSame(CommissionStatus::BEZAHLT, ContractCommission::sole()->status);
    }

    public function test_zwei_positionen_desselben_vertrags_am_selben_tag_bleiben_getrennt(): void
    {
        // Aus der echten Abrechnung: derselbe Vertrag, dieselbe Art,
        // dasselbe Datum - zwei verschiedene Betraege.
        $this->contract($this->customer(), ['internal_contract_number' => 'V19546846']);
        $csv = $this->poolCsv([
            ['intern' => 'V19546846', 'betrag' => '4,11'],
            ['intern' => 'V19546846', 'betrag' => '8,97'],
        ]);

        $import = $this->service()->analyze($this->file($csv), 'abrechnung.csv');

        $this->assertSame(2, $import->rows_new);
        $this->assertSame(0, $import->rows_duplicate);
        $this->service()->confirm($import);
        $this->assertSame(2, ContractCommission::count());
    }

    public function test_gleiche_zeile_zweimal_in_einer_datei_sind_zwei_positionen(): void
    {
        // LEHRE aus der echten Abrechnung: derselbe Vertrag steht dort mit
        // demselben Betrag am selben Tag bis zu ZEHNMAL - das sind zehn
        // Fälligkeiten, nicht ein Datensatz, der neunmal zu viel da ist.
        // Sie als Duplikate zu verwerfen hätte die Abrechnung verfälscht.
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->poolCsv([[], [], []])), 'abrechnung.csv');

        $this->assertSame(3, $import->rows_new);
        $this->assertSame(0, $import->rows_duplicate);
        $this->service()->confirm($import);
        $this->assertSame(3, ContractCommission::count());
        $this->assertSame('12.30', number_format((float) ContractCommission::sum('amount'), 2, '.', ''));
    }

    public function test_dieselbe_datei_mit_wiederholten_positionen_bleibt_idempotent(): void
    {
        // Die Position innerhalb der Datei geht in den Schlüssel ein. Weil
        // die Reihenfolge einer Datei feststeht, ergibt derselbe Upload
        // wieder dieselben Schlüssel - sonst wäre die Doppel-Import-Sperre
        // durch die Positionszählung ausgehebelt.
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $csv = $this->poolCsv([[], [], []]);

        $this->service()->confirm($this->service()->analyze($this->file($csv), 'lauf1.csv'));
        $this->assertSame(3, ContractCommission::count());

        $second = $this->service()->analyze($this->file($csv), 'lauf2.csv');
        $this->assertSame(3, $second->rows_duplicate);
        $this->assertSame(0, $second->rows_new);
        $this->service()->confirm($second);
        $this->assertSame(3, ContractCommission::count());
    }

    // ------------------------------------------------ 5. NIE RATEN

    public function test_unbekannte_vertragsnummer_legt_keinen_vertrag_an(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->poolCsv([['intern' => 'V99999999']])), 'abrechnung.csv');

        $this->assertSame(1, $import->rows_unmatched);
        $this->assertSame(0, $import->rows_new);
        $this->service()->confirm($import);

        // Es entsteht KEIN Vertrag - die Provision selbst bleibt aber
        // erhalten und wartet unter "Nicht zugeordnet" auf eine Entscheidung.
        $this->assertSame(1, Contract::count());
        $this->assertSame(1, ContractCommission::count());
        $this->assertNull(ContractCommission::sole()->contract_id);
        $this->assertStringContainsString('Kein Vertrag gefunden', (string) CommissionImportRow::first()->message);
    }

    public function test_gleiche_interne_nummer_an_zwei_vertraegen_wird_nicht_geraten(): void
    {
        $customer = $this->customer();
        $this->contract($customer, ['internal_contract_number' => 'V19613073']);
        $this->contract($customer, ['internal_contract_number' => 'V19613073', 'type' => 'haftpflicht']);

        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv');

        $this->assertSame(1, $import->rows_unmatched);
        $this->assertStringContainsString('trifft 2 Verträge', (string) CommissionImportRow::first()->message);
    }

    public function test_ungueltiger_betrag_und_datum_werden_als_fehler_gemeldet(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $csv = "Vertragsnummer intern;Provisionsbetrag;Provisionsdatum\n"
            . "V19613073;keine Angabe;15.08.2026\n"
            . "V19613073;100,00;irgendwann\n";

        $import = $this->service()->analyze($this->file($csv), 'abrechnung.csv');

        $this->assertSame(2, $import->rows_invalid);
        $messages = CommissionImportRow::orderBy('row_number')->pluck('message')->all();
        $this->assertStringContainsString('Provisionsbetrag ist ungültig', $messages[0]);
        $this->assertStringContainsString('Provisionsdatum konnte nicht erkannt werden', $messages[1]);
    }

    public function test_zeile_ohne_kennung_wird_nie_ueber_den_namen_zugeordnet(): void
    {
        $this->contract($this->customer('Max Mustermann'), ['internal_contract_number' => 'V19613073']);
        $csv = "Vertragsnummer intern;Kunde;Provisionsbetrag\n;Max Mustermann;100,00\n";

        $import = $this->service()->analyze($this->file($csv), 'abrechnung.csv');

        $this->assertSame(1, $import->rows_invalid);
        $this->assertSame(0, ContractCommission::count());
    }

    public function test_abweichende_interne_nummer_am_vertrag_fuehrt_zu_pruefung(): void
    {
        // Zuordnung ueber die Vermittler-Id gelingt, die interne Nummer in der
        // Datei widerspricht aber der gespeicherten. Das entscheidet ein Mensch.
        $this->contract($this->customer(), [
            'vermittler_id' => '9753224',
            'internal_contract_number' => 'V11111111',
        ]);
        $csv = "Id;Vertragsnummer intern;Provisionsbetrag\n9753224;V19613073;100,00\n";

        $import = $this->service()->analyze($this->file($csv), 'abrechnung.csv');

        $this->assertSame(1, $import->rows_unmatched);
        $this->assertStringContainsString('weicht vom Vertrag ab', (string) CommissionImportRow::first()->message);
    }

    // ------------------------------------------------ 6. Die BRUECKE

    public function test_fehlende_interne_nummer_wird_am_vertrag_ergaenzt(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1477-6741-9200-53']);
        $csv = "Referenz-Nr.;Vertragsnummer intern;Provisionsbetrag\n"
            . "1477-6741-9200-53;V19613073;100,00\n";

        $this->service()->confirm($this->service()->analyze($this->file($csv), 'abrechnung.csv'));

        // Ab jetzt genuegt die interne Nummer allein.
        $this->assertSame('V19613073', $contract->fresh()->internal_contract_number);

        $second = $this->service()->analyze(
            $this->file("Vertragsnummer intern;Provisionsbetrag;Provisionsdatum\nV19613073;250,00;01.09.2026\n"),
            'folgeabrechnung.csv'
        );
        $this->assertSame(1, $second->rows_new);
        $this->assertSame($contract->id, CommissionImportRow::where('import_id', $second->id)->first()->contract_id);
    }

    public function test_vorhandene_kennung_wird_nie_ueberschrieben(): void
    {
        $contract = $this->contract($this->customer(), [
            'internal_contract_number' => 'V19613073',
            'reference_number' => '1111-2222-3333-44',
        ]);
        $csv = "Vertragsnummer intern;Referenz-Nr.;Provisionsbetrag\nV19613073;9999-8888-7777-66;100,00\n";

        $this->service()->confirm($this->service()->analyze($this->file($csv), 'abrechnung.csv'));

        $this->assertSame('1111-2222-3333-44', $contract->fresh()->reference_number);
    }

    public function test_auftragsnummer_des_energieportals_findet_den_vertrag(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => '1672525', 'type' => 'strom']);
        $csv = "Auftr.-Nr.;Provision;Anlagedatum\n1672525;25,00;16.08.2026\n";

        $import = $this->service()->analyze($this->file($csv), 'auftraege.csv');
        $this->service()->confirm($import);

        $this->assertSame($contract->id, ContractCommission::sole()->contract_id);
        $this->assertSame('Auftr.-Nr.', ContractCommission::sole()->match_reason);
    }

    // ------------------------------------------------ 7. STATUS

    public function test_status_wird_aus_den_daten_abgeleitet_und_nie_geraten(): void
    {
        $this->assertSame(CommissionStatus::BEZAHLT, ContractCommission::derive(null, now(), null, 100.0, 100.0));
        $this->assertSame(CommissionStatus::TEILWEISE, ContractCommission::derive(null, now(), null, 40.0, 100.0));
        $this->assertSame(CommissionStatus::FAELLIG, ContractCommission::derive(null, null, now()->subDay(), null, 100.0));
        $this->assertSame(CommissionStatus::OFFEN, ContractCommission::derive(null, null, now()->addMonth(), null, 100.0));
        $this->assertSame(CommissionStatus::STORNIERT, ContractCommission::derive('storniert', null, null, null, 100.0));
        // Ein unbekanntes Wort wird NIE zu einem der gueltigen Zustaende.
        $this->assertSame(CommissionStatus::UNKLAR, ContractCommission::derive('irgendwas', null, null, null, 100.0));
    }

    public function test_stornogrund_ohne_storno_status_geht_auf_pruefung(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $csv = "Vertragsnummer intern;Provisionsbetrag;Stornogrund\nV19613073;100,00;Widerruf des Kunden\n";

        $this->service()->confirm($this->service()->analyze($this->file($csv), 'abrechnung.csv'));

        $this->assertSame(CommissionStatus::UNKLAR, ContractCommission::sole()->status);
    }

    public function test_erfasste_zahlung_wird_von_einer_aelteren_datei_nicht_zurueckgenommen(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $csv = "Vertragsnummer intern;Provisionsbetrag;Provisionsdatum;Status\nV19613073;100,00;15.08.2026;bezahlt\n";
        $this->service()->confirm($this->service()->analyze($this->file($csv), 'lauf1.csv'));
        $this->assertSame(CommissionStatus::BEZAHLT, ContractCommission::sole()->status);

        // Dieselbe Position, in der Datei noch "offen" - der Betrieb weiss es
        // besser als die Datei.
        $older = "Vertragsnummer intern;Provisionsbetrag;Provisionsdatum;Status;Bemerkung\n"
            . "V19613073;100,00;15.08.2026;offen;alte Datei\n";
        $this->service()->confirm($this->service()->analyze($this->file($older), 'lauf0.csv'));

        $this->assertSame(CommissionStatus::BEZAHLT, ContractCommission::sole()->status);
    }

    // ------------------------------------------------ 8. PROTOKOLL

    public function test_jeder_schritt_steht_im_protokoll(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);

        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv', $admin->id);
        $this->service()->confirm($import, $admin->id);

        $actions = CommissionAuditLog::pluck('action')->all();
        $this->assertContains('datei_hochgeladen', $actions);
        $this->assertContains('provision_angelegt', $actions);
        $this->assertContains('import_bestaetigt', $actions);
        $this->assertSame($admin->id, CommissionAuditLog::first()->user_id);
    }

    public function test_statuswechsel_und_zahlung_werden_protokolliert(): void
    {
        $admin = $this->admin();
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'));
        $commission = ContractCommission::sole();

        $this->actingAs($admin)
            ->post(route('admin.commissions_internal.pay', $commission->id), [
                'betrag' => '2,00',
                'zahlungsdatum' => '2026-08-26',
            ])->assertRedirect();

        $commission->refresh();
        $this->assertSame(CommissionStatus::TEILWEISE, $commission->status);
        $this->assertTrue(CommissionAuditLog::where('action', 'zahlung_erfasst')->where('field', 'status')->exists());
    }

    // ------------------------------------------------ 9. VERTRAULICHKEIT

    public function test_kunde_erreicht_die_provisionsseiten_nicht(): void
    {
        $customer = $this->customer();
        $this->contract($customer, ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'));

        $this->actingAs($customer->user)
            ->get(route('admin.commissions_internal.index'))
            ->assertRedirect();

        $this->actingAs($customer->user)
            ->get(route('admin.commissions_internal.show', ContractCommission::sole()->id))
            ->assertRedirect();
    }

    public function test_mitarbeiter_ohne_recht_wird_abgewiesen(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_commissions' => false]);

        $this->actingAs($employee)->get(route('admin.commissions_internal.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('admin.commissions_internal.import'))->assertForbidden();
        $this->actingAs($employee)->get(route('admin.commissions_internal.audit'))->assertForbidden();
    }

    public function test_mitarbeiter_mit_recht_darf(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_commissions' => true]);

        $this->actingAs($employee)->get(route('admin.commissions_internal.index'))->assertOk();
    }

    public function test_provisionsdaten_erscheinen_nicht_im_kundenportal(): void
    {
        $customer = $this->customer();
        $contract = $this->contract($customer, [
            'internal_contract_number' => 'V19613073',
            'contract_number' => 'POL-1',
        ]);
        $this->service()->confirm($this->service()->analyze(
            $this->file($this->poolCsv([['betrag' => '4711,11', 'empfaenger' => 'Geheimer Empfaenger']])),
            'abrechnung.csv'
        ));

        $response = $this->actingAs($customer->user)->get(route('portal.contracts'));
        $response->assertOk();
        // Weder Betrag noch Empfaenger noch die interne Nummer duerfen im
        // ausgelieferten HTML stehen - nicht in einem Attribut, nicht in
        // einem JSON-Anhang, nirgends.
        $response->assertDontSee('4711,11');
        $response->assertDontSee('4711.11');
        $response->assertDontSee('Geheimer Empfaenger');
        $response->assertDontSee('V19613073');
    }

    /**
     * Energievertrag im Kundenportal (Betreiber-Vorgabe 28.08.2026, Punkt 5):
     * Der Kunde sieht seinen Vertrag mit allen Daten, die IHN betreffen -
     * Tarif, Zaehlernummer, Preise. Alles, was die ABRECHNUNG betrifft, ist
     * ein internes Unternehmensdatum und darf in seiner Ansicht nirgends
     * auftauchen: nicht die Provision, nicht der Empfaenger, nicht die
     * Vermittler-Id und nicht die internen Vertragsnummern, ueber die wir
     * abrechnen.
     */
    // ----------------------- Energie: die Bruecke Auftrag -> Abrechnung

    /**
     * Abrechnung eines Energie-Vertriebsportals OHNE jede Vertragsnummer
     * (Betreiber-Auftrag 28.08.2026). Genau das ist der Regelfall: zum
     * Zeitpunkt des Auftrags gab es noch keine Vertragsnummer, und die
     * Abrechnung Wochen spaeter nennt nur Zaehlernummer und Betrag. Die
     * Zaehlernummer steht seit dem Auftrag in der Akte - sie ist die Bruecke.
     */
    private function energieCsv(array $rows): string
    {
        $header = 'Abrechnungsnummer;Abrechnungsdatum;Zählernummer;MaLo-ID;Kunde;'
            . 'Produktname;Gesellschaft;Provisionsbetrag;Provisionsart';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(';', [
                $row['abrechnung'] ?? '77001',
                $row['datum'] ?? '2026-11-02',
                $row['zaehler'] ?? '1 EBZ0 1037 16819',
                $row['malo'] ?? '',
                $row['kunde'] ?? 'Hammadi, Imane',
                $row['produkt'] ?? 'PBNZE NEO P0',
                $row['gesellschaft'] ?? 'PLAN B NET ZERO ENERGY',
                $row['betrag'] ?? '85,00',
                $row['art'] ?? 'Abschlussprovision',
            ]);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private function energieVertrag(Customer $customer, array $energie = [], array $vertrag = []): Contract
    {
        $contract = $this->contract($customer, array_merge([
            'type' => 'strom',
            'insurer' => 'PLAN B NET ZERO ENERGY',
            'stage' => Contract::STAGE_ANTRAG,
            'reference_number' => '1687519',
        ], $vertrag));
        \App\Models\ContractEnergyDetail::create(array_merge([
            'contract_id' => $contract->id,
            'meter_number' => '1EBZ0103716819',
            'tariff' => 'PBNZE NEO P0',
        ], $energie));

        return $contract;
    }

    public function test_abrechnung_findet_den_energievertrag_ueber_die_zaehlernummer(): void
    {
        $customer = $this->customer('Imane Hammadi');
        $contract = $this->energieVertrag($customer);

        $import = $this->service()->analyze($this->file($this->energieCsv([[]]), 'energie.csv'), 'energie.csv');
        $this->service()->confirm($import);

        $commission = \App\Models\ContractCommission::first();
        $this->assertNotNull($commission);
        // Die Zaehlernummer steht in der Datei mit Leerzeichen, in der Akte
        // ohne - verglichen wird deshalb normalisiert.
        $this->assertSame((string) $contract->id, (string) $commission->contract_id);
        $this->assertSame('Zählernummer', $commission->match_reason);
        $this->assertSame((string) $customer->id, (string) $commission->customer_id);
    }

    public function test_abrechnung_findet_den_energievertrag_ueber_die_malo_id(): void
    {
        $customer = $this->customer('Imane Hammadi');
        $contract = $this->energieVertrag($customer, ['meter_number' => null, 'malo_id' => '51214126166']);

        $import = $this->service()->analyze(
            $this->file($this->energieCsv([['zaehler' => '', 'malo' => '51214126166']]), 'energie.csv'),
            'energie.csv'
        );
        $this->service()->confirm($import);

        $this->assertSame((string) $contract->id,
            (string) \App\Models\ContractCommission::first()?->contract_id);
    }

    public function test_zaehlernummer_an_zwei_vertraegen_ordnet_nichts_zu(): void
    {
        // An EINER Lieferstelle koennen Strom und Gas haengen. Welchem der
        // beiden Vertraege die Provision gehoert, sagt die Zaehlernummer
        // nicht - also wird nichts zugeordnet, wie ueberall sonst auch.
        $customer = $this->customer('Imane Hammadi');
        $this->energieVertrag($customer, [], ['reference_number' => 'REF-STROM-1']);
        $this->energieVertrag($customer, [], ['type' => 'gas', 'reference_number' => 'REF-GAS-1']);

        $import = $this->service()->analyze($this->file($this->energieCsv([[]]), 'energie.csv'), 'energie.csv');
        $this->service()->confirm($import);

        $commission = \App\Models\ContractCommission::first();
        $this->assertNotNull($commission, 'Die Zeile muss trotzdem erfasst werden - nichts geht verloren.');
        $this->assertNull($commission->contract_id);
        // Der Grund steht in der Pruefliste - er nennt das Problem, statt
        // nur "nicht zugeordnet" zu melden.
        $this->assertStringContainsString('trifft 2 Verträge',
            (string) \App\Models\CommissionImportRow::first()?->message);
    }

    public function test_vertragsnummer_schlaegt_die_zaehlernummer(): void
    {
        // Trennschaerfe entscheidet die Reihenfolge: die interne
        // Vertragsnummer meint EINEN Vertrag, die Zaehlernummer eine
        // Lieferstelle. Steht beides in der Zeile, gewinnt die schaerfere.
        $customer = $this->customer('Imane Hammadi');
        $richtig = $this->energieVertrag($customer, ['meter_number' => '1EBZ9999999999'],
            ['internal_contract_number' => 'V19613073']);
        $this->energieVertrag($customer, [], ['type' => 'gas', 'reference_number' => 'REF-GAS-2']);

        $header = 'Abrechnungsnummer;Abrechnungsdatum;Vertragsnummer intern;Zählernummer;'
            . 'Kunde;Provisionsbetrag;Provisionsart';
        $csv = $header . "\r\n" . implode(';', [
            '77002', '2026-11-02', 'V19613073', '1EBZ0103716819',
            'Hammadi, Imane', '85,00', 'Abschlussprovision',
        ]) . "\r\n";

        $import = $this->service()->analyze($this->file($csv, 'energie.csv'), 'energie.csv');
        $this->service()->confirm($import);

        $commission = \App\Models\ContractCommission::first();
        $this->assertSame((string) $richtig->id, (string) $commission->contract_id);
        $this->assertSame('Interne Vertragsnummer', $commission->match_reason);
    }

    public function test_energievertrag_zeigt_dem_kunden_keine_abrechnungsdaten(): void
    {
        $customer = $this->customer();
        $contract = $this->contract($customer, [
            'type' => 'strom',
            'insurer' => 'PLAN B NET ZERO ENERGY',
            'internal_contract_number' => 'V19613073',
            'vermittler_id' => '9753224',
            'contract_number' => 'POL-1',
        ]);
        \App\Models\ContractEnergyDetail::create([
            'contract_id' => $contract->id,
            'meter_number' => '1EBZ0103716819',
            'tariff' => 'PBNZE NEO P0',
        ]);
        $this->service()->confirm($this->service()->analyze(
            $this->file($this->poolCsv([['betrag' => '4711,11', 'empfaenger' => 'Geheimer Empfaenger']])),
            'abrechnung.csv'
        ));

        $response = $this->actingAs($customer->user)->get(route('portal.contracts.show', $contract->id));
        $response->assertOk();
        // Was den Kunden angeht, steht da.
        $response->assertSee('1EBZ0103716819');
        // Was uns angeht, steht nicht da.
        $response->assertDontSee('4711,11');
        $response->assertDontSee('4711.11');
        $response->assertDontSee('Geheimer Empfaenger');
        $response->assertDontSee('V19613073');
        $response->assertDontSee('9753224');
    }

    public function test_provisionsbox_erscheint_in_der_vertragsakte_nur_fuer_berechtigte(): void
    {
        $contract = $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze(
            $this->file($this->poolCsv([['betrag' => '4711,11']])),
            'abrechnung.csv'
        ));

        $this->actingAs($this->admin())
            ->get(route('admin.contract.edit', $contract->id))
            ->assertOk()
            ->assertSee('4.711,11');

        $employee = User::factory()->create([
            'role' => 'employee', 'can_manage_commissions' => false,
            'can_see_all_customers' => true, 'can_manage_contracts' => true,
        ]);
        $this->actingAs($employee)
            ->get(route('admin.contract.edit', $contract->id))
            ->assertOk()
            ->assertDontSee('4.711,11');
    }

    // ------------------------------------------------ 10. Der WEG durch die Oberflaeche

    public function test_upload_bis_bestaetigung_ueber_die_oberflaeche(): void
    {
        $admin = $this->admin();
        $contract = $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);

        $upload = UploadedFile::fake()->createWithContent('abrechnung.csv', $this->poolCsv([[]]));

        $this->actingAs($admin)
            ->post(route('admin.commissions_internal.upload'), ['datei' => $upload])
            ->assertRedirect();

        $import = CommissionImport::sole();
        $this->assertSame(CommissionImport::ENTWURF, $import->status);
        $this->assertSame(0, ContractCommission::count());

        $this->actingAs($admin)->get(route('admin.commissions_internal.preview', $import->id))
            ->assertOk()
            ->assertSee('Schritt 5')
            ->assertSee('Vertragsnummer intern');

        $this->actingAs($admin)
            ->post(route('admin.commissions_internal.confirm', $import->id))
            ->assertRedirect();

        $this->assertSame(1, ContractCommission::count());
        $this->assertSame($contract->id, ContractCommission::sole()->contract_id);
    }

    public function test_excel_upload_wird_angenommen(): void
    {
        $admin = $this->admin();
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);

        $upload = UploadedFile::fake()->createWithContent('abrechnung.xlsx', $this->buildXlsx());

        $this->actingAs($admin)
            ->post(route('admin.commissions_internal.upload'), ['datei' => $upload])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $import = CommissionImport::sole();
        $this->assertSame('xlsx', $import->format);
        $this->assertSame(1, $import->rows_new);
    }

    public function test_falscher_dateityp_wird_verstaendlich_abgelehnt(): void
    {
        $upload = UploadedFile::fake()->createWithContent('vertrag.pdf', '%PDF-1.4 nichts');

        $this->actingAs($this->admin())
            ->post(route('admin.commissions_internal.upload'), ['datei' => $upload])
            ->assertSessionHasErrors('datei');

        $this->assertSame(0, CommissionImport::count());
    }

    public function test_zuordnung_kann_von_hand_geaendert_werden(): void
    {
        $admin = $this->admin();
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        // Spalten bewusst so benannt, dass der Vorschlag den Betrag nicht findet.
        $csv = "Vertragsnummer intern;Wert der Buchung\nV19613073;100,00\n";
        $import = $this->service()->analyze($this->file($csv), 'fremd.csv');

        $this->assertSame(1, $import->rows_invalid);

        $this->actingAs($admin)->post(route('admin.commissions_internal.remap', $import->id), [
            'modus' => Cols::MODE_ABRECHNUNG,
            'spalte' => ['internal_contract_number' => 0, 'amount' => 1],
        ])->assertRedirect();

        $this->assertSame(1, $import->fresh()->rows_new);
        $this->assertSame(0, $import->fresh()->rows_invalid);
    }

    public function test_fehlerliste_laesst_sich_herunterladen(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->poolCsv([['intern' => 'V99999999']])), 'abrechnung.csv');

        $response = $this->actingAs($this->admin())->get(route('admin.commissions_internal.errors', $import->id));
        $response->assertOk();
        $this->assertStringContainsString('V99999999', $response->streamedContent());
    }

    // ------------------------------------------------ 11. RECHNUNGSABGLEICH

    public function test_rechnungsabgleich_findet_vertrag_und_provision(): void
    {
        $contract = $this->contract($this->customer('Erika Muster'), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'));

        $result = app(\App\Services\CommissionImport\InvoiceCommissionMatcher::class)->lookup('V19613073');

        $this->assertSame($contract->id, $result['contract']?->id);
        $this->assertCount(1, $result['commissions']);
    }

    public function test_kennungen_werden_aus_einem_rechnungstext_gelesen(): void
    {
        $found = app(\App\Services\CommissionImport\InvoiceCommissionMatcher::class)->extract(
            "Rechnung 2026-0815\nInterne Vertragsnummer: V19613073\nReferenz-Nr.: 1477-6741-9200-53\nBetrag 850,00 EUR"
        );

        $this->assertContains('V19613073', $found['internal_contract_number']);
        $this->assertContains('1477-6741-9200-53', $found['reference_number']);
    }

    public function test_rechnung_verknuepfen_bestaetigt_noch_keine_zahlung(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'));
        $commission = ContractCommission::sole();

        $this->actingAs($this->admin())->post(route('admin.commissions_internal.invoice_link', $commission->id), [
            'invoice_number' => 'RE-2026-0815',
            'invoice_date' => '2026-08-26',
        ])->assertRedirect();

        $commission->refresh();
        $this->assertSame('RE-2026-0815', $commission->invoice_number);
        $this->assertNull($commission->payment_date);
        $this->assertSame(CommissionStatus::OFFEN, $commission->status);
    }

    // ---------------------------- 12. NICHTS GEHT VERLOREN (26.08.2026)

    public function test_nicht_zugeordnete_provision_wird_trotzdem_aufbewahrt(): void
    {
        // Kein einziger Vertrag im Bestand - frueher wurde die Zeile beim
        // Bestaetigen stillschweigend verworfen. In den echten Dateien des
        // Betriebs betraf das ueber 90 % aller Zeilen.
        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv');
        $this->assertSame(1, $import->rows_unmatched);

        $this->service()->confirm($import);

        $commission = ContractCommission::sole();
        $this->assertNull($commission->contract_id);
        $this->assertSame(ContractCommission::MATCH_OFFEN, $commission->match_status);
        $this->assertSame('V19613073', $commission->internal_contract_number);
        $this->assertSame('4.10', (string) $commission->amount);
        $this->assertSame(1, $import->fresh()->rows_unlinked_kept);
        // Und sie ist ueber den Filter "ohne Vertrag" auffindbar.
        $this->assertSame(1, ContractCommission::unmatched()->count());
    }

    public function test_aufbewahrte_provision_wird_beim_zweiten_lauf_nicht_verdoppelt(): void
    {
        $csv = $this->poolCsv([[]]);
        $this->service()->confirm($this->service()->analyze($this->file($csv), 'lauf1.csv'));

        $second = $this->service()->analyze($this->file($csv), 'lauf2.csv');
        $this->assertSame(1, $second->rows_duplicate);
        $this->service()->confirm($second);

        $this->assertSame(1, ContractCommission::count());
    }

    public function test_zwei_gleiche_zeilen_ohne_vertrag_kollidieren_nicht(): void
    {
        // Beide Zeilen werden geschrieben, obwohl sie identisch sind und
        // keinen Vertrag haben - der eindeutige Schlüssel darf daran nicht
        // scheitern.
        $import = $this->service()->analyze($this->file($this->poolCsv([[], []])), 'abrechnung.csv');

        $this->assertSame(2, $import->rows_unmatched);

        $this->service()->confirm($import);
        $this->assertSame(2, ContractCommission::count());
        $this->assertSame(2, ContractCommission::unmatched()->count());
    }

    public function test_nicht_zugeordnete_zeile_laesst_sich_von_hand_verknuepfen(): void
    {
        $contract = $this->contract($this->customer(), ['internal_contract_number' => 'V-SPAETER']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'));
        $commission = ContractCommission::sole();

        $this->actingAs($this->admin())
            ->post(route('admin.commissions_internal.link', $commission->id), ['contract_id' => $contract->id])
            ->assertRedirect();

        $this->assertSame($contract->id, $commission->fresh()->contract_id);
    }

    public function test_grosse_datei_wird_vollstaendig_uebernommen(): void
    {
        // WAECHTER gegen einen Fehler, der an den echten Dateien auffiel:
        // die Verarbeitung blätterte nach `id`, sortierte aber nach
        // Zeilennummer - dabei fielen Zeilen still aus dem Lauf (von 1711
        // kamen nur 689 an). Die Datei ist bewusst größer als ein Chunk.
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = ['intern' => 'V' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'betrag' => '10,00'];
        }
        $import = $this->service()->analyze($this->file($this->poolCsv($rows)), 'gross.csv');
        $this->assertSame(450, $import->rows_total);
        $this->assertSame(450, $import->rows_unmatched);

        $this->service()->confirm($import);

        $this->assertSame(450, ContractCommission::count());
        $this->assertSame('4500.00', number_format((float) ContractCommission::sum('amount'), 2, '.', ''));
    }

    public function test_platzhalter_datum_macht_die_zeile_nicht_fehlerhaft(): void
    {
        // Das Vertriebsportal schreibt ein fehlendes Geburtsdatum als
        // "00.00.0000". Das als kaputtes Datum zu werten hätte die ganze
        // Zeile verworfen - samt Name, Anschrift und Vertrag.
        $csv = "Vertragsnummer intern;Kunde;Provisionsbetrag;Geburtsdatum\n"
            . "V19613073;VN Muster, Max;100,00;00.00.0000\n";
        $import = $this->service()->analyze($this->file($csv), 'abrechnung.csv');

        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(1, $import->rows_buildable);

        // Ein wirklich verstümmeltes Datum wird weiterhin gemeldet.
        $bad = $this->service()->analyze(
            $this->file("Vertragsnummer intern;Provisionsbetrag;Geburtsdatum\nV19613073;100,00;32.13.9999\n"),
            'kaputt.csv'
        );
        $this->assertSame(1, $bad->rows_invalid);
    }

    // ---------------------------- 13. VERTRAEGE UND KUNDEN ANLEGEN

    public function test_vertrag_und_kunde_entstehen_nur_auf_ausdruecklichen_wunsch(): void
    {
        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv');
        $this->assertSame(1, $import->rows_buildable);

        // Ohne Haken passiert nichts.
        $this->service()->confirm($import);
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_mit_haken_entstehen_vertrag_und_kundenakte(): void
    {
        $import = $this->service()->analyze(
            $this->file($this->poolCsv([['kunde' => 'VN RANKO, MOHAMAD ADNAN']])),
            'abrechnung.csv'
        );
        $import = $this->service()->confirm($import, null, buildContracts: true);

        $this->assertSame(1, $import->contracts_created);
        $this->assertSame(1, $import->customers_created);

        $contract = Contract::sole();
        $this->assertSame('V19613073', $contract->internal_contract_number);
        $this->assertSame('2793227640', $contract->contract_number);
        $this->assertSame('Dialog Versicherung AG', $contract->insurer);
        $this->assertSame('betriebshaftpflicht', $contract->type);
        $this->assertSame($import->id, $contract->commission_import_id);

        // Der Name kommt lesbar in die Akte, nicht als "VN RANKO, MOHAMAD ADNAN".
        $this->assertSame('Mohamad Adnan Ranko', Customer::sole()->user->name);

        // Und die Provision haengt jetzt am neuen Vertrag.
        $this->assertSame($contract->id, ContractCommission::sole()->contract_id);
    }

    public function test_neu_angelegter_vertrag_zaehlt_nie_zum_aktiven_bestand(): void
    {
        // Dass Geld geflossen ist, belegt: es GAB den Vertrag - nicht, dass er
        // heute laeuft. Er darf die Vertragsstruktur des Kunden nicht
        // aufblaehen, bevor ihn ein Mensch bestaetigt hat.
        $this->service()->confirm(
            $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv'),
            null,
            buildContracts: true
        );

        $contract = Contract::sole();
        $this->assertSame(Contract::STATUS_PENDING, $contract->status);
        $this->assertFalse($contract->isCurrentlyActive());
        $this->assertSame(0, Contract::currentlyActive()->count());
        $this->assertStringContainsString('NICHT geprüft', (string) $contract->notes);
    }

    public function test_vorhandener_kunde_wird_nicht_dupliziert(): void
    {
        $existing = $this->customer('Mohamad Adnan Ranko');
        $csv = $this->poolCsv([['kunde' => 'VN RANKO, MOHAMAD ADNAN']]);

        $import = $this->service()->confirm(
            $this->service()->analyze($this->file($csv), 'abrechnung.csv'),
            null,
            buildContracts: true
        );

        $this->assertSame(1, Customer::count());
        $this->assertSame(0, $import->customers_created);
        $this->assertSame(1, $import->contracts_created);
        $this->assertSame((string) $existing->id, (string) Contract::sole()->customer_id);
    }

    public function test_ohne_kundennamen_wird_nichts_angelegt(): void
    {
        // Der Export des Vergleichsportals hat gar keine Namensspalte.
        $csv = "Id;Provision;Datum\n9787196;75,00;25.08.2026\n";
        $import = $this->service()->analyze($this->file($csv), 'tc24.csv');

        $this->assertSame(1, $import->rows_unmatched);
        $this->assertSame(0, $import->rows_buildable);

        $import = $this->service()->confirm($import, null, buildContracts: true);

        $this->assertSame(0, Contract::count());
        $this->assertSame(0, Customer::count());
        // Die Provision selbst bleibt trotzdem erhalten.
        $this->assertSame(1, ContractCommission::count());
        $this->assertNull(ContractCommission::sole()->contract_id);
    }

    public function test_anlegen_wird_protokolliert(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->service()->confirm(
            $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv', $admin->id),
            $admin->id,
            buildContracts: true
        );

        $actions = CommissionAuditLog::pluck('action')->all();
        $this->assertContains('kunde_angelegt', $actions);
        $this->assertContains('vertrag_angelegt', $actions);
    }

    // ---------------------------- 14. AUFTRAGSLISTE OHNE BETRAEGE

    /** Die Bauform des Energie-Vertriebsportals: Kundendaten, kein Betrag. */
    private function orderCsv(array $rows): string
    {
        $header = 'VP-Name;Auftr.-Nr.;Anlagedatum;Auftr.-Statustext;Kunden;Anschrift;Geburtsdatum;'
            . 'Telefonnummer;Zählernummer;Verbrauch;Tarif/Produkt';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(';', [
                'Herr Ahmad Albhre',
                $row['auftrag'] ?? '1672525',
                '16.08.2026',
                'Auftrag wurde an den Anbieter übermittelt',
                $row['kunde'] ?? 'Herr Muhieddin Termanini',
                $row['anschrift'] ?? 'Alte Kieler Landstr. 141, 24768 Rendsburg',
                $row['geburt'] ?? '03.04.1978',
                '+49 0176 23681009',
                $row['zaehler'] ?? '1EBZ0103873550',
                '2800',
                $row['produkt'] ?? 'RheinEnergie AG - Fair Ökostrom 24',
            ]);
        }
        return implode("\n", $lines) . "\n";
    }

    public function test_auftragsliste_ohne_betragsspalte_ist_kein_fehler(): void
    {
        // Genau dieser Fall meldete vorher 584 von 584 Zeilen als fehlerhaft.
        $import = $this->service()->analyze($this->file($this->orderCsv([[]])), 'order.csv');

        $this->assertSame(Cols::MODE_AUFTRAGSLISTE, $import->mode);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(1, $import->rows_unmatched);
        $this->assertSame(1, $import->rows_buildable);
    }

    public function test_auftragsliste_legt_kunde_und_vertrag_mit_allen_daten_an(): void
    {
        $import = $this->service()->confirm(
            $this->service()->analyze($this->file($this->orderCsv([[]])), 'order.csv'),
            null,
            buildContracts: true
        );

        $this->assertSame(1, $import->contracts_created);
        $this->assertSame(1, $import->customers_created);
        // Aus einer Auftragsliste entsteht NIE eine Provision.
        $this->assertSame(0, ContractCommission::count());

        $customer = Customer::sole();
        $this->assertSame('Muhieddin Termanini', $customer->user->name);
        $this->assertSame('male', $customer->gender);
        $this->assertStringStartsWith('1978-04-03', (string) $customer->birth_date);
        $this->assertSame('Alte Kieler Landstr.', $customer->address_street);
        $this->assertSame('141', $customer->address_house_number);
        $this->assertSame('24768', $customer->address_zip);
        $this->assertSame('Rendsburg', $customer->address_city);

        $contract = Contract::sole();
        $this->assertSame('strom', $contract->type);
        $this->assertSame('1672525', $contract->reference_number);
        $this->assertStringContainsString('1EBZ0103873550', (string) $contract->notes);
    }

    public function test_auftragsliste_meldet_bereits_erfasste_vertraege_statt_sie_zu_verdoppeln(): void
    {
        $this->contract($this->customer(), ['reference_number' => '1672525', 'type' => 'strom']);

        $import = $this->service()->analyze($this->file($this->orderCsv([[]])), 'order.csv');

        $this->assertSame(0, $import->rows_unmatched);
        $this->assertSame(1, $import->rows_duplicate);

        $this->service()->confirm($import, null, buildContracts: true);
        $this->assertSame(1, Contract::count());
    }

    public function test_betriebsart_laesst_sich_umstellen(): void
    {
        // Eine Abrechnung, deren Betragsspalte ungewoehnlich heisst, wird
        // zunaechst als Auftragsliste erkannt - der Admin stellt um.
        $csv = "Vertragsnummer intern;Kunde;Wert der Buchung\nV19613073;VN Muster, Max;100,00\n";
        $import = $this->service()->analyze($this->file($csv), 'fremd.csv');
        $this->assertSame(Cols::MODE_AUFTRAGSLISTE, $import->mode);

        $this->actingAs($this->admin())->post(route('admin.commissions_internal.remap', $import->id), [
            'modus' => Cols::MODE_ABRECHNUNG,
            'spalte' => ['internal_contract_number' => 0, 'customer_name' => 1, 'amount' => 2],
        ])->assertRedirect();

        $import->refresh();
        $this->assertSame(Cols::MODE_ABRECHNUNG, $import->mode);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(1, $import->rows_unmatched);
    }

    // ---------------------------- 15. NAMEN UND ANSCHRIFTEN

    public function test_namen_der_fremdsysteme_werden_lesbar(): void
    {
        $parser = new PersonNameParser();

        $this->assertSame('Mohamad Adnan Ranko', $parser->parse('VN RANKO, MOHAMAD ADNAN')['name']);
        $this->assertSame('Sven Kaergel', $parser->parse('VN Kaergel, Sven')['name']);
        // Haengendes Komma ohne Vorname: es wird NICHT gedreht.
        $this->assertSame('Ahmed Al Huweij', $parser->parse('VN Ahmed Al Huweij, ')['name']);
        $this->assertSame('Saddam Alahmad Al Hakkar', $parser->parse('Herr Saddam Alahmad Al Hakkar')['name']);
        $this->assertSame('female', $parser->parse('Frau Hend Al Mohamad')['gender']);
        // Eine Firma bleibt unangetastet.
        $this->assertSame('Muster Transporte GmbH', $parser->parse('Muster Transporte GmbH')['name']);
        $this->assertTrue($parser->parse('Muster Transporte GmbH')['company']);
        $this->assertNull($parser->parse('VN , ')['name']);

        // Namenszusaetze gehoeren zum Nachnamen.
        $this->assertSame('Al Jashi', $parser->lastName('VN Al Khatib Al Jashi, Ahmad'));
    }

    public function test_einzeilige_anschrift_wird_zerlegt(): void
    {
        $a = ValueParser::address('Alte Kieler Landstr. 141, 24768 Rendsburg');
        $this->assertSame('Alte Kieler Landstr.', $a['street']);
        $this->assertSame('141', $a['house_number']);
        $this->assertSame('24768', $a['zip']);
        $this->assertSame('Rendsburg', $a['city']);

        $b = ValueParser::address('Schützenstr. 11a, 12526 Berlin');
        $this->assertSame('11a', $b['house_number']);

        // Ohne PLZ wird NICHTS zerlegt - eine halb erkannte Adresse waere
        // schlechter als eine unzerlegte.
        $c = ValueParser::address('Irgendein Text');
        $this->assertNull($c['zip']);
        $this->assertNull($c['street']);
        $this->assertSame('Irgendein Text', $c['raw']);
    }

    // ---------------------------- 16. MEHRERE QUELLEN, MEHRERE FORMATE

    public function test_die_drei_echten_quellen_werden_erkannt(): void
    {
        $pool = ['Abrechnungsnummer', 'Abrechnungsdatum', 'Vertragsnummer extern', 'Vertragsnummer intern',
            'Kunde', 'Produktname', 'Gesellschaft', 'Sparte', 'Provisionsbetrag', 'Provisionsart', 'Kontoinhaber'];
        $portal = ['Datum', 'Produkt', 'Id', 'Status', 'Provision', 'Tracking-Id', 'Stornogrund', 'Referenz-Nr.'];
        $energie = ['VP-Name', 'Auftr.-Nr.', 'Ihre Auftr.-Nr.', 'Anlagedatum', 'Auftr.-Status',
            'Auftr.-Statustext', 'Kunden', 'Anschrift', 'Geburtsdatum', 'Telefonnummer', 'Zählernummer'];

        $this->assertSame('maklerpool', CommissionSourceProfile::detect($pool));
        $this->assertSame('tarifcheck24', CommissionSourceProfile::detect($portal));
        $this->assertSame('energie_vertriebsportal', CommissionSourceProfile::detect($energie));

        // Eine UNBEKANNTE Quelle ist kein Fehler - sie hat nur kein Profil.
        $this->assertNull(CommissionSourceProfile::detect(['Vertrag', 'Betrag', 'Wann']));
        $this->assertSame(CommissionSourceProfile::UNKNOWN_LABEL, CommissionSourceProfile::label(null));
    }

    public function test_die_quelle_steht_am_import_und_an_der_provision(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $import = $this->service()->analyze($this->file($this->poolCsv([[]])), 'abrechnung.csv');

        $this->assertSame('maklerpool', $import->provider);
        $this->assertSame('Maklerpool-Abrechnung', $import->providerLabel());

        $this->service()->confirm($import);
        $this->assertSame('maklerpool', ContractCommission::sole()->provider);
    }

    public function test_die_quelle_bestimmt_die_betriebsart(): void
    {
        // Die Auftragsliste hat keine Betragsspalte - das Profil stellt die
        // Betriebsart, ohne dass jemand sie von Hand waehlen muss.
        $import = $this->service()->analyze($this->file($this->orderCsv([[]])), 'order.csv');

        $this->assertSame('energie_vertriebsportal', $import->provider);
        $this->assertSame(Cols::MODE_AUFTRAGSLISTE, $import->mode);
    }

    public function test_unbekannte_quelle_wird_trotzdem_importiert(): void
    {
        // Der naechste Maklerpool darf ohne Code-Aenderung importiert werden -
        // sonst waere aus "mehrere Quellen" wieder "eine Quelle, nur eine
        // andere".
        $this->contract($this->customer(), ['internal_contract_number' => 'V77777777']);
        $csv = "Interne Vertragsnummer;Kunde;Provision;Provisionsdatum\n"
            . "V77777777;VN Neu, Anna;250,00;15.08.2026\n";

        $import = $this->service()->analyze($this->file($csv), 'fremder-pool.csv');

        $this->assertNull($import->provider);
        $this->assertSame(0, $import->rows_invalid);
        $this->assertSame(1, $import->rows_new);

        $this->service()->confirm($import);
        $this->assertSame('250.00', (string) ContractCommission::sole()->amount);
    }

    public function test_liste_laesst_sich_nach_quelle_filtern(): void
    {
        $this->contract($this->customer(), ['internal_contract_number' => 'V19613073']);
        $this->service()->confirm($this->service()->analyze($this->file($this->poolCsv([[]])), 'pool.csv'));
        $this->service()->confirm($this->service()->analyze(
            $this->file("Id;Provision;Status;Datum\n9787196;75,00;1;25.08.2026\n"),
            'tc24.csv'
        ));
        $this->assertSame(2, ContractCommission::count());

        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.commissions_internal.index', ['quelle' => 'maklerpool']));

        $antwort->assertOk()->assertSee('Maklerpool-Abrechnung');
        $this->assertSame(1, $antwort->viewData('commissions')->total());
    }

    // ---------------------------- 17. KEINE SACKGASSE AUF DER FALSCHEN SEITE

    public function test_falsche_seite_nennt_die_richtige(): void
    {
        // Genau der gemeldete Fall: die Auftragsliste des Energieportals
        // landet auf der TARIFCHECK24-Seite. Vorher stand dort nur
        // "Die Spalte Id fehlt" - ohne jeden Weg weiter.
        $upload = UploadedFile::fake()->createWithContent('order.csv', $this->orderCsv([[]]));

        $this->actingAs($this->admin())
            ->post(route('admin.vermittler.import'), ['csv_file' => $upload])
            ->assertRedirect();

        $meldung = (string) session('error');
        $this->assertStringContainsString('Energie-Vertriebsportal', $meldung);
        $this->assertStringContainsString(route('admin.commissions_internal.import'), $meldung);
    }

    public function test_echte_vermittler_datei_bekommt_keinen_falschen_hinweis(): void
    {
        // Eine TARIFCHECK24-Datei gehoert dorthin - dann darf kein Hinweis
        // auf die andere Seite erscheinen, auch wenn der Import scheitert.
        $upload = UploadedFile::fake()->createWithContent(
            'tc24.csv',
            "Datum;Produkt;Id;Status;Provision;Tracking-Id;Stornogrund;Referenz-Nr.\n"
            . "2026-08-25 00:00:00;Kfz-Versicherung Abschluss;9787196;1;75;;;1437-7875-9260-98\n"
        );

        $this->actingAs($this->admin())
            ->post(route('admin.vermittler.import'), ['csv_file' => $upload])
            ->assertRedirect();

        $this->assertStringNotContainsString(
            route('admin.commissions_internal.import'),
            (string) session('error')
        );
    }

    public function test_vermittler_seite_weist_vorab_auf_die_andere_hin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.vermittler.index'))
            ->assertOk()
            ->assertSee('nur für die Abrechnung von TARIFCHECK24', false)
            ->assertSee(route('admin.commissions_internal.import'), false);
    }

    // ------------------------------------------------ Hilfsmittel: XLSX bauen

    /**
     * Eine echte (minimale) XLSX-Datei erzeugen - ohne Fremdpaket, damit der
     * Test dieselbe Struktur prueft, die Excel schreibt.
     */
    private function buildXlsx(bool $twoSheets = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '</Types>');

        $sheets = $twoSheets
            ? '<sheet name="Deckblatt" sheetId="1" r:id="rId1"/><sheet name="Abrechnung" sheetId="2" r:id="rId2"/>'
            : '<sheet name="Abrechnung" sheetId="1" r:id="rId1"/>';
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>');

        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
        if ($twoSheets) {
            $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>';
        }
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');

        $strings = ['Interne Vertragsnummer', 'Provision', 'Provisionsdatum', 'V19613073', 'Deckblatt – bitte Blatt wechseln'];
        $si = implode('', array_map(fn ($s) => '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>', $strings));
        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($strings) . '" uniqueCount="' . count($strings) . '">' . $si . '</sst>');

        // Format 14 (Datum) auf Stilindex 1 - so schreibt Excel es auch.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');

        $data = '<sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>850</v></c><c r="C2" s="1"><v>46249</v></c></row>'
            . '</sheetData>';
        $sheetXml = fn ($body) => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $body . '</worksheet>';

        if ($twoSheets) {
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml('<sheetData><row r="1"><c r="A1" t="s"><v>4</v></c></row></sheetData>'));
            $zip->addFromString('xl/worksheets/sheet2.xml', $sheetXml($data));
        } else {
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml($data));
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);
        return $content;
    }

    /**
     * Eine minimale, aber echte .xls-Datei (BIFF8 in einem
     * OLE-Verbunddokument) erzeugen: Kopf, Belegungstabelle, Verzeichnis und
     * der Datenstrom "Workbook".
     */
    private function buildXls(): string
    {
        $record = fn (int $id, string $data) => pack('vv', $id, strlen($data)) . $data;
        $shortString = fn (string $text) => chr(strlen($text)) . "\x00" . $text;

        // --- Globalteil ------------------------------------------------
        $strings = ['Interne Vertragsnummer', 'Provision', 'Provisionsdatum', 'V19613073'];
        $sst = pack('VV', count($strings), count($strings));
        foreach ($strings as $text) {
            $sst .= pack('v', strlen($text)) . "\x00" . $text; // 8-Bit-Zeichen
        }

        $globals = $record(0x0809, pack('vv', 0x0600, 0x0005) . str_repeat("\x00", 12));
        $globals .= $record(0x00E0, pack('vvv', 0, 0, 0) . str_repeat("\x00", 14));  // XF 0: Zahl
        $globals .= $record(0x00E0, pack('vvv', 0, 14, 0) . str_repeat("\x00", 14)); // XF 1: Datum
        $globals .= $record(0x00FC, $sst);

        // Die Position des Blattes steht IM Globalteil - sie haengt also von
        // dessen eigener Laenge ab und wird deshalb hier berechnet.
        $boundSheetBody = fn (int $position) => pack('V', $position) . "\x00\x00" . $shortString('Abrechnung');
        $placeholder = $record(0x0085, $boundSheetBody(0));
        $globalsLength = strlen($globals) + strlen($placeholder) + 4; // + EOF-Satz
        $globals = substr($globals, 0, strlen($globals)) . $record(0x0085, $boundSheetBody($globalsLength)) . $record(0x000A, '');

        // --- Blatt ------------------------------------------------------
        $sheet = $record(0x0809, pack('vv', 0x0600, 0x0010) . str_repeat("\x00", 12));
        foreach ([0 => 0, 1 => 1, 2 => 2] as $column => $index) {
            $sheet .= $record(0x00FD, pack('vvvV', 0, $column, 0, $index)); // LABELSST
        }
        $sheet .= $record(0x00FD, pack('vvvV', 1, 0, 0, 3));                 // V19613073
        $sheet .= $record(0x0203, pack('vvv', 1, 1, 0) . pack('e', 850.0));  // NUMBER
        $sheet .= $record(0x0203, pack('vvv', 1, 2, 1) . pack('e', 46249.0));// NUMBER im Datumsformat
        $sheet .= $record(0x000A, '');

        $workbook = $globals . $sheet;
        // Auf mehr als die Mini-Stream-Grenze auffuellen, damit der Strom in
        // normalen Sektoren liegt. Nullbytes nach dem EOF-Satz stoeren nicht -
        // der Leser haelt am EOF an.
        $workbook = str_pad($workbook, 5120, "\x00");

        return $this->oleContainer('Workbook', $workbook);
    }

    /** Einen Datenstrom in ein OLE-Verbunddokument verpacken. */
    private function oleContainer(string $streamName, string $stream): string
    {
        $sectorSize = 512;
        $end = 0xFFFFFFFE;
        $free = 0xFFFFFFFF;

        $stream = str_pad($stream, (int) ceil(strlen($stream) / $sectorSize) * $sectorSize, "\x00");
        $dataSectors = intdiv(strlen($stream), $sectorSize);

        // Sektor 0 = Belegungstabelle, Sektor 1 = Verzeichnis, ab 2 die Daten.
        $fat = [0xFFFFFFFD, $end];
        for ($i = 0; $i < $dataSectors; $i++) {
            $fat[] = $i === $dataSectors - 1 ? $end : (2 + $i + 1);
        }
        $fat = array_pad($fat, intdiv($sectorSize, 4), $free);
        $fatSector = '';
        foreach ($fat as $entry) {
            $fatSector .= pack('V', $entry);
        }

        $entry = function (string $name, int $type, int $start, int $size) {
            $utf16 = (string) mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');
            $data = str_pad($utf16, 64, "\x00");
            $data .= pack('v', strlen($utf16) + 2);   // Laenge inkl. Abschluss
            $data .= chr($type) . chr(1);             // Typ, Farbe
            $data .= pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF); // Geschwister/Kind
            $data = str_pad($data, 0x74, "\x00");
            $data .= pack('V', $start);
            $data .= pack('VV', $size, 0);
            return str_pad($data, 128, "\x00");
        };

        $directory = $entry('Root Entry', 5, 0xFFFFFFFE, 0)
            . $entry($streamName, 2, 2, strlen($stream)) // Daten beginnen in Sektor 2
            . str_repeat("\x00", 256);

        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 16);
        $header .= pack('vv', 0x003E, 0x0003);     // Version
        $header .= pack('v', 0xFFFE);              // Bytereihenfolge
        $header .= pack('vv', 9, 6);               // Sektorgroesse 512 / Mini 64
        $header = str_pad($header, 0x2C, "\x00");
        $header .= pack('V', 1);                   // Anzahl FAT-Sektoren
        $header .= pack('V', 1);                   // erster Verzeichnis-Sektor
        $header .= pack('V', 0);                   // Transaktionsnummer
        $header .= pack('V', 4096);                // Mini-Stream-Grenze
        $header .= pack('V', $end);                // erster Mini-FAT-Sektor
        $header .= pack('V', 0);                   // Anzahl Mini-FAT-Sektoren
        $header .= pack('V', $end);                // erster DIFAT-Sektor
        $header .= pack('V', 0);                   // Anzahl DIFAT-Sektoren
        $header .= pack('V', 0);                   // DIFAT[0] = Sektor 0
        $header = str_pad($header, $sectorSize, "\xFF");

        return $header . $fatSector . $directory . $stream;
    }
}
