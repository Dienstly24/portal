<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: verschluesselte Bankdaten (iban/iban2) muessen sich speichern
 * lassen. Der Laravel-Ciphertext ist >255 Zeichen; solange die Spalten noch
 * VARCHAR(255) waren, brach das Speichern der Kundenakte auf MySQL mit
 * "Data too long for column 'iban'" (HTTP 500) ab. Die Spalten sind jetzt TEXT.
 */
class CustomerEncryptedColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Mustermann']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-ENC01',
            'preferred_lang' => 'de',
        ]);
    }

    public function test_iban_and_iban2_columns_are_not_length_limited(): void
    {
        // iban/iban2 duerfen keine kurze VARCHAR-Spalte mehr sein, sonst passt
        // der verschluesselte Wert nicht hinein.
        foreach (['iban', 'iban2'] as $column) {
            $type = strtolower(Schema::getColumnType('customers', $column));
            $this->assertStringNotContainsString('varchar', $type, "Spalte {$column} darf kein VARCHAR sein");
        }
    }

    public function test_admin_can_save_customer_with_long_formatted_iban(): void
    {
        $customer = $this->makeCustomer();

        // Formatierte IBAN mit Kontoinhaber -> Ciphertext > 255 Zeichen.
        $iban = 'DE89 3704 0044 0532 0130 00 / Max Mustermann';

        $this->actingAs($this->admin())
            ->put(route('admin.customer.update', $customer->id), [
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'preferred_lang' => 'de',
                'customer_type' => 'privat',
                'iban' => $iban,
                'iban2' => $iban,
            ])
            ->assertRedirect();

        // Rundlauf: der Wert wird korrekt verschluesselt abgelegt und wieder
        // entschluesselt gelesen (kein Abschneiden, keine 500).
        $fresh = Customer::find($customer->id);
        $this->assertSame($iban, $fresh->iban);
        $this->assertSame($iban, $fresh->iban2);

        // Der roh gespeicherte Ciphertext ist tatsaechlich laenger als 255.
        $raw = DB::table('customers')->where('id', $customer->id)->value('iban');
        $this->assertGreaterThan(255, strlen($raw));
    }
}
