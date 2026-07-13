<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Portal\PortalAccessService;
use Illuminate\Console\Command;

/**
 * Setzt das Portal-Passwort eines Kunden auf sein Geburtsdatum (TT.MM.JJJJ) -
 * ohne E-Mail-Versand. Zweck: importierte oder frueh angelegte Kunden haben
 * ein zufaelliges Passwort und konnten sich nie mit ihrem Geburtsdatum
 * anmelden. Damit gilt die Regel "Startpasswort = Geburtsdatum" fuer alle.
 *
 * Beispiele:
 *   php artisan portal:birthdate-password kunde@example.de
 *   php artisan portal:birthdate-password --all-missing   (alle ohne gesetztes Passwort)
 */
class SetPortalBirthdatePassword extends Command
{
    protected $signature = 'portal:birthdate-password {email? : E-Mail des Kunden} {--all-missing : Alle Kunden ohne gesetztes Portal-Passwort}';

    protected $description = 'Setzt das Portal-Passwort auf das Geburtsdatum (TT.MM.JJJJ), ohne E-Mail zu senden.';

    public function handle(PortalAccessService $portal): int
    {
        $email = $this->argument('email');
        $allMissing = (bool) $this->option('all-missing');

        if (!$email && !$allMissing) {
            $this->error('Bitte eine E-Mail angeben oder --all-missing verwenden.');
            return self::FAILURE;
        }

        $query = Customer::query()->with('user')->whereHas('user', function ($q) use ($email) {
            $q->where('role', 'customer');
            if ($email) {
                $q->where('email', trim((string) $email));
            }
        });

        if ($allMissing) {
            $query->whereHas('user', fn ($q) => $q->whereNull('portal_password_set_at'));
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            $this->warn('Keine passenden Kunden gefunden.');
            return self::SUCCESS;
        }

        $set = 0;
        $skipped = 0;
        foreach ($customers as $customer) {
            $password = $portal->initialPasswordFor($customer);
            if ($password === null) {
                $this->warn("Uebersprungen (kein Geburtsdatum): {$customer->user?->email}");
                $skipped++;
                continue;
            }
            $customer->user->forceFill([
                'password' => bcrypt($password),
                'portal_password_set_at' => now(),
            ])->save();
            $this->line("Gesetzt: {$customer->user->email} -> {$password}");
            $set++;
        }

        $this->info("Fertig. {$set} Passwort(er) gesetzt, {$skipped} uebersprungen.");
        return self::SUCCESS;
    }
}
