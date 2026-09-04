<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ARCH-1: die Indexe, auf die sich die heissen Abfragen verlassen.
 *
 * Sie stehen hier als Vertrag, nicht als Schmuck: faellt einer weg (etwa
 * weil eine spaetere Migration eine Tabelle neu aufbaut), wird aus einer
 * indizierten Suche wieder ein Full Table Scan - und das merkt man nicht
 * an einem Fehler, sondern erst Monate spaeter an der Ladezeit.
 */
class DatabaseIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function erwarteteIndexe(): array
    {
        return [
            // Haeufigste Bedingung im ganzen Projekt - war unindiziert.
            'customers.user_id' => ['customers', 'customers_user_id_idx'],
            // Gleichheits-Vorfilter der Dublettenerkennung.
            'customers.email2' => ['customers', 'customers_email2_idx'],
            'customers.phone' => ['customers', 'customers_phone_idx'],
            'customers.mobile' => ['customers', 'customers_mobile_idx'],
            'customers.address_zip' => ['customers', 'customers_address_zip_idx'],
            'customers.created_at' => ['customers', 'customers_created_at_idx'],
            'customers.created_by' => ['customers', 'customers_created_by_idx'],
            'customers.acquired_by' => ['customers', 'customers_acquired_by_idx'],
            // Ablauf-/Kuendigungslaeufe.
            'contracts.end_date' => ['contracts', 'contracts_end_date_idx'],
            'contracts.status+end_date' => ['contracts', 'contracts_status_end_date_idx'],
            // Kundenakte-Dokumente UND Eingang (customer_id IS NULL).
            'documents.customer_id+created_at' => ['documents', 'documents_customer_created_idx'],
            'documents.uploaded_by' => ['documents', 'documents_uploaded_by_idx'],
            // Vorgangsliste: Filter + Standardsortierung.
            'tickets.status+created_at' => ['tickets', 'tickets_status_created_idx'],
            'tickets.assigned_to' => ['tickets', 'tickets_assigned_to_idx'],
            // hasMany-Relationen, die vorher gar keinen Index hatten.
            'customer_vehicles.customer_id' => ['customer_vehicles', 'customer_vehicles_customer_idx'],
            'customer_timeline.customer_id' => ['customer_timeline', 'customer_timeline_customer_created_idx'],
            'customer_notes.customer_id' => ['customer_notes', 'customer_notes_customer_created_idx'],
            'appointments.customer_id' => ['appointments', 'appointments_customer_idx'],
            'contract_commissions.customer_id' => ['contract_commissions', 'contract_commissions_customer_idx'],
        ];
    }

    #[DataProvider('erwarteteIndexe')]
    public function test_erwarteter_index_existiert(string $table, string $index): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $index),
            "Index {$index} auf {$table} fehlt - die zugehoerige Abfrage laeuft wieder ueber die ganze Tabelle."
        );
    }

    /**
     * Kein Index darf die FUEHRENDE Spalte eines anderen Index derselben
     * Tabelle nur wiederholen: der laengere deckt den kuerzeren mit ab, der
     * zweite Baum kostet dann nur Schreibzeit.
     */
    public function test_keine_doppelten_indexe_auf_den_kerntabellen(): void
    {
        foreach (['customers', 'contracts', 'documents', 'tickets'] as $table) {
            $spaltenlisten = [];
            foreach (Schema::getIndexes($table) as $index) {
                // Primaerschluessel bleiben aussen vor.
                if ($index['primary'] ?? false) {
                    continue;
                }
                $spaltenlisten[$index['name']] = $index['columns'];
            }

            foreach ($spaltenlisten as $name => $columns) {
                foreach ($spaltenlisten as $anderer => $andereSpalten) {
                    if ($name === $anderer || count($columns) > count($andereSpalten)) {
                        continue;
                    }
                    $this->assertNotSame(
                        $columns,
                        array_slice($andereSpalten, 0, count($columns)),
                        "Index {$name} auf {$table} ist von {$anderer} bereits abgedeckt (gleiche fuehrende Spalten)."
                    );
                }
            }
        }
    }

    /**
     * Die Abfragen, wegen derer die Indexe da sind, muessen weiterhin
     * dasselbe liefern - ein Index darf nie ein Ergebnis veraendern.
     */
    public function test_kernabfragen_liefern_weiterhin_ergebnisse(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'name' => 'Anna Beispiel',
            'email' => 'anna-index@example.test',
        ]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-IDXTEST1',
            'email2' => 'zweit@example.test',
            'phone' => '040123456',
            'address_zip' => '22765',
        ]);
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG',
            'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Ticket::create([
            'customer_id' => $customer->id,
            'status' => 'open',
            'type' => 'question',
            'subject' => 'Index-Test',
            'description' => 'Index-Test',
        ]);
        Document::create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'category' => 'sonstiges',
            'file_name' => 'a.pdf',
            'file_path' => 'a.pdf',
            'disk' => 'local',
        ]);
        Document::create([
            'customer_id' => null,
            'category' => 'sonstiges',
            'file_name' => 'b.pdf',
            'file_path' => 'b.pdf',
            'disk' => 'local',
        ]);

        $this->assertSame((string) $customer->id, Customer::where('user_id', $user->id)->value('id'));
        $this->assertSame((string) $customer->id, Customer::where('email2', 'zweit@example.test')->value('id'));
        $this->assertSame((string) $customer->id, Customer::where('phone', '040123456')->value('id'));
        $this->assertSame((string) $customer->id, Customer::where('address_zip', '22765')->value('id'));
        $this->assertSame((string) $customer->id, Customer::search('Anna')->value('customers.id'));

        $this->assertSame(1, Contract::where('status', 'active')->whereNotNull('end_date')->count());
        $this->assertSame(1, Ticket::where('status', 'open')->orderByDesc('created_at')->count());
        $this->assertSame(1, Document::inbox()->count());
        $this->assertSame(1, Document::where('customer_id', $customer->id)->orderByDesc('created_at')->count());
    }
}
