<?php

namespace Tests\Feature;

use App\Mail\EmployeeWelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * KI-006 (Wissensbasis, 23.09.2026): die Einladungsmail an einen neuen
 * Mitarbeiter listete die Rechte aus dem ANGEKREUZTEN Formular, das Konto
 * bekam dagegen nur die Rechte, die der Handelnde selbst weitergeben darf
 * (`vergebbareRechte`, Sicherheitsaudit 19.09.2026). Kreuzte ein Manager ein
 * Recht an, das er nicht hat, wurde es korrekt NICHT vergeben - die Mail
 * behauptete es trotzdem. Der neue Kollege suchte dann eine Funktion, die
 * es fuer ihn nicht gibt.
 *
 * Die Mail beschreibt jetzt das, was im Konto STEHT.
 */
class EinladungsmailRechteTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_mail_nennt_kein_recht_das_nicht_vergeben_wurde(): void
    {
        Mail::fake();
        $manager = User::factory()->create([
            'role' => 'manager',
            'can_send_emails' => false,
            'can_manage_contracts' => true,
        ]);

        $this->actingAs($manager)->post(route('admin.employees.store'), [
            'name' => 'Neue Kollegin',
            'email' => 'neu@example.org',
            'access_level' => 'limited',
            'can_send_emails' => '1',
            'can_manage_contracts' => '1',
        ])->assertRedirect();

        $konto = User::where('email', 'neu@example.org')->firstOrFail();
        $this->assertFalse((bool) $konto->can_send_emails, 'Vorbedingung: das Recht darf nicht vergeben sein.');
        $this->assertTrue((bool) $konto->can_manage_contracts);

        Mail::assertSent(EmployeeWelcomeMail::class, function (EmployeeWelcomeMail $mail) {
            $text = implode(' | ', $mail->permissions);

            return ! str_contains($text, 'E-Mails senden')
                && str_contains($text, 'Verträge verwalten');
        });
    }

    public function test_admin_vergibt_und_die_mail_nennt_es(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.employees.store'), [
            'name' => 'Voller Zugriff',
            'email' => 'voll@example.org',
            'access_level' => 'full',
            'can_see_all_customers' => '1',
            'can_send_emails' => '1',
        ])->assertRedirect();

        Mail::assertSent(EmployeeWelcomeMail::class, function (EmployeeWelcomeMail $mail) {
            $text = implode(' | ', $mail->permissions);

            return str_contains($text, 'E-Mails senden') && str_contains($text, 'Zugriff auf alle Kunden');
        });
    }
}
