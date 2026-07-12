<?php

namespace App\Console\Commands;

use App\Models\User;
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

        if (strlen($password) < 8) {
            $this->error('Passwort muss mindestens 8 Zeichen lang sein.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // Passwort wird durch das 'hashed'-Cast automatisch gehasht.
            $user->update(['password' => $password, 'role' => 'admin']);
            $this->info("Passwort für bestehendes Konto {$email} zurückgesetzt und Rolle=admin gesetzt.");
        } else {
            User::create([
                'id' => (string) Str::uuid(),
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $this->info("Neues Admin-Konto {$email} angelegt.");
        }

        return self::SUCCESS;
    }
}
