<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\PasswordSetupController;
use App\Mail\EmployeeWelcomeMail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lebenslauf der Mitarbeiter-Einladung (KI-043, Nachpruefung vor dem Merge
 * von PR #358).
 *
 * Vorher: "Einladung erneut senden" stellte einen weiteren Link aus, und
 * ALLE frueheren blieben daneben 14 Tage gueltig. Wer eine alte Mail hatte
 * (weitergeleitet, geteiltes Postfach), setzte damit das Passwort, bevor
 * der Mitarbeiter die neue Mail oeffnete. Jetzt gilt nur die neueste.
 * Die Links stammen aus der WIRKLICH verschickten Mail.
 */
class MitarbeiterEinladungErneutSendenTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORT = 'ein-sehr-langes-passwort-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function mitarbeiter(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'employee', 'email' => uniqid().'@dienstly24.de',
        ], $attrs));
        $user->forceFill(['can_see_all_customers' => false, 'can_manage_commissions' => false])->save();

        return $user;
    }

    /** Erneut senden und das Ergebnis pruefen, bevor die Sitzung fuer den naechsten Akteur geleert wird. */
    private function erneutSenden(User $durch, User $an, bool $erlaubt = true): void
    {
        $antwort = $this->actingAs($durch)->post(route('admin.employees.resend_invitation', $an->id));
        if ($erlaubt) {
            $antwort->assertRedirect()->assertSessionHas('success');
        } else {
            $antwort->assertForbidden();
        }
        auth()->logout();
        $this->flushSession();
    }

    private function letzteEinladung(User $an): string
    {
        $mail = Mail::sent(EmployeeWelcomeMail::class, fn ($m) => $m->hasTo($an->email))->last();
        $this->assertNotNull($mail, 'Keine Einladung an '.$an->email);

        return $mail->setPasswordUrl;
    }

    private function passwortSetzen(string $link, string $passwort = self::PASSWORT): TestResponse
    {
        return $this->post($link, ['password' => $passwort, 'password_confirmation' => $passwort]);
    }

    public function test_nach_erneutem_senden_gilt_nur_die_neue_einladung(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mitarbeiter = $this->mitarbeiter();
        $alt = PasswordSetupController::invitationUrl($mitarbeiter);
        $passwortVorher = $mitarbeiter->fresh()->password;

        // 1. Die alte Einladung funktioniert vor dem erneuten Senden.
        $this->get($alt)->assertOk();

        $this->erneutSenden($admin, $mitarbeiter);
        $neu = $this->letzteEinladung($mitarbeiter);

        // 2. Danach ist die alte ungueltig - Anzeigen UND Absenden.
        $this->get($alt)->assertForbidden();
        $this->passwortSetzen($alt, 'fremdes-sehr-langes-passwort-99')->assertForbidden();
        // 4. ... und sie hat nichts bewirkt.
        $this->assertSame($passwortVorher, $mitarbeiter->fresh()->password);
        $this->assertNull($mitarbeiter->fresh()->password_changed_at);
        $this->assertSame(0, ActivityLog::where('action', 'password_set_via_invitation')->count());

        // 3. Die neue funktioniert.
        $this->get($neu)->assertOk();
        $this->passwortSetzen($neu)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check(self::PASSWORT, $mitarbeiter->fresh()->password));
    }

    /** 5. Eine Einladung setzt ein Passwort - sonst nichts, schon gar keine Rechte. */
    public function test_einladung_aendert_weder_rolle_noch_rechte(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mitarbeiter = $this->mitarbeiter();
        $this->erneutSenden($admin, $mitarbeiter);

        $this->passwortSetzen($this->letzteEinladung($mitarbeiter))->assertRedirect(route('login'));

        $danach = $mitarbeiter->fresh();
        $this->assertSame('employee', $danach->role);
        $this->assertFalse($danach->can_see_all_customers);
        $this->assertFalse($danach->can_manage_commissions);
    }

    /** Der Link gehoert genau einem Konto - eine andere Konto-Nummer bricht die Signatur. */
    public function test_einladung_laesst_sich_nicht_auf_ein_anderes_konto_umbiegen(): void
    {
        $mitarbeiter = $this->mitarbeiter();
        $opfer = User::factory()->create(['role' => 'admin', 'email' => 'chef@dienstly24.de']);
        $link = PasswordSetupController::invitationUrl($mitarbeiter);

        $umgebogen = str_replace('/passwort-festlegen/'.$mitarbeiter->id.'?', '/passwort-festlegen/'.$opfer->id.'?', $link);
        $this->assertNotSame($link, $umgebogen);

        $this->passwortSetzen($umgebogen)->assertForbidden();
        $this->assertFalse(Hash::check(self::PASSWORT, $opfer->fresh()->password));
    }

    /** 6. Ist eine Einladung angenommen, sind alle anderen verbraucht. */
    public function test_nach_annahme_einer_einladung_sind_alle_anderen_ungueltig(): void
    {
        $mitarbeiter = $this->mitarbeiter();
        // Zwei Links mit demselben Stand, z.B. Anlage-Mail und ein
        // unmittelbar danach erzeugter zweiter Link.
        $erste = PasswordSetupController::invitationUrl($mitarbeiter);
        $zweite = PasswordSetupController::invitationUrl($mitarbeiter);

        $this->travel(1)->minutes();
        $this->passwortSetzen($erste)->assertRedirect(route('login'));
        $this->travel(1)->minutes();

        $this->get($zweite)->assertForbidden();
        $this->passwortSetzen($zweite, 'fremdes-sehr-langes-passwort-99')->assertForbidden();
        $this->assertTrue(Hash::check(self::PASSWORT, $mitarbeiter->fresh()->password));
    }

    /**
     * Ein Manager hat keinen Zugriff auf Administrator-Konten (Regel aus
     * dem Bearbeiten). Seit das erneute Senden die Links widerruft, gilt
     * sie auch hier - sonst koennte ein Manager die Zugangslinks eines
     * Administrators entwerten und neue ausloesen.
     */
    public function test_manager_kann_die_einladung_eines_administrators_nicht_erneut_senden(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $chef = User::factory()->create(['role' => 'admin', 'email' => 'chef@dienstly24.de']);
        $link = PasswordSetupController::invitationUrl($chef);
        $stand = $chef->aktuelleZugangslinkVersion();

        $this->erneutSenden($manager, $chef, erlaubt: false);

        $this->assertSame($stand, $chef->aktuelleZugangslinkVersion());
        Mail::assertNotSent(EmployeeWelcomeMail::class);
        $this->get($link)->assertOk();
    }

    /** Die Gegenprobe: Manager duerfen Mitarbeitern weiterhin eine Einladung schicken. */
    public function test_manager_sendet_einem_mitarbeiter_weiterhin(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $mitarbeiter = $this->mitarbeiter();

        $this->erneutSenden($manager, $mitarbeiter);
        $this->get($this->letzteEinladung($mitarbeiter))->assertOk();
    }
}
