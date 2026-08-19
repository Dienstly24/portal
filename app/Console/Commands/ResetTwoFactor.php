<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/**
 * Letzte Rettung, wenn NIEMAND mehr in die Beraterwelt kommt.
 *
 * Der Weg ueber die Oberflaeche (Mitarbeiter -> Zwei-Faktor zuruecksetzen)
 * setzt voraus, dass sich mindestens ein Admin noch anmelden kann. Genau
 * das ist beim Verlust des einzigen Admin-Telefons nicht mehr der Fall -
 * dann bleibt nur der Server. Der Befehl laeuft ausschliesslich dort, wo
 * ohnehin schon SSH-Zugriff besteht; er schafft also keine neue Luecke.
 *
 * Beispiel:
 *   php artisan 2fa:zuruecksetzen chef@dienstly24.de
 */
class ResetTwoFactor extends Command
{
    protected $signature = '2fa:zuruecksetzen {email} {--alle-anzeigen : Nur auflisten, wer 2FA eingerichtet hat}';

    protected $description = 'Zwei-Faktor-Anmeldung eines Kontos zuruecksetzen (Telefon verloren).';

    public function handle(TwoFactorService $twoFactor): int
    {
        if ($this->option('alle-anzeigen')) {
            $rows = User::whereNotNull('two_factor_confirmed_at')
                ->orderBy('email')
                ->get(['email', 'role', 'two_factor_confirmed_at'])
                ->map(fn ($u) => [$u->email, $u->role, (string) $u->two_factor_confirmed_at])
                ->all();
            $this->table(['E-Mail', 'Rolle', 'Eingerichtet am'], $rows);

            return self::SUCCESS;
        }

        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Kein Konto mit der Adresse {$email} gefunden.");

            return self::FAILURE;
        }
        if (! $user->hasTwoFactor()) {
            $this->warn("Fuer {$email} ist derzeit keine Zwei-Faktor-Anmeldung eingerichtet - nichts zu tun.");

            return self::SUCCESS;
        }

        $twoFactor->disable($user, null, 'two_factor_reset_via_cli');

        $this->info("Zwei-Faktor-Anmeldung fuer {$email} zurueckgesetzt.");
        $this->line('Beim naechsten Login fuehrt das System durch die Neueinrichtung.');

        return self::SUCCESS;
    }
}
