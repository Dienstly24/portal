<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Legt ein Admin-Konto an oder setzt dessen Passwort zurück – zum
 * Wiederherstellen des Zugangs, wenn man ausgesperrt ist.
 *
 * Beispiel:
 *   php artisan admin:set-password admin@dienstly24.de "MeinNeuesPasswort"
 */
class SetAdminPassword extends Command
{
    protected $signature = 'admin:set-password {email} {password} {--name=Administrator}';

    protected $description = 'Admin-Konto anlegen oder Passwort zurücksetzen (Zugang wiederherstellen).';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');

        // Gleiche Mindestlaenge wie in der Oberflaeche (PasswordPolicy) -
        // ein CLI-Hintertuerchen mit schwaecherer Regel macht die Regel
        // wertlos. Ein Admin-Konto sieht alle Kundendaten.
        $minimum = PasswordPolicy::MIN_STAFF;
        if (mb_strlen($password) < $minimum) {
            $this->error("Passwort muss mindestens {$minimum} Zeichen lang sein.");
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // Passwort wird durch das 'hashed'-Cast automatisch gehasht.
            $user->update(['role' => 'admin']);
            // setPassword() fuehrt die Zeitstempel mit und hebt einen
            // faelligen Zwangswechsel auf - hier vergibt der Betreiber das
            // Passwort bewusst selbst am Server.
            $user->setPassword($password);
            $this->info("Passwort für bestehendes Konto {$email} zurückgesetzt und Rolle=admin gesetzt.");
        } else {
            User::create([
                'id' => (string) Str::uuid(),
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
                'role' => 'admin',
                'email_verified_at' => now(),
            ])->forceFill([
                'password_changed_at' => now(),
                'portal_password_set_at' => now(),
            ])->save();
            $this->info("Neues Admin-Konto {$email} angelegt.");
        }

        return self::SUCCESS;
    }
}
