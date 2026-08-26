<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Familien-Anzeige im Admin: Die Kundenakte zeigt Ehepartner/Kinder mit
 * vollstaendigen Daten (Geburtsdatum, Krankenkasse ...) und das Geschlecht
 * wird beim Speichern uebernommen (frueher ging family_geschlecht verloren).
 */
class AdminFamilyDisplayTest extends TestCase
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
            'customer_number' => 'C-FAM01',
            'preferred_lang' => 'de',
        ]);
    }

    public function test_customer_akte_shows_family_member_with_details(): void
    {
        $customer = $this->makeCustomer();
        CustomerFamily::create([
            'customer_id' => $customer->id,
            'name' => 'Karam Mustermann',
            'relation' => 'kind',
            'birth_date' => '2015-04-01',
            'gender' => 'male',
            'health_insurance_company' => 'TK',
            'health_insurance_number' => 'A123456789',
            'health_insurance_status' => 'familienversichert',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('Karam Mustermann')
            ->assertSee('TK')
            ->assertSee('A123456789')
            ->assertSee('👨‍👩‍👦 Familie', false);
    }

    public function test_update_persists_family_gender(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->put(route('admin.customer.update', $customer->id), [
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'preferred_lang' => 'de',
                'customer_type' => 'privat',
                'family_name' => ['Baraka Mustermann'],
                'family_relation' => ['Ehepartner'],
                'family_birth' => ['1992-02-02'],
                'family_geschlecht' => ['female'],
            ])
            ->assertRedirect();

        $member = CustomerFamily::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('Baraka Mustermann', $member->name);
        $this->assertSame('female', $member->gender);
    }

    /**
     * Der Entfernen-Knopf darf NICHT in einem Formular im Formular stecken:
     * der Browser wirft das innere Formular weg, der Klick schickte dann das
     * Kunden-Formular ab und das Mitglied blieb stehen (gemeldet: Ehepartner
     * liess sich nicht loeschen).
     */
    public function test_family_delete_form_is_not_nested_inside_edit_form(): void
    {
        $customer = $this->makeCustomer();
        $spouse = CustomerFamily::create([
            'customer_id' => $customer->id,
            'name' => 'Baraka Mustermann',
            'relation' => 'ehepartner',
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.customer.edit', $customer->id))
            ->assertOk()
            ->getContent();

        // Knopf verweist per form-Attribut auf das ausgelagerte Formular
        $this->assertStringContainsString('form="family-delete-' . $spouse->id . '"', $html);
        $this->assertStringContainsString('id="family-delete-' . $spouse->id . '"', $html);

        // Im Bearbeiten-Formular selbst steht kein weiteres <form>
        $start = strpos($html, 'action="' . route('admin.customer.update', $customer->id) . '"');
        $this->assertNotFalse($start);
        $end = strpos($html, '</form>', $start);
        $this->assertNotFalse($end);
        $this->assertStringNotContainsString('<form', substr($html, $start, $end - $start));
    }

    public function test_admin_can_delete_spouse(): void
    {
        $customer = $this->makeCustomer();
        $spouse = CustomerFamily::create([
            'customer_id' => $customer->id,
            'name' => 'Baraka Mustermann',
            'relation' => 'ehepartner',
        ]);
        $child = CustomerFamily::create([
            'customer_id' => $customer->id,
            'name' => 'Karam Mustermann',
            'relation' => 'kind',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.customer.family.delete', $spouse->id))
            ->assertRedirect(route('admin.customer.edit', $customer->id));

        $this->assertDatabaseMissing('customer_family', ['id' => $spouse->id]);
        $this->assertDatabaseHas('customer_family', ['id' => $child->id]);
        // Der Kunde selbst bleibt unangetastet
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }
}
