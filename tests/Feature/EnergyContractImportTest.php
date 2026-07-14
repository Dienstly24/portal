<?php

namespace Tests\Feature;

use App\Console\Commands\ImportEnergyContracts;
use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\Customer;
use App\Models\ExternalReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnergyContractImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvPath = tempnam(sys_get_temp_dir(), 'energie') . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
        parent::tearDown();
    }

    /** Schreibt die CSV im selben Format wie der Export (Windows-1252, ";"). */
    private function writeCsv(array $dataRows): void
    {
        $header = 'VP-Name;Auftr.-Nr.;"Ihre Auftr.-Nr.";Anlagedatum;Auftr.-Status;'
            . 'Auftr.-Statustext;Kunden;Anschrift;Geburtsdatum;Telefonnummer;'
            . 'Zaehlernummer;Verbrauch;"Verbrauch NT";Tarif/Produkt;VAP;VAP-Datum;'
            . 'RL;RL-Datum;Stornodatum;Wiederanschaltungsdatum;"VP Nummer";"UVP Nummer"';

        $lines = array_merge([$header], $dataRows);
        $utf8 = implode("\r\n", $lines) . "\r\n";
        // Export ist Windows-1252 kodiert -> genau so ablegen, damit der
        // Dekodierpfad des Kommandos real getestet wird.
        file_put_contents($this->csvPath, mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'));
    }

    /** Eine Datenzeile mit 22 Spalten in Export-Reihenfolge. */
    private function row(array $o): string
    {
        $cells = [
            $o['vp'] ?? 'Herr Ahmad Albhre',
            $o['nr'],
            '',
            $o['anlage'] ?? '08.07.2026',
            $o['status'] ?? '7100',
            $o['statustext'] ?? 'Vom Anbieter verprovisioniert',
            $o['kunde'],
            $o['anschrift'],
            $o['geburt'] ?? '16.05.1984',
            $o['tel'] ?? '+49 0176 1234567',
            $o['zaehler'] ?? '18956665',
            $o['verbrauch'] ?? '4400',
            $o['verbrauch_nt'] ?? '0',
            $o['produkt'],
            $o['vap'] ?? 'Ja',
            $o['vap_datum'] ?? '22.06.2026',
            $o['rl'] ?? 'Ja',
            $o['rl_datum'] ?? '22.06.2026',
            $o['storno'] ?? '-',
            $o['wieder'] ?? '-',
            '10009798',
            '0',
        ];
        // Werte mit Leerzeichen/Sonderzeichen in Anfuehrungszeichen setzen.
        return implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cells));
    }

    public function test_imports_customer_and_energy_contract(): void
    {
        $this->writeCsv([
            $this->row([
                'nr' => '1636476', 'kunde' => 'Herr Obaida Khrewish',
                'anschrift' => 'Grafenstr. 26, 24768 Rendsburg', 'geburt' => '16.05.1984',
                'produkt' => 'LichtBlick - ÖkoStrom 24', 'verbrauch' => '4400',
                'zaehler' => '18956665', 'status' => '7100',
            ]),
        ]);

        $this->artisan('energie:import', ['file' => $this->csvPath])->assertSuccessful();

        $this->assertSame(1, Customer::count());
        $customer = Customer::with('user')->first();
        $this->assertSame('Obaida Khrewish', $customer->user->name);
        $this->assertSame('male', $customer->gender);
        $this->assertSame('1984-05-16', (string) $customer->birth_date);
        $this->assertSame('24768', $customer->address_zip);
        $this->assertSame('Rendsburg', $customer->address_city);
        $this->assertSame('Grafenstr.', $customer->address_street);
        $this->assertSame('26', $customer->address_house_number);
        $this->assertSame('import', $customer->source);

        $this->assertSame(1, Contract::count());
        $contract = Contract::first();
        $this->assertSame('strom_gas', $contract->type);
        $this->assertSame('LichtBlick', $contract->insurer);
        $this->assertSame('active', $contract->status);
        $this->assertSame('1636476', $contract->contract_number);

        $detail = ContractEnergyDetail::first();
        $this->assertSame('ÖkoStrom 24', $detail->tariff);
        $this->assertSame(4400, $detail->consumption_kwh);
        $this->assertSame('18956665', $detail->meter_number);

        // Auftragsnummer als externe Referenz (Rueckverfolgung + Idempotenz).
        $this->assertSame(1, ExternalReference::where('type', ImportEnergyContracts::REF_TYPE)->count());
    }

    public function test_multiple_contracts_of_same_customer_are_merged(): void
    {
        $this->writeCsv([
            $this->row([
                'nr' => '1585819', 'kunde' => 'Herr Mahmoud Alolabi',
                'anschrift' => 'Oestermörsch 9, 44145 Dortmund', 'geburt' => '16.07.1970',
                'produkt' => 'LichtBlick - ÖkoStrom 24', 'zaehler' => '1EMH0007733900',
            ]),
            $this->row([
                'nr' => '1585809', 'kunde' => 'Herr Mahmoud Alolabi',
                'anschrift' => 'Oestermörsch 9, 44145 Dortmund', 'geburt' => '16.07.1970',
                'produkt' => 'LichtBlick - LichtBlick Gas Relax 24', 'zaehler' => '3341538',
            ]),
        ]);

        $this->artisan('energie:import', ['file' => $this->csvPath])->assertSuccessful();

        // EIN Kunde, ZWEI Vertraege (Strom + Gas) an derselben Akte.
        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Contract::count());
        $this->assertSame(2, Customer::first()->contracts()->count());
    }

    public function test_status_mapping_pending_and_cancelled(): void
    {
        $this->writeCsv([
            $this->row([
                'nr' => 'A1', 'kunde' => 'Herr Test Pending', 'anschrift' => 'Teststr. 1, 12345 Berlin',
                'geburt' => '01.01.1990', 'produkt' => 'EWE - EWE business Gas', 'status' => '3100',
            ]),
            $this->row([
                'nr' => 'A2', 'kunde' => 'Frau Test Storno', 'anschrift' => 'Teststr. 2, 12345 Berlin',
                'geburt' => '02.02.1992', 'produkt' => 'EWE - EWE business Gas', 'status' => '9300',
                'storno' => '10.06.2026',
            ]),
        ]);

        $this->artisan('energie:import', ['file' => $this->csvPath])->assertSuccessful();

        $pending = Contract::where('contract_number', 'A1')->first();
        $this->assertSame('pending', $pending->status);

        $storno = Contract::where('contract_number', 'A2')->first();
        $this->assertSame('cancelled', $storno->status);
        $this->assertSame('2026-06-10', (string) $storno->cancellation_date);
        $this->assertSame('female', $storno->customer->gender);
    }

    public function test_import_is_idempotent(): void
    {
        $rows = [
            $this->row([
                'nr' => '999001', 'kunde' => 'Herr Rerun Test', 'anschrift' => 'Wiederstr. 5, 50667 Köln',
                'geburt' => '03.03.1988', 'produkt' => 'LichtBlick - ÖkoStrom 24',
            ]),
        ];
        $this->writeCsv($rows);

        $this->artisan('energie:import', ['file' => $this->csvPath])->assertSuccessful();
        $this->artisan('energie:import', ['file' => $this->csvPath])->assertSuccessful();

        // Zweiter Lauf legt nichts doppelt an.
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Contract::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->writeCsv([
            $this->row([
                'nr' => '777001', 'kunde' => 'Herr Dry Run', 'anschrift' => 'Probestr. 1, 10115 Berlin',
                'geburt' => '04.04.1980', 'produkt' => 'LichtBlick - ÖkoStrom 24',
            ]),
        ]);

        $this->artisan('energie:import', ['file' => $this->csvPath, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Contract::count());
    }
}
